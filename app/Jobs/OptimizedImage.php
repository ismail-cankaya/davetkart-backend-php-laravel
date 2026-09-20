<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * OptimizeUploadedImage'in ara sonucu: kodlanmis baytlar ve yeni kimligi.
 *
 * Neden dizi degil? Uc alan birlikte anlam tasiyor ve ikisi birbirinden
 * TURETILMIS ('image/webp' -> 'webp'). Dizi dondurulse cagiran taraf anahtar
 * adlarina guvenmek zorunda kalirdi ve PHPStan bunu dogrulayamazdi.
 * CheckoutResult (Faz 7) ile ayni gerekce: uretenin yaninda duran kucuk bir
 * deger nesnesi.
 *
 * Ayrintili aciklama: docs/rehber/app/Jobs/OptimizeUploadedImage.md
 */
final readonly class OptimizedImage
{
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $extension,
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }
}
