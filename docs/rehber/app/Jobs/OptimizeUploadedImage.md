# `app/Jobs/OptimizeUploadedImage.php`

> **Kod dosyası:** `app/Jobs/OptimizeUploadedImage.php`
> **Faz:** 6 — Medya dilimi, dosya 6.7
> **Birlikte değişen:** `config/davetkart.php` → `media.optimize`
> **Bu projede ilk kuyruk işi.** `app/Jobs/` klasörü bu dosyayla doğdu.

---

## 1. 🔴 "15 saniye kuralı" — bu işin var olma sebebi

`CLAUDE.md` §4:

> İsteğe hemen cevap verilmeli, uzun sürecek (resim optimizasyonu, mail
> gönderimi vb.) işlemler asla ana HTTP sürecini bekletmemeli ve `app/Jobs/`
> (Kuyruk) sistemine gönderilmelidir.

Sayı nereden geliyor? Frontend'in `axios` istemcisinden: `api.ts` timeout'u
**15 saniye**. Bir isteği ondan uzun tutarsak istemci bağlantıyı keser —
**ama sunucu işlemeye devam eder**. Kullanıcı "yükleme başarısız" görür, oysa
dosya yüklenmiştir. En kötü hata türü: sessizce tutarsız durum.

Akış şöyle ayrılıyor:

```
POST /api/media/upload
  ├─ dosyayı diske yaz          ~100 ms
  ├─ media satırı oluştur       ~5 ms
  ├─ işi kuyruğa at             ~2 ms
  └─ 201 + {url} DÖN            ← kullanıcı burada serbest
                                   ⋮
        [kuyruk işçisi]  gorseli yeniden kodla   ~1-5 sn
```

Kullanıcı **büyük** dosyanın URL'ini hemen alır ve önizlemede görür; birkaç
saniye sonra aynı URL **küçültülmüş** dosyayı gösterir. Hiçbir an beklemez.

---

## 2. `optimized_at` ne demek — ve ne demek değil

> ⚠️ `optimized_at`, *"bayt sayısı azaldı"* demek **değildir**.
> *"Optimizasyon geçişi tamamlandı"* demektir.

Zaten küçük ya da zaten optimize edilmiş bir görselde hiçbir şey değişmeyebilir
— ama iş koştu ve bir daha koşmasına gerek yok.

Bu tanımı yazmak zorundayız (**B6**), yoksa altı ay sonra biri
`WHERE optimized_at IS NOT NULL` sorgusuna bakıp *"demek ki hepsi küçüldü"*
der.

---

## 3. Idempotans — kuyruk mekanizmasıyla değil, **veriyle**

```php
if ($this->media->isOptimized()) {
    return;
}
```

Kuyruklar **en az bir kez** teslim eder: bir iş, işçi çökerse ya da zaman aşımı
olursa **tekrar** koşabilir. Yani her iş, ikinci kez koştuğunda zarar
vermemelidir.

Laravel'in hazır çözümü `ShouldBeUnique` arayüzüdür. Kullanmıyoruz:

| Yol | Nereye dayanır | Riski |
|---|---|---|
| `ShouldBeUnique` | **Cache** sürücüsü | Cache temizlenirse **sessizce** devre dışı kalır |
| `optimized_at` damgası ✅ | **Veritabanı** | Kalıcı; `RefreshDatabase` dışında kaybolmaz |

Bu, Faz 4'ün **O4** kuralıyla aynı refleks: *kuyruk kararı "yavaş mı" ile değil
"gecikirse/kaçarsa ne olur" ile verilir.*

---

## 4. 🔴 Yalnızca **küçüldüyse** yaz

```php
if ($optimized !== null && strlen($optimized) < $this->media->size_bytes) {
    $disk->put($this->media->path, $optimized);
    $this->media->size_bytes = strlen($optimized);
}
```

Yeniden kodlama bazen dosyayı **büyütür**. Örnekler:

