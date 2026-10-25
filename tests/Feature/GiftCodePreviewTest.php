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
 * Faz 11: POST /api/gift-codes/preview ve gift-code hız sınırı.
 * Ayrıntılı açıklama: docs/rehber/tests/Feature/GiftCodePreviewTest.md
 */
final class GiftCodePreviewTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'DUGUN2026';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.tiers.standart.price', 249);
        Config::set('davetkart.tiers.gold.price', 399);
        Config::set('davetkart.tiers.elit.price', 549);
    }

    #[Test]
    public function a_percent_code_previews_the_discount_and_the_total(): void
    {
        GiftCode::factory()->withCode(self::CODE)->percent(25)->create();

        $this->preview(User::factory()->create(), self::CODE, 'gold')
            ->assertOk()
            ->assertExactJson(['data' => [
                'benefit' => 'percent',
                'tier' => 'gold',
                'priceMinor' => 39900,
                // Faz 13: otomatik indirim yok (TestCase ilk yayını kapatır).
                'promotion' => null,
                'discountMinor' => 9975,
                'totalMinor' => 29925,
                'giftDiscountMinor' => 9975,
                'currency' => 'TRY',
            ]]);
    }

    #[Test]
    public function a_fixed_code_previews_and_never_goes_below_zero(): void
    {
        GiftCode::factory()->withCode(self::CODE)->fixed(1_000_000)->create();

        $this->preview(User::factory()->create(), self::CODE, 'standart')
            ->assertOk()
            ->assertJsonPath('data.benefit', 'fixed')
            ->assertJsonPath('data.discountMinor', 24900)
            ->assertJsonPath('data.totalMinor', 0);
    }

    #[Test]
    public function a_free_code_previews_a_zero_total_for_its_tier(): void
    {
        GiftCode::factory()->withCode(self::CODE)->free(SubscriptionTier::Elit)->create();

        $this->preview(User::factory()->create(), 'dugun-2026', 'elit')
            ->assertOk()
            ->assertJsonPath('data.benefit', 'free')
            ->assertJsonPath('data.totalMinor', 0);
    }

    #[Test]
    public function previewing_reserves_nothing(): void
    {
        GiftCode::factory()->withCode(self::CODE)->maxUses(1)->create();
        $user = User::factory()->create();

        $this->preview($user, self::CODE, 'gold')->assertOk();
        $this->forgetAuthState();
        $this->preview(User::factory()->create(), self::CODE, 'gold')->assertOk();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, GiftCodeRedemption::query()->count());
    }

    #[Test]
    public function the_preview_never_leaks_the_code_internals(): void
    {
        $code = GiftCode::factory()->withCode(self::CODE)->create(['label' => 'Instagram cekilisi']);

        $body = (string) $this->preview(User::factory()->create(), self::CODE, 'gold')->getContent();

        foreach ([$code->id, $code->code_hash, $code->code_hint, 'Instagram', 'max', 'remaining', 'expires'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    #[Test]
    public function the_preview_applies_the_same_rules_as_checkout(): void
    {
        GiftCode::factory()->withCode('KAPALI01')->disabled()->create();
        GiftCode::factory()->withCode('PLANLI01')->free(SubscriptionTier::Gold)->create();
        $mine = GiftCode::factory()->withCode('BENIM001')->create();
        $user = User::factory()->create();
        GiftCodeRedemption::factory()->for($mine)->forOrder(Order::factory()->for($user)->paid()->create())->create();

        $unknown = $this->preview($user, 'YOKBOYLEKOD', 'gold')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeInvalid->value);
        $this->forgetAuthState();

        $disabled = $this->preview($user, 'KAPALI01', 'gold')->assertStatus(422);
        $this->forgetAuthState();
        $this->assertSame($unknown->getContent(), $disabled->getContent());

        $this->preview($user, 'PLANLI01', 'elit')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeNotApplicable->value)
            ->assertJsonPath('error.params.tier', 'gold');
        $this->forgetAuthState();

        $this->preview($user, 'BENIM001', 'gold')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::GiftCodeAlreadyUsed->value);
    }

    #[Test]
    public function the_body_is_validated(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('gift-codes.preview'), ['code' => self::CODE, 'tier' => 'platin'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
        $this->forgetAuthState();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('gift-codes.preview'), ['tier' => 'gold'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
    }

    #[Test]
    public function a_guest_cannot_preview(): void
    {
        $this->postJson(route('gift-codes.preview'), ['code' => self::CODE, 'tier' => 'gold'])
            ->assertUnauthorized();
    }

    // ------------------------------------------------- hız sınırı

    #[Test]
    public function guessing_codes_is_rate_limited_per_user(): void
    {
        Config::set('davetkart.gift_codes.rate_limit.per_user_per_minute', 3);
        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->preview($user, 'TAHMIN'.$i, 'gold')->assertStatus(422);
            $this->forgetAuthState();
        }

        $this->preview($user, 'TAHMIN9', 'gold')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ErrorCode::RateLimited->value);
        $this->forgetAuthState();

        // Kova kullanıcıya ait: başka biri etkilenmez.
        $this->preview(User::factory()->create(), 'TAHMIN9', 'gold')->assertStatus(422);
    }

    #[Test]
    public function the_hourly_bucket_holds_even_when_the_minute_resets(): void
    {
        Config::set('davetkart.gift_codes.rate_limit.per_user_per_minute', 100);
        Config::set('davetkart.gift_codes.rate_limit.per_user_per_hour', 2);
        $user = User::factory()->create();

        $this->preview($user, 'TAHMIN1', 'gold')->assertStatus(422);
        $this->forgetAuthState();
        $this->preview($user, 'TAHMIN2', 'gold')->assertStatus(422);
        $this->forgetAuthState();

        $this->travel(2)->minutes();

        $this->preview($user, 'TAHMIN3', 'gold')->assertStatus(429);
    }

    /** Önizleme ile kodlu checkout aynı kovada: biri kapanınca diğerine kaçılamaz. */
    #[Test]
    public function preview_and_coded_checkout_share_one_bucket(): void
    {
        Config::set('davetkart.gift_codes.rate_limit.per_user_per_minute', 2);
        $user = User::factory()->create();

        $this->preview($user, 'TAHMIN1', 'gold')->assertStatus(422);
        $this->forgetAuthState();
        $this->preview($user, 'TAHMIN2', 'gold')->assertStatus(422);
        $this->forgetAuthState();

        $this->withToken($this->tokenFor($user))
            ->postJson(route('payments.checkout'), ['tier' => 'gold', 'giftCode' => 'TAHMIN3'])
            ->assertStatus(429);
    }

    /** Kodsuz checkout bu kovaya yazılmaz ve ondan etkilenmez. */
    #[Test]
    public function a_checkout_without_a_code_is_not_limited_by_the_gift_code_bucket(): void
    {
        Config::set('davetkart.gift_codes.rate_limit.per_user_per_minute', 1);
        $user = User::factory()->create();

        $this->preview($user, 'TAHMIN1', 'gold')->assertStatus(422);
        $this->forgetAuthState();

        for ($i = 0; $i < 3; $i++) {
            $this->withToken($this->tokenFor($user))
                ->postJson(route('payments.checkout'), ['tier' => 'gold'])
                ->assertCreated();
            $this->forgetAuthState();
        }
    }

    // ------------------------------------------------- yardımcılar

    /** @return TestResponse<JsonResponse> */
    private function preview(User $user, string $code, string $tier): TestResponse
    {
        return $this->withToken($this->tokenFor($user))
            ->postJson(route('gift-codes.preview'), ['code' => $code, 'tier' => $tier]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
