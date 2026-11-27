# `app/Support/Media/ImageResizer.php`

> Faz 21.12 · `ARC-10`. `OptimizeUploadedImage::downscale()`.

En uzun kenar `davetkart.media.optimize.max_edge_px`'e (2000) iner; genişlik değil, çünkü telefon fotoğraflarının çoğu
dikey. `IMG_TRIANGLE` iki geçişli ölçekleyicidir (ölçüldü: 24 MP → 2000 px, bilinear 112 ms, triangle 370 ms; kuyrukta,
kaliteye verildi). Ölçekleme başarısızsa (bellek) uyarı ve orijinalle devam. Testler: yatay 3000×2000 → 2000×1333, dikey
2000×3000 → 1333×2000, küçük görsel aynı nesne.

## Koddan taşınan notlar (Faz 21.12, `ARC-10`, K18)

Faz 21.12'ye kadar bu iş `app/Jobs/OptimizeUploadedImage.php` içinde, uzun yorumlarıyla duruyordu. Bölünürken kodda
1–3 satırlık özet kaldı; aşağıda o günkü hâli yorumlarıyla **aynen** (anlam kaybı olmasın diye). Davranış değişmedi.

### Eski `downscale()`

```php
    /**
     * En uzun kenari sinira indirir.
     *
     * 🔴 Asil kazanc burada: genisligi yariya indirmek piksel sayisini DORTTE
     * BIRE dusurur (alan = genislik x yukseklik). Yeniden kodlamanin kalite
     * ayarindan cok daha buyuk bir kazanc.
     *
     * Sinir GENISLIGE degil EN UZUN KENARA uygulanir. Genislik sinirlandiginda
     * 3000x4000'lik dikey bir fotograf 2000x2667 kaliyordu: hedeflenen 4 MP
     * yerine 5,3 MP. Dikey fotograf bir kenar durum degil, telefonla cekilen
     * fotograflarin cogunlugu.
     */
    private function downscale(GdImage $image, int $width, int $height): GdImage
    {
        $maxEdge = Config::integer('davetkart.media.optimize.max_edge_px');
        $longestEdge = max($width, $height);

        if ($longestEdge <= $maxEdge) {
            return $image;
        }

        $ratio = $maxEdge / $longestEdge;

        // 🔴 IMG_TRIANGLE, varsayilan IMG_BILINEAR_FIXED DEGIL. Varsayilan
        // yontem hedef pikseli kaynaktan ORNEKLEYEREK bulur: 4 kat kucultmede
        // aradaki pikselleri hic gormez ve dantel, sac, ince cizgi gibi
        // dokularda tirtik (aliasing) birakir. IMG_TRIANGLE iki gecisli
        // olcekleyiciyi kullanir, yani pencereye giren TUM pikselleri hesaba
        // katar. Olculdu (24 MP -> 2000 px): bilinear 112 ms, IMG_TRIANGLE
        // 370 ms. Aradaki 0,26 saniyeyi kaliteye veriyoruz — is kuyrukta,
        // kimse beklemiyor.
        $resized = imagescale(
            $image,
            max(1, (int) round($width * $ratio)),
            max(1, (int) round($height * $ratio)),
            IMG_TRIANGLE,
        );

        if ($resized === false) {
            // Bellek yetmemis olabilir. Optimize EDEMEMEK, yuklemeyi geri
            // almak icin sebep degil.
            Log::warning('Gorsel kucultulemedi.', ['media' => $this->media->id]);

            return $image;
        }

        $this->keepAlpha($resized);

        return $resized;
    }
```
