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
