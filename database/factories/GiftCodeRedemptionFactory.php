<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test için sahte kullanım satırı.
 *
 * Varsayılan siparişin durumu `pending`: kullanım AKTİFTİR (D4). İade olmuş bir
 * kullanım için siparişi testin kendisi `failed`/`expired` yapar.
 * Ayrıntılı açıklama: docs/rehber/database/factories/GiftCodeFactory.md §3
 *
 * @extends Factory<GiftCodeRedemption>
 */
class GiftCodeRedemptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gift_code_id' => GiftCode::factory(),
            'order_id' => Order::factory(),
            // Sahiplik siparişten taşınır: üretimde kullanım ile sipariş aynı kişinindir.
            'user_id' => static fn (array $attributes): mixed => Order::query()
                ->whereKey($attributes['order_id'])
                ->value('user_id'),
            'discount_minor' => 1000,
        ];
    }

    /** Var olan bir siparişe bağlar; kullanıcı da o siparişten gelir. */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes): array => [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
        ]);
    }
}
