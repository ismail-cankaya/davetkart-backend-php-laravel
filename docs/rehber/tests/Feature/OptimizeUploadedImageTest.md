# `tests/Feature/OptimizeUploadedImageTest.php` — Eğitim Dokümanı

> **Kapsanan dosyalar:** `app/Jobs/OptimizeUploadedImage.php`,
> `app/Jobs/DeleteReplacedMediaFile.php`
> **Faz:** 9 — Medya küçültme kapanışı
> **Bağlantılı:** [`OptimizeUploadedImage.md`](../../app/Jobs/OptimizeUploadedImage.md),
> [`DeleteReplacedMediaFile.md`](../../app/Jobs/DeleteReplacedMediaFile.md)

---

## 0. Bir dakikalık özet

Faz 6'da küçültme işinin `handle()` metodunun **hiçbir testi yoktu**; yalnızca
"iş kuyruğa atıldı mı" sınanıyordu (`MediaTest`). Kılavuzun mutasyon
bölümünde adı geçen `optimize_job_is_idempotent` testi de gerçekte mevcut
değildi — kılavuz ile kod ayrışmıştı.

Bu dosya o boşluğu kapatıyor: 19 test, işin **gerçek GD** ile ne yaptığını
sınıyor.

---

## 1. 🔴 Neden HTTP ucundan değil, `handle()` doğrudan çağrılıyor?

Projenin alışkanlığı uçtan uca test etmektir. Burada olmuyor, çünkü test
altyapısı iki yerde gerçeği taklit etmiyor:

| | Gerçek yükleme | `UploadedFile::fake()` |
|---|---|---|
| PHP yükleme katmanı | Dosya geçici klasöre yazılır | **Atlanır** |
| Görsel içeriği | Telefon fotoğrafı | `imagecreatetruecolor` → **boş (siyah) kare** |

Boş bir kare JPEG'de birkaç yüz bayta iner. Yani yükleme ucundan yapılan bir
test *"küçüldü mü"* sorusunu **hiç sormamış** olurdu: her dosya zaten küçüktür,
kalite basamağı hiç tetiklenmez, PNG dönüşümü anlamsızlaşır.

Bu yüzden görseller testte **elle** üretiliyor:

```php
private function photoJpeg(int $width, int $height, int $quality = 92): string
```

Yumuşak renk geçişleri + gürültü, sıkıştırılabilirliği gerçek bir fotoğrafa
yaklaştırır. `Storage::fake()` ile dosyalar bellekte yaşar, satır ise
`Media::factory()` ile kurulur.

---

## 2. 🔴 Kendini ölçekleyen test: kalite basamağı

Beklenen bayt sayısını sabit yazmak kırılgan bir test olurdu: GD, libjpeg ve
libwebp sürümleri farklı boyutlar üretir. Bunun yerine aynı kaynak **iki kez**
işlenir:

```php
$withDefaultTarget = ...;                                   // hedef 2 MB
Config::set('davetkart.media.optimize.target_kb', 1);       // ulaşılamaz hedef
$withTinyTarget = ...;
$this->assertLessThan($withDefaultTarget->size_bytes, $withTinyTarget->size_bytes);
```

İkinci dosya daha küçükse kalite gerçekten düşmüştür. Testin **bitmesi** ayrıca
sonsuz döngü olmadığını, son boyut kontrolü de dosyanın hâlâ çözülebilir bir
görsel olduğunu kanıtlar.

---

## 3. EXIF testi neden elle bayt kuruyor?

GD **EXIF yazamaz**. Depoya ikili bir örnek fotoğraf koymak ise testin neyi
sınadığını gizlerdi: dosyanın içinde ne olduğunu kimse göremez.

Bu yüzden APP1 segmenti elle kuruluyor:

```
FF E1 | uzunluk | "Exif\0\0" | TIFF başlığı (II 2A 00 | IFD0 ofseti 8)
      | girdi sayısı 1 | etiket 0112 | tip 3 (SHORT) | sayı 1 | değer | sonraki IFD 0
```

Kaynak 1200×600 (yatay), EXIF ise "90 derece çevir" diyor. Doğru sonuç
**600×1200**; yön uygulanmazsa çıktı 1200×600 kalır ve tarayıcı artık
çevirmez — çünkü yeniden kodlanan dosyada o not silinmiştir.

Aynı test çıktıda `Exif\0\0` işaretinin **kalmadığını** da doğruluyor: bu,
KVKK tarafının (konum bilgisinin silinmesi) kanıtı.

---

## 4. Testlerin haritası

