# `app/Http/Requests/Media/MediaRequest.php`

> **Kod dosyası:** `app/Http/Requests/Media/MediaRequest.php`
> **Faz:** 6 — Medya dilimi, dosya 6.6
> **Alt sınıfları:** [`StoreMediaRequest.md`](StoreMediaRequest.md) ·
> [`StorePublicMediaRequest.md`](StorePublicMediaRequest.md)
> **Kardeş dosya:** [`../Invitation/InvitationRequest.md`](../Invitation/InvitationRequest.md)

---

## 1. Neden kurallar sabit değil?

Diğer FormRequest'lerde `rules()` sabit bir dizi döndürüyordu. Burada
döndüremez, çünkü **her türün kendi sınırları var**:

| Tür | Boyut | Kabul edilen MIME |
|---|---|---|
| `gallery` | 5 MB | jpeg, png, webp |
| `rsvp_photo` | 2 MB | jpeg, png, webp |
| `rsvp_video` | 20 MB | mp4, quicktime |

Yani doğrulama kuralları, **doğrulanacak veriye bağlı**. Bu bir tavuk-yumurta
problemi yaratıyor: `kind`'ı okumadan `file` kuralını yazamayız, ama `kind`
henüz doğrulanmamıştır.

---

## 2. 🔴 `resolveKind()` — doğrulanmamış veriyle çalışmanın kuralı

```php
private function resolveKind(): MediaKind
{
    $raw = $this->input('kind');

    $kind = is_string($raw) ? MediaKind::tryFrom($raw) : null;

    if ($kind !== null && in_array($kind->value, $this->allowedKinds(), true)) {
        return $kind;
    }

    return $this->strictestAllowedKind();
}
```

Üç savunma var ve üçü de **D2**'nin ("`prepareForValidation` içindeki veri
güvenilmezdir") aynı ailesinden:

**1. `is_string($raw)` — tip kontrolü.**
Saldırgan `kind[]=gallery` gönderirse `$raw` bir **dizi** olur. Kontrol
olmasaydı `(string) $raw` bir `TypeError` fırlatır ve kullanıcı `422` yerine
**500** görürdü. Faz 2'de `email[]=x` ile birebir aynı tuzak yaşanmıştı.

**2. `tryFrom()`, `from()` değil.**
`from()` geçersiz değerde `ValueError` fırlatır → 500. `tryFrom()` `null`
döndürür → akış devam eder, `in:` kuralı 422 üretir.

**3. Geçersizse en dar sınır.**
`kind` geçersizse `in:` kuralı zaten isteği reddedecek. Peki neden yine de bir
tür seçiyoruz? Çünkü Laravel **bütün kuralları** çalıştırır: `file` kuralı da
değerlendirilecek. O anda gevşek bir sınır (20 MB) seçseydik, hata mesajı
"dosya çok büyük değil ama tür geçersiz" gibi tutarsız bir tablo çizerdi.

En dar sınırı seçmek, "şüphede kal, dar tarafta dur" ilkesi — Faz 5'te
`TierRsvpQuotaResolver`'ın en dar planı varsaymasıyla aynı refleks.

---

## 3. 🔴 `mimetypes:` — `mimes:` değil

```php
'mimetypes:'.implode(',', $kind->allowedMimeTypes()),
```

Bu tek satır, Faz 6'nın en önemli güvenlik kararı.

| Kural | Neye bakar |
|---|---|
| `mimes:jpg,png` | Dosyanın **uzantısına** (ve tahmin edilen tipine) |
| **`mimetypes:image/jpeg,image/png`** ✅ | Dosyanın **içeriğine** — `finfo` ile ilk baytlar |

Neden fark ediyor? Çünkü **uzantıyı kullanıcı belirler**:

```
kotu.php  →  yeniden adlandır  →  masum.jpg
```

`mimes:` bunu yakalamayabilir. `mimetypes:` dosyanın ilk baytlarını okur —
JPEG'in `FF D8 FF`, PNG'nin `89 50 4E 47` ile başlaması gibi. Bu baytlara
**magic bytes** denir ve kullanıcı onları değiştiremeden dosyayı geçerli bir
görsel yapamaz.

