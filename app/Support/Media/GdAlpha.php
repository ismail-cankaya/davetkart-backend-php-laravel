<?php

declare(strict_types=1);

namespace App\Support\Media;

use GdImage;

/**
 * Şeffaflık korunsun: alfa karıştırması kapalı + alfa kaydı açık. Her yeni GD görselinde tekrarlanır (miras kalmaz).
 * Ayrıntılı açıklama: docs/rehber/app/Support/Media/GdAlpha.md
 */
final class GdAlpha
{
    public static function keep(GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
}
