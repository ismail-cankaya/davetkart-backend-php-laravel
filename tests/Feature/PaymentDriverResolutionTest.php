<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\FakeGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\Shopier\ShopierSignature;
use App\Services\Payment\ShopierGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Hangi sürücü? (Faz 12, 12.8 · K70, K123) — AppServiceProvider::resolvePaymentGateway.
 *
 * Kural: yapılandırma hatası GÜRÜLTÜLÜ olmalı. Bilinmeyen sürücü ya da eksik
 * sır -> ödeme uçları 503, sipariş YAZILMAZ; sessizce Fake'e düşmek yok.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/PaymentDriverResolutionTest.md
 */
final class PaymentDriverResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.default', 'shopier');
        Config::set('payment.providers.shopier.api_key', 'test-key');
        Config::set('payment.providers.shopier.api_secret', 'test-secret');
        Config::set('payment.providers.shopier.confirmation', 'osb');
        Config::set('payment.providers.shopier.osb.username', 'osb-user');
        Config::set('payment.providers.shopier.osb.key', 'osb-key');
    }

    #[Test]
    public function the_configured_drivers_resolve(): void
    {
        $this->assertInstanceOf(ShopierGateway::class, $this->app->make(PaymentGateway::class));

        Config::set('payment.default', 'fake');
        $this->assertInstanceOf(FakeGateway::class, $this->app->make(PaymentGateway::class));
    }

    /** Uçtan uca: Shopier seçiliyken checkout imzalı bir form döner, sipariş Shopier'e yazılır. */
    #[Test]
    public function a_shopier_checkout_returns_a_signed_form(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('payments.checkout'), ['tier' => 'gold', 'phone' => '05321234567'])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.checkout.type', 'form')
            ->assertJsonPath('data.checkout.url', 'https://www.shopier.com/ShowProduct/api_pay4.php')
            ->assertJsonMissingPath('data.redirectUrl')
            ->assertJsonMissingPath('data.providerRef');

        $order = Order::query()->sole();
        $this->assertSame('shopier', $order->provider);
        $this->assertSame($order->id, $order->provider_ref);

        /** @var array<string, string> $f */
        $f = $response->json('data.checkout.fields');
        $this->assertSame($order->id, $f['platform_order_id']);
        $this->assertSame(ShopierSignature::forPayment(
            $f['random_nr'],
            $f['platform_order_id'],
            $f['total_order_value'],
            $f['currency'],
            'test-secret',
        ), $f['signature']);
        $this->assertStringNotContainsString('test-secret', (string) $response->getContent());
    }

    /**
     * 🔴 SEC-07 (K180): forma giden callback adresi APP_URL'den; isteğin host'u istemcinin
     * elindedir (host header injection). İki yol: `Host` ve güvenilen vekil arkasında `X-Forwarded-Host`.
     *
     * @param  array<string, string>  $headers
     */
    #[Test]
    #[DataProvider('spoofedHosts')]
    public function the_shopier_callback_url_ignores_the_request_host(string $root, array $headers, ?string $trustedProxies): void
    {
        Config::set('app.url', 'https://davetkart.test');
        Config::set('trustedproxy.proxies', $trustedProxies);
        $user = User::factory()->create();

        $callback = $this->withToken($user->createToken('test')->plainTextToken)
            ->withHeaders($headers)
            ->postJson($root.route('payments.checkout', absolute: false), ['tier' => 'gold', 'phone' => '05321234567'])
            ->assertCreated()
            ->json('data.checkout.fields.callback');

        $this->assertSame('https://davetkart.test'.ShopierGateway::CALLBACK_PATH, $callback);
    }

    /** @return iterable<string, array{string, array<string, string>, string|null}> */
    public static function spoofedHosts(): iterable
    {
        // Testte Host başlığı istek adresinden kurulur (Request::create); withHeader('Host') etkisiz.
        yield 'Host: evil.example' => ['http://evil.example', [], null];
        yield 'güvenilen vekil + X-Forwarded-Host' => ['', ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'], '*'];
    }

    /**
     * @param  array<string, string|null>  $config
     */
    #[Test]
    #[DataProvider('brokenConfigs')]
    public function a_misconfigured_provider_is_503_and_writes_no_order(array $config): void
    {
        foreach ($config as $key => $value) {
            Config::set($key, $value);
        }

        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('payments.checkout'), ['tier' => 'gold', 'phone' => '05321234567'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);

        $this->assertDatabaseCount('orders', 0);
    }

    /** @return iterable<string, array{array<string, string|null>}> */
    public static function brokenConfigs(): iterable
    {
        yield 'bilinmeyen sürücü (K70)' => [['payment.default' => 'iyzico']];
        yield 'API sırrı boş (K123)' => [['payment.providers.shopier.api_secret' => '']];
        yield 'API anahtarı yok' => [['payment.providers.shopier.api_key' => null]];
        yield 'osb modunda OSB anahtarı yok' => [['payment.providers.shopier.osb.key' => null]];
    }

    #[Test]
    public function callback_mode_resolves_without_osb_credentials(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');
        Config::set('payment.providers.shopier.osb.username', null);
        Config::set('payment.providers.shopier.osb.key', null);

        $this->assertInstanceOf(ShopierGateway::class, $this->app->make(PaymentGateway::class));
    }

    /** 🔴 SEC-03 (K170): üretimde `callback` kipi bir yapılandırma hatasıdır; sır eksikliği gibi 503. */
    #[Test]
    public function the_callback_confirmation_mode_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        Config::set('payment.providers.shopier.confirmation', 'callback');
        $logger = Log::spy();

        $this->checkout()
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);

        $this->assertDatabaseCount('orders', 0);
        $logger->shouldHaveReceived('critical', ['Shopier callback confirmation mode refused in production']);
    }

    #[Test]
    public function the_callback_mode_still_works_outside_production(): void
    {
        Config::set('payment.providers.shopier.confirmation', 'callback');

        $this->checkout()->assertCreated()->assertJsonPath('data.checkout.type', 'form');
        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status);
    }

    #[Test]
    public function osb_mode_works_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->checkout()->assertCreated()->assertJsonPath('data.checkout.type', 'form');
        $this->assertSame(OrderStatus::Pending, Order::query()->sole()->status);
    }

    /** @return TestResponse<Response> */
    private function checkout(): TestResponse
    {
        $user = User::factory()->create();

        return $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('payments.checkout'), ['tier' => 'gold', 'phone' => '05321234567']);
    }
}