> 🔴 `fileinfo` PHP eklentisi bunun ön koşulu. `docs/04` §1'de *"LCV
> yüklemelerinde ŞART"* diye işaretlenmişti; Faz 6'da o söz karşılığını buldu.
> Eklenti yoksa doğrulama **sessizce zayıflar**.

> ⚠️ **B6 — bunun kapatmadığı şey:** geçerli bir JPEG'in içine gömülmüş zararlı
> yük (polyglot dosyalar) bu kontrolden geçer. Asıl savunma, yüklenen dosyanın
> **çalıştırılamaz** olmasıdır — 6.7'de rastgele ad + içerikten türetilen uzantı,
> Faz 9'da sunucu yapılandırması.

---

## 4. `max:` neden kilobayt?

```php
'max:'.$kind->maxSizeKb(),
```

Laravel'de `max:` kuralının **birimi kural tipine göre değişir**:

| Alan tipi | `max:100` ne demek |
|---|---|
| String | 100 karakter |
| Sayı | değer ≤ 100 |
| Dizi | 100 eleman |
| **Dosya** | **100 kilobayt** |

Config'te değerler zaten `max_size_kb` adıyla duruyor — isim, birimi taşıyor.
Bu tesadüf değil: birim taşımayan bir isim (`max_size`) bir gün megabayt
sanılırdı.

> **PHP'nin kendi sınırı ayrı bir katman:** `upload_max_filesize` ve
> `post_max_size` aşılırsa istek Laravel'e **hiç ulaşmaz**; PHP onu daha
> önce keser. Laravel bunu `PostTooLargeException` olarak görür ve
> `ApiExceptionRenderer` zaten `FILE_TOO_LARGE` (413) koduna eşliyor (Faz 1).
> Yani iki farklı yerden gelen "çok büyük" cevabı **tek bir koda** çıkıyor.

---

## 5. `uploadedFile()` — neden `LogicException`?

```php
$file = $this->file('file');

if (! $file instanceof UploadedFile) {
    throw new LogicException('Validated media request has no uploaded file.');
}
```

Bu satıra **ulaşılmaması gerekir**: `required|file` kuralı çoktan elemiş
olmalı. O hâlde neden yazılıyor?

1. **PHPStan level 8** için: `file()` metodu `UploadedFile|array|null`
   döndürür. Tip daraltması olmadan `$file->getSize()` çağrısı hata verirdi.
2. **Sessiz `null` tehlikeli.** Eğer bir gün kurallar değişir ve `file`
   opsiyonel olursa, `null` sessizce akıp diske **boş dosya** yazmaya kadar
   giderdi.

`LogicException` seçimi bilinçli: bu bir **programlama hatası** (invariant
ihlali), kullanıcı hatası değil. `ApiExceptionRenderer` onu `SERVER_ERROR`
(500) yapar — doğru sınıflandırma, çünkü suç bizde.

Metodun adı `file()` **olamazdı**: `FormRequest::file()` zaten var. Üst
sınıfın metodunu farklı imzayla ezmek kovaryans hatası verirdi.

---

## 6. `allowedKinds()` — en az ayrıcalık, tek satırda

Soyut metot alt sınıflara tek bir soru soruyor: *"bu uç noktada hangi türler
serbest?"*

```php
StoreMediaRequest        → ['gallery']
StorePublicMediaRequest  → MediaKind::guestUploadableValues()
```

Bu ayrım **yetkilendirmeden önce** gelen bir savunma katmanı. Misafirin
`gallery` göndermesi, Policy'ye hiç ulaşmadan `422` ile durur.

