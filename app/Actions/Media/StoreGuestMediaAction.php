<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Actions\Rsvp\ResolveOpenRsvpInvitationAction;
use App\Enums\MediaKind;
use App\Exceptions\MediaQuotaExceededException;
use App\Models\Media;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * Misafirin LCV foto/videosunu kabul eder — sistemin IKINCI auth'suz yazma yolu.
 *
 * 🔴 Faz 5'te tek bir auth'suz yazma yolu vardi (LCV metni). Bu ikincisi ve
 * DAHA PAHALI: satir degil DOSYA yaziyor. Bir LCV kaydi birkac yuz bayt; bir
 * video 20 MB. Ayni tehdit modeli, on kat maliyet.
 *
 * Katmanlar (L1 — en ucuzdan pahaliya):
 *   0. Hiz siniri     -> rota katmani (6.16), buraya hic gelmez
 *   1. Bicim/boyut    -> StorePublicMediaRequest (mimetypes = ICERIKTEN)
 *   2. Tur izni       -> BU DOSYA (asagida) — misafir galeriye yukleyemez
 *   3. Hedef acik mi  -> ResolveOpenRsvpInvitationAction (yayin + modul + son tarih)
 *   4. Kota + saklama -> StoreUploadedMediaAction (kilitli transaction, E9)
 *
 * 🔴 Honeypot YOK ve olamaz: honeypot GORUNMEZ BIR ALANIN doldurulmasina
 * bakar; burada gonderilen sey bir form degil bir DOSYA. Faz 5'in en ucuz
 * katmani burada yok, yani hiz siniri daha cok is yapmak zorunda (B6).
 *
 * Bu Action iki Action'i BIRLESTIRIR (composition). Controller'a iki cagri
 * yazilabilirdi; yazilmadi cunku "misafir medya yukler" TEK BIR EYLEMDIR
 * (CLAUDE.md §1) ve is akisi HTTP katmaninda kurgulanmamalidir.
 * Ayrintili aciklama: docs/rehber/app/Actions/Media/StoreGuestMediaAction.md
 */
final class StoreGuestMediaAction
{
    public function __construct(
        private readonly ResolveOpenRsvpInvitationAction $resolveOpenInvitation,
        private readonly StoreUploadedMediaAction $storeMedia,
    ) {}

    /**
     * @param  string  $invitationId  Paylasilan linkteki ULID (K40)
     *
     * @throws ModelNotFoundException Yok / yayinda degil / LCV kapali -> 404
     * @throws \App\Exceptions\RsvpDeadlinePassedException Son tarih gecti -> 403
     * @throws MediaQuotaExceededException Kota doldu -> 403 (params BOS, H9)
     * @throws LogicException Misafirin yukleyemeyecegi bir tur geldi -> 500
     */
    public function handle(string $invitationId, MediaKind $kind, UploadedFile $file): Media
    {
        // 2. KATMAN — 🔴 TUR IZNI. StorePublicMediaRequest bunu zaten 'in:'
        // kuraliyla eliyor; burada IKINCI KEZ soruluyor ve bu bir tekrar degil
        // bir DEGISMEZ (invariant).
        //
        // Sebep: dogrulama HTTP katmanina aittir ve o katman atlanabilir —
        // bir konsol komutu, bir kuyruk isi ya da yeni bir uc bu Action'i
        // FormRequest'siz cagirabilir. O gun MediaKind::Gallery gecirilse
        // misafir davetiyenin GALERISINE yazardi.
        //
        // "Sinifin sekliyle koru" (A2) burada uygulanamiyor cunku MediaKind
        // tek bir enum; o yuzden koruma sinifin GIRISINDE duruyor.
        if (! $kind->isGuestUploadable()) {
            // LogicException, RuntimeException degil: buraya ulasmak bir
            // KOD hatasidir, bir kullanici hatasi degil. 500 dogru cevap —
            // 422 deseydik var olmayan bir kullanici hatasini raporlardik.
            throw new LogicException(
                "Media kind [{$kind->value}] cannot be uploaded by a guest.",
            );
        }

        // 3. KATMAN — hedef acik mi? LCV metniyle BIREBIR AYNI uc kosul
        // (C3, 6.12). Son tarih burada kritik: olmasaydi suresi dolmus bir
        // davetiyeye misafir SINIRSIZ SURE dosya yuklerdi.
        $invitation = $this->resolveOpenInvitation->handle($invitationId);

        // 4. KATMAN — kota, rastgele ad, icerikten MIME, kuyruk.
        // 🔴 Kota asiminda forGuest() secilir ve `limit` DISARI CIKMAZ (H9):
        // karari StoreUploadedMediaAction, MediaKind::isGuestUploadable()'a
        // bakarak veriyor — yani bu Action'in ona ayrica soylemesi gerekmiyor.
        return $this->storeMedia->handle($invitation, $kind, $file);
    }
}
