<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Models\Order;
use App\Services\Payment\PaymentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dogrulanmis bir odeme bildirimini siparise isler — IDEMPOTANSIN UYGULAMA YARISI.
 *
 * 🔴 Bu Action'in tamami tek bir soruya odaklidir: AYNI BILDIRIM IKI KEZ
 * GELIRSE NE OLUR? Odeme saglayicilari webhook'u tekrarlar (ag hatasi,
 * timeout, retry politikasi) — bu bir istisna degil, NORMAL ISLEYIStir.
 *
 * Idempotans iki katmanda kuruluyor ve ikisi FARKLI yarislari kapatir:
 *
 *   1. orders.provider_ref UNIQUE (7.2)  -> ayni odeme icin IKINCI SATIR olamaz
 *   2. lockForUpdate + canTransitionTo() -> bir satir IKI KEZ ILERLEYEMEZ
 *
 * Yalnizca birincisine guvenmek yeterli DEGILDIR: UNIQUE kisit var olan bir
 * satirin iki kez guncellenmesini engellemez (B6 — docs/rehber/app/Enums/
 * OrderStatus.md §4).
 *
 * 🔴 Bu Action IMZA DOGRULAMAZ. Elindeki PaymentNotification'in var olmasi
 * imzanin dogrulandiginin kanitidir — o nesneyi uretebilen tek yer
 * PaymentGateway::parseNotification()'dir.
 * Ayrintili aciklama: docs/rehber/app/Actions/Payment/HandlePaymentCallbackAction.md
 */
final class HandlePaymentCallbackAction
{
    /**
     * @return Order|null Islenen siparis; `null` = bu referansla siparis yok.
     */
    public function handle(PaymentNotification $notification): ?Order
    {
        return DB::transaction(function () use ($notification): ?Order {
            // 🔴 KILIT SORGUNUN KENDISINDE. Once okuyup sonra kilitlemek
            // arada bir bosluk birakirdi (check-then-act, E9). Es zamanli
            // ikinci webhook bu satirda BEKLER; kilidi aldiginda satiri
            // GUNCELLENMIS haliyle okur ve gecis kontrolune takilir.
            $order = Order::query()
                ->where('provider_ref', $notification->providerRef)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                // Bilinmeyen referans: bizim baslatmadigimiz bir odeme ya da
                // baska bir ortamin (staging) bildirimi. Sessizce yutulur —
                // imza gecerli oldugu icin gonderen mesru, ama yapacak bir
                // sey yok. Log tek izdir.
                Log::warning('Payment webhook for an unknown provider_ref', [
                    'provider_ref' => $notification->providerRef,
                ]);

                return null;
            }

            // 🔴 IDEMPOTANS. Ikinci 'paid' bildirimi burada eleniyor:
            // paid -> paid gecisi OrderStatus'te YASAK. Yan etki (paid_at
            // damgasi, ilerideki e-posta ve muhasebe kaydi) ikinci kez
            // uygulanmaz. `if (status === 'paid') return;` yazmadik cunku o
            // kural burada, calisma yerinde durur ve ikinci bir cagiranda
            // (iade ucu, admin paneli) yeniden yazilmasi gerekirdi (C3).
            if (! $order->status->canTransitionTo($notification->status)) {
                return $order;
            }

            $order->status = $notification->status;

            // orders_paid_at_check kisiti: parasi alinmis siparis damga
            // TASIMAK ZORUNDA. Damga bir kez yazilir — iade bildirimi
            // odemenin gerceklestigi ani DEGISTIRMEZ.
            if ($notification->status->hasBeenPaid() && $order->paid_at === null) {
                $order->paid_at = now();
            }

            $order->save();

            return $order;
        });
    }
}
