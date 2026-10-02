<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Enums\MediaKind;
use App\Exceptions\RsvpDeadlinePassedException;
use App\Exceptions\RsvpQuotaExceededException;
use App\Models\Rsvp;
use App\Support\RsvpEditCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Misafirin kendi LCV'sini düzenleme koduyla günceller (Faz 10, 10.59 · K101).
 *
 * Aynı misafir formu ikinci kez gönderdiğinde yeni satır açılmaz, eskisi
 * değişir; kişi sayısı kotadan iki kez düşmez.
 *
 * Katmanlar SubmitRsvpAction'la aynı, honeypot hariç (kodu bilmeyen buraya
 * gelemez):
 *   1. Hedef açık mı    -> ResolveOpenRsvpInvitationAction (yayın + modül + son tarih)
 *   2. Kod doğru mu     -> yanlışsa 404; "yanıt yok" ile ayırt edilemez
 *   3. Medya aidiyeti   -> ResolveGuestMediaAction
 *   4. Kota             -> eski kişi sayısı hariç, kilitli transaction içinde
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Rsvp/UpdateRsvpAction.md
 */
final class UpdateRsvpAction
{
    public function __construct(
        private readonly ResolveOpenRsvpInvitationAction $resolveOpenInvitation,
        private readonly ResolveGuestMediaAction $guestMedia,
        private readonly EnsureRsvpQuotaAction $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  UpdateRsvpRequest::rsvpAttributes()
     * @param  array{photo: ?string, video: ?string}  $mediaIds  🔴 DOGRULANMAMIS
     *
     * @throws ModelNotFoundException Davetiye kapalı, yanıt yok ya da kod yanlış -> 404
     * @throws RsvpDeadlinePassedException Son tarih geçti -> 403
     * @throws RsvpQuotaExceededException Kota doldu -> 403
     */
    public function handle(
        string $invitationId,
        string $rsvpId,
        string $editCode,
        array $attributes,
        array $mediaIds = ['photo' => null, 'video' => null],
    ): RsvpSubmission {
        $invitation = $this->resolveOpenInvitation->handle($invitationId);

        // Yanıt BU davetiyenin mi ve kod doğru mu? İkisinin de yanıtı 404:
        // yanlış kodla "böyle bir yanıt var" bilgisi sızmasın (H7).
        $rsvp = $invitation->rsvps()->whereKey($rsvpId)->first();

        if (! $rsvp instanceof Rsvp || ! RsvpEditCode::matches($rsvp->edit_code_hash, $editCode)) {
            throw (new ModelNotFoundException)->setModel(Rsvp::class, [$rsvpId]);
        }

        $rsvp->fill($attributes);

        $rsvp->photo_media_id = $this->guestMedia->handle(
            $invitation, $mediaIds['photo'], MediaKind::RsvpPhoto, owner: $rsvp,
        );

        $rsvp->video_media_id = $this->guestMedia->handle(
            $invitation, $mediaIds['video'], MediaKind::RsvpVideo, owner: $rsvp,
        );

        DB::transaction(function () use ($invitation, $rsvp): void {
            $this->quota->handle($invitation, $rsvp->guest_count, replacing: $rsvp);

            $rsvp->save();
        });

        return new RsvpSubmission($rsvp, $editCode);
    }
}
