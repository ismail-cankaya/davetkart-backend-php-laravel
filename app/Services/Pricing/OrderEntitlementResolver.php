<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\PublishEntitlementResolver;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Yayin hakkini `orders` tablosundan okur: bu davetiyeye BAGLI odenmis
 * siparislerin en yuksek plani.
 *
 * Faz 10 (10.58 · K99): "hesabin tum davetiyelerini acan paket" kolu
 * kaldirildi. Paket de bagsiz bir tekil siparis olarak acilir ve ilk yayinda
 * bir davetiyeye baglanir (ClaimReleasedOrderAction).
 *
 * `user_id` kosulu bir savunma katmani: siparis zaten davetiyeye bagli, ama
 * bag yanlis kurulsa bile baskasinin siparisi bu davetiyeyi acmaz.
 * Ayrintili aciklama: docs/rehber/app/Contracts/PublishEntitlementResolver.md §4
 */
final class OrderEntitlementResolver implements PublishEntitlementResolver
{
    public function highestTierFor(Invitation $invitation): ?SubscriptionTier
    {
        return $this->highestTiersFor([$invitation])[$invitation->getKey()];
    }

    /**
     * Faz 10 (10.66b): tek davetiyelik soru da buradan geçer, kural tek yerde kalır.
     *
     * @param  iterable<Invitation>  $invitations
     *
     * @return array<string, SubscriptionTier|null>
     */
    public function highestTiersFor(iterable $invitations): array
    {
        $highest = [];
        $idsByOwner = [];

        foreach ($invitations as $invitation) {
            $highest[$invitation->getKey()] = null;
            $idsByOwner[$invitation->user_id][] = $invitation->getKey();
        }

        if ($highest === []) {
            return [];
        }

        // Sahip koşulu sorguda kalır (P3): her davetiye yalnızca KENDİ
        // sahibinin siparişiyle eşleşir. İç closure parantez koyar (§4.2).
        $orders = Order::query()
            ->grantingPublishRight()
            ->where(function (Builder $query) use ($idsByOwner): void {
                foreach ($idsByOwner as $userId => $invitationIds) {
                    $query->orWhere(function (Builder $query) use ($userId, $invitationIds): void {
                        $query->where('user_id', $userId)->whereIn('invitation_id', $invitationIds);
                    });
                }
            })
            ->get();

        foreach ($orders as $order) {
            $id = (string) $order->invitation_id;
            $current = $highest[$id];

            if ($current === null || $order->tier->rank() > $current->rank()) {
                $highest[$id] = $order->tier;
            }
        }

        return $highest;
    }
}