- Zaten agresif sıkıştırılmış bir PNG, GD'nin varsayılan seviyesiyle büyür
- Küçük bir JPEG, kalite 82 ile yeniden kodlanınca büyüyebilir

Koşul olmasaydı "optimizasyon" adı altında dosyayı büyütürdük — **adın yalan
söylemesi**. Bu, Faz 4'ün 38. dersinin akrabası: *bir optimizasyon altındaki
hatayı düzeltmez, hızlandırır.*

Ve `size_bytes` yalnızca gerçekten yazdığımızda güncelleniyor — satır her zaman
**diskteki gerçeği** söylüyor.

---

## 5. Asıl kazanç: piksel sayısı

```php
private function downscale(\GdImage $image): \GdImage
```

Telefon kameraları 4000+ piksel genişliğinde üretir. Bir davetiye galerisinde
2000 piksel fazlasıyla yeterli.

Kazanç oranı sezgiye aykırı: genişliği yarıya indirmek piksel sayısını
**dörtte bire** düşürür (alan = genişlik × yükseklik). Yeniden kodlamanın
kalite ayarından çok daha büyük bir kazanç.

`imagescale()` `false` dönebilir (bellek yetersizliği) — o durumda orijinal
görselle devam ediyoruz. Optimize **edememek**, yüklemeyi geri almak için sebep
değil.

---

## 6. GD yoksa ne olur?

```php
if (! extension_loaded('gd')) {
    Log::warning('GD eklentisi yok; gorsel optimizasyonu atlandi.');

    return null;
}
```

Üç ayrı yerde "yapamıyorum" durumu var ve üçü de **exception fırlatmıyor**:

| Durum | Davranış | Neden |
|---|---|---|
| GD eklentisi yok | Log + geç | Ortam eksikliği; kullanıcının suçu değil |
| Görsel çözülemedi | Log + geç | Dosya bozuk olabilir; yükleme zaten kabul edildi |
| Dosya bulunamadı | Log + geç | Davetiye silinmiş olabilir — yapılacak iş kalmadı |

🔴 Ayrım önemli: **exception fırlatsaydık** iş `failed_jobs` tablosuna düşer ve
biri onu incelemek zorunda kalırdı. Oysa bunların hiçbiri müdahale gerektirmiyor.
`$tries = 3` gerçek geçici hatalar (disk meşgul, bellek) için duruyor.

---

## 7. Çıktı tamponu ve **T3**

```php
ob_start();
$written = imagejpeg($image, null, $quality);
$bytes = (string) ob_get_clean();
```

GD fonksiyonları dosya yolu `null` iken görüntüyü **doğrudan çıktıya** yazar.
`ob_start()` / `ob_get_clean()` onu tamamen yakalar.

Bu **T3**'ün ("testte çıktı üretilmez", `beStrictAboutOutputDuringTests`)
ihlali değildir — çünkü tek bir bayt bile dışarı sızmıyor. Ama okuyan biri
şüpheye düşeceği için yorumda açıkça yazıldı.

---

## 8. `public readonly Media $media` — model kuyruğa nasıl gider?

`Queueable` trait'i `SerializesModels`'i içerir: model **kimliğiyle**
serileştirilir, tüm nesne değil. İş koştuğunda veritabanından **taze** okunur.

Kazancı büyük: kuyrukta bekleyen bir iş, bu arada değişmiş veriyi görür. Bayat
bir nesne kopyasıyla çalışsaydı, Faz 4'ün 38. dersindeki gibi *"yanlış veriyi
daha verimli işlemek"* olurdu.

