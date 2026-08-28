<?php

declare(strict_types=1);

namespace App\Services\Rsvp;

use App\Contracts\RsvpQuotaResolver;
use App\Models\Invitation;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Kotayi plan tanimlarindan okur.
 *
 * 🔴 FAZ 5'IN GECICI GERCEGI: `subscriptions` tablosu ve `TierResolver` henuz
 * yok (Faz 7 · K42). Bu yuzden her davetiye EN DAR plandan sayilir.
 *
 * Yon secimi bilinclidir: bilinmeyen durumda DAR tarafta kalmak, genis tarafta
 * kalmaktan iyidir. Yanlis yonde hata yapsaydik (varsayilan 'sinirsiz'), Faz 7
 * gelene kadar kota HIC uygulanmamis olurdu ve K47'nin Faz 4'te engelledigi
 * seyin aynisi olurdu: paywall'siz bir bedava yol.
 *
 * Faz 7'de bu sinifin ICI degisir (TierResolver'a sorar); Action ve testler
 * degismez.
 * Ayrintili aciklama: docs/rehber/app/Services/Rsvp/TierRsvpQuotaResolver.md
 */
final class TierRsvpQuotaResolver implements RsvpQuotaResolver
{
    /**
     * Faz 7'ye kadar herkesin sayildigi plan.
     *
     * Sabit bir sinif sabiti, config'te bir "varsayilan plan" anahtari DEGIL:
     * bu bir is ayari degil, GECICI BIR EKSIKLIGIN adidir. Config'e konsaydi
     * kalici bir ozellik gibi gorunur ve Faz 7'de silinmesi unutulurdu.
     */
    private const FALLBACK_TIER = 'standart';

    public function limitFor(Invitation $invitation): ?int
    {
        /** @var array<string, mixed> $tiers */
        $tiers = Config::array('davetkart.tiers');

        $tier = $tiers[self::FALLBACK_TIER] ?? null;

        // Yapilandirma hatasi SESSIZ kalmamali: kota okunamiyorsa kotasiz
        // devam etmek, odemeli bir sinirin sessizce kalkmasi demektir.
        if (! is_array($tier) || ! array_key_exists('rsvp_limit', $tier)) {
            throw new RuntimeException(
                'Configuration error: davetkart.tiers.'.self::FALLBACK_TIER.'.rsvp_limit is missing.',
            );
        }

        $limit = $tier['rsvp_limit'];

        // config'te `null` yazan plan (gold, elit) SINIRSIZ demektir.
        return is_int($limit) ? $limit : null;
    }
}
