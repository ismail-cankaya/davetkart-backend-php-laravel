<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Facades\Config;

/**
 * Yuklenen bir dosyanin TURU — ve o turun sinirlarinin tek adresi.
 *
 * 🔴 Enum'un DEGERI ayni zamanda config anahtaridir:
 *   MediaKind::Gallery->value === 'gallery'
 *   -> config('davetkart.media.gallery.max_size_kb')
 *
 * Bu bilincli bir baglamadir. Ayri bir eslemede (match ile 'gallery' => 'galeri')
 * iki isim olurdu ve biri degisince digeri sessizce eskirdi (C3). Boylece yeni
 * bir tur eklemek TEK bir config blogu + TEK bir case demek.
 *
 * Sinirlarin kendisi burada DEGIL config'te: boyut ve MIME listesi birer IS
 * TERCIHIDIR (E6), kod degisikligi gerektirmemeli.
 * Ayrintili aciklama: docs/rehber/app/Enums/MediaKind.md
 */
enum MediaKind: string
{
    /** Davetiye galerisi — sahibi yukler. */
    case Gallery = 'gallery';

    /** LCV fotografi — MISAFIR yukler, kimligi bilinmiyor. */
    case RsvpPhoto = 'rsvp_photo';

    /** LCV videosu — MISAFIR yukler. */
    case RsvpVideo = 'rsvp_video';

    /** Bu turu kimligi bilinmeyen biri yukleyebilir mi? */
    public function isGuestUploadable(): bool
    {
        return match ($this) {
            self::Gallery => false,
            self::RsvpPhoto, self::RsvpVideo => true,
        };
    }

    /**
     * Kuyruktaki optimizasyon bu turu isler mi?
     *
     * Video isleme (transcode) bambaska bir is: ffmpeg, dakikalar suren islem,
     * ayri bir kuyruk. Faz 6 yalnizca GORSEL optimize eder; video oldugu gibi
     * saklanir. Bir savunmanin/optimizasyonun NEYI KAPSAMADIGI da yazilir (B6).
     */
    public function isOptimizable(): bool
    {
        return match ($this) {
            self::Gallery, self::RsvpPhoto => true,
            self::RsvpVideo => false,
        };
    }

    /** Dosya boyutu ust siniri (kilobayt). */
    public function maxSizeKb(): int
    {
        return Config::integer("davetkart.media.{$this->value}.max_size_kb");
    }

    /** Davetiye basina bu turden en fazla kac dosya olabilir. */
    public function maxPerInvitation(): int
    {
        return Config::integer("davetkart.media.{$this->value}.max_per_invitation");
    }

    /**
     * Kabul edilen MIME tipleri — ICERIKTEN dogrulanir, uzantidan degil.
     *
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        /** @var list<string> $mimes */
        $mimes = Config::array("davetkart.media.{$this->value}.mimes");

        return $mimes;
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
     * Misafirin gonderebilecegi turler — public ucun 'in:' kuralini besler.
     *
     * Liste elle yazilmaz, isGuestUploadable()'dan TURETILIR: iki yer olsaydi
     * biri degisip digeri unutuldugunda misafir galeriye yukleyebilirdi.
     *
     * @return list<string>
     */
    public static function guestUploadableValues(): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->isGuestUploadable()) {
                $values[] = $case->value;
            }
        }

        return $values;
    }
}