> ⚠️ Bunun yan etkisi: model **silinmişse** iş `ModelNotFoundException` ile
> başarısız olur. Laravel bunu varsayılan olarak "sessizce sil" (`deleteWhenMissingModels`)
> yapmaz; bizde de yapmıyoruz — çünkü `media` satırının kaybolması bir
> anormalliktir ve görülmesi gerekir.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Optimizasyonu Action içinde senkron yapmak | 15 saniye kuralı ihlali; kullanıcı bekler, istemci timeout'a düşer |
| 2 | `ShouldBeUnique` ile idempotans | Cache temizlenirse koruma sessizce kalkar |
| 3 | Büyüse de yazmak | "Optimizasyon" adı yalan söyler |
| 4 | GD yokken exception fırlatmak | `failed_jobs` gereksiz yere dolar |
| 5 | `optimized_at`'i "küçüldü" diye okumak | B6 — tanım yazılmazsa yanlış varsayım doğar |
| 6 | `ob_start()` olmadan `imagejpeg($image, null)` | Görüntü ham baytları HTTP yanıtına sızar |
| 7 | `imagedestroy()` çağırmamak | Uzun süren işçide bellek sızıntısı |
| 8 | `$tries` vermemek | Bozuk bir dosya kuyruğu sonsuza kadar tıkar |

---

## 10. Kendin dene

```powershell
# Kuyruk işçisi ayrı bir terminalde koşmalı
php artisan queue:work
```

```php
// php artisan tinker
$m = App\Models\Media::latest()->first();
$m->size_bytes;        // yükleme anındaki boyut
$m->optimized_at;      // null ise iş henüz koşmadı

App\Jobs\OptimizeUploadedImage::dispatch($m);
```

İşçi terminalinde işin koştuğunu gör, sonra:

```php
$m->refresh();
$m->optimized_at;      // damgalandı
$m->size_bytes;        // BÜYÜK bir fotoğraf yüklediysen küçülmüş olmalı
```

> `QUEUE_CONNECTION=sync` (testlerde) işi **anında** koşturur — kuyruk yokmuş
> gibi. `database` (yerelde) gerçek kuyruğa yazar ve `queue:work` gerektirir.
> İkisi arasındaki farkı görmek, kuyruğun ne yaptığını anlamanın en hızlı yolu.

**Mutasyon (kural 14):** `if ($this->media->isOptimized()) return;` satırını
sil ve işi iki kez dispatch et. `optimize_job_is_idempotent` testi kırılmalı.

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Kuyruk (queue)** | İşi sonraya bırakıp arka planda çalıştıran sistem |
| **İşçi (worker)** | Kuyruktaki işleri çeken uzun ömürlü süreç |
| **Idempotans** | Aynı işlemin tekrarının tek etki üretmesi |
| **En az bir kez teslim** | Kuyruğun bir işi ≥1 kez çalıştırabilmesi |
| **`SerializesModels`** | Modeli kimliğiyle saklayıp iş anında taze okuma |
| **Çıktı tamponu** | PHP'nin doğrudan çıktıyı yakalayan mekanizması |
| **GD** | PHP'nin yerleşik görüntü işleme eklentisi |
| **`failed_jobs`** | Tüm denemeleri tükenen işlerin tablosu |

---

## 12. Sırada ne var?

**6.8 — `StoreUploadedMediaAction`.** Bu işi kuyruğa atan yer. Orada rastgele
ad üretimi, içerikten okunan MIME'in saklanması ve kilitli kota kontrolü var.

| İlgili | Nerede |
|---|---|
| Model | [`../Models/Media.md`](../Models/Media.md) |
| Tür enum'u | [`../Enums/MediaKind.md`](../Enums/MediaKind.md) |
| Ayarlar | [`../../config/davetkart.md`](../../config/davetkart.md) |

---

## 🆕 Faz 9 — 15 MB'lık fotoğraflar ve işin yeniden kurulması

Faz 6'da bu iş bir **küçültme denemesiydi**: genişliği 2000 px'e indir, JPEG'i
82 kalitesiyle yeniden kodla, küçüldüyse **aynı dosyanın üzerine** yaz. Sınır 5
MB'dı ve iş yerelde hiç çalışmamıştı (kuyruk işçisi açık değildi), yani bu kod
ilk kez Faz 9'da gerçek bir telefon fotoğrafıyla karşılaştı.

Yükleme sınırı 15 MB'a çıkınca yedi karar değişti.

### 1. Ölçüm önce geldi

