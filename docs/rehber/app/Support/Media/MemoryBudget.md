# `app/Support/Media/MemoryBudget.php`

> Faz 21.12 · `ARC-10`. `OptimizeUploadedImage::withMemoryLimit()`.

GD piksel başına ~4 bayt ister (24 MP ≈ 99 MB); CLI varsayılanı 128M. `run()` sınırı iş süresince
`davetkart.media.optimize.memory_limit`'e (512M) yükseltir ve **bitince geri yükler**: işçi uzun ömürlü bir süreçtir, sonraki
iş yükseltmeyi miras alsaydı kaçak bir tüketim 512 MB'a kadar sessizce büyürdü. Sınır sınırsızsa (-1) ya da bütçenin
üstündeyse dokunmaz. Neden kodda, `php.ini`'de değil? `php.ini` makineye ait, depoya girmez. Testte süreç 256M ile koşar
(`phpunit.xml`, Faz 21.1), yani yükseltme yolu testte de çalışır. Testler: içeride 512M, sonra 256M; -1 ve 1G'ye dokunmaz.

## Koddan taşınan notlar (Faz 21.12, `ARC-10`, K18)

Faz 21.12'ye kadar bu iş `app/Jobs/OptimizeUploadedImage.php` içinde, uzun yorumlarıyla duruyordu. Bölünürken kodda
1–3 satırlık özet kaldı; aşağıda o günkü hâli yorumlarıyla **aynen** (anlam kaybı olmasın diye). Davranış değişmedi.

### Eski `withMemoryLimit()`

```php
    /**
     * Bellek butcesini yukseltir, isi kosar ve eski siniri GERI YUKLER.
     *
     * 🔴 Neden kodda, php.ini'de degil? Cunku php.ini makineye aittir, depoya
     * girmez: ekipteki her gelistirici ve her sunucu ayni hatayla yeniden
     * karsilasirdi (phpstan'in memory-limit dersi ile ayni gerekce). Isin
     * ihtiyaci koddan okunabilir olmali.
     *
     * Neden geri yukluyoruz? Isci UZUN OMURLU bir surectir; bir sonraki is bu
     * yukseltmeyi miras alsaydi, kacak bir bellek tuketimi 512 MB'a kadar
     * sessizce buyuyebilirdi. Geri indirme guvenli: cozulmus gorsel
     * $callback'in cercevesiyle birlikte serbest kalir, yani kullanim eski
     * sinirin altina inmis olur (aksi halde ini_set uyari verirdi).
     *
     * @param  callable(): ?OptimizedImage  $callback
     */
    private function withMemoryLimit(callable $callback): ?OptimizedImage
    {
        $budget = Config::string('davetkart.media.optimize.memory_limit');
        $current = ini_get('memory_limit');

        // Mevcut sinir sinirsiz (-1) ya da butcenin ustundeyse dokunmuyoruz:
        // is asla bulundugu ortamin sinirini DUSURMEMELI.
        $shouldRaise = is_string($current)
            && ini_parse_quantity($current) > 0
            && ini_parse_quantity($current) < ini_parse_quantity($budget);

        if (! $shouldRaise) {
            return $callback();
        }

        ini_set('memory_limit', $budget);

        try {
            return $callback();
        } finally {
            ini_set('memory_limit', $current);
        }
    }
```
