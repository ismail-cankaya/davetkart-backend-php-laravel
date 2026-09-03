<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Contracts\PublishEntitlementResolver;
use App\Enums\InvitationStatus;
use App\Exceptions\InvitationAlreadyPublishedException;
use App\Exceptions\PaywallViolationException;
use App\Models\Invitation;
use App\Services\Pricing\TierResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Davetiyeyi yayina alir — projenin PAYWALL KAPISI.
 *
 * 🔴 Bu sinif Faz 3'ten beri BOS BIR ISKELET olarak duruyordu. Bilerek:
 * K47, "yayin ucu Faz 4'te yazilirsa paywall'siz bir bedava yol acilir"
 * demisti. Iskelet bugun, kapiyi kilitleyecek anahtarlar (TierResolver,
 * PublishEntitlementResolver) var olduktan SONRA dolduruluyor.
 *
 * Sira, ucuzdan pahaliya ve gizliden aciga (L1 + H7):
 *   1. Sahiplik      -> Gate::authorize('publish') (controller) -> yoksa 404
 *   2. Durum         -> zaten yayindaysa 409
 *   3. Gereken plan  -> TierResolver (SUNUCUDA hesaplanir)
 *   4. Sahip olunan  -> PublishEntitlementResolver (K42: iki kaynak, tek arayuz)
 *   5. Yayin         -> status + published_at, kilitli transaction icinde
 *
 * 🔴 Istemciden gelen `tier` bilgisi HICBIR ADIMDA kullanilmaz (docs/09).
 * Frontend'in `activeTier`'i yalnizca arayuz karari ve DevTools'tan
 * degistirilebilir.
 * Ayrintili aciklama: docs/rehber/app/Actions/Invitation/PublishInvitationAction.md
 */
final class PublishInvitationAction
{
    public function __construct(
        private readonly TierResolver $tiers,
        private readonly PublishEntitlementResolver $entitlements,
    ) {}

    /**
     * @throws ModelNotFoundException Davetiye kilit aninda silinmis -> 404
     * @throws InvitationAlreadyPublishedException Zaten yayinda -> 409
     * @throws PaywallViolationException Odeme yok / plan yetmiyor -> 402
     */
    public function handle(Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($invitation): Invitation {
            // 🔴 Satir kilitlenip YENIDEN OKUNUYOR. Elimizdeki nesne rota
            // baglamasindan geldi ve o okumadan bu ana kadar baska bir istek
            // davetiyeyi yayinlamis olabilirdi. Kilitsiz calisilsaydi iki
            // es zamanli istek ikisi de "yayinda degil" gorur ve ikisi de
            // yayinlardi — 409 hic firlamaz, published_at iki kez yazilirdi
            // (check-then-act, E9; Faz 5 ve 6'daki kota kilitleriyle ayni desen).
            $fresh = Invitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // 2. KATMAN — durum catismasi. 409, cunku istek gecerli ama kaynak
            // zaten o durumda (docs/08 §4). Sessizce basarili donmek
            // kullaniciya iki kez yayinladigini (ve belki iki kez odedigini)
            // dusundururdu.
            if ($fresh->status === InvitationStatus::Published) {
                throw new InvitationAlreadyPublishedException;
            }

            // 3. KATMAN — gereken plan SUNUCUDA hesaplanir. K6'nin Faz 3'te
            // odenen bedeli (show_* ayri kolonlar) burada karsiligini buluyor.
            $required = $this->tiers->requiredFor($fresh);

            // 4. KATMAN — sahip olunan plan. Tekil ve paket alim tek arayuzden
            // soruluyor (K42); bu Action iki kaynagin varligini bile bilmiyor.
            $owned = $this->entitlements->highestTierFor($fresh);

            // 🔴 IKI AYRI RED, IKI AYRI KOD. Kullanicinin onundeki eylem
            // farkli: "once bir plan al" ile "planini yukselt" ayni ekran
            // degil (docs/08 §4 — durum kodu kaba, `code` ince ayrim).
            if ($owned === null) {
                throw PaywallViolationException::noPurchase($required);
            }

            if (! $owned->covers($required)) {
                throw PaywallViolationException::insufficientTier($required, $owned);
            }

            // 5. KATMAN — yayin. Iki alan BIRLIKTE degisir; InvitationFactory
            // ::published() de ayni ikiliyi yaziyor.
            $fresh->status = InvitationStatus::Published;
            $fresh->published_at = now();

            // save() -> 'updated' -> InvitationChanged -> ClearInvitationCache
            // (K48). Olay MODELDEN yapisal olarak firliyor; bu Action'in
            // cache'i temizlemeyi HATIRLAMASI gerekmiyor — Faz 4'te tam olarak
            // bu unutulma riski icin oyle tasarlanmisti.
            $fresh->save();

            return $fresh;
        });
    }
}
