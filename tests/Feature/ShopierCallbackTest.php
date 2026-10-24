<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Payment\ShopierGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * POST /api/public/payments/shopier/callback (Faz 12, 12.7 · K117, K122).
 *
 * İki modda sınanıyor. 🔴 En önemli test:
 * a_failed_signature_with_a_flipped_status_does_not_pay_in_osb_mode —
 * `status` imzasız; 'osb' modunun bütün gerekçesi o test.
 *
 * T14: yanıt değil ETKİ — her test siparişin veritabanındaki durumuna bakar.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/ShopierCallbackTest.md
 */
final class ShopierCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    private const SUCCESS = 'https://davetkart.test/odeme/basarili';

    private const FAILURE = 'https://davetkart.test/odeme/hata';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.providers.shopier.api_key', 'test-key');
        Config::set('payment.providers.shopier.api_secret', self::SECRET);
        Config::set('payment.providers.shopier.osb.username', 'osb-user');
        Config::set('payment.providers.shopier.osb.key', 'osb-key');
        Config::set('payment.providers.shopier.confirmation', 'osb');
        Config::set('payment.return_urls.success', self::SUCCESS);
        Config::set('payment.return_urls.failure', self::FAILURE);
    }

    /** Forma yazılan adres ile rota aynı yer olmalı (ShopierGateway::CALLBACK_PATH). */
    #[Test]
    public function the_callback_route_is_the_address_written_into_the_form(): void
    {
        $this->assertSame(url(ShopierGateway::CALLBACK_PATH), route('public.payments.shopier.callback'));
    }

    // ------------------------------------------------------------- osb modu

    #[Test]
    public function in_osb_mode_a_successful_callback_only_redirects(): void
    {
        $order = $this->shopierOrder();

        $this->postCallback($this->signed($order->id, 'success'))
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertHeader('Location', self::SUCCESS.'?order='.$order->id);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertNull($order->paid_at);
    }

    /**
     * 🔴 Saldırı: reddedilen ödemenin imzalı callback'inde yalnızca status değiştirilir.
     * İmza geçerli kalır (status imzada yok); 'osb' modunda sipariş yine ödenmez.
     */
    #[Test]
    public function a_failed_signature_with_a_flipped_status_does_not_pay_in_osb_mode(): void
    {
        $order = $this->shopierOrder();
        $body = $this->signed($order->id, 'failed');
        $body['status'] = 'success';

        $this->postCallback($body)->assertStatus(Response::HTTP_SEE_OTHER);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertNull($order->paid_at);
    }

    #[Test]
    public function in_osb_mode_a_failed_callback_goes_to_the_failure_page(): void
    {
        $order = $this->shopierOrder();

        $this->postCallback($this->signed($order->id, 'failed'))
            ->assertHeader('Location', self::FAILURE.'?order='.$order->id);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    // -------------------------------------------------------- callback modu

    #[Test]
    public function in_callback_mode_a_successful_callback_pays_the_order(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $order = $this->shopierOrder();

        $this->postCallback($this->signed($order->id, 'success', ['payment_id' => '700123']))
            ->assertHeader('Location', self::SUCCESS.'?order='.$order->id);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('700123', $order->provider_payment_id);
        $this->assertNotNull($order->paid_at);
    }

    /** İdempotans: aynı callback iki kez gelir (geri tuşu, yenileme) -> tek geçiş. */
    #[Test]
    public function in_callback_mode_a_repeated_callback_is_applied_once(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $order = $this->shopierOrder();
        $body = $this->signed($order->id, 'success');

        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'UTC'));
        $this->postCallback($body);
        $firstPaidAt = $order->refresh()->paid_at;

        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:05:00', 'UTC'));
        $this->postCallback($body)->assertHeader('Location', self::SUCCESS.'?order='.$order->id);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertEquals($firstPaidAt, $order->paid_at);
    }

    #[Test]
    public function in_callback_mode_a_failed_callback_fails_the_order(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $order = $this->shopierOrder();

        $this->postCallback($this->signed($order->id, 'failed'))
            ->assertHeader('Location', self::FAILURE.'?order='.$order->id);

        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
    }

    /**
     * 🔴 BİLİNÇLİ RİSKİN BELGESİ (K117): 'callback' modunda imzasız `status` güvenilir
     * sayılır. Bu test kırmızıya dönerse (Shopier status'ü imzalamaya başladı ya
     * da kod değişti) K117 yeniden değerlendirilmeli; ortadan kaldırılmamalı.
     */
    #[Test]
    public function callback_mode_trusts_the_unsigned_status_by_design(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $order = $this->shopierOrder();
        $body = $this->signed($order->id, 'failed');
        $body['status'] = 'success';

        $this->postCallback($body);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    /** K89: süresi dolmuş siparişe geç gelen ödeme yine kabul edilir. */
    #[Test]
    public function in_callback_mode_a_late_payment_still_pays_an_expired_order(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $order = $this->shopierOrder(OrderStatus::Expired);

        $this->postCallback($this->signed($order->id, 'success'));

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    // --------------------------------------------------------- reddedilenler

    #[Test]
    public function a_wrong_signature_changes_nothing_and_goes_to_the_failure_page(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $order = $this->shopierOrder();
        $logger = Log::spy();

        $body = $this->signed($order->id, 'success');
        $body['signature'] = base64_encode(str_repeat('x', 32));

        // Sipariş kimliği imzasız istekte YANKILANMAZ: hangi siparişlerin var olduğu öğrenilmesin.
        $this->postCallback($body)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertHeader('Location', self::FAILURE);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $logger->shouldHaveReceived('warning', [
            'Shopier callback rejected',
            Mockery::on(fn (array $context): bool => ! str_contains((string) json_encode($context), $body['signature'])),
        ]);
    }

    #[Test]
    public function an_empty_callback_goes_to_the_failure_page(): void
    {
        $this->postCallback([])->assertHeader('Location', self::FAILURE);
    }

    /** Bilinmeyen sipariş: imza geçerli ama bizde yok -> yönlendirme olur, satır doğmaz. */
    #[Test]
    public function an_unknown_order_creates_nothing(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $ghost = (string) Str::ulid();

        $this->postCallback($this->signed($ghost, 'success'))
            ->assertHeader('Location', self::SUCCESS.'?order='.$ghost);

        $this->assertDatabaseCount('orders', 0);
    }

    /** 🔴 Open redirect: hedef istekten okunmaz; ULID olmayan kimlik adrese girmez. */
    #[Test]
    public function the_redirect_target_never_comes_from_the_request(): void
    {
        $order = $this->shopierOrder();

        $this->postCallback([...$this->signed($order->id, 'success'), 'callback' => 'https://evil.example', 'return_url' => 'https://evil.example', 'redirect' => 'https://evil.example'])
            ->assertHeader('Location', self::SUCCESS.'?order='.$order->id);

        $this->postCallback([
            'platform_order_id' => 'https://evil.example/?',
            'status' => 'success',
            'random_nr' => '123456',
            'signature' => 'abc',
        ])->assertHeader('Location', self::FAILURE);
    }

    // ------------------------------------------------------------ yardımcılar

    private function shopierOrder(OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()->create(['provider' => ShopierGateway::NAME, 'status' => $status]);
        $order->provider_ref = $order->id;
        $order->save();

        return $order;
    }

    /**
     * Shopier'in üreteceği gibi imzalı bir callback gövdesi.
     *
     * @param  array<string, string>  $extra
     *
     * @return array<string, string>
     */
    private function signed(string $orderId, string $status, array $extra = []): array
    {
        $random = (string) random_int(100000, 999999);

        return [
            'platform_order_id' => $orderId,
            'status' => $status,
            'installment' => '0',
            'payment_id' => '700001',
            'random_nr' => $random,
            'signature' => base64_encode(hash_hmac('sha256', $random.$orderId, self::SECRET, true)),
            ...$extra,
        ];
    }

    /**
     * Tarayıcının otomatik form POST'u: application/x-www-form-urlencoded.
     *
     * @param  array<string, string>  $body
     *
     * @return TestResponse<Response>
     */
    private function postCallback(array $body): TestResponse
    {
        return $this->post(route('public.payments.shopier.callback'), $body);
    }
}
