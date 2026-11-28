<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Contracts\PublishEntitlementResolver;
use App\Enums\InvitationStatus;
use App\Enums\SubscriptionTier;
use App\Exceptions\PaywallViolationException;
use App\Models\Invitation;
use App\Services\Pricing\TierResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Davetiyeyi gunceller, programi senkronize eder; yayindaki davetiyede paket farkini sorar (K88). Yetki Policy'de.
 * Ayrintili aciklama: docs/rehber/app/Actions/Invitation/UpdateInvitationAction.md
 */
final class UpdateInvitationAction
{
    public function __construct(
        private readonly SyncTimelineEventsAction $syncTimelineEvents,
        private readonly TierResolver $tiers,
        private readonly PublishEntitlementResolver $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $timelineEvents  null = programa dokunma
     *
     * @throws ModelNotFoundException Davetiye kilit aninda silinmis -> 404
     * @throws PaywallViolationException Yayindaki davetiyede plan ustu modul -> 402
     */
    public function handle(Invitation $invitation, array $attributes, ?array $timelineEvents): Invitation
    {
        return DB::transaction(function () use ($invitation, $attributes, $timelineEvents): Invitation {
            // 🔴 Satir kilitlenip YENIDEN OKUNUYOR (E9): es zamanli "yayinla" ile SIRAYLA calisir (kilavuz).
            $fresh = Invitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Kural bir FARKA bakar: gereksinim degisiklikten ONCE olculur.
            $requiredBefore = $this->tiers->requiredFor($fresh);

            $fresh->fill($attributes);

            // K324: canlıya yazılan alanlar yayınlanmamış taslaktan düşer (alan bazında son yazan kazanır).
            $fresh->pending_changes = PendingChanges::of($fresh)
                ->without(array_keys($attributes), $timelineEvents !== null)
                ->toColumn();

            // Kontrol save()'den ONCE: reddedilen istek hicbir satir yazmaz ve
            // InvitationChanged olayi hic firlamaz (cache bosuna temizlenmez).
            if ($fresh->status === InvitationStatus::Published) {
                $this->ensureRaisedTierIsOwned($fresh, $requiredBefore);
            }

            $fresh->save();

            $timelineChanged = $timelineEvents !== null
                && $this->syncTimelineEvents->handle($fresh, $timelineEvents);

            // Yalnizca program degistiyse kaydin kendisi "kirli" olmaz ve
            // updated_at bayat kalirdi — frontend onu "son kaydetme" diye gosteriyor.
            if ($timelineChanged && ! $fresh->wasChanged()) {
                $fresh->touch();
            }

            // Galeri bu Action'da YAZILMAZ (sirasini medya Action'lari tutar),
            // ama yanit onu da tasir.
            return $fresh->load(['timelineEvents', 'galleryMedia']);
        });
    }

    /**
     * Gereksinim YUKSELIYORSA sahip olunan plan kapsamali; yukseltmeyen her degisiklik serbest (kilavuz §9.3).
     */
    private function ensureRaisedTierIsOwned(Invitation $invitation, SubscriptionTier $before): void
    {
        $after = $this->tiers->requiredFor($invitation);

        // Onceki gereksinim yenisini zaten kapsiyorsa hicbir sey yukselmedi.
        if ($before->covers($after)) {
            return;
        }

        $owned = $this->entitlements->highestTierFor($invitation);

        // PublishInvitationAction'daki iki red, ayni iki kod (docs/08 §4).
        if ($owned === null) {
            throw PaywallViolationException::noPurchase($after);
        }

        if (! $owned->covers($after)) {
            throw PaywallViolationException::insufficientTier($after, $owned);
        }
    }
}
