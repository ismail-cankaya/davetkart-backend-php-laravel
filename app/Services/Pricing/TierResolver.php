<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * "Bu davetiyeyi yayinlamak icin EN AZ hangi plan gerekir?"
 *
 * 🔴 Frontend'deki getRequiredTier()'in SUNUCU IKIZI
 * (davetkart-frontent/src/stores/useSubscriptionStore.ts).
 *
 * Ikiz olmasi bir tekrar degil bir ZORUNLULUKTUR (CLAUDE.md §3):
 *   - Frontend'deki kopya bir ARAYUZ kararidir: hangi plan karti vurgulanacak,
 *     paywall modali ne zaman acilacak. DevTools'tan degistirilebilir.
 *   - Buradaki kopya bir GUVENLIK kararidir: yayin gercekten acilir mi.
 *
 * "Tek doğruluk kaynağı" ilkesi burada GECERSIZ degil, YANLIS UYGULANIRDI:
 * istemciden gelen bir hesabin sonucuna guvenmek, hesabi hic yapmamakla
 * aynidir. Iki kopya ayni KURALI degil, iki farkli SORUMLULUGU tasir.
 *
 * K6'nin bedeli burada odendi: `show_*` bayraklari Faz 3'te JSON degil AYRI
 * KOLON olarak acildi, tam olarak bu hesabin SQL ile de yapilabilmesi icin.
 * Ayrintili aciklama: docs/rehber/app/Services/Pricing/TierResolver.md
 */
final class TierResolver
{
    /**
     * Davetiyenin acik modullerini kapsayan EN UCUZ plan.
     *
     * Hicbir modul acik degilse en dusuk plan doner — bedava katman YOK
     * (docs/09): yayinlamak her hâlükârda bir satin alma ister.
     */
    public function requiredFor(Invitation $invitation): SubscriptionTier
    {
        $required = SubscriptionTier::lowest();

        foreach ($this->moduleTiers() as $column => $tier) {
            // 🔴 Kati kip (Model::shouldBeStrict) sayesinde config'e var
            // olmayan bir kolon adi yazilirsa burasi GURULTULU patlar
            // (MissingAttributeException). Sessizce false sayilsaydi, yazim
            // hatasi olan bir modul paywall'dan MUAF olurdu.
            if ($invitation->getAttribute($column) !== true) {
                continue;
            }

            if ($tier->rank() > $required->rank()) {
                $required = $tier;
            }
        }

        return $required;
    }

    /**
     * config('davetkart.module_tiers') haritasini tiplere cevirir.
     *
     * Harita bir IS TERCIHIDIR (E6): "galeri Elit'e ait" karari bir kod
     * degisikligi degil bir fiyatlandirma karari olmali. Bu yuzden config'te
     * duruyor — ama config'ten gelen ham string burada BIR KEZ dogrulanip
     * enum'a cevriliyor, boylece geri kalan kod sihirli string gormuyor.
     *
     * @return array<string, SubscriptionTier>
     */
    private function moduleTiers(): array
    {
        $map = [];

        /** @var array<string, mixed> $configured */
        $configured = Config::array('davetkart.module_tiers');

        foreach ($configured as $column => $value) {
            $tier = is_string($value) ? SubscriptionTier::tryFrom($value) : null;

            // Yapilandirma hatasi SESSIZ kalmamali: taninmayan bir plan adi
            // "bu modul bedava" anlamina gelirdi. Ayni refleks Faz 5'te
            // TierRsvpQuotaResolver'da da vardi.
            if ($tier === null) {
                throw new RuntimeException(
                    "Configuration error: davetkart.module_tiers.{$column} is not a valid tier.",
                );
            }

            $map[$column] = $tier;
        }

        return $map;
    }
}
