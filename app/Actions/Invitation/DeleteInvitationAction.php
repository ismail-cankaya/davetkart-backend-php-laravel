<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Davetiyeyi siler ve odenmis TEKIL siparisin akibetine karar verir.
 *
 * 🔴 Bu sinif Faz 6'dan Faz 8'e, Faz 8'den Faz 9'a ertelendi ve bu DOGRUYDU:
 * o gunlerin hicbirinde silmenin bir IS KURALI yoktu. `$invitation->delete()`
 * controller'da tek satirdi ve onu bir Action'a sarmak toren olurdu (K15).
 * Bugun bir kural dogdu — uc gunluk geri alma penceresi — ve sinif onunla
 * birlikte geliyor. Bir Action, sardigi kadar degil TASIDIGI kural kadar
 * degerlidir.
 *
 * KURAL:
 *   published_at IS NULL              -> hak hic harcanmadi  -> SERBEST
 *   published_at + N gun  gelecekte   -> pencere acik         -> SERBEST
 *   published_at + N gun  gecmis      -> pencere kapali       -> hak YANAR
 *
 * "Serbest" demek: `orders.invitation_id = NULL`. Kapsam ('invitation')
 * DEGISMEZ — yani siparis pakete DONUSMEZ ve hicbir davetiyeye hak vermez;
 * sahibi onu yeni bir davetiyeye baglayana kadar bekler (A2.7).
 * Ayrintili aciklama: docs/rehber/app/Actions/Invitation/DeleteInvitationAction.md
 */
final class DeleteInvitationAction
{
    /**
     * @throws ModelNotFoundException Davetiye kilit aninda silinmis -> 404
     */
    public function handle(Invitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            // 🔴 Satir kilitlenip YENIDEN OKUNUYOR — PublishInvitationAction
            // ile birebir ayni desen (E9). Kilit olmasaydi es zamanli bir
            // "yayinla" istegi ile bu silme birbirini gormezdi: yayin
            // published_at'i yazarken silme onu NULL diye okuyup hakki serbest
            // birakabilirdi. Iki akisin ortak kilitlenebilir nesnesi UST
            // KAYITTIR ve o da davetiyenin kendisi.
            $fresh = Invitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->releaseWindowIsOpen($fresh)) {
                // Kapsam DEGISMIYOR, yalnizca bag kopuyor. `scope` kolonu
                // 'invitation' kaldigi icin bu satir OrderEntitlementResolver'in
                // paket koluna DUSMEZ — Faz 9'un kapattigi delik tam buydu.
                //
                // releasable(): paket siparisleri disarida kalir (zaten bagli
                // degiller). Kural enum'da: OrderScope::isReleasable().
                $fresh->orders()->releasable()->update(['invitation_id' => null]);
            }

            // Soft delete. `deleted` olayi -> InvitationChanged ->
            // ClearInvitationCache (K48): bu Action cache'i temizlemeyi
            // HATIRLAMAK zorunda degil, olay modelden yapisal olarak firiyor.
            //
            // 🔴 Sira onemli: siparisler ONCE serbest birakiliyor. Ters sirada
            // da calisirdi (soft delete FK'yi tetiklemez) ama ayni transaction
            // icinde okunan bir kayit uzerinde calismak, yarin kalici silmeye
            // gecilirse sirayi zaten dogru birakir.
            $fresh->delete();
        });
    }

    /**
     * Odenen hak geri alinabilir mi?
     *
     * 🔴 Bu bir SURE hesabi, bir TAKVIM hesabi degil — ve fark onemli.
     * K71'de LCV son tarihi davetiyenin saat dilimine tasinmisti, cunku
     * "15 Agustos" bir yerin takvim gunudur. Burada ise "yayindan 3 gun
     * sonrasi" bir ANDAN itibaren gecen SUREDIR; saat dilimi hic girmez ve
     * girmemeli. Ders 58'in tersi: o testte saat dilimi UNUTULMUSTU, burada
     * eklenmesi hata olurdu.
     */
    private function releaseWindowIsOpen(Invitation $invitation): bool
    {
        // Hic yayinlanmadiysa hak zaten harcanmadi: kullanici odedi ama
        // davetiye hic ortaya cikmadi. Pencere sorusu bile sorulmaz.
        if ($invitation->published_at === null) {
            return true;
        }

        return $invitation->published_at
            ->addDays(Config::integer('davetkart.orders.release_window_days'))
            ->isFuture();
    }
}
