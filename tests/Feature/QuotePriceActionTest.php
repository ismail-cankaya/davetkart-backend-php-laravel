<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\GiftCode\QuoteGiftCodeAction;
use App\Actions\Pricing\PriceQuote;
use App\Actions\Pricing\QuotePriceAction;
use App\Enums\OrderStatus;
use App\Enums\PromotionKind;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\Order;
use App\Models\PriceCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 13 (13.4): fiyat hesabının tek yeri (K129–K133).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/QuotePriceAction.md
 */
final class QuotePriceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('davetkart.tiers.standart.price', 249);
        Config::set('davetkart.tiers.gold.price', 399);
        Config::set('davetkart.tiers.elit.price', 549);
        Config::set('davetkart.pricing.first_publish_percent', 60);
    }

    // ------------------------------------------------- ilk yayın (K130–K131)

    #[Test]
    public function a_new_user_gets_the_first_publish_discount(): void
    {
        $quote = $this->quote(User::factory()->create(), SubscriptionTier::Gold);

        $this->assertSame(39900, $quote->listMinor);
        $this->assertSame(PromotionKind::FirstPublish, $quote->promotion?->kind);
        $this->assertSame(60, $quote->promotion->percent);
        $this->assertSame(23940, $quote->promotionDiscountMinor());
        $this->assertSame(15960, $quote->totalMinor());
    }

    #[Test]
    public function the_discount_is_rounded_down_in_favour_of_the_business(): void
    {
        Config::set('davetkart.tiers.gold.price', 333);
        Config::set('davetkart.pricing.first_publish_percent', 33);

        // 33300 * 33 / 100 = 10989 tam; 33301 olsaydı kesir aşağı giderdi.
        $this->assertSame(10989, $this->quote(User::factory()->create(), SubscriptionTier::Gold)->promotionDiscountMinor());
    }

    /** @return array<string, array{string}> */
    public static function paidStates(): array
    {
        return ['paid' => ['paid'], 'refunded' => ['refunded'], 'gift (free)' => ['gift']];
    }

    #[Test]
    #[DataProvider('paidStates')]
    public function any_order_that_was_ever_paid_ends_the_first_publish_discount(string $state): void
    {
        $user = User::factory()->create();

        match ($state) {
            'paid' => Order::factory()->for($user)->paid()->create(),
            'refunded' => Order::factory()->for($user)->refunded()->create(),
            'gift' => Order::factory()->for($user)->paid()->create(['amount_minor' => 0, 'discount_minor' => 24900, 'provider' => Order::GIFT_PROVIDER]),
            default => $this->fail("Bilinmeyen durum: {$state}"),
        };

        $quote = $this->quote($user, SubscriptionTier::Gold);

        $this->assertNull($quote->promotion);
        $this->assertSame(39900, $quote->totalMinor());
    }

    #[Test]
    public function pending_failed_and_expired_orders_do_not_end_it(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->create();
        Order::factory()->for($user)->failed()->create();
        Order::factory()->for($user)->create(['status' => OrderStatus::Expired]);

        $this->assertSame(PromotionKind::FirstPublish, $this->quote($user, SubscriptionTier::Gold)->promotion?->kind);
    }

    #[Test]
    public function another_users_order_does_not_count(): void
    {
        Order::factory()->paid()->create();

        $this->assertNotNull($this->quote(User::factory()->create(), SubscriptionTier::Gold)->promotion);
    }

    #[Test]
    public function a_guest_never_gets_the_first_publish_discount(): void
    {
        $this->assertNull($this->quote(null, SubscriptionTier::Gold)->promotion);
    }

    #[Test]
    public function zero_turns_the_first_publish_discount_off(): void
    {
        Config::set('davetkart.pricing.first_publish_percent', 0);

        $this->assertNull($this->quote(User::factory()->create(), SubscriptionTier::Gold)->promotion);
    }

    #[Test]
    public function a_percent_that_would_make_the_order_free_is_a_loud_error(): void
    {
        Config::set('davetkart.pricing.first_publish_percent', 100);

        $this->expectException(LogicException::class);

        $this->quote(User::factory()->create(), SubscriptionTier::Gold);
    }

    // ------------------------------------------------- kampanya (K132)

    #[Test]
    public function an_active_campaign_applies_to_a_returning_customer_and_a_guest(): void
    {
        $campaign = PriceCampaign::factory()->percent(20)->create();
        $returning = User::factory()->create();
        Order::factory()->for($returning)->paid()->create();

        foreach ([$returning, null] as $user) {
            $quote = $this->quote($user, SubscriptionTier::Elit);
            $this->assertSame(PromotionKind::Campaign, $quote->promotion?->kind);
            $this->assertSame($campaign->id, $quote->promotion->campaign?->id);
            $this->assertSame(54900 - 10980, $quote->totalMinor());
        }
    }

    #[Test]
    public function upcoming_ended_and_stopped_campaigns_are_ignored(): void
    {
        PriceCampaign::factory()->percent(50)->upcoming()->create();
        PriceCampaign::factory()->percent(50)->ended()->create();
        PriceCampaign::factory()->percent(50)->stopped()->create();

        $this->assertNull($this->quote(null, SubscriptionTier::Gold)->promotion);
    }

    #[Test]
    public function a_tier_campaign_applies_only_to_its_tier(): void
    {
        PriceCampaign::factory()->percent(30)->forTier(SubscriptionTier::Gold)->create();

        $quotes = app(QuotePriceAction::class)->forTiers(null);

        $this->assertNull($quotes['standart']->promotion);
        $this->assertSame(30, $quotes['gold']->promotion?->percent);
        $this->assertNull($quotes['elit']->promotion);
    }

    #[Test]
    public function the_biggest_applicable_campaign_wins_and_a_tie_prefers_the_later_end(): void
    {
        PriceCampaign::factory()->percent(10)->create();
        $gold = PriceCampaign::factory()->percent(30)->forTier(SubscriptionTier::Gold)->create(['ends_at' => now()->addDays(3)]);
        $longer = PriceCampaign::factory()->percent(30)->create(['ends_at' => now()->addDays(10)]);

        $quotes = app(QuotePriceAction::class)->forTiers(null);

        $winner = $quotes['gold']->promotion?->campaign;
        $this->assertNotNull($winner);
        $this->assertSame($longer->id, $winner->id, "Gold'a özel kampanya ({$gold->id}) aynı yüzdede ama daha erken bitiyor.");
        $this->assertSame(30, $quotes['standart']->promotion?->percent);
    }

    // ------------------------------------------------- ikisi birden (K133)

    #[Test]
    public function they_are_not_added_the_bigger_one_wins(): void
    {
        PriceCampaign::factory()->percent(20)->create();
        $newUser = User::factory()->create();

        $quote = $this->quote($newUser, SubscriptionTier::Gold);
        $this->assertSame(PromotionKind::FirstPublish, $quote->promotion?->kind);
        $this->assertSame(15960, $quote->totalMinor());

        PriceCampaign::factory()->percent(70)->create();
        $this->assertSame(PromotionKind::Campaign, $this->quote($newUser, SubscriptionTier::Gold)->promotion?->kind);
    }

    #[Test]
    public function a_tie_goes_to_the_first_publish_discount(): void
    {
        PriceCampaign::factory()->percent(60)->create();

        $this->assertSame(PromotionKind::FirstPublish, $this->quote(User::factory()->create(), SubscriptionTier::Gold)->promotion?->kind);
    }

    #[Test]
    public function three_tiers_cost_two_queries(): void
    {
        $user = User::factory()->create();
        PriceCampaign::factory()->create();

        DB::enableQueryLog();
        app(QuotePriceAction::class)->forTiers($user);

        $this->assertCount(2, DB::getQueryLog());
    }

    // ------------------------------------------------- hediye kodu kalan fiyata

    #[Test]
    public function a_gift_code_applies_to_what_is_left_after_the_promotion(): void
    {
        $user = User::factory()->create();
        $quote = $this->quote($user, SubscriptionTier::Gold);
        $code = GiftCode::factory()->percent(50)->create();

        $withCode = $quote->withGiftCode(
            app(QuoteGiftCodeAction::class)->handle($code, $user, SubscriptionTier::Gold, $quote->afterPromotionMinor()),
        );

        // 399 → %60 → 159,60 → %50 → 79,80
        $this->assertSame(7980, $withCode->giftDiscountMinor());
        $this->assertSame(23940 + 7980, $withCode->discountMinor());
        $this->assertSame(7980, $withCode->totalMinor());
        $this->assertFalse($withCode->coversEverything());
    }

    #[Test]
    public function a_free_code_covers_the_rest_and_a_fixed_code_cannot_go_below_zero(): void
    {
        $user = User::factory()->create();
        $quote = $this->quote($user, SubscriptionTier::Gold);
        $gift = app(QuoteGiftCodeAction::class);

        $free = $quote->withGiftCode($gift->handle(GiftCode::factory()->free(SubscriptionTier::Gold)->create(), $user, SubscriptionTier::Gold, $quote->afterPromotionMinor()));
        $fixed = $quote->withGiftCode($gift->handle(GiftCode::factory()->fixed(50_000)->create(), $user, SubscriptionTier::Gold, $quote->afterPromotionMinor()));

        foreach ([$free, $fixed] as $withCode) {
            $this->assertSame(15960, $withCode->giftDiscountMinor());
            $this->assertSame(0, $withCode->totalMinor());
            $this->assertTrue($withCode->coversEverything());
        }
    }

    #[Test]
    public function a_gift_quote_on_the_list_price_is_refused(): void
    {
        $user = User::factory()->create();
        $quote = $this->quote($user, SubscriptionTier::Gold);
        $onListPrice = app(QuoteGiftCodeAction::class)->handle(GiftCode::factory()->percent(50)->create(), $user, SubscriptionTier::Gold);

        $this->expectException(LogicException::class);

        $quote->withGiftCode($onListPrice);
    }

    private function quote(?User $user, SubscriptionTier $tier): PriceQuote
    {
        return app(QuotePriceAction::class)->handle($user, $tier);
    }
}
