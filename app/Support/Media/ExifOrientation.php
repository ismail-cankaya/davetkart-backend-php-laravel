<?php

declare(strict_types=1);

namespace App\Support\Media;

use GdImage;
use Illuminate\Support\Facades\Log;

/**
 * JPEG'in EXIF yönünü okur ve PİKSELLERE uygular (GD meta veriyi atar, yoksa fotoğraf yan yatar); EXIF bloğu var mı sorusu.
 * Ayrıntılı açıklama: docs/rehber/app/Support/Media/ExifOrientation.md
 */
final class ExifOrientation
{
    /** EXIF APP1 bloğunun JPEG'in başında arandığı pencere. */
    private const int SCAN_BYTES = 65_536;

    /** Kaynakta EXIF bloğu var mı? APP1 dosyanın başında: ilk 64 KB'a bakmak yeterli. */
    public static function hasBlock(string $contents, string $mimeType): bool
    {
        if ($mimeType !== 'image/jpeg') {
            return false;
        }

        return str_contains(substr($contents, 0, self::SCAN_BYTES), "Exif\0\0");
    }

    /** Yön etiketi (1-8); yoksa, okunamazsa ya da JPEG değilse 1. */
    public function read(string $contents, string $mimeType): int
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        // exif_read_data akış ister; php://temp 2 MB'tan sonra geçici dosyaya taşınır (ikinci büyük kopya yok).
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

            // Susturma bilinçli: bozuk EXIF uyarısı logu doldurur ve failOnWarning'li testi kırar.
            $exif = @exif_read_data($stream, 'IFD0');
        } finally {
            fclose($stream);
        }

        if ($exif === false || ! isset($exif['Orientation']) || ! is_numeric($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /** Küçültmeden SONRA çağrılır (imagerotate bir kopya daha ayırır). imagerotate saat yönünün tersine: "90 CW" → 270. */
    public function apply(GdImage $image, int $orientation, string $mediaId): GdImage
    {
        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),                                  // yatay ayna
            3 => $this->rotate($image, 180, $mediaId),                                      // 180
            4 => $this->flip($image, IMG_FLIP_VERTICAL),                                    // dikey ayna
            5 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90, $mediaId),     // ayna + 270 CW
            6 => $this->rotate($image, 270, $mediaId),                                      // 90 CW
            7 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 270, $mediaId),    // ayna + 90 CW
            8 => $this->rotate($image, 90, $mediaId),                                       // 270 CW
            default => $image,
        };
    }

    /** imagerotate yeni bir görsel üretir; başarısızsa elimizdekiyle devam. */
    private function rotate(GdImage $image, int $degrees, string $mediaId): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            Log::warning('Gorsel cevrilemedi.', ['media' => $mediaId]);

            return $image;
        }

        GdAlpha::keep($rotated);

        return $rotated;
    }

    /** imageflip YERİNDE çalışır, yeni görsel üretmez. */
    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }
}
