<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Enums\MediaKind;
use App\Models\Invitation;

/**
 * Misafirin LCV'sine iliştirdiği medya kimliğini doğrular; geçersizse null döner.
 *
 * Faz 10 (10.59): SubmitRsvpAction'ın içinden çıkarıldı; güncelleme
 * (UpdateRsvpAction) aynı kuralı kullanıyor ve kural iki yerde duramaz (C3).
 *
 * Iki kosul birden aranir ve ikisi de sorgunun KAPSAMINDA (P3 ailesi):
 *   1. Medya BU davetiyeye ait mi   -> $invitation->media() iliskisi
 *   2. Beklenen TURDE mi            -> where('kind', ...)
 *
 * 🔴 Ikincisi olmasaydi misafir kendi yukledigi rsvp_video kimligini
 * photoMediaId olarak gonderebilir, ya da (davetiyeye ait oldugu icin)
 * SAHIBIN GALERI fotografini kendi yanitina ilistirebilirdi.
 *
 * 🔴 Gecersiz kimlik EXCEPTION FIRLATMAZ, sessizce null olur. Gerekce
 * bir kolaylik degil bir savunma: 403/422 donmek, saldirgana "bu kimlik
 * gecerliydi ama senin degil" ile "bu kimlik hic yok" arasindaki farki
 * ogretirdi — yani media tablosu ULID uzayindan taranabilir hale gelirdi
 * (docs/08 §3.2'nin ayni gerekcesi). Misafir kendi gonderdigi kimligi
 * zaten biliyor; yanitta fotografin gorunmemesi ona yeterli sinyal.
 *
 * Dogrulama kurali olarak yazilamazdi: FormRequest davetiyeyi henuz
 * cozmemistir, dolayisiyla "hangi davetiye" sorusunun cevabi orada YOK.
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md
 */
final class ResolveGuestMediaAction
{
    public function handle(Invitation $invitation, ?string $mediaId, MediaKind $kind): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        $belongs = $invitation->media()
            ->whereKey($mediaId)
            ->where('kind', $kind)
            ->exists();

        return $belongs ? $mediaId : null;
    }
}
