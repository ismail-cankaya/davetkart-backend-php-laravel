<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Enums\MediaKind;
use App\Models\Invitation;
use App\Models\Rsvp;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Misafirin LCV'sine iliştirdiği medya kimliğini doğrular; geçersizse null döner.
 *
 * Faz 10 (10.59): SubmitRsvpAction'ın içinden çıkarıldı; güncelleme
 * (UpdateRsvpAction) aynı kuralı kullanıyor ve kural iki yerde duramaz (C3).
 *
 * Uc kosul birden aranir ve ucu de sorgunun KAPSAMINDA (P3 ailesi):
 *   1. Medya BU davetiyeye ait mi   -> $invitation->media() iliskisi
 *   2. Beklenen TURDE mi            -> where('kind', ...)
 *   3. Baska bir yanita bagli DEGIL mi (Faz 10, 10.64) -> whereNotExists(rsvps)
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
    /**
     * @param  Rsvp|null  $owner  Güncellenen yanıt: ona zaten bağlı medya serbest.
     */
    public function handle(Invitation $invitation, ?string $mediaId, MediaKind $kind, ?Rsvp $owner = null): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        $belongs = $invitation->media()
            ->whereKey($mediaId)
            ->where('kind', $kind)
            // Faz 10 (10.64): başka bir yanıta bağlı medya kimsenin değil.
            // Kimliği bilen bir misafir başkasının fotoğrafını kendi yanıtına
            // iliştiremesin.
            ->whereNotExists(function (QueryBuilder $query) use ($owner): void {
                $query->selectRaw('1')
                    ->from('rsvps')
                    ->where(function (QueryBuilder $linked): void {
                        $linked->whereColumn('rsvps.photo_media_id', 'media.id')
                            ->orWhereColumn('rsvps.video_media_id', 'media.id');
                    })
                    ->when($owner !== null, fn (QueryBuilder $other) => $other->where('rsvps.id', '!=', $owner?->getKey()));
            })
            ->exists();

        return $belongs ? $mediaId : null;
    }
}