| Test | Soru |
|---|---|
| `a_landscape_photo_is_reduced_to_the_longest_edge` | 3000×2000 → 2000×1333, dosya küçüldü mü? |
| `a_portrait_photo_is_reduced_by_its_height` | 🔴 Faz 6'nın genişlik hatası geri geldi mi? |
| `an_image_that_is_already_at_target_is_left_untouched` | Kısa yol: baytlar aynı, yol aynı, damga var |
| `quality_is_lowered_until_the_file_fits_the_target` | Kalite basamağı çalışıyor mu? |
| `the_exif_orientation_is_applied_and_the_metadata_is_dropped` | Yan yatma ve GPS |
| `a_file_with_exif_is_rewritten_even_when_it_does_not_shrink` | KVKK, boyut kazancı olmasa bile |
| `a_png_becomes_a_webp_and_keeps_its_transparency` | Biçim dönüşümü + alfa kanalı |
| `an_image_above_the_processing_limit_is_left_untouched` | Sıkıştırma bombası koruması |
| `an_unreadable_file_is_stamped_and_left_untouched` | Bozuk dosya bir kullanıcı hatası değil |
| `a_missing_file_is_not_stamped` | "İş kalmadı" ≠ "iş tamamlandı" |
| `a_video_is_never_processed` | T6: yokluğun testi |
| `optimize_job_is_idempotent` | Kuyruk "en az bir kez" teslim eder |
| `the_memory_limit_is_restored_after_the_job` | Uzun ömürlü işçi kirlenmesin |
| `a_row_deleted_mid_flight_leaves_no_file_behind` | Yarış + telafi |
| `a_gallery_swap_refreshes_the_public_invitation` | Misafir önbelleği |
| `an_rsvp_photo_swap_does_not_refresh_the_invitation` | Gereksiz temizlik yapılmıyor |
| `the_replaced_file_is_scheduled_for_deletion` | Gecikmeli silme kuyrukta |
| `the_cleanup_job_deletes_a_file_no_row_points_to` | Temizlik işi siliyor |
| `the_cleanup_job_keeps_a_file_that_is_still_in_use` | 🔴 Yaşayan dosyaya dokunmuyor |

---

## 5. Yarışı taklit etmek

```php
Media::query()->whereKey($media->getKey())->delete();   // satır gitti, dosya duruyor
$this->optimize($media);
$this->assertSame([$path], Storage::disk($this->disk())->allFiles());
```

Kuyrukta bu iş `deleteWhenMissingModels` sayesinde hiç **başlamazdı**. Test
`handle()`'ı doğrudan çağırarak yarışın **ortasını** taklit ediyor: dosya
okundu, satır gitti. Doğru davranış, yazılan yeni dosyanın silinmesidir —
yoksa diskte sahipsiz bir dosya kalırdı.

> `Media::query()->...->delete()` kullanılıyor, `$media->delete()` değil:
> ikincisi elimizdeki örneği de "silinmiş" işaretler ve `save()` çağrıları
> sessizce davranış değiştirirdi. Amaç **satırın** gitmesi, örneğin değil.

---

## 6. Yardımcı metot neden `optimize()`, `run()` değil?

İlk yazımda adı `run()` idi ve test paketi **hiç başlamadı**:

```
Fatal error: Cannot override final method PHPUnit\Framework\TestCase::run()
```

PHPUnit'in kendi `run()` metodu `final`dir. Test sınıflarında yardımcı metot
adları, üst sınıfın API'siyle çakışmamak zorunda.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `UploadedFile::fake()->image()` ile küçültme sınamak | Boş kare zaten küçük; test hiçbir şey ölçmez |
| 2 | Beklenen bayt sayısını sabit yazmak | GD/libjpeg sürümü değişince test kırılır |
| 3 | `Storage::fake()` çağırmamak | Testler depoya gerçek dosya yazar |
| 4 | Bellek testinde sınırı geri yüklememek | Sonraki testler 256M ile koşar, sebebi görünmez |
| 5 | `Event::fake()`'i tüm olaylar için açmak | Model olayları da susar, sebebi anlaşılmaz hatalar çıkar |

---

## 8. Kendin dene

**Mutasyon (kural 14):** `OptimizeUploadedImage::downscale()` içinde
`max($width, $height)` yerine `$width` yaz.

→ `a_portrait_photo_is_reduced_by_its_height` **kırılmalı**, diğerleri geçmeli.
Bu, Faz 6'daki gerçek hatanın birebir kendisidir.

İkinci mutasyon: `applyOrientation()` çağrısını sil.

→ `the_exif_orientation_is_applied_and_the_metadata_is_dropped` kırılmalı.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **EXIF** | Fotoğrafın içine gömülü meta veri (yön, tarih, konum) |
| **APP1** | JPEG içinde EXIF'in taşındığı segment |
| **Alfa kanalı** | Pikselin saydamlık bilgisi |
| **Sıkıştırma bombası** | Küçük dosyada devasa piksel açarak belleği tüketen saldırı |
| **Telafi (compensation)** | Transaction'a giremeyen bir yan etkiyi elle geri alma |