Ve dikkat: public taraf listeyi **elle yazmıyor**, enum'dan türetiyor
(**C3**). İki liste olsaydı biri değişip diğeri unutulduğunda misafir galeriye
yükleyebilirdi — ve testler de aynı listeye baktığı için **hiçbir test bunu
söylemezdi**.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `mimes:` kullanmak | Uzantıya güvenilir; `kotu.php → masum.jpg` geçebilir |
| 2 | `is_string()` kontrolünü atlamak | `kind[]=x` → `TypeError` → 422 yerine **500** |
| 3 | `MediaKind::from()` kullanmak | Geçersiz değerde `ValueError` → 500 |
| 4 | Geçersiz `kind`'da en **gevşek** sınırı seçmek | Tutarsız hata tablosu |
| 5 | `Rule::enum()` kullanmak | Framework sınıf adı sözleşmeye sızar (**D6**) |
| 6 | `max:`'ı bayt sanmak | 5120 KB yerine 5 KB sınırı koyulur |
| 7 | `uploadedFile()` yerine `$this->file('file')`'ı doğrudan kullanmak | PHPStan level 8 hatası; `null` sızabilir |
| 8 | `allowedKinds()`'ı public tarafta elle yazmak | İki liste ayrışır; misafir galeriye yükleyebilir |

---

## 8. Kendin dene

```powershell
$token = "<owner token>"
$id    = "<davetiye ulid>"

# 1) Geçerli görsel -> 201
curl.exe -s -X POST "http://127.0.0.1:8000/api/media/upload" `
  -H "Authorization: Bearer $token" `
  -F "invitationId=$id" -F "kind=gallery" -F "file=@foto.jpg"

# 2) 🔴 PHP dosyasını .jpg diye yeniden adlandır ve gönder
Copy-Item kotu.php masum.jpg
curl.exe -s -X POST "http://127.0.0.1:8000/api/media/upload" `
  -H "Authorization: Bearer $token" `
  -F "invitationId=$id" -F "kind=gallery" -F "file=@masum.jpg"
# 422, rule = "mimetypes"   ← BEKLENEN
```

İkincisi `201` dönüyorsa `fileinfo` eklentisi yok ya da `mimes:` kullanılmış
demektir. **Bu, fazın en kritik elle kontrolüdür.**

```powershell
# 3) Dizi enjeksiyonu -> 500 DEĞİL 422 olmalı
curl.exe -s -X POST ".../api/media/upload" -H "Authorization: Bearer $token" `
  -F "invitationId=$id" -F "kind[]=gallery" -F "file=@foto.jpg"
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Magic bytes** | Dosya türünü belirleyen ilk baytlar |
| **`finfo`** | İçerikten MIME tespiti yapan PHP eklentisi |
| **Polyglot dosya** | Aynı anda iki formatta geçerli olan dosya |
| **En az ayrıcalık** | Bir aktöre yalnızca ihtiyacı olan yetkiyi vermek |
| **Invariant** | Kodun her zaman doğru varsaydığı koşul |
| **Kovaryans** | Alt sınıfın dönüş tipini daraltabilmesi |
| **`multipart/form-data`** | Dosya yüklemede kullanılan istek gövde biçimi |

---

## 10. Sırada ne var?

**6.7 — `StoreUploadedMediaAction`.** Rastgele ad üretimi, içerikten okunan
MIME'in **saklanması**, kota kontrolü (kilitli transaction) ve kuyruk
gönderimi.

| İlgili | Nerede |
|---|---|
| Tür enum'u | [`../../../Enums/MediaKind.md`](../../../Enums/MediaKind.md) |
| Kota exception'ı | [`../../../Exceptions/MediaQuotaExceededException.md`](../../../Exceptions/MediaQuotaExceededException.md) |
| Kardeş request | [`../Invitation/InvitationRequest.md`](../Invitation/InvitationRequest.md) |

---

## 🆕 Faz 9 — piksel sınırı ve PHP sınırının görünür kılınması

### 1. `dimensions` — boyut sınırının **yanında** duran ikinci sınır

```php
'dimensions:max_width=8192,max_height=8192'   // yalnızca görsel türlerde
```

İkisi **ayrı şeyi** ölçüyor:

| | `max:15360` | `dimensions:…8192` |
|---|---|---|
| Ölçtüğü | Kaç bayt | Kaç piksel |
| Koruduğu | Disk, bant genişliği | 🔴 Kuyruk işçisinin **belleği** |

