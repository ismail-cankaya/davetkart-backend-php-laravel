<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\PublishEntitlementResolver;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;

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
        $orders = Order::query()
            ->grantingPublishRight()
            ->where('user_id', $invitation->user_id)
            ->where('invitation_id', $invitation->getKey())
            ->get();

        $highest = null;

        foreach ($orders as $order) {
            if ($highest === null || $order->tier->rank() > $highest->rank()) {
                $highest = $order->tier;
            }
        }

        return $highest;
    }
}
