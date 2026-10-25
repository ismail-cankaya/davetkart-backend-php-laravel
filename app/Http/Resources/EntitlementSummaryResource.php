<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Account\EntitlementRight;
use App\Actions\Account\EntitlementSummary;
use App\Actions\Account\TierEntitlements;
use App\Enums\EntitlementState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * "Planlarım" ekranının sözleşmesi (Faz 14, 14.4 · K140) — types.ts → AccountEntitlements.
 *
 * 🔴 Beyaz liste (C1). Siparişten GİTMEYENLER: provider / provider_ref /
 * provider_payment_id (sağlayıcı iç kimliği), user_id, scope, indirim dökümü
 * (siparişlerim ekranı OrderResource'tan okur). Davetiyeden yalnızca kimlik,
 * başlık ve iki tarih: hak listesi bir davetiye düzenleyicisi değil.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Resources/EntitlementSummaryResource.md
 *
 * @property EntitlementSummary $resource
 */
final class EntitlementSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'tiers' => array_map($this->tier(...), $summary->tiers),
            'totalAvailable' => $summary->totalAvailable(),
            'rights' => array_map($this->right(...), $summary->rights),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tier(TierEntitlements $tier): array
    {
        return [
            'tier' => $tier->tier->value,
            'available' => $tier->count(EntitlementState::Available),
            'inUse' => $tier->count(EntitlementState::InUse),
            'refundRequested' => $tier->count(EntitlementState::RefundRequested),
            'pending' => $tier->count(EntitlementState::Pending),
            'consumed' => $tier->count(EntitlementState::Consumed),
            'refunded' => $tier->count(EntitlementState::Refunded),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function right(EntitlementRight $right): array
    {
        $order = $right->order;
        $invitation = $order->invitation;
        $request = $order->openRefundRequest;

        return [
            'orderId' => (string) $order->id,
            'tier' => $order->tier->value,
            'state' => $right->state->value,
            'createdAt' => $order->created_at?->toIso8601String(),
            'paidAt' => $order->paid_at?->toIso8601String(),
            'totalMinor' => $order->amount_minor,
            'currency' => $order->currency,

            // Bağsız hakta null. Yanmış hakta davetiye çöpte (deleted) ya da
            // kalıcı silinmiş (null) olabilir.
            'invitation' => $invitation === null ? null : [
                'id' => $invitation->id,
                'title' => $invitation->title,
                'publishedAt' => $invitation->published_at?->toIso8601String(),
                'releasableUntil' => $invitation->releaseWindowEndsAt()?->toIso8601String(),
                'deleted' => $invitation->trashed(),
            ],

            'refund' => [
                'eligible' => $right->isRefundable(),
                'blocker' => $right->refundBlocker?->value,
                'windowEndsAt' => $right->refundWindowEndsAt?->toIso8601String(),
                'request' => $request === null ? null : [
                    'id' => $request->id,
                    'status' => $request->status->value,
                    'createdAt' => $request->created_at?->toIso8601String(),
                ],
            ],
        ];
    }
}
