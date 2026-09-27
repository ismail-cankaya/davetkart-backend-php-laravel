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
 * Mevcut davetiyeyi gunceller; program listesi gonderildiyse senkronize eder.
 *
 * Yetki kontrolu BURADA DEGIL: Policy controller'da calisir (3.7).
 *
 * 🔴 Faz 10 (K88): YAYINDAKI davetiyede paywall bu Action'da da sorulur.
 * Soru yalnizca yayin aninda sorulsaydi, Standart ile yayinlanan bir
 * davetiyenin Elit modulleri sonradan bir PUT ile acilabilirdi (rapor §1.1,
 * denetim K-1). Taslak serbesttir: denemenin bedeli olmaz (K43'un ruhu).
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
            // 🔴 Satir kilitlenip YENIDEN OKUNUYOR (E9). Rota baglamasindan
            // gelen nesne bayat olabilir: arada es zamanli bir "yayinla"
            // istegi davetiyeyi yayina almis olabilir. PublishInvitationAction
            // ayni satiri kilitledigi icin ikisi artik SIRAYLA calisir; biri
            // digerinin yazdigini gormeden karar veremez.
            $fresh = Invitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Kural bir FARKA bakar: gereksinim degisiklikten ONCE olculur.
            $requiredBefore = $this->tiers->requiredFor($fresh);

            $fresh->fill($attributes);

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
     * Degisiklik gereken plani YUKSELTIYORSA, yeni gereksinim sahip olunan
     * planca kapsanmali.
     *
     * 🔴 Gereksinimi yukseltmeyen her degisiklik serbesttir: modul kapatmak,
     * metin duzeltmek. Hak iade ya da fiyat haritasi degisikligiyle azalmis
     * olsa bile kullanici davetiyesini duzenleyebilmeli (kilavuz §9.3).
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
