<?php

declare(strict_types=1);

namespace App\Services\Rsvp;

use App\Contracts\PublishEntitlementResolver;
use App\Contracts\RsvpQuotaResolver;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;

/**
 * LCV kotasini davetiyenin GERCEK planindan okur.
 *
 * 🔴 Faz 5'te birakilan DIKIS YERI (K51) bugun kapaniyor. O gun
 * TierRsvpQuotaResolver herkesi FALLBACK_TIER ('standart') sayiyordu, cunku
 * kotanin gercek kaynagi (siparis kayitlari) henuz yoktu.
 *
 * Degisen TEK sey AppServiceProvider'daki baglama satiri oldu:
 *   - bind(RsvpQuotaResolver::class, TierRsvpQuotaResolver::class);
 *   + bind(RsvpQuotaResolver::class, SubscriptionRsvpQuotaResolver::class);
 *
 * SubmitRsvpAction, RsvpTest'in kota testleri, hata sozlesmesi ve
 * RsvpQuotaExceededException'in hicbiri degismedi. Arayuzun kazandirdigi
 * tam olarak buydu (DIP).
 * Ayrintili aciklama: docs/rehber/app/Services/Rsvp/SubscriptionRsvpQuotaResolver.md
 */
final class SubscriptionRsvpQuotaResolver implements RsvpQuotaResolver
{
    public function __construct(
        // K42: tekil ve paket alim TEK arayuzden soruluyor. Bu sinif iki
        // kaynagin varligini bile bilmiyor.
        private readonly PublishEntitlementResolver $entitlements,
    ) {}

    public function limitFor(Invitation $invitation): ?int
    {
        $tier = $this->entitlements->highestTierFor($invitation);

        // 🔴 Odeme yoksa EN DAR plan sayilir.
        //
        // Bu, FALLBACK_TIER sabitinin geri gelmesi DEGIL. Fark gerekcede:
        //   FALLBACK_TIER  -> \"kaynak henuz yok\" — GECICI BIR EKSIKLIGIN adiydi
        //   lowest()       -> \"odeme yok\" — KALICI bir is kurali
        //
        // Odemesiz bir davetiye zaten yayinlanamaz (7.12), yayinlanmamis
        // davetiyeye de LCV yazilamaz (ResolveOpenRsvpInvitationAction). Yani
        // bu kol pratikte ulasilmaz gorunuyor — ama bir davetiye yayinlandiktan
        // SONRA iade edilirse (refunded) hak duser ve kol gercek olur.
        // Bilinmeyende DAR tarafta kalmak, genis tarafta kalmaktan iyidir.
        return ($tier ?? SubscriptionTier::lowest())->rsvpLimit();
    }
}