50 KB'lık bir PNG 20000×20000 piksel açabilir: boyut kuralından rahatça geçer,
ama kuyruktaki iş onu çözmeye kalktığında piksel başına 4 bayt ister — ~1,6 GB
— ve işçiyi bellek hatasıyla öldürür. Buna **sıkıştırma bombası** deniyor ve
misafirin yükleme ucu auth'suzdur, yani bu bir konfor ayarı değil bir
**savunmadır**.

Üç ayrıntı:

- **Hazır kural kullanıldı.** `dimensions` dosyanın yalnızca başlığını okur
  (`getimagesize`), yani ucuzdur.
- **Kural nesnesi yazılmadı.** Metin kural olduğu için hata `dimensions` adıyla
  raporlanır; sınıf adı sözleşmeye sızmaz — `Rule::enum()` yerine `in:`
  kullanmakla (D6) aynı gerekçe.
- 🔴 **Videoya eklenmez.** `getimagesize` bir mp4'ün boyutlarını okuyamaz ve
  `false` döner: kural **her videoyu** reddederdi. Soru bu yüzden
  `$kind->isOptimizable()` — "işin göreceği türler".

Sınır, kuyruk işinin bellek bütçesiyle birlikte seçildi: 8192 × 8192 ≈ 67 MP ≈
270 MB çözme belleği, bütçe ise 512 MB
([`OptimizeUploadedImage.md`](../../../Jobs/OptimizeUploadedImage.md) §6).
Panorama fotoğrafları (12000+ px genişlik) bu sınıra takılır; bilinen ve kabul
edilmiş bir kenar durum.

### 2. §4'teki not eksikti: PHP'nin iki sınırı aynı sonucu vermiyor

Yukarıdaki §4 *"PHP'nin kendi sınırı aşılırsa `PostTooLargeException` → 413
`FILE_TOO_LARGE`"* diyor. Bu yalnızca **`post_max_size`** için doğru:

| Aşılan sınır | Ne olur | Yanıt |
|---|---|---|
| `post_max_size` | `ValidatePostSize` middleware'i isteği keser | **413** `FILE_TOO_LARGE` |
| `upload_max_filesize` | PHP dosyayı atar, isteğin geri kalanı gelir; Laravel'e hata kodu taşıyan **boş** bir dosya nesnesi ulaşır | **422** `VALIDATION_FAILED`, kural `uploaded` |

İkinci satır bu projeye bir gün kaybettirdi: uygulama 15 MB derken Herd'in
`php.ini`'si 2 MB diyordu ve yanıttaki *"dosya yüklenemedi"* mesajı **sebebi
söylemiyordu**.

### 3. `prepareForValidation()` — teşhis logu

```php
if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
    Log::warning('Dosya PHP sinirina takildi: php.ini degeri uygulama sinirindan dusuk.', [...]);
}
```

Yanıt sözleşmesi **değişmiyor** (hâlâ 422). Eklenen şey yalnızca teşhis:
`php_upload_max_filesize`, `php_post_max_size` ve türün kendi sınırı loga
yazılır. Bir yapılandırma hatasını kullanıcıya anlatmanın yolu yok, ama loga
yazmanın maliyeti de yok.

> **Neden 413'e çevirmedik?** Çünkü bu durum bir **sunucu yapılandırma
> hatasıdır**, kullanıcının gönderdiği dosyayla ilgili değil. Frontend zaten
> aynı 15 MB sınırını uyguluyor; buraya düşen bir istek, sunucunun yanlış
> ayarlandığını söyler. Kullanıcıya "dosyan çok büyük" demek yanlış bilgi
> olurdu.

**Test:** `MediaTest::a_photo_above_the_pixel_limit_is_rejected`,
`a_guest_photo_above_the_pixel_limit_is_rejected`,
`a_video_is_not_subject_to_the_pixel_limit`,
`a_file_dropped_by_the_php_limit_is_logged`.
