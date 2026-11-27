# `app/Support/Media/GdAlpha.php`

> Faz 21.12 · `ARC-10`. `OptimizeUploadedImage::keepAlpha()`'dan ayrıldı.

İki bayrak birlikte: `imagealphablending(false)` (şeffaf pikseller çevirme/ölçeklemede siyahla karışmasın) ve
`imagesavealpha(true)` (kodlanırken alfa atılmasın). Bayraklar yeni görsele **miras kalmaz**; bu yüzden çözme, ölçekleme
ve döndürmeden sonra yeniden çağrılır (`ImageDecoder`, `ImageResizer`, `ExifOrientation`). Üç yerde aynı iki satır
yazılsaydı biri unutulurdu (C3); tek yer burası. Test: `ImagePipelineTest::gd_alpha_keeps_a_transparent_pixel_through_encoding`.

## Koddan taşınan notlar (Faz 21.12, `ARC-10`, K18)

Faz 21.12'ye kadar bu iş `app/Jobs/OptimizeUploadedImage.php` içinde, uzun yorumlarıyla duruyordu. Bölünürken kodda
1–3 satırlık özet kaldı; aşağıda o günkü hâli yorumlarıyla **aynen** (anlam kaybı olmasın diye). Davranış değişmedi.

### Eski `keepAlpha()`

```php
    /**
     * Seffaflik korunsun.
     *
     * Iki bayrak birlikte gerekir: alfa karistirmasi ACIK kalirsa seffaf
     * pikseller cevirme/olcekleme sirasinda siyahla karisir; `saveAlpha`
     * olmazsa da alfa kanali kodlanirken atilir. Her donusumden sonra
     * tekrarlaniyor cunku bayraklar YENI gorsele miras kalmiyor.
     */
    private function keepAlpha(GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
```
