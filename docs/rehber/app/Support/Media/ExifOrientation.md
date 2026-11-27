# `app/Support/Media/ExifOrientation.php`

> Faz 21.12 · `ARC-10`. `applyOrientation()`, `readOrientation()`, `rotate()`, `flip()`, `hasExifBlock()`.

- `read()`: JPEG'in EXIF yön etiketi (1–8), yoksa 1. `php://temp` akışı (2 MB'tan sonra dosyaya taşınır, ikinci büyük
  kopya yok); `@exif_read_data` bilinçli susturulur.
- `apply()`: etiketi **piksellere** uygular. Tarayıcı EXIF'i okur, GD okumaz; yeniden kodlanan dosyada not silinir ve
  pikseller dönmemiş kalır. Küçültmeden **sonra** çağrılır (`imagerotate` bir kopya daha ayırır: 48 MP'de 190 MB, 2000 px'de
  16 MB). `imagerotate` saat yönünün tersine döndürür: "90 CW" → 270.
- `hasBlock()` (statik): JPEG'in ilk 64 KB'ında `Exif\0\0`. İki soru için aynı cevap: kısa yolda "silinecek meta veri var
  mı", yazma kararında "silmek için yeniden yazmaya değer mi" (KVKK: konum).

Testler: 6 → 90° saat yönü (piksel konumuyla), 1 ve bilinmeyen değer dokunmaz, `hasBlock` yalnızca JPEG + başlık.

## Koddan taşınan notlar (Faz 21.12, `ARC-10`, K18)

Faz 21.12'ye kadar bu iş `app/Jobs/OptimizeUploadedImage.php` içinde, uzun yorumlarıyla duruyordu. Bölünürken kodda
1–3 satırlık özet kaldı; aşağıda o günkü hâli yorumlarıyla **aynen** (anlam kaybı olmasın diye). Davranış değişmedi.

### Eski yön ve EXIF metotları

```php
    /**
     * EXIF yon etiketini PIKSELLERE uygular.
     *
     * 🔴 Telefonlar fotografi sensorun gordugu gibi kaydeder ve "bunu
     * gosterirken 90 derece cevir" notunu EXIF'e yazar. Tarayicilar bu notu
     * okur, GD OKUMAZ: yeniden kodlanan dosyada hem not silinir hem pikseller
     * donmemis kalir — sonuc yan yatmis bir fotograftir. Hata ancak biz
     * dosyaya dokundugumuzda dogar, yani "optimizasyonun" kendi urettigi bir
     * hatadir.
     *
     * ⚠️ KUCULTMEDEN SONRA cagriliyor: imagerotate goruntunun BIR KOPYASINI
     * daha ayirir. 48 MP'lik kareyi cevirmek 190 MB daha isterdi; 2000 px'e
     * inmis kare icin ayni is 16 MB. En uzun kenar sinirini cevirme
     * degistirmedigi icin sira serbest — maliyet degil.
     *
     * Etiket degerleri (exiftool adlandirmasi) ve GD karsiliklari: imagerotate
     * SAAT YONUNUN TERSINE cevirir, bu yuzden "90 CW" -> 270.
     */
    private function applyOrientation(GdImage $image, string $contents): GdImage
    {
        return match ($this->readOrientation($contents)) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),           // yatay ayna
            3 => $this->rotate($image, 180),                         // 180
            4 => $this->flip($image, IMG_FLIP_VERTICAL),             // dikey ayna
            5 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 90),   // ayna + 270 CW
            6 => $this->rotate($image, 270),                         // 90 CW
            7 => $this->rotate($this->flip($image, IMG_FLIP_HORIZONTAL), 270),  // ayna + 90 CW
            8 => $this->rotate($image, 90),                          // 270 CW
            default => $image,
        };
    }

    /**
     * JPEG'in EXIF yon etiketi (1-8); yoksa ya da okunamazsa 1.
     *
     * Baytlar `php://temp` akisina yaziliyor: exif_read_data bir dosya ya da
     * akis ister, elimizdeki ise bellekteki bir metin. `php://temp` 2 MB'a
     * kadar bellekte kalir, sonrasinda kendini gecici dosyaya tasir — yani
     * 15 MB'lik bir fotograf icin ikinci bir 15 MB'lik kopya olusmaz.
     *
     * Susturma (@) bilincli: bozuk EXIF uyari uretir ve bu uyari hem loglari
     * doldurur hem testlerde `failOnWarning` ile suiteyi kirar. Yon
     * okunamiyorsa yapilacak sey zaten cevirmemektir.
     */
    private function readOrientation(string $contents): int
    {
        if ($this->media->mime_type !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

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

    /** imagerotate yeni bir gorsel uretir; basarisizsa elimizdekiyle devam. */
    private function rotate(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            Log::warning('Gorsel cevrilemedi.', ['media' => $this->media->id]);

            return $image;
        }

        $this->keepAlpha($rotated);

        return $rotated;
    }

    /** imageflip YERINDE calisir, yeni gorsel uretmez. */
    private function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    /** EXIF APP1 blogunun JPEG'in basinda arandigi pencere. */
    private const EXIF_SCAN_BYTES = 65_536;

    /**
     * Kaynakta EXIF blogu var mi?
     *
     * JPEG'de APP1 segmenti dosyanin BASINDA durur; ilk 64 KB'a bakmak yeterli
     * ve tam bir EXIF ayristiricisindan cok daha ucuz. Soru iki yerde ayni
     * anlama geliyor: kisa yolda "silinecek meta veri var mi", yazma
     * kararinda "silmek icin yeniden yazmaya deger mi".
     */
    private function hasExifBlock(string $contents): bool
    {
        if ($this->media->mime_type !== 'image/jpeg') {
            return false;
        }

        return str_contains(substr($contents, 0, self::EXIF_SCAN_BYTES), "Exif\0\0");
    }
```
