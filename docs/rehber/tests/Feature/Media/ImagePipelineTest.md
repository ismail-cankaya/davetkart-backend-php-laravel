# `tests/Feature/Media/ImagePipelineTest.php`

> Faz 21.12 · `ARC-10`. `OptimizeUploadedImage`'den ayrılan sınıfların birim testleri (uygulama açılır: `Config`, `Log`,
> `Storage`, kuyruk sahtesi gerekiyor). Uçtan uca davranış `OptimizeUploadedImageTest`'te ve aynen yeşil.

| Sınıf | Test | Öldürdüğü mutant |
|---|---|---|
| `GdAlpha` | `gd_alpha_keeps_a_transparent_pixel_through_encoding` | alfa karıştırması açık kalır |
| `ImageDecoder` | palet → truecolor · çöp → `null` | palet dönüşümü yok |
| `ImageResizer` | en uzun kenar (yatay ve dikey) · küçük görsel aynı nesne | genişlik sınırı |
| `ExifOrientation` | 6 → 90° saat yönü (piksel konumu) · 1 ve 9 dokunmaz · `hasBlock` yalnızca JPEG + başlık | 6 → ters yön · `hasBlock` biçime bakmaz |
| `ImageEncoder` | hedef biçimler · uzantı biçimden · ulaşılamaz hedefte kalite tabana iner | PNG hedefi JPEG · kalite düşürülmez |
| `MemoryBudget` | içeride 512M, sonra 256M · -1 ve 1G'ye dokunmaz | geri yüklemez · sınırsızı düşürür |
| `SwapOptimizedMediaFileAction` | yeni yol + eski dosya duruyor + silme işi kuyrukta · satır ilerlemişse geri çekilir ve yazdığı dosyayı siler | telafi silmesi yok · silme işi kuyruğa girmez |

`OptimizeUploadedImageTest::a_php_trailer_after_exif_is_dropped_with_the_rewrite` (Faz 21.12, AUD P55) ve işteki
`isWorthWriting` EXIF kolu: "EXIF'li dosya yeniden yazılmaz" mutantı iki uçtan uca testle öldü.
