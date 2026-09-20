# `app/Jobs/DeleteReplacedMediaFile.php`

> **Kod dosyası:** `app/Jobs/DeleteReplacedMediaFile.php`
> **Faz:** 9 — Medya küçültme kapanışı
> **Birlikte değişen:** `app/Jobs/OptimizeUploadedImage.php` (bu işi kuyruğa atan yer),
> `config/davetkart.php` → `media.optimize.replaced_file_grace_hours`

---

## 1. Bir dakikalık özet

`OptimizeUploadedImage`, küçülttüğü görseli **yeni bir yola** yazıp satırı o
yola çevirir. Geriye eski dosya kalır. Bu iş onu siler — ama **hemen değil**.

```php
DeleteReplacedMediaFile::dispatch($disk, $previousPath)
    ->delay(now()->addHours(24));
```

---

## 2. 🔴 Neden gecikmeli?

Yükleme yanıtını almış olan sekme, elinde **eski URL'i** tutuyor:

- editördeki galeri önizlemesi (`applyGallery` yanıttaki `url`'i saklar),
- misafirin LCV formundaki "yüklendi" görseli.

Dosya optimizasyondan hemen sonra silinse, o açık sayfalarda **kırık görsel**
çıkardı. 24 saat boyunca iki dosya birlikte yaşar; sayfa yenilendiğinde
sunucudan yeni URL gelir.

Maliyeti küçük: geçici olarak iki kopya tutulur, ama orijinal 15 MB olsa bile
bu bir günlük depolama demektir.

> ⚠️ Bekleme süresini **kısaltmak** diski değil kullanıcıyı riske atar: sekmesi
> açık duran biri fotoğrafını kaybetmiş gibi görür. Faz 6'daki
> `orphan_grace_hours` kararıyla aynı takas — yanlış tarafa düşmek ucuz olan
> taraftır.

---

## 3. 🔴 Neden ayrı bir iş, neden zamanlanmış bir komut değil?

Zamanlanmış bir komut (`media:prune-orphans` gibi) *"hangi dosyalar artık
kullanılmıyor"* sorusunu **diski tarayarak** cevaplamak zorunda kalırdı. Yerel
diskte bu yavaş, S3'te ise binlerce listeleme isteği (ve para) demek.

Gecikmeli iş ise silinecek yolu **zaten biliyor**. Soru sormuyor, cevabı
taşıyor.

| | Zamanlanmış tarama | Gecikmeli iş ✅ |
|---|---|---|
| Hangi dosya? | Diski tara, satırlarla karşılaştır | Yol işin içinde |
| S3 maliyeti | Listeleme istekleri | Tek `delete` |
| Gecikme | Bir sonraki koşuya kadar | Tam istenen süre |

---

## 4. Model değil **metin** taşıyor

```php
public function __construct(
    public readonly string $disk,
    public readonly string $path,
) {}
```

`OptimizeUploadedImage` modeli kimliğiyle taşıyor (`SerializesModels`), bu iş
taşımıyor — ve fark bilinçli: bu işin işaret ettiği şey bir **satır** değil,
artık hiçbir satırın göstermediği bir **dosya**. Model geçirilse iş, silmesi
gereken yolu modelden okumaya çalışırdı; oysa model o yolu artık taşımıyor,
çevrildi.

---

## 5. 🔴 Silmeden önce sahiplik sorusu

```php
$stillInUse = Media::query()
    ->where('disk', $this->disk)
    ->where('path', $this->path)
    ->exists();

if ($stillInUse) {
    return;
}
```

İş **gecikmeli** koşuyor ve arada her şey olabilir: optimizasyon geri alınmış,
aynı yol başka bir satıra yazılmış ya da iş elle yeniden kuyruğa atılmış
olabilir. Bir satır o yolu gösteriyorsa dosya **yaşayan** bir dosyadır.

Sorgu ucuz: `media` tablosunda `UNIQUE(disk, path)` indeksi var (Faz 6, 6.2).

`Storage::delete()` olmayan dosyada da `true` döner; iş ikinci kez koşsa bile
hata üretmez — `PruneOrphanMedia`'daki idempotans gerekçesiyle aynı.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Eski dosyayı optimizasyonun içinde hemen silmek | Editörde ve LCV formunda kırık görsel |
| 2 | Sahiplik kontrolünü atlamak | Yeniden kullanılan bir yol silinir; yaşayan bir fotoğraf kaybolur |
| 3 | Model geçirmek | İş, çevrilmiş satırdan **yeni** yolu okur ve doğru dosyayı siler |
| 4 | `$tries` vermemek | Geçici bir disk hatası işi sonsuza kadar denettirir |

---

## 7. Kendin dene

```powershell
php artisan queue:work        # ayrı bir terminalde
```

```php
// php artisan tinker
$m = App\Models\Media::latest()->first();
$m->path;                     // optimizasyondan sonraki YENİ yol

// Gecikmeli iş kuyrukta bekliyor:
DB::table('jobs')->where('payload', 'like', '%DeleteReplacedMediaFile%')
    ->value('available_at');  // 24 saat sonrası
```

`available_at` sütunu, `database` sürücüsünde gecikmenin **nerede yaşadığını**
gösterir: işçi o zamana kadar bu işi hiç görmez.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Gecikmeli iş** | Kuyruğa girip belirli bir ana kadar çalıştırılmayan iş |
| **`available_at`** | `jobs` tablosunda işin çalışmaya hazır olacağı zaman |
| **Sahipsiz (yetim) dosya** | Diskte olan ama hiçbir satırın göstermediği dosya |
| **Idempotans** | Aynı işlemin tekrarının tek etki üretmesi |

---

## 9. Sırada ne var?

| İlgili | Nerede |
|---|---|
| Bu işi kuyruğa atan | [`OptimizeUploadedImage.md`](OptimizeUploadedImage.md) |
| Yetim misafir yüklemeleri | [`../Console/Commands/PruneOrphanMedia.md`](../Console/Commands/PruneOrphanMedia.md) |
| Ayarlar | [`../../config/davetkart.md`](../../config/davetkart.md) |
| Test | [`../../tests/Feature/OptimizeUploadedImageTest.md`](../../tests/Feature/OptimizeUploadedImageTest.md) |
