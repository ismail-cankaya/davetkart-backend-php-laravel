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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
            ->postJson(route('payments.checkout'), ['tier' => 'gold'])
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
            ->postJson(route('payments.checkout'), ['tier' => 'gold'])
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
}
