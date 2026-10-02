<?php

declare(strict_types=1);

namespace App\Actions\Rsvp;

use App\Contracts\RsvpQuotaResolver;
use App\Enums\RsvpStatus;
use App\Exceptions\RsvpQuotaExceededException;
use App\Models\Invitation;
use App\Models\Rsvp;

/**
 * Gelen kişi sayısı davetiyenin LCV kotasına sığıyor mu? Sığmıyorsa 403.
 *
 * Faz 10 (10.59): SubmitRsvpAction'ın içinden çıkarıldı. Güncellemede
 * (UpdateRsvpAction) değişen yanıtın ESKİ kişi sayısı sayıma katılmaz;
 * katılsaydı misafir aynı kişileri iki kez saydırırdı.
 *
 * 🔴 Çağıran bir TRANSACTION içinde olmalı: kilit ve ardından gelen yazma
 * aynı transaction'da değilse kilit bir işe yaramaz.
 *
 * 🔴 Kota COUNT(*) ile DEGIL SUM(guest_count) ile olculur.
 *
 * COUNT(*) olsaydi 100 kayit x 4 kisi = 400 misafir kotayi asmadan gecerdi
 * (docs/09 §Faz 5). Frontend'in LiveRsvpPanel'i de ayni metrigi kullaniyor;
 * iki taraf ayni seyi saymak ZORUNDA.
 *
 * Hangi durumlarin sayildigini enum soyler (K50), bu sorgu degil.
 * Ayrintili aciklama: docs/rehber/app/Actions/Rsvp/SubmitRsvpAction.md
 */
final class EnsureRsvpQuotaAction
{
    public function __construct(
        private readonly RsvpQuotaResolver $quota,
    ) {}

    /**
     * @throws RsvpQuotaExceededException
     */
    public function handle(Invitation $invitation, int $incomingGuests, ?Rsvp $replacing = null): void
    {
        $limit = $this->quota->limitFor($invitation);

        // Sinirsiz plan: SORGU BILE ACILMAZ.
        if ($limit === null) {
            return;
        }

        // Ust kaydin satirini kilitle. Es zamanli iki gonderim ayni SUM'i okuyup
        // ikisi de "yer var" diyebilirdi (check-then-act yaris kosulu, Faz 2 E2).
        // PostgreSQL'in varsayilan READ COMMITTED seviyesinde SELECT'ler birbirini
        // beklemez; bu kilit onlari siraya sokar.
        Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

        $used = (int) $invitation->rsvps()
            ->whereIn('status', RsvpStatus::quotaConsumingValues())
            ->when($replacing !== null, fn ($query) => $query->whereKeyNot($replacing?->getKey()))
            ->sum('guest_count');

        if ($used + $incomingGuests > $limit) {
            throw new RsvpQuotaExceededException;
        }
    }
}
