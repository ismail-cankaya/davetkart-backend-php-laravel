<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Payment\ShopierGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * POST /api/public/payments/shopier/osb (Faz 12, 12.7 · K117, K126).
 *
 * 'osb' modunda siparişi 'paid' yapan tek kanal. Karşıda bir makine var:
 * imza hatası 404 (K69), başarı düz metin `success`.
 *
 * 🔴 OSB'nin gövde alanları ve eşleme biçimi resmi dokümanla doğrulanmadı
 * (§A-c). Buradaki gövdeler topluluk örneklerindeki biçimde; `platform_order_id`
 * alanının varlığı bir VARSAYIM (ShopierGateway::matchOsbToOrder).
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/ShopierOsbTest.md
 */
final class ShopierOsbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.providers.shopier.api_key', 'test-key');
        Config::set('payment.providers.shopier.api_secret', 'test-secret');
        Config::set('payment.providers.shopier.osb.username', 'osb-user');
        Config::set('payment.providers.shopier.osb.key', 'osb-key');
        Config::set('payment.providers.shopier.confirmation', 'osb');
    }

    #[Test]
    public function a_signed_osb_pays_the_order_and_acknowledges(): void
    {
        $order = $this->shopierOrder(24900);

        $this->osb($this->body($order, ['orderid' => '88001']))
            ->assertOk()
            ->assertContent('success');

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('88001', $order->provider_payment_id);
        $this->assertNotNull($order->paid_at);
    }

    /** İdempotans: Shopier aynı bildirimi tekrarlar -> tek geçiş, yine `success`. */
    #[Test]
    public function a_repeated_osb_is_applied_once(): void
    {
        $order = $this->shopierOrder(24900);
        $body = $this->body($order);

        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'UTC'));
        $this->osb($body)->assertContent('success');
        $first = $order->refresh()->paid_at;

        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:07:00', 'UTC'));
        $this->osb($body)->assertContent('success');

        $this->assertEquals($first, $order->refresh()->paid_at);
    }

    /** K69: makine ucu -> imza hatası 404, satır değişmez. */
    #[Test]
    public function a_wrong_hash_is_a_silent_404(): void
    {
        $order = $this->shopierOrder(24900);
        $body = $this->body($order);
        $body['hash'] = str_repeat('0', 64);

        $this->osb($body)
            ->assertNotFound()
            ->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    /** 🔴 K126: imza doğru, tutar farklı -> ödenmez, critical log, yine `success` (retry etmesin). */
    #[Test]
    public function an_osb_with_a_different_amount_does_not_pay(): void
    {
        $order = $this->shopierOrder(24900);
        $logger = Log::spy();

        $this->osb($this->body($order, ['price' => '1.00']))->assertContent('success');

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $logger->shouldHaveReceived('critical');
    }

    /** İndirimli sipariş (Faz 11): beklenen tutar `amount_minor`, katalog fiyatı değil. */
    #[Test]
    public function a_discounted_order_is_paid_with_its_discounted_amount(): void
    {
        $order = $this->shopierOrder(19950);

        $this->osb($this->body($order, ['price' => '199.50']))->assertContent('success');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    #[Test]
    public function a_test_notification_is_refused_in_production(): void
    {
        $order = $this->shopierOrder(24900);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->osb($this->body($order, ['istest' => '1']))->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    #[Test]
    public function a_test_notification_is_accepted_outside_production(): void
    {
        $order = $this->shopierOrder(24900);

        $this->osb($this->body($order, ['istest' => '1']))->assertContent('success');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    /** Eşlenemeyen OSB (Shopier mağazasındaki başka bir satış): ack + critical, satır yok. */
    #[Test]
    public function an_unmatched_osb_is_acknowledged_and_logged(): void
    {
        $logger = Log::spy();

        $this->osb($this->sign(['orderid' => '88001', 'price' => '249.00', 'currency' => 'TRY']))
            ->assertOk()
            ->assertContent('success');

        $this->assertDatabaseCount('orders', 0);
        $logger->shouldHaveReceived('critical');
    }

    /** K89: süresi dolmuş siparişe geç gelen OSB yine ödenir. */
    #[Test]
    public function a_late_osb_still_pays_an_expired_order(): void
    {
        $order = $this->shopierOrder(24900, OrderStatus::Expired);

        $this->osb($this->body($order))->assertContent('success');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    /** 'callback' modunda OSB kurulmamışsa uç doğrulayamaz -> 404. */
    #[Test]
    public function without_osb_credentials_the_endpoint_refuses(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        Config::set('payment.providers.shopier.osb.key', null);
        $order = $this->shopierOrder(24900);

        $this->osb($this->body($order))->assertNotFound();

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    // ------------------------------------------------------------ yardımcılar

    private function shopierOrder(int $amountMinor, OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()->create([
            'provider' => ShopierGateway::NAME,
            'amount_minor' => $amountMinor,
            'status' => $status,
        ]);
        $order->provider_ref = $order->id;
        $order->save();

        return $order;
    }

    /**
     * @param  array<string, string>  $overrides
     *
     * @return array<string, string>
     */
    private function body(Order $order, array $overrides = []): array
    {
        return $this->sign([
            'platform_order_id' => $order->id,
            'orderid' => '88001',
            'email' => 'ayse@example.com',
            'price' => sprintf('%d.%02d', intdiv($order->amount_minor, 100), $order->amount_minor % 100),
            'currency' => 'TRY',
            'istest' => '0',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, string>  $data
     *
     * @return array<string, string>
     */
    private function sign(array $data): array
    {
        $res = base64_encode((string) json_encode($data));

        return ['res' => $res, 'hash' => hash_hmac('sha256', $res.'osb-user', 'osb-key')];
    }

    /**
     * @param  array<string, string>  $body
     *
     * @return TestResponse<Response>
     */
    private function osb(array $body): TestResponse
    {
        return $this->post(route('public.payments.shopier.osb'), $body, ['Accept' => 'application/json']);
    }
}
