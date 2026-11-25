<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Enums\MediaKind;
use App\Exceptions\RsvpDeadlinePassedException;
use App\Exceptions\RsvpQuotaExceededException;
use App\Models\Invitation;
use App\Models\Rsvp;
use App\Support\IpHasher;
use App\Support\RsvpEditCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Misafirin LCV yanitini kabul eder (tek auth'suz yazma yolu); katmanlar ucuzdan pahaliya: honeypot, hedef, medya, kota, KVKK.
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md
 */
final class SubmitRsvpAction
{
    public function __construct(
        private readonly ResolveOpenRsvpInvitationAction $resolveOpenInvitation,
        private readonly ResolveGuestMediaAction $guestMedia,
        private readonly EnsureRsvpQuotaAction $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  StoreRsvpRequest::rsvpAttributes()
     * @param  string  $ip  Ham IP — SAKLANMAZ, yalnizca hash'lenir
     * @param  bool  $honeypotTripped  Gorunmez alan dolduruldu mu (5.4)
     * @param  array{photo: ?string, video: ?string}  $mediaIds  🔴 DOGRULANMAMIS
     *
     * @throws ModelNotFoundException Davetiye yok / yayinda degil / LCV kapali -> 404
     * @throws RsvpDeadlinePassedException Son tarih gecti -> 403
     * @throws RsvpQuotaExceededException Kota doldu -> 403
     */
    public function handle(
        string $invitationId,
        array $attributes,
        string $ip,
        bool $honeypotTripped,
        array $mediaIds = ['photo' => null, 'video' => null],
    ): RsvpSubmission {
        // 1. KATMAN — 🔴 En basta, cunku en ucuzu ve en cok isleyeni.
        // Bot ne bir sorgu actirir ne bir satir yazar; yine de basarili
        // gorunen bir yanit alir (5.4: sessizlik bir savunmadir).
        if ($honeypotTripped) {
            return $this->silentlyDiscard($attributes);
        }

        // 2. KATMAN — hedef acik mi? Uc kosul (yayinda + modul acik + son tarih
        // gecmemis) TEK yerde soruluyor; ayni uculu misafirin medya yukleme
        // ucunda da gecerli (C3).
        $invitation = $this->resolveOpenInvitation->handle($invitationId);

        $rsvp = $invitation->rsvps()->make($attributes);

        // 🔴 ip_hash #[Fillable] listesinde YOK: toplu atamayla degil, sunucu
        // kodu tarafindan atanir (E7'nin ayni gerekcesi).
        $rsvp->ip_hash = IpHasher::hash($ip);

        // Faz 10 (10.59): misafire bir kez gosterilen kod; satira yalnizca
        // ozeti yazilir.
        $editCode = RsvpEditCode::generate();
        $rsvp->edit_code_hash = RsvpEditCode::hash($editCode);

        // 4. KATMAN — kota. Kontrol ve yazma AYNI transaction icinde:
        // aralarinda baska bir istek araya girerse ikisi birden kotayi asardi.
        DB::transaction(function () use ($invitation, $rsvp, $mediaIds): void {
            // 🔴 MEDYA BAGLAMA (Faz 6): sahiplik ResolveGuestMediaAction'da. A-E4: davetiye kilidi
            // altinda, cunku iliştirme misafir bütçesini tüketir (yükleme ucuyla aynı sıra).
            Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            $rsvp->photo_media_id = $this->guestMedia->handle($invitation, $mediaIds['photo'], MediaKind::RsvpPhoto);
            $rsvp->video_media_id = $this->guestMedia->handle($invitation, $mediaIds['video'], MediaKind::RsvpVideo);

            $this->quota->handle($invitation, $rsvp->guest_count);

            $rsvp->save();
        });

        return new RsvpSubmission($rsvp, $editCode);
    }

    /**
     * Bot yanitini yutar ama KAYDETMEZ: gercek ULID ve kod tasir, kayittan ayirt edilemez (kanit testtir, T14).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function silentlyDiscard(array $attributes): RsvpSubmission
    {
        $rsvp = new Rsvp($attributes);

        $rsvp->id = $rsvp->newUniqueId();
        $rsvp->created_at = now();
        $rsvp->updated_at = now();

        return new RsvpSubmission($rsvp, RsvpEditCode::generate());
    }
}