GD ile, sentetik ama fotoğrafa benzeyen görsellerle ölçüldü (Intel 12. nesil;
küçük bir VPS çekirdeğinde süreler ~2 kat uzar):

| Kaynak | Çöz + küçült + JPEG q82 | Tepe bellek | Çıktı |
|---|---|---|---|
| 12 MP · 4,3 MB | 0,25 sn | 59 MB | 625 KB |
| 24 MP · 8,7 MB | 0,34 sn | 99 MB | 506 KB |
| 48 MP · 17,4 MB | 0,54 sn | 187 MB | 644 KB |

Kodlayıcılar (2560 px çıktı): JPEG q82 **70 ms / 598 KB**, WebP q80
**409 ms / 398 KB**, PNG **530 ms / 5 066 KB**. PNG seviye 9 ise 3,5 saniye
sürüp %1 kazandırıyor — yani kullanılmaz.

Küçültme yöntemleri (24 MP → 2000 px): `IMG_BILINEAR_FIXED` **112 ms**,
`IMG_BICUBIC` 252 ms, `IMG_TRIANGLE` **370 ms**, `imagecopyresampled` 948 ms.

Üç sonuç:

1. **İşlemci ucuz.** Fotoğraf başına yarım saniyeden az. Tek bir işçi saatte
   binlerce fotoğraf işler; ek sunucu gerekmez.
