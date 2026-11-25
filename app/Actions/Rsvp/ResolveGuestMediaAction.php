<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Enums\MediaKind;
use App\Models\Invitation;
use App\Models\Rsvp;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;

/**
 * Misafirin LCV'sine iliştirdiği medya kimliğini doğrular (bu davetiye, bu tür, başka yanıta bağlı değil, bütçe); geçersizse null.
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/ResolveGuestMediaAction.md
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

        return $belongs && $this->fitsAttachedBudget($invitation, $mediaId, $kind, $owner) ? $mediaId : null;
    }

    /**
     * A-E4 (K190): bekleyen dosya iliştirilirken iliştirilmiş bütçe yeniden sınanır; aşarsa iliştirilmez.
     * Çağıran davetiye satırını kilitlemiş olmalı (yükleme ucuyla aynı sıra).
     */
    private function fitsAttachedBudget(Invitation $invitation, string $mediaId, MediaKind $kind, ?Rsvp $owner): bool
    {
        // Güncellemede bu yanıtın aynı türdeki eski dosyası yerini bırakıyor: sayımdan çıkar.
        $replaced = $owner?->getOriginal($kind === MediaKind::RsvpPhoto ? 'photo_media_id' : 'video_media_id');

        $attached = fn () => $invitation->media()
            ->whereIn('kind', MediaKind::guestUploadableValues())
            ->attachedToRsvp()
            ->when(is_string($replaced), fn ($query) => $query->whereKeyNot($replaced));

        $incomingBytes = (int) $invitation->media()->whereKey($mediaId)->value('size_bytes');

        return $attached()->where('kind', $kind)->count() < $kind->maxPerInvitation()
            && (int) $attached()->sum('size_bytes') + $incomingBytes
                <= Config::integer('davetkart.media.guest_budget.attached_bytes_per_invitation');
    }
}
