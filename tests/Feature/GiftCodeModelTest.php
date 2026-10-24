<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GiftCodeBenefit;
use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 11: hediye kodu modeli, indirim kuralı (D6), aktif kullanım sayımı (D4)
 * ve veritabanı kısıtları (E2).
 * Ayrıntılı açıklama: docs/rehber/app/Models/GiftCode.md
 */
final class GiftCodeModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Hesap testten okunabilsin diye fiyatlar sabitlenir.
        Config::set('davetkart.tiers.standart.price', 149);
        Config::set('davetkart.tiers.gold.price', 249);
        Config::set('davetkart.tiers.elit.price', 399);
    }

    // ------------------------------------------------------------ D6

    #[Test]
    public function a_free_code_discounts_the_whole_price_of_its_own_tier_only(): void
    {
        $code = GiftCode::factory()->free(SubscriptionTier::Gold)->make();

        $this->assertSame(24900, $code->discountFor(SubscriptionTier::Gold));
        $this->assertSame(0, $code->discountFor(SubscriptionTier::Elit));
        $this->assertFalse($code->isApplicableTo(SubscriptionTier::Standart));
    }

    /** @return array<string, array{int, SubscriptionTier, int}> */
    public static function percentages(): array
    {
        return [
            '%50 standart' => [50, SubscriptionTier::Standart, 7450],
            // 249 TL × %33 = 82,17 TL → 8217 kuruş
            '%33 gold' => [33, SubscriptionTier::Gold, 8217],
            // 149 TL × %7 = 10,43 TL → 1043 kuruş
            '%7 standart' => [7, SubscriptionTier::Standart, 1043],
            '%100 elit' => [100, SubscriptionTier::Elit, 39900],
        ];
    }

    #[Test]
    #[DataProvider('percentages')]
    public function percent_discounts_are_whole_kurus(int $percent, SubscriptionTier $tier, int $expected): void
    {
        $code = GiftCode::factory()->percent($percent)->make();

        $this->assertSame($expected, $code->discountFor($tier));
    }

    #[Test]
    public function a_fixed_discount_never_exceeds_the_price(): void
    {
        $small = GiftCode::factory()->fixed(5050)->make();
        $big = GiftCode::factory()->fixed(1_000_000)->make();

        $this->assertSame(5050, $small->discountFor(SubscriptionTier::Standart));
        $this->assertSame(14900, $big->discountFor(SubscriptionTier::Standart));
    }

    #[Test]
    public function an_untiered_discount_code_applies_to_every_tier_and_a_tiered_one_to_its_own(): void
    {
        $any = GiftCode::factory()->percent(10)->make();
        $goldOnly = GiftCode::factory()->percent(10, SubscriptionTier::Gold)->make();

        foreach (SubscriptionTier::cases() as $tier) {
            $this->assertTrue($any->isApplicableTo($tier));
        }

        $this->assertTrue($goldOnly->isApplicableTo(SubscriptionTier::Gold));
        $this->assertFalse($goldOnly->isApplicableTo(SubscriptionTier::Elit));
        $this->assertSame(0, $goldOnly->discountFor(SubscriptionTier::Elit));
    }

    #[Test]
    public function expiry_and_disabling_are_read_from_their_columns(): void
    {
        $this->assertFalse(GiftCode::factory()->make()->isExpired());
        $this->assertTrue(GiftCode::factory()->expired()->make()->isExpired());
        $this->assertFalse(GiftCode::factory()->make(['expires_at' => now()->addMinute()])->isExpired());
        $this->assertTrue(GiftCode::factory()->disabled()->make()->isDisabled());
        $this->assertFalse(GiftCode::factory()->make()->isDisabled());
    }

    // ------------------------------------------------------------ D4

    #[Test]
    public function only_pending_paid_and_refunded_orders_hold_a_redemption(): void
    {
        $code = GiftCode::factory()->create();

        $statuses = [
            OrderStatus::Pending->value => Order::factory()->create(),
            OrderStatus::Paid->value => Order::factory()->paid()->create(),
            OrderStatus::Refunded->value => Order::factory()->refunded()->create(),
            OrderStatus::Failed->value => Order::factory()->failed()->create(),
            OrderStatus::Expired->value => Order::factory()->create(['status' => OrderStatus::Expired]),
        ];

        foreach ($statuses as $order) {
            GiftCodeRedemption::factory()->for($code)->forOrder($order)->create();
        }

        $loaded = GiftCode::query()->withActiveRedemptionCount()->findOrFail($code->id);

        $this->assertSame(3, $loaded->active_redemptions_count);
        $this->assertSame(3, GiftCodeRedemption::query()->active()->count());
    }

    #[Test]
    public function every_order_status_is_classified_and_new_ones_hold_by_default(): void
    {
        // releasingStatuses() bir "iade eden" listesi; geri kalan her durum tutar.
        $releasing = GiftCodeRedemption::releasingStatuses();

        $this->assertEqualsCanonicalizing([OrderStatus::Failed, OrderStatus::Expired], $releasing);
    }

    // ------------------------------------------------------------ E2 — kısıtlar

    #[Test]
    public function the_database_rejects_a_gift_order_that_costs_money(): void
    {
        $this->expectException(QueryException::class);

        Order::factory()->paid()->create(['provider' => Order::GIFT_PROVIDER, 'amount_minor' => 100]);
    }

    #[Test]
    public function the_database_rejects_a_free_order_that_is_not_a_gift(): void
    {
        $this->expectException(QueryException::class);

        Order::factory()->create(['provider' => 'fake', 'amount_minor' => 0]);
    }

    #[Test]
    public function the_database_accepts_a_paid_zero_amount_gift_order(): void
    {
        $order = Order::factory()->create([
            'provider' => Order::GIFT_PROVIDER,
            'amount_minor' => 0,
            'discount_minor' => 14900,
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'expires_at' => null,
        ]);

        $this->assertTrue($order->refresh()->isGift());
        $this->assertSame(14900, $order->discount_minor);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidCodes(): array
    {
        return [
            'free ama plansız' => [['benefit' => GiftCodeBenefit::Free->value, 'tier' => null, 'percent_off' => null]],
            'percent ama yüzde yok' => [['percent_off' => null]],
            'percent 0' => [['percent_off' => 0]],
            'percent 101' => [['percent_off' => 101]],
            'percent kodunda tutar da var' => [['amount_off_minor' => 100]],
            'fixed ama tutar yok' => [['benefit' => GiftCodeBenefit::Fixed->value, 'percent_off' => null]],
            'fixed tutar 0' => [['benefit' => GiftCodeBenefit::Fixed->value, 'percent_off' => null, 'amount_off_minor' => 0]],
            'bilinmeyen fayda' => [['benefit' => 'bogo']],
            'bilinmeyen plan' => [['tier' => 'platin']],
            'max 0' => [['max_redemptions' => 0]],
        ];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[DataProvider('invalidCodes')]
    public function the_database_rejects_inconsistent_codes(array $override): void
    {
        $row = array_merge([
            'id' => (string) str()->ulid(),
            'code_hash' => str_repeat('a', 64),
            'code_hint' => 'AAAA',
            'benefit' => GiftCodeBenefit::Percent->value,
            'tier' => null,
            'percent_off' => 10,
            'amount_off_minor' => null,
            'max_redemptions' => 1,
        ], $override);

        $this->expectException(QueryException::class);

        DB::table('gift_codes')->insert($row);
    }

    #[Test]
    public function an_order_carries_at_most_one_redemption(): void
    {
        $order = Order::factory()->create();
        GiftCodeRedemption::factory()->forOrder($order)->create();

        $this->expectException(QueryException::class);

        GiftCodeRedemption::factory()->forOrder($order)->create();
    }
}
