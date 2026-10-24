<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Exceptions\PaymentProviderException;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\FormPostAction;
use App\Services\Payment\Shopier\ShopierSignature;
use App\Services\Payment\ShopierGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

/**
 * ShopierGateway (Faz 12, 12.6) — sürücü doğrudan kuruluyor, HTTP ucu yok.
 *
 * 🔴 Gerçek Shopier'e HİÇBİR istek gitmez (TestCase: preventStrayRequests);
 * sürücü zaten ağa çıkmıyor: formu tarayıcı gönderir. Sırlar sahte, imzalar
 * testte hesaplanıyor. Uçlar: ShopierCallbackTest, ShopierOsbTest.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/ShopierGatewayTest.md
 */
final class ShopierGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.providers.shopier.api_key', 'test-key');
        Config::set('payment.providers.shopier.api_secret', self::SECRET);
        Config::set('payment.providers.shopier.confirmation', 'osb');
        Config::set('payment.providers.shopier.osb.username', 'osb-user');
        Config::set('payment.providers.shopier.osb.key', 'osb-key');
    }

    // ---------------------------------------------------------- startCheckout

    #[Test]
    public function checkout_returns_a_signed_form_built_from_the_stored_amount(): void
    {
        $user = User::factory()->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'email' => 'ayse@example.com']);
        $order = $this->order($user, 19950);

        $session = $this->gateway()->startCheckout($order);

        $this->assertInstanceOf(FormPostAction::class, $session->action);
        $this->assertSame((string) $order->id, $session->providerRef);
        $this->assertNull($session->expiresAt);
        $this->assertSame('https://www.shopier.com/ShowProduct/api_pay4.php', $session->action->url());

        $f = $session->action->fields();
        $this->assertSame('test-key', $f['API_key']);
        $this->assertSame((string) $order->id, $f['platform_order_id']);
        $this->assertSame('199.50', $f['total_order_value']);
        $this->assertSame('0', $f['currency']);
        $this->assertSame('1', $f['product_type']);
        $this->assertSame('DavetKart Gold', $f['product_name']);
        $this->assertSame('Ayşe', $f['buyer_name']);
        $this->assertSame('Yılmaz', $f['buyer_surname']);
        $this->assertSame('ayse@example.com', $f['buyer_email']);
        $this->assertSame('11111111110', $f['buyer_id_nr']);
        $this->assertSame(url(ShopierGateway::CALLBACK_PATH), $f['callback']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $f['random_nr']);

        // İmza, formdaki metinlerle (aynı değişken, K124) yeniden hesaplanınca tutmalı.
        $this->assertSame(
            ShopierSignature::forPayment($f['random_nr'], $f['platform_order_id'], $f['total_order_value'], $f['currency'], self::SECRET),
            $f['signature'],
        );
    }

    /** 🔴 Sır yanıta/forma GİRMEZ; yalnızca imzayı üretir. */
    #[Test]
    public function the_secret_never_appears_in_the_form(): void
    {
        $session = $this->gateway()->startCheckout($this->order(User::factory()->create(), 24900));

        $this->assertInstanceOf(FormPostAction::class, $session->action);
        $this->assertNotContains(self::SECRET, $session->action->fields());
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($session->action->fields()));
    }

    #[Test]
    public function each_checkout_gets_a_new_random_number(): void
    {
        $order = $this->order(User::factory()->create(), 24900);
        $gateway = $this->gateway();

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $action = $gateway->startCheckout($order)->action;
            $this->assertInstanceOf(FormPostAction::class, $action);
            $numbers[] = $action->fields()['random_nr'];
        }

        $this->assertGreaterThan(1, count(array_unique($numbers)));
    }

    // ---------------------------------------------------------- kurulum (E7)

    /**
     * @param  array<string, string|null>  $overrides
     */
    #[Test]
    #[DataProvider('brokenConfigs')]
    public function the_driver_refuses_to_start_without_its_secrets(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            Config::set('payment.providers.shopier.'.$key, $value);
        }

        $this->expectException(PaymentProviderException::class);

        $this->gateway();
    }

    /** @return iterable<string, array{array<string, string|null>}> */
    public static function brokenConfigs(): iterable
    {
        yield 'api_key yok' => [['api_key' => null]];
        yield 'api_secret boş' => [['api_secret' => '']];
        yield 'api_secret boşluk' => [['api_secret' => '   ']];
        yield 'osb modunda OSB anahtarı yok' => [['osb.key' => null]];
        yield 'osb modunda kullanıcı adı yok' => [['osb.username' => '']];
        yield 'bilinmeyen mod' => [['confirmation' => 'browser']];
    }

    #[Test]
    public function callback_mode_starts_without_osb_credentials(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        Config::set('payment.providers.shopier.osb.username', null);
        Config::set('payment.providers.shopier.osb.key', null);

        $this->assertSame('shopier', $this->gateway()->name());
    }

    // --------------------------------------------------------- parseCallback

    #[Test]
    public function an_osb_mode_callback_redirects_but_carries_no_notification(): void
    {
        $id = '01JB2Z8Q4M7X3K9V5N6P0R1T2W';

        $callback = $this->gateway()->parseCallback($this->callbackRequest($id, 'success'));

        $this->assertSame($id, $callback->orderId);
        $this->assertTrue($callback->succeeded);
        $this->assertNull($callback->notification);
    }

    #[Test]
    public function a_callback_mode_callback_carries_a_notification(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $id = '01JB2Z8Q4M7X3K9V5N6P0R1T2W';

        $callback = $this->gateway()->parseCallback($this->callbackRequest($id, 'failed', ['payment_id' => '9001']));

        $this->assertFalse($callback->succeeded);
        $this->assertNotNull($callback->notification);
        $this->assertSame($id, $callback->notification->providerRef);
        $this->assertSame(OrderStatus::Failed, $callback->notification->status);
        $this->assertSame('9001', $callback->notification->providerPaymentId);
        $this->assertNull($callback->notification->amountMinor);
    }

    #[Test]
    public function a_callback_with_a_wrong_signature_is_refused(): void
    {
        $request = $this->callbackRequest('01JB2Z8Q4M7X3K9V5N6P0R1T2W', 'success', ['signature' => base64_encode('nope')]);

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->gateway()->parseCallback($request);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[Test]
    #[DataProvider('malformedCallbacks')]
    public function a_malformed_callback_is_a_bad_request(array $overrides): void
    {
        $request = $this->callbackRequest('01JB2Z8Q4M7X3K9V5N6P0R1T2W', 'success', $overrides);

        $this->expectException(BadRequestHttpException::class);

        $this->gateway()->parseCallback($request);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function malformedCallbacks(): iterable
    {
        yield 'durum yok' => [['status' => '']];
        yield 'bilinmeyen durum' => [['status' => 'pending']];
        yield 'imza yok' => [['signature' => '']];
        yield 'random_nr yok' => [['random_nr' => '']];
    }

    #[Test]
    public function a_non_ulid_order_id_is_refused_before_the_signature(): void
    {
        $request = Request::create('/', 'POST', [
            'platform_order_id' => '../../admin',
            'random_nr' => '123456',
            'signature' => 'x',
            'status' => 'success',
        ]);

        $this->expectException(BadRequestHttpException::class);

        $this->gateway()->parseCallback($request);
    }

    // -------------------------------------------------------------- parseOsb

    #[Test]
    public function an_osb_becomes_a_paid_notification_with_its_amount(): void
    {
        $id = '01JB2Z8Q4M7X3K9V5N6P0R1T2W';

        $notification = $this->gateway()->parseOsb($this->osbRequest([
            'platform_order_id' => $id, 'orderid' => '55501', 'price' => '199.50', 'currency' => 'TRY', 'istest' => '0',
        ]));

        $this->assertNotNull($notification);
        $this->assertSame($id, $notification->providerRef);
        $this->assertSame(OrderStatus::Paid, $notification->status);
        $this->assertSame(19950, $notification->amountMinor);
        $this->assertSame('TRY', $notification->currency);
        $this->assertSame('55501', $notification->providerPaymentId);
    }

    /** 🔴 Eşleme belirsiz (§A-c): Shopier'in kendi numarası bir siparişe eşlenmez. */
    #[Test]
    public function an_osb_without_our_order_id_is_not_matched(): void
    {
        $logger = Log::spy();

        $notification = $this->gateway()->parseOsb($this->osbRequest(['orderid' => '55501', 'price' => '199.50']));

        $this->assertNull($notification);
        $logger->shouldHaveReceived('critical');
    }

    #[Test]
    public function an_osb_with_a_tampered_body_is_refused(): void
    {
        $request = Request::create('/', 'POST', [
            'res' => base64_encode('{"orderid":"01JB2Z8Q4M7X3K9V5N6P0R1T2W","price":"1.00"}'),
            'hash' => hash_hmac('sha256', base64_encode('{"orderid":"01JB2Z8Q4M7X3K9V5N6P0R1T2W","price":"399.00"}').'osb-user', 'osb-key'),
        ]);

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->gateway()->parseOsb($request);
    }

    #[Test]
    public function an_osb_with_an_unreadable_price_is_refused(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->gateway()->parseOsb($this->osbRequest(['orderid' => '01JB2Z8Q4M7X3K9V5N6P0R1T2W', 'price' => '1.399,00']));
    }

    #[Test]
    public function an_osb_is_refused_when_osb_is_not_configured(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        Config::set('payment.providers.shopier.osb.key', null);

        $this->expectException(InvalidWebhookSignatureException::class);

        $this->gateway()->parseOsb($this->osbRequest(['orderid' => '01JB2Z8Q4M7X3K9V5N6P0R1T2W', 'price' => '1.00']));
    }

    // ------------------------------------------------------------ yardımcılar

    private function gateway(): ShopierGateway
    {
        return new ShopierGateway;
    }

    private function order(User $user, int $amountMinor): Order
    {
        $order = Order::factory()->tier(SubscriptionTier::Gold)->create([
            'user_id' => $user->id,
            'provider' => ShopierGateway::NAME,
            'amount_minor' => $amountMinor,
        ]);

        return $order->load('user');
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function callbackRequest(string $orderId, string $status, array $overrides = []): Request
    {
        $random = '482913';

        return Request::create('/', 'POST', array_merge([
            'platform_order_id' => $orderId,
            'status' => $status,
            'installment' => '0',
            'payment_id' => '9001',
            'random_nr' => $random,
            'signature' => base64_encode(hash_hmac('sha256', $random.$orderId, self::SECRET, true)),
        ], $overrides));
    }

    /**
     * @param  array<string, string>  $data
     */
    private function osbRequest(array $data): Request
    {
        $res = base64_encode((string) json_encode($data));

        return Request::create('/', 'POST', [
            'res' => $res,
            'hash' => hash_hmac('sha256', $res.'osb-user', 'osb-key'),
        ]);
    }
}
