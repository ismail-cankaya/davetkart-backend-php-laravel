# `app/Support/Media/ImageEncoder.php`

> Faz 21.12 · `ARC-10`. `compress()`, `encode()`, `targetMimeType()`, `extensionFor()`, `targetBytes()`.

- `targetMimeType()`: PNG → WebP (PNG kayıpsız; 2000 px'de bile 3–5 MB, 2 MB hedefi ulaşılamaz; WebP kayıplı ve alfa
  taşır, JPEG şeffafı siyahlatırdı). JPEG ve WebP kendi biçiminde kalır.
- `compress()`: kalite `jpeg_quality` (82) / `webp_quality` (80)'den başlar, hedef boyuta (`target_kb` 2048) inene ya da
  `min_quality` tabanına varana kadar 10'ar düşer. Taban altında bozulma görünür; dosya büyük kalır, kalite feda edilmez.
- `encode()`: GD dosya yolu `null` iken doğrudan çıktıya yazar; `ob_start`/`ob_get_clean` onu tamamen yakalar (T3 ihlali
  değil). WebP desteği yoksa uyarı + `null` (ortam eksiği, kullanıcı hatası değil).

Testler: hedef biçimler, uzantı biçimden, ulaşılamaz hedefte kalite tabana iner.

## Koddan taşınan notlar (Faz 21.12, `ARC-10`, K18)

Faz 21.12'ye kadar bu iş `app/Jobs/OptimizeUploadedImage.php` içinde, uzun yorumlarıyla duruyordu. Bölünürken kodda
1–3 satırlık özet kaldı; aşağıda o günkü hâli yorumlarıyla **aynen** (anlam kaybı olmasın diye). Davranış değişmedi.

### Eski kodlama metotları

```php
    /** Kalite, hedefe inene kadar bu adimla dusurulur. */
    private const QUALITY_STEP = 10;

    /**
     * Hedef boyuta inene kadar kaliteyi basamak basamak dusurur.
     *
     * 2000 px'lik bir fotografi kodlamak JPEG'de ~70 ms, WebP'de ~410 ms
     * (olculdu), yani ikinci bir deneme ucuzdur. Taban `min_quality`: altinda
     * bozulma gorunur hale gelir ve hedefe inmek icin fotografi harcamaya
     * degmez — o dosya buyuk kalir.
     */
    private function compress(GdImage $image): ?OptimizedImage
    {
        $mimeType = $this->targetMimeType();
        $minQuality = Config::integer('davetkart.media.optimize.min_quality');
        $quality = $mimeType === 'image/webp'
            ? Config::integer('davetkart.media.optimize.webp_quality')
            : Config::integer('davetkart.media.optimize.jpeg_quality');

        while (true) {
            $bytes = $this->encode($image, $mimeType, $quality);

            if ($bytes === null) {
                return null;
            }

            if (strlen($bytes) <= $this->targetBytes() || $quality <= $minQuality) {
                return new OptimizedImage($bytes, $mimeType, $this->extensionFor($mimeType));
            }

            $quality = max($minQuality, $quality - self::QUALITY_STEP);
        }
    }

    /**
     * Gorseli baytlara cevirir; kodlayici yoksa null.
     *
     * Cikti tamponu: GD fonksiyonlari dosya yolu null iken DOGRUDAN ciktiya
     * yazar. ob_start/ob_get_clean onu tamamen yakalar — yani T3'un
     * ("testte cikti uretilmez") ihlali degildir.
     *
     * GD ya da bicim destegi yoksa null doner ve is SESSIZCE ama LOGLANARAK
     * gecer: kodlayamamak bir kullanici hatasi degil, bir ortam eksikligidir —
     * yuklemeyi geri almak icin sebep degil. (composer.json artik ext-gd
     * istiyor, yani bu durum yalnizca yanlis derlenmis bir PHP'de gorulur.)
     */
    private function encode(GdImage $image, string $mimeType, int $quality): ?string
    {
        if ($mimeType === 'image/webp' && ! function_exists('imagewebp')) {
            Log::warning('GD WebP destegi yok; gorsel optimizasyonu atlandi.', [
                'media' => $this->media->id,
            ]);

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

    /**
     * Bu dosyanin HEDEF bicimi.
     *
     * 🔴 PNG'nin karsiligi WebP. PNG kayipsizdir: bir fotograf 2000 px'e
     * indirildikten sonra bile ~3-5 MB kalir (olculdu), yani "2 MB'in altina
     * in" hedefi PNG'de ULASILAMAZ. WebP hem kayipli sikistirir hem alfa
     * kanalini tasir — JPEG'e cevirmek seffaf gorselleri siyahlatirdi.
     *
     * JPEG ve WebP kendi bicimlerinde kalir: bicim degisimi URL degisimi
     * demek, ve sebebi olmayan bir degisim yalnizca risk.
     */
    private function targetMimeType(): string
    {
        return $this->media->mime_type === 'image/png'
            ? 'image/webp'
            : $this->media->mime_type;
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function targetBytes(): int
    {
        return Config::integer('davetkart.media.optimize.target_kb') * 1024;
    }
```
