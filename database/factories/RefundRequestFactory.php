<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefundRequestStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test için iade talebi. Varsayılan: ödenmiş, bağsız bir siparişte AÇIK talep.
 *
 * Sahiplik siparişten taşınır: başkasının siparişine açılmış bir talep üretim
 * kodunda oluşmaz, fabrika da onu üretmemeli (OrderFactory::forInvitation ile aynı ilke).
 *
 * @extends Factory<RefundRequest>
 */
class RefundRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->paid(),
            'user_id' => static fn (array $attributes): ?int => Order::query()
                ->whereKey($attributes['order_id'])
                ->value('user_id'),
            'status' => RefundRequestStatus::Requested,
            'reason' => null,
            'admin_note' => null,
            'resolved_at' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes): array => [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
        ]);
    }

    public function approved(): static
    {
        return $this->resolved(RefundRequestStatus::Approved);
    }

    public function rejected(): static
    {
        return $this->resolved(RefundRequestStatus::Rejected);
    }

    public function withdrawn(): static
    {
        return $this->resolved(RefundRequestStatus::Withdrawn);
    }

    /** Durum ve karar anı birlikte değişir (refund_requests_resolved_check). */
    private function resolved(RefundRequestStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'resolved_at' => now(),
        ]);
    }
}
