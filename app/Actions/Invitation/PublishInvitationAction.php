<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Actions\Payment\ClaimReleasedOrderAction;
use App\Actions\Payment\StampFirstPublishAction;
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
 * 🔴 Bu sinif Faz 3'ten beri BOS BIR ISKELET olarak duruyordu. Bilerek:
 * Ayrıntı: docs/rehber/app/Actions/Invitation/PublishInvitationAction.md §K18-1
 */
final class PublishInvitationAction
{
    public function __construct(
        private readonly TierResolver $tiers,
        private readonly PublishEntitlementResolver $entitlements,
        private readonly ClaimReleasedOrderAction $claims,
        private readonly StampFirstPublishAction $stamps,
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
            // Ayrıntı: docs/rehber/app/Actions/Invitation/PublishInvitationAction.md §K18-2
            $fresh = Invitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // 2. KATMAN — durum catismasi. 409, cunku istek gecerli ama kaynak
            // zaten o durumda (docs/08 §4). Sessizce basarili donmek
            // Ayrıntı: docs/rehber/app/Actions/Invitation/PublishInvitationAction.md §K18-3
            if ($fresh->status === InvitationStatus::Published) {
                throw new InvitationAlreadyPublishedException;
            }

            // 3. KATMAN — gereken plan SUNUCUDA hesaplanir. K6'nin Faz 3'te
            // odenen bedeli (show_* ayri kolonlar) burada karsiligini buluyor.
            $required = $this->tiers->requiredFor($fresh);

            // 4. KATMAN — sahip olunan plan: bu davetiyeye bagli odenmis
            // siparisler.
            $owned = $this->entitlements->highestTierFor($fresh);

            // 4b. SERBEST HAKKI BAGLA (Faz 9). Faz 10 (K99): fiyat sayfasindan
            // alinan paket de burada baglanir.
            // Ayrıntı: docs/rehber/app/Actions/Invitation/PublishInvitationAction.md §K18-4
            if ($owned === null || ! $owned->covers($required)) {
                if ($this->claims->handle($fresh, $required)) {
                    // Baglandi: hak tablosu degisti, cevabi YENIDEN sor.
                    // Claim'in dondurdugu plani varsaymiyoruz — tek dogruluk
                    // kaynagi resolver (C3).
                    $owned = $this->entitlements->highestTierFor($fresh);
                }
            }

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
            // Ayrıntı: docs/rehber/app/Actions/Invitation/PublishInvitationAction.md §K18-5
            $fresh->save();

            // 6. KATMAN — DAMGA (Faz 18.4, SEC-02 · K164): hak veren siparişler "bir davetiyeyi
            // yayınladı" diye işaretlenir, aynı transaction'da. Serbest bırakmada silinmez ve
            // iadeyi kapatır. Ayrı Action, çünkü bu sınıf `orders` tablosunu bilmez (K42).
            $this->stamps->handle($fresh);

            return $fresh;
        });
    }
}
