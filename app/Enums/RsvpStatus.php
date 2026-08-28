<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir misafirin LCV (RSVP) yanitinin durumu.
 *
 * Degerler INGILIZCE ve makine-okunurdur (K21): backend metin dondurmez, KOD
 * dondurur. 'Katiliyor' gibi bir GOSTERIM metni asla veri degeri olamaz —
 * o durumda arayuzun dilini degistirmek veritabanini degistirmek olurdu.
 *
 * 🔴 K50: hangi durumlarin kotadan yer tuttugu BURADA tanimlidir, kota
 * sorgusunun icinde degil. Kural tek yerde durur; sorgu ona sorar.
 * Ayrintili aciklama: docs/rehber/app/Enums/RsvpStatus.md
 */
enum RsvpStatus: string
{
    /** Misafir geliyor. */
    case Attending = 'attending';

    /** Misafir henuz karar vermedi — arayuzde "Belirsiz". */
    case Pending = 'pending';

    /** Misafir gelmiyor. */
    case Declined = 'declined';

    /**
     * Bu yanit davetiyenin LCV kotasindan yer tutuyor mu? (K50)
     *
     * Kota bir KAPASITE sinirdir (K28: bu yuzden 429 degil 403). Gelmeyeceğini
     * bildiren misafir masada yer kaplamaz; kararsiz olan kaplayabilir, bu
     * yuzden temkinli tarafta sayilir.
     *
     * match ARM'LERI TEK TEK yazildi, `default` YOK: enum'a dorduncu bir durum
     * eklendigi gun PHP burada UnhandledMatchError firlatir ve karari vermeye
     * zorlar. `default => false` yazsaydik yeni durum sessizce kotasiz olurdu.
     */
    public function consumesQuota(): bool
    {
        return match ($this) {
            self::Attending, self::Pending => true,
            self::Declined => false,
        };
    }

    /**
     * Veritabani CHECK kisiti ve dogrulama kurallari icin ham degerler.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Kota sorgusunun `WHERE status IN (...)` listesi.
     *
     * Liste elle yazilmaz, consumesQuota()'dan TURETILIR: iki yer olsaydi biri
     * degisip digeri unutuldugunda kota sessizce yanlis hesaplanirdi (C3).
     *
     * @return list<string>
     */
    public static function quotaConsumingValues(): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->consumesQuota()) {
                $values[] = $case->value;
            }
        }

        return $values;
    }
}
