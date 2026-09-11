<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\PublishEntitlementResolver;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Yayin hakkini `orders` tablosundan okur — K42'nin iki kolunu TEK sorguda
 * birlestirir.
 *
 * Sorgu uc kosulu birden tasir ve ucu de SORGUNUN KAPSAMINDA (P3 ailesi):
 *   1. Siparis odenmis mi          -> grantingPublishRight() kapsami
 *   2. Siparis BU KULLANICININ mi  -> where('user_id', ...)
 *   3. Bu davetiyeyi kapsiyor mu   -> (scope='account' OR invitation_id = :id)
 *
 * Ikinci kosul bir tekrar gibi gorunur (tekil siparis zaten davetiyeye bagli)
 * ama degil: PAKET siparisin davetiyeyle hicbir baglantisi yoktur — sahipligi
 * kuran TEK sey user_id'dir. Kaldirilsaydi baskasinin paketi bu davetiyeyi
 * acardi (IDOR'un odeme katmanindaki hali).
 * Ayrintili aciklama: docs/rehber/app/Contracts/PublishEntitlementResolver.md §4
 */
final class OrderEntitlementResolver implements PublishEntitlementResolver
{
    public function highestTierFor(Invitation $invitation): ?SubscriptionTier
    {
        $orders = Order::query()
            ->grantingPublishRight()
            ->where('user_id', $invitation->user_id)
            ->where(function (Builder $query) use ($invitation): void {
                // 🔴 Ic ice closure ZORUNLU: parantezsiz yazilsaydi SQL
                //   ... AND user_id = ? AND scope = ? OR invitation_id = ?
                // olurdu ve OR'un onceligi yuzunden SON kol tek basina
                // eslesirdi — yani BASKA birinin odenmis siparisi bu
                // davetiyeyi acardi. Operator onceligi burada bir GUVENLIK
                // meselesidir.
                //
                // 🔴 whereNull('invitation_id') DEGIL, kapsam suzgeci. Eski
                // hali "davetiyesi olmayan siparis = paket" diyordu; silinen
                // bir davetiyenin tekil siparisi de davetiyesiz kaliyor ve
                // ayni kola dusuyordu. Fark su satirda gorunur:
                //
                //   scope='invitation' + invitation_id=NULL  (serbest birakilmis)
                //     eski sorgu -> ESLESIR  (hesap geneline bedava yayin)
                //     yeni sorgu -> eslesmez (dogru: once baglanmali)
                $query->grantingAcrossAccount()
                    ->orWhere('invitation_id', $invitation->getKey());
            })
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
