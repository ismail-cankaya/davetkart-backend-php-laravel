<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PromotionKind;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Faz 13 (13.2): `price_campaigns` ve `orders` indirim kısıtları.
 *
 * Her test "imkânsız" bir satırı veritabanına yazdırmaya çalışır; kuralı
 * uygulama kodu değil kısıt tutmalı (E2).
 * Ayrıntılı açıklama: docs/rehber/database/migrations/2026_10_25_100100_add_promotion_to_orders.md
 */
final class OrderPromotionConstraintsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_first_publish_discount_row_is_accepted(): void
    {
        $order = Order::factory()->create();

        $this->updateOrder($order, [
            'amount_minor' => 15960,
            'discount_minor' => 23940,
            'promotion' => PromotionKind::FirstPublish->value,
            'promotion_discount_minor' => 23940,
        ]);

        $order->refresh();
        $this->assertSame(PromotionKind::FirstPublish, $order->promotion);
        $this->assertSame(39900, $order->listPriceMinor());
    }

    #[Test]
    public function a_campaign_discount_row_needs_the_campaign_id(): void
    {
        $order = Order::factory()->create();
        $campaignId = $this->campaign();

        $this->updateOrder($order, [
            'discount_minor' => 1000,
            'promotion' => PromotionKind::Campaign->value,
            'promotion_discount_minor' => 1000,
            'price_campaign_id' => $campaignId,
        ]);

        $this->assertSame($campaignId, $order->refresh()->getAttribute('price_campaign_id'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function impossibleOrders(): array
    {
        return [
            'promotion bigger than the whole discount' => [[
                'discount_minor' => 100, 'promotion' => 'first_publish', 'promotion_discount_minor' => 200,
            ]],
            'amount without a source' => [[
                'discount_minor' => 100, 'promotion' => null, 'promotion_discount_minor' => 100,
            ]],
            'source without an amount' => [[
                'discount_minor' => 0, 'promotion' => 'first_publish', 'promotion_discount_minor' => 0,
            ]],
            'unknown source' => [[
                'discount_minor' => 100, 'promotion' => 'black_friday', 'promotion_discount_minor' => 100,
            ]],
            'campaign without the campaign id' => [[
                'discount_minor' => 100, 'promotion' => 'campaign', 'promotion_discount_minor' => 100,
            ]],
        ];
    }

    /** @param  array<string, mixed>  $columns */
    #[Test]
    #[DataProvider('impossibleOrders')]
    public function an_impossible_promotion_row_is_rejected_by_the_database(array $columns): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);

        $this->updateOrder($order, $columns);
    }

    #[Test]
    public function a_campaign_id_on_a_first_publish_row_is_rejected(): void
    {
        $order = Order::factory()->create();
        $campaignId = $this->campaign();

        $this->expectException(QueryException::class);

        $this->updateOrder($order, [
            'discount_minor' => 100,
            'promotion' => 'first_publish',
            'promotion_discount_minor' => 100,
            'price_campaign_id' => $campaignId,
        ]);
    }

    #[Test]
    public function a_used_campaign_cannot_be_deleted(): void
    {
        $order = Order::factory()->create();
        $campaignId = $this->campaign();

        $this->updateOrder($order, [
            'discount_minor' => 100,
            'promotion' => 'campaign',
            'promotion_discount_minor' => 100,
            'price_campaign_id' => $campaignId,
        ]);

        $this->expectException(QueryException::class);

        DB::table('price_campaigns')->where('id', $campaignId)->delete();
    }

    /** @return array<string, array{int}> */
    public static function badPercents(): array
    {
        return ['zero' => [0], 'free' => [100], 'over' => [91]];
    }

    #[Test]
    #[DataProvider('badPercents')]
    public function a_campaign_percent_outside_one_to_ninety_is_rejected(int $percent): void
    {
        $this->expectException(QueryException::class);

        $this->campaign($percent);
    }

    #[Test]
    public function a_campaign_cannot_end_before_it_starts(): void
    {
        $this->expectException(QueryException::class);

        DB::table('price_campaigns')->insert([
            'id' => strtolower((string) Str::ulid()),
            'percent' => 10,
            'starts_at' => now(),
            'ends_at' => now()->subDay(),
        ]);
    }

    /** @param  array<string, mixed>  $columns */
    private function updateOrder(Order $order, array $columns): void
    {
        // Model değil sorgu: #[Fillable] boş ve test KISITI sınıyor, modeli değil.
        DB::table('orders')->where('id', $order->getKey())->update($columns);
    }

    private function campaign(int $percent = 20): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('price_campaigns')->insert([
            'id' => $id,
            'percent' => $percent,
            'tier' => null,
            'starts_at' => now()->subHour(),
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
