<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Contracts\PublishEntitlementResolver;
use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Services\Pricing\TierResolver;

/**
 * Bir iadeden sonra: davetiyeyi kapsayan ödenmiş sipariş kalmadıysa onu
 * yayından kaldırır ve taslağa döndürür (Faz 10, 10.61 · K100).
 *
 * Başka bir ödenmiş sipariş davetiyenin gerektirdiği planı hâlâ karşılıyorsa
 * davetiye yayında kalır.
 *
 * 🔴 Çağıran bir transaction içinde olmalı: davetiye satırı kilitlenir.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Invitation/WithdrawUncoveredInvitationAction.md
 */
final class WithdrawUncoveredInvitationAction
{
    public function __construct(
        private readonly TierResolver $tiers,
        private readonly PublishEntitlementResolver $entitlements,
    ) {}

    /** Yayından kaldırıldıysa true. */
    public function handle(string $invitationId): bool
    {
        $invitation = Invitation::query()
            ->whereKey($invitationId)
            ->lockForUpdate()
            ->first();

        if ($invitation === null || $invitation->status !== InvitationStatus::Published) {
            return false;
        }

        $owned = $this->entitlements->highestTierFor($invitation);

        if ($owned !== null && $owned->covers($this->tiers->requiredFor($invitation))) {
            return false;
        }

        // İki alan birlikte değişir (PublishInvitationAction'ın tersi).
        // `updated` olayı InvitationChanged'i ateşler: public cache düşer.
        $invitation->status = InvitationStatus::Saved;
        $invitation->published_at = null;
        $invitation->save();

        return true;
    }
}
