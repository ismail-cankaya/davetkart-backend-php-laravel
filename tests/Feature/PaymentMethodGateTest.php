<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 13 (13.5 · K134, K136): `PAYMENT_METHODS` anahtarının checkout ve
 * önizlemedeki etkisi. Frontend düğmeyi gizler; bu testler backend'in de
 * REDDETTİĞİNİ kanıtlar.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/PaymentMethodGateTest.md
 */
final class PaymentMethodGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.tiers.gold.price', 399);
    }

    // ------------------------------------------------- kart kapalı

    #[Test]
    public function with_the_card_off_a_plain_checkout_is_refused_and_writes_nothing(): void
    {
        Config::set('payment.methods', 'gift_code');

        $this->checkout(User::factory()->create(), ['tier' => 'gold'])
            ->assertForbidden()
            ->assertJsonPath('error.code', ErrorCode::PaymentMethodDisabled->value)
            ->assertJsonPath('error.params.method', 'card');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function with_the_card_off_a_partial_code_is_refused_and_the_code_stays_free(): void
    {
        Config::set('payment.methods', 'gift_code');
        GiftCode::factory()->withCode('YUZDEELLI')->percent(50)->maxUses(1)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => 'YUZDEELLI'])
            ->assertForbidden()
            ->assertJsonPath('error.params.method', 'card');

        // Rezervasyon transaction'la geri alındı: kod hâlâ tek hakkını taşıyor.
        $this->assertSame(0, GiftCodeRedemption::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function with_the_card_off_a_free_code_still_works(): void
    {
        Config::set('payment.methods', 'gift_code');
        GiftCode::factory()->withCode('BEDAVA2026')->free(SubscriptionTier::Gold)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => 'BEDAVA2026'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid');
    }

    #[Test]
    public function a_free_code_does_not_need_a_configured_provider(): void
    {
        // K136: kart kapalı + Shopier seçili + sırlar BOŞ. Sürücü kurulsaydı 503.
        Config::set('payment.methods', 'gift_code');
        Config::set('payment.default', 'shopier');
        Config::set('payment.providers.shopier.api_key', null);
        Config::set('payment.providers.shopier.api_secret', null);
        GiftCode::factory()->withCode('BEDAVA2026')->free(SubscriptionTier::Gold)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => 'BEDAVA2026'])
            ->assertCreated();

        $this->assertTrue(Order::query()->sole()->isGift());
    }

    #[Test]
    public function an_unconfigured_provider_still_fails_loudly_when_the_card_is_on(): void
    {
        Config::set('payment.methods', 'card,gift_code');
        Config::set('payment.default', 'shopier');
        Config::set('payment.providers.shopier.api_key', null);
        Config::set('payment.providers.shopier.api_secret', null);

        $this->checkout(User::factory()->create(), ['tier' => 'gold'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);

        $this->assertSame(0, Order::query()->count());
    }

    // ------------------------------------------------- kod kapalı

    #[Test]
    public function with_gift_codes_off_a_coded_checkout_is_refused_before_the_code_is_looked_up(): void
    {
        Config::set('payment.methods', 'card');
        GiftCode::factory()->withCode('BEDAVA2026')->free(SubscriptionTier::Gold)->create();

        $this->checkout(User::factory()->create(), ['tier' => 'gold', 'giftCode' => 'BEDAVA2026'])
            ->assertForbidden()
            ->assertJsonPath('error.params.method', 'gift_code');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function with_gift_codes_off_the_preview_is_refused(): void
    {
        Config::set('payment.methods', 'card');

        $this->withToken(User::factory()->create()->createToken('t')->plainTextToken)
            ->postJson(route('gift-codes.preview'), ['code' => 'HERHANGI1', 'tier' => 'gold'])
            ->assertForbidden()
            ->assertJsonPath('error.params.method', 'gift_code');
    }

    #[Test]
    public function with_gift_codes_off_a_plain_card_checkout_still_works(): void
    {
        Config::set('payment.methods', 'card');

        $this->checkout(User::factory()->create(), ['tier' => 'gold'])->assertCreated();
    }

    // ------------------------------------------------- yanlış yazılmış ayar

    #[Test]
    public function a_misspelled_setting_is_a_503_without_leaking_the_word(): void
    {
        Config::set('payment.methods', 'card,gift_cod');

        $response = $this->checkout(User::factory()->create(), ['tier' => 'gold'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', ErrorCode::ProviderUnavailable->value);

        $this->assertStringNotContainsString('gift_cod', (string) $response->getContent());
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @return TestResponse<JsonResponse>
     */
    private function checkout(User $user, array $body): TestResponse
    {
        return $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson(route('payments.checkout'), $body);
    }
}
