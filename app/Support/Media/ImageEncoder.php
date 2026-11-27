<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Jobs\OptimizedImage;
use GdImage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Hedef biçim (PNG → WebP, diğerleri kendi biçiminde) ve hedef boyuta inene kadar kaliteyi düşüren kodlama (ARC-10, Faz 21.12).
 * Ayrıntılı açıklama: docs/rehber/app/Support/Media/ImageEncoder.md
 */
final class ImageEncoder
{
    /** Kalite, hedefe inene kadar bu adımla düşürülür. */
    private const int QUALITY_STEP = 10;

    /** PNG'nin karşılığı WebP: kayıplı + alfa. JPEG ve WebP kendi biçiminde kalır (sebepsiz URL değişimi yok). */
    public static function targetMimeType(string $sourceMimeType): string
    {
        return $sourceMimeType === 'image/png' ? 'image/webp' : $sourceMimeType;
    }

    public static function targetBytes(): int
    {
        return Config::integer('davetkart.media.optimize.target_kb') * 1024;
    }

    /** Hedef boyuta inene ya da `min_quality` tabanına varana kadar kodlar; kodlayıcı yoksa null. */
    public function compress(GdImage $image, string $mimeType, string $mediaId): ?OptimizedImage
    {
        $minQuality = Config::integer('davetkart.media.optimize.min_quality');
        $quality = $mimeType === 'image/webp'
            ? Config::integer('davetkart.media.optimize.webp_quality')
            : Config::integer('davetkart.media.optimize.jpeg_quality');

        while (true) {
            $bytes = $this->encode($image, $mimeType, $quality, $mediaId);

            if ($bytes === null) {
                return null;
            }

            if (strlen($bytes) <= self::targetBytes() || $quality <= $minQuality) {
                return new OptimizedImage($bytes, $mimeType, $this->extensionFor($mimeType));
            }

            $quality = max($minQuality, $quality - self::QUALITY_STEP);
        }
    }

    /** GD dosya yolu null iken doğrudan çıktıya yazar; ob_start onu tamamen yakalar. GD/biçim desteği yoksa null. */
    private function encode(GdImage $image, string $mimeType, int $quality, string $mediaId): ?string
    {
        if ($mimeType === 'image/webp' && ! function_exists('imagewebp')) {
            Log::warning('GD WebP destegi yok; gorsel optimizasyonu atlandi.', ['media' => $mediaId]);

            return null;
        }

        ob_start();

        $written = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, null, $quality),
            'image/webp' => imagewebp($image, null, $quality),
            default => false,
        };

        $bytes = (string) ob_get_clean();

        return $written === true && $bytes !== '' ? $bytes : null;
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
