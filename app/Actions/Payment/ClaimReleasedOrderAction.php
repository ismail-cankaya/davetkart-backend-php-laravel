<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use App\Models\Order;

/**
 * Serbest birakilmis bir TEKIL siparisi yeni bir davetiyeye baglar.
 *
 * 🔴 A2.6 (DeleteInvitationAction) hakki serbest birakiyor ama serbest bir
 * hak KIMSENIN isine yaramaz: OrderEntitlementResolver onu bilerek
 * eslesmeyen birakiyor (scope='invitation' + invitation_id=NULL). Halkayi
 * kapatan sey, hakki YENIDEN BAGLAYAN bu adimdir.
 *
 * Neden PublishInvitationAction'in icine yazilmadi? Cunku o Action, K42'nin
 * geregi olarak hak kaynaklarinin VARLIGINI bile bilmiyor — yalnizca
 * "sahip olunan en yuksek plan nedir" diye soruyor. Icine `Order::query()`
 * yazsaydik, arayuzun Faz 7'de kurdugu soyutlama ilk degisiklikte delinirdi.
 * Ayrintili aciklama: docs/rehber/app/Actions/Payment/ClaimReleasedOrderAction.md
 */
final class ClaimReleasedOrderAction
{
    /**
     * @param  SubscriptionTier  $required  Davetiyenin acik modullerinin gerektirdigi plan
     * @return bool Bir siparis baglandiysa true
     */
    public function handle(Invitation $invitation, SubscriptionTier $required): bool
    {
        $candidate = $this->cheapestCovering(
            $this->releasedOrdersOf($invitation),
            $required,
        );

        if ($candidate === null) {
            return false;
        }

        // #[Fillable] bos: alan acikca atanir (E7). scope'a DOKUNULMUYOR —
        // zaten 'invitation'di ve oyle kalmali.
        $candidate->invitation_id = $invitation->getKey();
        $candidate->save();

        return true;
    }

    /**
     * Bu kullanicinin serbest, odenmis tekil siparisleri.
     *
     * 🔴 lockForUpdate ZORUNLU. Ayni kullanici iki farkli davetiyeyi es
     * zamanli yayinlarsa, kilitsiz iki istek AYNI serbest siparisi gorur ve
     * ikisi de baglar: ikinci `save()` birincinin uzerine yazar, bir odeme
     * IKI yayin acar. Faz 7'nin M8'i bunun webhook'taki ikiziydi — UNIQUE
     * kisit "iki satir olamaz" der, "bir satir iki kez ilerleyemez" demez.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Order>
     */
    private function releasedOrdersOf(Invitation $invitation): \Illuminate\Database\Eloquent\Collection
    {
        return Order::query()
            ->grantingPublishRight()   // yalnizca odenmis (OrderStatus)
            ->releasable()             // yalnizca tekil kapsam (OrderScope)
            ->whereNull('invitation_id')
            ->where('user_id', $invitation->user_id)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Gereksinimi karsilayan EN UCUZ siparis.
     *
     * 🔴 "En yuksek" degil "yeten en dusuk". Kullanicinin elinde hem Standart
     * hem Elit serbest siparis varsa ve davetiye yalnizca Standart
     * gerektiriyorsa, Elit'i harcamak kullanicinin 300 lirasini yakardi.
     * Resolver'in "en yuksegi kazanir" kurali OKUMA icindir (ne kadar hakkim
     * var?); burada bir TUKETIM karari veriliyor ve tuketimde dogru refleks
     * terstir.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Order>  $orders
     */
    private function cheapestCovering(
        \Illuminate\Database\Eloquent\Collection $orders,
        SubscriptionTier $required,
    ): ?Order {
        $best = null;

        foreach ($orders as $order) {
            if (! $order->tier->covers($required)) {
                continue;
            }

            if ($best === null || $order->tier->rank() < $best->tier->rank()) {
                $best = $order;
            }
        }

        return $best;
    }
}
