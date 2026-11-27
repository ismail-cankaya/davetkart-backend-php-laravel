<?php

declare(strict_types=1);

namespace App\Support\Media;

use GdImage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * En uzun kenarı `max_edge_px`'e indirir (genişliği değil: dikey fotoğraf); IMG_TRIANGLE ile (ARC-10, Faz 21.12).
 * Ayrıntılı açıklama: docs/rehber/app/Support/Media/ImageResizer.md
 */
final class ImageResizer
{
    public function toLongestEdge(GdImage $image, int $width, int $height, string $mediaId): GdImage
    {
        $maxEdge = Config::integer('davetkart.media.optimize.max_edge_px');
        $longestEdge = max($width, $height);

        if ($longestEdge <= $maxEdge) {
            return $image;
        }

        $ratio = $maxEdge / $longestEdge;

        // 🔴 IMG_TRIANGLE, varsayılan bilinear değil: pencereye giren TÜM pikseller hesaba katılır (tırtık yok).
        $resized = imagescale(
            $image,
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
            IMG_TRIANGLE,
        );

        if ($resized === false) {
            // Bellek yetmemiş olabilir; küçültememek yüklemeyi geri almak için sebep değil.
            Log::warning('Gorsel kucultulemedi.', ['media' => $mediaId]);

            return $image;
        }

        GdAlpha::keep($resized);

        return $resized;
    }
}
