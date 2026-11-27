<?php

declare(strict_types=1);

namespace App\Support\Media;

use GdImage;
use Illuminate\Support\Facades\Log;

/**
 * Baytları truecolor, alfası korunmuş bir GD görseline çözer; çözülemezse null (ARC-10, Faz 21.12).
 * Ayrıntılı açıklama: docs/rehber/app/Support/Media/ImageDecoder.md
 */
final class ImageDecoder
{
    public function decode(string $contents, string $mediaId): ?GdImage
    {
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            Log::warning('Gorsel cozulemedi.', ['media' => $mediaId]);

            return null;
        }

        // Paletli PNG truecolor değil; ölçekleyici ve WebP kodlayıcısı truecolor ister (şeffaflık alfaya taşınır).
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        GdAlpha::keep($image);

        return $image;
    }
}
