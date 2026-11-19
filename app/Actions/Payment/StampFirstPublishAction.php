<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Models\Invitation;

/**
 * Yayınlanmış davetiyeye hizmet eden siparişlere "yayınladı" damgası basar (Faz 18.4, SEC-02 · K164, K165).
 *
 * 🔴 Damga YALNIZCA boşsa basılır ve hiçbir yol onu silmez: "ilk yayın" bir olaydır,
 * bağ (invitation_id) kopsa da gerçekleşmiş kalır. İade kuralı bu damgaya bakar.
 *
 * Çağıranlar: PublishInvitationAction (yayın anı), DeleteInvitationAction (hak
 * ayrılmadan önce; yayından sonra ödenen yükseltme için). İkisi de davetiye
 * satırını transaction içinde kilitlemiş olmalı.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Payment/StampFirstPublishAction.md
 */
final class StampFirstPublishAction
{
    /**
     * @return int Damgalanan sipariş sayısı; davetiye hiç yayınlanmadıysa 0
     */
    public function handle(Invitation $invitation): int
    {
        $publishedAt = $invitation->published_at;

        if ($publishedAt === null) {
            return 0;
        }

        // Davetiyeye bağlı, parası alınmış HER sipariş yayına hizmet eder
        // (consumed_at damgasıyla aynı küme, K137). Ödenmemiş olan etmez.
        $orders = $invitation->orders()
            ->grantingPublishRight()
            ->neverPublished()
            ->get();

        foreach ($orders as $order) {
            // Yayından sonra ödenen sipariş için hizmet ödemeyle başladı.
            $paidAt = $order->paid_at;

            // #[Fillable] boş: alan açıkça atanır (E7).
            $order->first_published_at = $paidAt !== null && $paidAt->isAfter($publishedAt)
                ? $paidAt
                : $publishedAt;
            $order->save();
        }

        return $orders->count();
    }
}
