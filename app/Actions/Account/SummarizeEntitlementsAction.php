<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\EntitlementState;
use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\User;
use App\Services\Refund\RefundEligibility;
use Illuminate\Database\Eloquent\Collection;

/**
 * Kullanıcının plan haklarını kovalara ayırır: "Planlarım" (Faz 14, 14.3 · K140).
 *
 * 🔴 Yeni bir tablo ya da sayaç kolonu YOK. Hak zaten siparişte duruyor; bu
 * sınıf bir OKUMA MODELİ kurar. "2 Gold aldı, 1'ini iptal etti" sorusunun
 * cevabı: 2 sipariş, biri `paid` + bağsız (kullanılabilir), biri `refunded`.
 *
 * Sorgu sayısı SABİT (sipariş sayısından bağımsız): siparişler + davetiyeler
 * (çöptekiler dahil) + açık talepler. Kovaya ayırma PHP'de, çünkü kural
 * enum'larda ve RefundEligibility'de duruyor; SQL'de CASE ile yazılsaydı
 * kuralın ikinci bir kopyası olurdu.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/SummarizeEntitlementsAction.md
 */
final class SummarizeEntitlementsAction
{
    public function __construct(private readonly RefundEligibility $eligibility) {}

    public function handle(User $user): EntitlementSummary
    {
        $orders = $user->orders()
            // Eski 'account' kapsamı 2026_10_02 migration'ıyla çevrildi;
            // sayaç yalnızca bugünkü biçimi tanır.
            ->releasable()
            ->whereIn('status', array_column([OrderStatus::Paid, OrderStatus::Refunded, OrderStatus::Pending], 'value'))
            ->with('openRefundRequest')
            ->latest()
            ->orderByDesc('id')
            ->get();

        $this->loadInvitationsIncludingTrashed($orders);

        $rights = [];

        foreach ($orders as $order) {
            $state = $this->stateOf($order);

            if ($state === null) {
                continue;
            }

            $rights[] = new EntitlementRight(
                $order,
                $state,
                $this->eligibility->blockerFor($order),
                $this->eligibility->windowEndsAt($order),
            );
        }

        return new EntitlementSummary($this->countByTier($rights), $rights);
    }

    /**
     * 🔴 Davetiyeler ÇÖPTEKİLER DAHİL yüklenir: yanmış hakkın davetiyesi çöpte.
     * Soft delete kapsamı onu gizleseydi ilişki null döner ve yanmış hak
     * "kullanılabilir" görünürdü.
     *
     * Neden `with('invitation' => withTrashed)` değil? İlişki üzerinden
     * withTrashed çağrısı bir makro; tip denetimi (Larastan) onu göremiyor.
     * Elle yüklemek aynı tek sorguyu açıkça yazıyor.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function loadInvitationsIncludingTrashed(Collection $orders): void
    {
        $ids = $orders->pluck('invitation_id')->filter()->unique()->values()->all();

        $invitations = $ids === []
            ? collect()
            : Invitation::withTrashed()->whereKey($ids)->get()->keyBy('id');

        foreach ($orders as $order) {
            $order->setRelation('invitation', $order->invitation_id === null ? null : $invitations->get($order->invitation_id));
        }
    }

    /**
     * Bir siparişin kovası; null = sayaca girmez (süresi dolmuş bekleyen ödeme).
     *
     * Sıra önemli: açık talep, bağdan önce sorulur (talep yalnızca bağsız
     * siparişe açılabilir ama kural burada da sağlam kalsın); yanma, bağdan önce
     * (bağ kopmuş olabilir, K137).
     */
    private function stateOf(Order $order): ?EntitlementState
    {
        return match ($order->status) {
            OrderStatus::Refunded => EntitlementState::Refunded,

            // orders:expire henüz geçmediyse satır hâlâ 'pending'; penceresi
            // dolmuş ödeme kullanıcıya "onay bekliyor" diye gösterilmez.
            OrderStatus::Pending => $order->isExpired() ? null : EntitlementState::Pending,

            OrderStatus::Paid => match (true) {
                $order->openRefundRequest !== null => EntitlementState::RefundRequested,
                $order->isConsumed() || $order->invitation?->trashed() === true => EntitlementState::Consumed,
                $order->invitation_id !== null => EntitlementState::InUse,
                default => EntitlementState::Available,
            },

            default => null,
        };
    }

    /**
     * @param  list<EntitlementRight>  $rights
     *
     * @return list<TierEntitlements>
     */
    private function countByTier(array $rights): array
    {
        $tiers = SubscriptionTier::cases();
        usort($tiers, static fn (SubscriptionTier $a, SubscriptionTier $b): int => $a->rank() <=> $b->rank());

        $result = [];

        foreach ($tiers as $tier) {
            $counts = array_fill_keys(array_column(EntitlementState::cases(), 'value'), 0);

            foreach ($rights as $right) {
                if ($right->order->tier === $tier) {
                    $counts[$right->state->value]++;
                }
            }

            $result[] = new TierEntitlements($tier, $counts);
        }

        return $result;
    }
}
