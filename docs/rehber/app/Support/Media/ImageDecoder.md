# `app/Support/Media/ImageDecoder.php`

> Faz 21.12 · `ARC-10`. `OptimizeUploadedImage::encodeOptimized()`'ın çözme kısmı.

`imagecreatefromstring` (susturulmuş: bozuk dosya uyarısı loga ve `failOnWarning`'e gitmesin) → paletli PNG ise
`imagepalettetotruecolor` (ölçekleyici ve WebP kodlayıcısı truecolor ister; şeffaflık alfaya taşınır) → `GdAlpha::keep`.
Çözülemezse `Log::warning('Gorsel cozulemedi.')` ve `null`; iş damgayı atar. Testler: palet → truecolor, çöp → null.

## Koddan taşınan notlar (Faz 21.12, `ARC-10`, K18)

Faz 21.12'ye kadar bu iş `app/Jobs/OptimizeUploadedImage.php` içinde, uzun yorumlarıyla duruyordu. Bölünürken kodda
1–3 satırlık özet kaldı; aşağıda o günkü hâli yorumlarıyla **aynen** (anlam kaybı olmasın diye). Davranış değişmedi.

### Eski `encodeOptimized()` (çözme + akış)

```php
    /**
     * Gorseli cozer, kucultur, yonunu duzeltir ve kodlar.
     *
     * Butun GD isi TEK bir yerde ve bellek butcesinin ICINDE: cozulmus gorsel
     * bu metodun cercevesinden disari cikmaz, dolayisiyla butce geri
     * indirildiginde bellek zaten serbest kalmis olur.
     */
    private function encodeOptimized(string $contents, int $width, int $height): ?OptimizedImage
    {
        return $this->withMemoryLimit(function () use ($contents, $width, $height): ?OptimizedImage {
            $image = @imagecreatefromstring($contents);

            if ($image === false) {
                Log::warning('Gorsel cozulemedi.', ['media' => $this->media->id]);

                return null;
            }

            // Paletli PNG'ler (256 renk) truecolor DEGIL; olcekleyici ve WebP
            // kodlayicisi truecolor ister. Donusum ayni zamanda seffafligi
            // alfa kanalina tasir.
            if (! imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }

            $this->keepAlpha($image);

            $image = $this->downscale($image, $width, $height);
            $image = $this->applyOrientation($image, $contents);

            return $this->compress($image);
        });
    }
```