2. **Asıl kısıt bellek.** Çözme megapiksel başına **~4 MB** istiyor. CLI'ın
   varsayılan `memory_limit` değeri 128 MB'dır: 24 MP'lik bir fotoğraf (99 MB
   + Laravel'in kendi kullanımı) işçiyi oracıkta çökertir.
3. **Kalite parayla ölçülebilir hale geldi.** `IMG_TRIANGLE`'ın bilinear'a göre
   maliyeti 0,26 saniye; kuyrukta kimse beklemediği için bu takas kolaydı.

### 2. 🔴 Kısa yol — sıkıştırmayı **önce tarayıcı** yapar

Faz 9'da frontend, dosyayı göndermeden önce en uzun kenarı 2000 px'e indirip
kaliteyi düşürüyor (`utils/compressImage.ts`). Yani buraya gelen dosya
**genelde zaten hedeftedir**.

```php
if ($this->alreadyAtTarget($contents, $width, $height)) {
    $this->stamp();

    return;
}
```

Dört koşul birden aranır: hedef biçimde, hedef boyutun altında, hedef ölçünün
altında ve üzerinde silinecek meta veri yok. Hepsi tutarsa görsel **hiç
çözülmez** — işin maliyeti tek bir dosya başlığı okumasına iner.

> Sıra önemli: boyutlar `getimagesizefromstring()` ile öğrenilir, bu da
> yalnızca ilk baytları okur. Hem kısa yolu hem bellek korumasını mümkün kılan
> şey budur.

### 3. Sınır genişliğe değil **en uzun kenara**

Faz 6'daki `max_width_px` dikey fotoğrafı serbest bırakıyordu: 2000×3000'lik
bir kare *"genişliği 2000, sınır 2000"* diye **hiç küçültülmüyordu** — oysa
6 MP'lik bir dosyaydı. Telefonla çekilen fotoğrafların çoğunluğu dikeydir, yani
bu bir kenar durum değil **ana durumdu**.

`max_edge_px` ile 2000×3000 → 1333×2000 olur. Sayının kendisi frontend'den
geliyor: galeri en fazla 448 CSS px genişlikte gösteriliyor (`Gallery.tsx`,
`max-w-md`), 3× ekranda bile 1344 px yeter.

### 4. EXIF yönü — "optimizasyonun" kendi ürettiği hata

Telefon, fotoğrafı sensörün gördüğü gibi kaydeder ve *"gösterirken 90 derece
çevir"* notunu EXIF'e yazar. Tarayıcılar bu notu okur, **GD okumaz**.

Sonuç ters bir zincir: dosyaya dokunmasak fotoğraf **doğru** görünür; yeniden
kodlarsak hem not silinir hem pikseller dönmemiş kalır ve fotoğraf **yan
yatar**. Yani hatayı kullanıcı değil, bizim optimizasyonumuz üretir.

```php
6 => $this->rotate($image, 270),   // "90 CW" — imagerotate saat yönünün TERSİNE çevirir
```

İki ayrıntı:

- **Küçültmeden sonra** çevriliyor. `imagerotate` görüntünün bir kopyasını daha
  ayırır: 48 MP'lik kareyi çevirmek 190 MB daha isterdi, 2000 px'e inmiş kare
  için aynı iş 16 MB. En uzun kenar sınırını çevirme değiştirmediği için sıra
  serbesttir — maliyet değil.
- EXIF `php://temp` akışından okunuyor. `exif_read_data` dosya ya da akış
  ister; elimizdeki ise bellekteki bir metin. `php://temp` 2 MB'a kadar
  bellekte kalır, sonra kendini geçici dosyaya taşır — 15 MB'lık bir fotoğraf
  için ikinci bir 15 MB'lık kopya oluşmaz.

### 5. PNG → WebP

PNG **kayıpsızdır**: 2000 px'e indirilmiş bir fotoğraf PNG olarak ~3-5 MB
kalır. Yani "2 MB'ın altına in" hedefi PNG'de **ulaşılamaz**.

| Yol | Sonuç |
|---|---|
| PNG kal | Hedefe inilemez |
| JPEG'e çevir | Şeffaf görseller **siyahlanır** |
| WebP'ye çevir ✅ | Kayıplı sıkıştırır **ve** alfa kanalını taşır |

JPEG ve WebP kendi biçimlerinde kalır: biçim değişimi URL değişimi demek ve
sebebi olmayan bir değişim yalnızca risktir.

### 6. Bellek bir **yapılandırmadır**, umut değil

```php
ini_set('memory_limit', $budget);
try { return $callback(); } finally { ini_set('memory_limit', $current); }
```

Neden php.ini'de değil kodda? Çünkü php.ini **makineye** aittir, depoya
girmez: ekipteki her geliştirici ve her sunucu aynı çökmeyle yeniden
karşılaşırdı (`docs/rehber/phpstan.md`'deki `memory-limit` dersiyle aynı
gerekçe). İşin ihtiyacı koddan okunabilir olmalı.

Neden geri yüklüyoruz? İşçi **uzun ömürlü** bir süreçtir; bir sonraki iş bu
yükseltmeyi miras alsaydı kaçak bir tüketim 512 MB'a kadar sessizce büyüyebilirdi.
Geri indirme güvenli: çözülmüş görsel `$callback`'in çerçevesiyle serbest kalır,
yani kullanım eski sınırın altına inmiş olur — aksi hâlde `ini_set` uyarı verir
ve `failOnWarning` yüzünden testler kırılırdı.

İkinci katman `max_dimension_px`: doğrulamadaki `dimensions` kuralının ikizi.
Kural bugün her yüklemeyi eliyor, ama kuyrukta o kuraldan geçmemiş satırlar
olabilir (kural eklenmeden önce yüklenmiş dosyalar, elle açılmış işler).

### 7. 🔴 Yeni yol + kilitli çevirme (üzerine yazmanın sonu)

Faz 6 aynı dosyanın üzerine yazıyordu ve kılavuz bunu bir **özellik** olarak
anlatıyordu: *"kullanıcı aynı URL'de birkaç saniye sonra küçüğü görür."* Üç
sebep bu kararı tersine çevirdi:

1. **Uzantı.** PNG → WebP dönüşümünde içerik değişiyor; içeriği WebP olan bir
   `.png` dosyası, uzantıyı içerikten üreten yükleme katmanına ters düşerdi.
2. **Yarış.** İş dosyayı okuduktan sonra fotoğraf silinirse, üzerine yazmak
   dosyayı **sahipsiz olarak geri yaratır** (satır yok, dosya var). Bu delik
   Faz 6'dan beri açıktı.
3. **Önbellek.** CDN ve tarayıcı aynı URL altında **büyük orijinali** tutabilir;
   küçültülmüş sürüm TTL dolana kadar hiç görünmeyebilir.

Bugünkü akış:

```
yeni yola yaz  →  DB::transaction
                    ├─ davetiye satırını lockForUpdate ile KİLİTLE
                    ├─ medya satırını kilit altında YENİDEN OKU
                    │    ├─ satır yok      → çevirme yok
                    │    └─ yol değişmiş   → iş ikinci kez koşuyor, çevirme yok
                    └─ path / mime_type / size_bytes / optimized_at güncelle
  ├─ çevirme olmadıysa → yazdığımız dosyayı SİL (telafi)
  ├─ galeri öğesiyse   → InvitationChanged  (misafir önbelleği)
  └─ eski dosya        → DeleteReplacedMediaFile, gecikmeli
```

Kilit **davetiye** satırıdır: `StoreUploadedMediaAction` ve
`DeleteGalleryMediaAction` da aynı ortak kilidi alıyor, böylece "sil" ile
"çevir" sıraya girer. PostgreSQL'in READ COMMITTED seviyesinde var olmayan bir
satır kilitlenemediği için kilitlenebilecek tek ortak nesne **üst kayıttır**.

> **`touch()` değil `event()`.** Önbelleği temizlemenin kolay yolu davetiyeye
> `touch()` atmaktı; kullanılmadı. `updated_at` frontend'de **"son kaydetme"**
> olarak gösteriliyor ve bir arka plan işi kullanıcının kaydetme zamanını
> kaydırmamalı.

### 8. Eski dosya neden hemen silinmiyor?

Editördeki sekme, yükleme yanıtından gelen **eski URL'i** elinde tutuyor: galeri
önizlemesi ve misafirin LCV formundaki "yüklendi" görseli. Dosya o anda silinse
açık sayfalarda kırık görsel olurdu. Bu yüzden silme
[`DeleteReplacedMediaFile`](DeleteReplacedMediaFile.md)'e devredilir ve
`replaced_file_grace_hours` (24 saat) kadar geciktirilir.

### Faz 6 kılavuzundaki iki not artık geçersiz

| Nerede | Eski not | Bugün |
|---|---|---|
| §8 | "`deleteWhenMissingModels`… bizde de yapmıyoruz" | Kod Faz 6'dan beri `public bool $deleteWhenMissingModels = true;` taşıyor — kılavuz ile kod ayrışmıştı. Gerekçe sınıf yorumunda: galeriden silme, yüklemeden saniyeler sonra gelebilir. |
| §9 no 7 | "`imagedestroy()` çağırmamak → bellek sızıntısı" | `imagedestroy()` PHP 8.0'dan beri **etkisiz**, PHP 8.5'te **deprecated**. Çağrılar kaldırıldı; nesneler kapsam bitince ya da `unset()` ile serbest kalıyor. |

### Kapsam dışı (B6)

- **Video:** Faz 6'nın kararı korunuyor; transcode ffmpeg ister.
- **HEIC:** GD çözemez. iOS Safari yüklerken zaten JPEG'e çeviriyor, Android'de
  gelen bir HEIC `mimetypes` kuralına takılır.
- **Animasyonlu WebP:** GD ilk kareyi bile güvenilir çözmez; dosya
  çözülemediğinde iş loglar, damgalar ve geçer — dosya olduğu gibi kalır.
- **Çoklu boyut (thumbnail/srcset):** Galeri tek bir ölçü kullanıyor.
- **libvips/Imagick:** Yükleme sırasında küçülterek belleği çok düşürür
  (shrink-on-load), ama Herd Windows'ta yok ve barındırma henüz belli değil.

**Test:** `tests/Feature/OptimizeUploadedImageTest.php` —
[kılavuzu](../../tests/Feature/OptimizeUploadedImageTest.md)
