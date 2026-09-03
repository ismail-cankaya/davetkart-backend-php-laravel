# `app/Enums/ErrorCode.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Enums/ErrorCode.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.1 (fazın ilk dosyası)
> **Bağlantılı:** [`docs/08-HATA-SOZLESMESI.md`](../../../08-HATA-SOZLESMESI.md) ·
> [`SubscriptionTier.md`](SubscriptionTier.md) (enum temelleri orada anlatıldı)

---

## 0. Bir dakikalık özet

Bu dosya, API'nin dışarıya söyleyebileceği **her hatanın listesi**dir.

Backend bir hata olduğunda "E-posta zaten kullanılıyor" gibi bir **cümle** kurmaz.
`REGISTRATION_FAILED` gibi bir **kod** döner. Cümleyi frontend kurar, çünkü 10 dilin
sözlüğü zaten orada.

Enum üç şey bilir:

| Metot | Sorusu |
|---|---|
| `status()` | Bu hatanın HTTP karşılığı kaç? |
| `allowedParams()` | Bu hata dışarıya hangi ek bilgiyi verebilir? |
| `filterParams()` | Verilmek istenen bilgiyi **süz** — listede yoksa at |

---

## 1. Neden bu dosya en başta yazıldı?

Faz 1'in altı dosyasından beşi bu enum'a referans verir. Ama asıl sebep sıralama
değil, **tekrarın önlenmesi**.

Bu dosya olmasaydı, hata üretimi şöyle dağılırdı:

```php
// AuthController
return response()->json(['error' => ['code' => 'INVALID_CREDENTIALS']], 401);

// RsvpController — üç ay sonra, başka bir gün
return response()->json(['error' => ['code' => 'INVALID_CREDENTAILS']], 400);
//                                             ^^ yazım hatası      ^^ yanlış durum
```

İki hata var ve **ikisi de sessiz**. PHP `'INVALID_CREDENTAILS'` string'ini kabul
eder; yanlış olduğunu ancak kullanıcı şikâyet edince öğrenirsin. `401` yerine `400`
yazılması ise frontend'in oturum düşürme mantığını bozar.

Enum ile aynı iki hata **imkânsız** hâle gelir:

```php
ErrorCode::InvalidCredentails;   // PHP: "Undefined constant" — anında patlar
ErrorCode::InvalidCredentials->status();   // her zaman 401, tek yerden
```

> **Genel ilke:** Bir bilgi iki yerde yazılabiliyorsa, er ya da geç iki farklı
> şey yazılır. Tek doğruluk kaynağı (single source of truth) bunu yapısal olarak
> engeller.

---

## 2. PHP temelleri (bu dosyaya özel olanlar)

`namespace`, `enum`, `match` ve `$this` [`SubscriptionTier.md`](SubscriptionTier.md)
§1'de anlatıldı. Burada **yeni** olanlar var.

### 2.1 `match` içinde çoklu koşul

```php
self::Unauthenticated,
self::InvalidCredentials,
self::TokenExpired => 401,
```

Virgülle ayrılmış birden fazla değer **aynı sonuca** bağlanabilir. Bu, üç ayrı satır
yazmaktan hem kısa hem de niyeti açık: "bu üçü aynı sınıfa ait."

TypeScript'teki `switch` fall-through'una benzer ama tehlikesi yok — `break` unutma
diye bir şey PHP `match`'te mümkün değildir.

### 2.2 `match` neden `switch` değil?

```php
match ($this) { ... }
```

Üç farklı sebep:

| | `switch` | `match` |
|---|---|---|
| Karşılaştırma | `==` (gevşek) | `===` (katı) |
| Değer döndürür mü | Hayır, atama gerekir | **Evet**, ifadedir |
| Eksik durum | Sessizce düşer | **`UnhandledMatchError`** fırlatır |

🔴 Üçüncü satır bu dosyanın can damarı. `status()` metodunda `default` **bilerek
yok**. Yarın enum'a yeni bir case eklersen ve `status()`'ta karşılığını yazmayı
unutursan, PHP çalışma anında patlar. Unutmak mümkün değildir.

`allowedParams()`'ta ise `default => []` **var** — çünkü orada varsayılan davranış
anlamlı: "hiçbir parametre verme." İkisi arasındaki fark bilinçlidir.

### 2.3 `array_flip` + `array_intersect_key`

```php
array_intersect_key($params, array_flip($this->allowedParams()));
```

Adım adım. Elimizde `['requiredTier' => 'elit', 'sql' => 'SELECT ...']` var ve
beyaz liste `['requiredTier']`.

```php
$this->allowedParams()          // ['requiredTier']            ← liste (0,1,2...)
array_flip([...])               // ['requiredTier' => 0]       ← anahtar-değer takla attı
array_intersect_key($p, $flip)  // ['requiredTier' => 'elit']  ← ANAHTARLARI kesiştir
```

`array_flip` anahtarlarla değerlerin yerini değiştirir. Neden gerekli?
`array_intersect_key` **anahtarlara** bakar, değerlere değil. Beyaz listemiz düz bir
liste olduğu için önce anahtar hâline getirilmesi gerekiyor.

Sonuç: `sql` anahtarı **sessizce düştü**. `filterParams()` çağrıldığı sürece o veri
dışarı çıkamaz.

### 2.4 `in_array`'in üçüncü argümanı

```php
in_array($this->status(), [429, 502, 503], true);
//                                          ^^^^ strict
```

`true` olmadan PHP gevşek karşılaştırma yapar ve `in_array('429abc', [429])` **true**
döner. Üçüncü argüman `===` kullanılmasını zorlar. **Her zaman yazılır.**

### 2.5 Docblock'taki `list<string>` ne işe yarar?

```php
/** @return list<string> */
```

PHP bunu okumaz — çalışma anında hiçbir etkisi yoktur. Ama **PHPStan** okur.

- `array` → "içinde ne olduğu belirsiz bir dizi"
- `list<string>` → "0'dan başlayan ardışık sayısal anahtarlar, değerleri string"
- `array<string, mixed>` → "anahtarları string, değerleri her şey olabilir"

PHPStan level 5'te bu ayrım hâlihazırda işe yarıyor; level 8'de (Faz 5) zorunlu
hâle gelecek. Şimdiden doğru yazmak, o gün yüzlerce hatayla karşılaşmamayı sağlar.

---

## 3. Tasarım kararları

### 3.1 Neden HTTP durumu enum'un içinde?

Alternatif, controller'larda `if` zinciri yazmaktı:

```php
// ❌ Yapmadığımız
if ($code === ErrorCode::ResourceNotFound) { $status = 404; }
elseif ($code === ErrorCode::RateLimited) { $status = 429; }
// ... 19 satır, üstelik her controller'da tekrar
```

Her `code`'un **tek** bir HTTP karşılığı var. Bu bir "duruma göre değişen" bilgi
değil, kodun **kimliğinin parçası**. Kimliğin parçası olan bilgi nesnenin kendisinde
durur.

Buna nesne yönelimli tasarımda **"Tell, Don't Ask"** denir: nesneye durumunu sorup
dışarıda karar vermek yerine, nesneye kararı sorarsın.

```php
$code->status();   // ✅ nesne kendi cevabını biliyor
```

### 3.2 🔴 Neden `label()` yok?

`SubscriptionTier`'da `label()` metodu var — `'Gold'` döndürüyor. `ErrorCode`'da
**kasten yok**.

Sebep K20'nin ta kendisi: bir `label()` metodu yazarsak, birileri onu bir gün
API yanıtına koyar. Metnin var olmaması, sızmasının **imkânsız** olması demektir.

> Bu, Faz 0'dan beri tekrarlanan temanın aynısı: **güvenliği disipline değil
> yapıya bağlamak.** `debug` bloğunun `APP_DEBUG`'a bağlı olması da,
> `filterParams()`'ın beyaz listeyi zorlaması da aynı fikrin uygulamalarıdır.

### 3.3 `allowedParams()` — neden varsayılan boş?

```php
default => [],
```

Güvenlikte iki tasarım yönü vardır:

| Yaklaşım | Varsayılan | Yeni bir şey eklendiğinde |
|---|---|---|
| **Kara liste** | Her şey açık | Yasaklamayı unutursan **sızar** |
| **Beyaz liste** ✅ | Her şey kapalı | İzin vermeyi unutursan **çalışmaz** |

İkisi de hata yapmaya açık, ama hatanın **maliyeti** farklı. Beyaz listede hata
"özellik eksik" olarak görünür ve düzeltilir; kara listede "veri sızdı" olarak
görünür ve çoğu zaman hiç görünmez.

### 3.4 🔴 `filterParams()` — kural neden koda yazıldı?

`08-HATA-SOZLESMESI.md` §3.4 şunu diyor: *"`remaining` ve `limit` sadece davetiye
sahibine verilir."*

Bu bir **belge cümlesi**. Belgeler okunmayabilir. Faz 5'te `SubmitRsvpAction`'ı
yazan kişi (yani sen, iki ay sonra) bunu hatırlamayabilir:

```php
// Faz 5'te yazılacak, iyi niyetli ama yanlış kod
throw new RsvpQuotaExceededException(remaining: 3, limit: 100, sql: $query);
```

`filterParams()` devrede olduğu sürece `sql` anahtarı yanıta **giremez** — çünkü
`allowedParams()` listesinde adı geçmiyor. Kural belgede değil, çağrı yolunun
üzerinde duruyor.

> **Not:** "sadece sahibine" kısmının *kim olduğu* bilgisi enum'da değil, Faz 5'teki
> Action'da belirlenir. Enum "bu iki anahtar teknik olarak verilebilir" der;
> "şu anki isteyene verilir mi" sorusunu iş kuralı cevaplar. Katman ayrımı.

### 3.5 `isRetryable()` neden var?

429, 502 ve 503 **geçici** hatalardır — aynı istek biraz sonra başarılı olabilir.
400 veya 422 ise kalıcıdır; tekrar denemek aynı sonucu verir.

Frontend bu ayrımı yaparak otomatik yeniden deneme (retry with backoff)
uygulayabilir. Ayrımı burada yapmak, frontend'in durum kodu listesini kendi
tarafında tekrar yazmasını önler.

---

## 4. 🔴 Sektör standardıyla hizalama

Kullanıcının isteği üzerine kodlar sektör pratiğine göre gözden geçirildi.
İki değişiklik yapıldı, bir sapma bilinçli olarak korundu.

### 4.1 Değişiklik: `PAYMENT_PROVIDER_ERROR` 503 → **502**

`08-HATA-SOZLESMESI.md` §4 tüm sağlayıcı hatalarını 503'e koymuştu. RFC 9110'a göre:

| Durum | Anlamı | Bizim durumumuz |
|---|---|---|
| **502** Bad Gateway | Aracı sunucu, **yukarı akıştan geçersiz yanıt** aldı | Iyzico "hata" dedi → **502** |
| **503** Service Unavailable | **Bu sunucu** aşırı yüklü veya bakımda | Gemini'ye hiç ulaşılamıyor → 503 |
| **504** Gateway Timeout | Yukarı akış zamanında cevap vermedi | (şimdilik kullanılmıyor) |

Ödeme akışında biz bir **gateway**'iz: isteği Iyzico'ya iletiyoruz. Iyzico hata
döndüğünde sorun bizde değil, aracılık ettiğimiz serviste — bu tam olarak 502'nin
tanımıdır. 503 demek, kendi sunucumuzun çöktüğünü söylemek olurdu ve izleme
(monitoring) alarmlarını yanlış yere yönlendirirdi.

`PROVIDER_UNAVAILABLE` 503'te kaldı: orada "bu özellik şu an kullanılamıyor,
sonra dene" mesajı doğru ve `Retry-After` başlığıyla eşleşiyor.

### 4.2 Değişiklik: `PROVIDER_UNAVAILABLE`'a `retryAfter` eklendi

RFC 9110, `Retry-After` başlığını 429 **ve** 503 için tanımlar. `RATE_LIMITED`'da
zaten vardı; 503'te de olması gerekiyordu.

### 4.3 Korunan sapma: `RSVP_QUOTA_EXCEEDED` → 403

Sektörde kota aşımı için iki pratik var:

- **Google (gRPC `RESOURCE_EXHAUSTED`)** → 429
- **Klasik REST / eski Google JSON API** → 403 `quotaExceeded`

**Biz 403 seçtik.** Gerekçe: 429 bir **hız** sınırıdır — "çok hızlısın, yavaşla".
Bizim kotamız bir **kapasite** sınırıdır — "davetiye 100 kişilik, doldu". Misafir
yavaşlayarak bu sınırı aşamaz; beklemek hiçbir şeyi değiştirmez. `Retry-After`
vermek yanıltıcı olurdu.

Ayrıca projede 429 zaten rate limit için kullanılıyor (Faz 5). İkisini aynı durum
koduna koymak, frontend'in "bekle ve tekrar dene" mantığını yanlış tetiklerdi.

### 4.4 Bilinçli sapma: RFC 9457 (Problem Details) kullanılmıyor

HTTP hata zarfları için bir RFC standardı var: **RFC 9457 Problem Details**.

```json
{
  "type": "https://example.com/probs/out-of-credit",
  "title": "You do not have enough credit.",
  "status": 403,
  "detail": "Your current balance is 30."
}
```

Kullanmıyoruz çünkü `title` ve `detail` alanları **insan tarafından okunabilir
metin** zorunlu kılar — K20'nin tam olarak yasakladığı şey. Standarda uymak için
İngilizce cümle üretip sonra frontend'in onu görmezden gelmesini istemek, ölü kod
üretmektir.

Bizim zarfımız (`{error: {code, fields?, params?}}`) RFC 9457'nin **makine
tarafından okunabilir** çekirdeğini alır, metin kısmını atar.

---

## 5. Katalog

| Kod | HTTP | `params` | İlk kullanım |
|---|:---:|---|:---:|
| `MALFORMED_REQUEST` | 400 | — | Faz 1 |
| `UNAUTHENTICATED` | 401 | — | Faz 2 |
| `INVALID_CREDENTIALS` | 401 | — | Faz 2 |
| `TOKEN_EXPIRED` | 401 | — | Faz 2 |
| `PAYWALL_TIER_INSUFFICIENT` | 402 | `requiredTier` | Faz 7 |
| `PAYMENT_REQUIRED` | 402 | — | Faz 7 |
| `INVITATION_LOCKED` | 403 | — | Faz 3 |
| `RSVP_DEADLINE_PASSED` | 403 | — | Faz 5 |
| `RSVP_QUOTA_EXCEEDED` | 403 | `remaining`, `limit` 🔴 | Faz 5 |
| `RESOURCE_NOT_FOUND` | 404 | — | **Faz 1** |
| `INVITATION_ALREADY_PUBLISHED` | 409 | — | Faz 7 |
| `SLUG_TAKEN` | 409 | — | Faz 7 |
| `FILE_TOO_LARGE` | 413 | `max` | Faz 6 |
| `VALIDATION_FAILED` | 422 | — | **Faz 1** |
| `REGISTRATION_FAILED` | 422 | — | Faz 2 |
| `RATE_LIMITED` | 429 | `retryAfter` | Faz 5 |
| `SERVER_ERROR` | 500 | — | **Faz 1** |
| `PAYMENT_PROVIDER_ERROR` | 502 | — | Faz 7 |
| `PROVIDER_UNAVAILABLE` | 503 | `retryAfter` | Faz 8 |

> **`VALIDATION_FAILED` neden `params` almıyor?** `max` ve `min` değerleri
> `error.params`'a değil, `error.fields.guestCount[0].params`'a girer — farklı
> seviye. Zarf örneği için `08` §2.1.

🔴 **Bu tablo bir sözleşmedir (H5).** Bir kod adı yayınlandıktan sonra yeniden
adlandırmak, API alanını yeniden adlandırmakla aynı kırıcılıktadır: frontend'in
`errors.RSVP_QUOTA_EXCEEDED` çeviri anahtarı kırılır. **Eklemek serbest,
adlandırmayı değiştirmek değil.**

---

## 6. Kullanım örnekleri

Hiçbiri henüz yazılmadı — enum'un nereye bağlanacağını göstermek için.

```php
// Faz 1 — exception handler (bootstrap/app.php)
$code = ErrorCode::ResourceNotFound;

return response()->json(
    ['error' => ['code' => $code->value]],
    $code->status(),                        // 404 — elle yazılmadı
);
```

```php
// Faz 5 — kota aşımı, params beyaz listeden geçiyor
$code = ErrorCode::RsvpQuotaExceeded;

$params = $code->filterParams([
    'remaining' => 3,
    'limit'     => 100,
    'sql'       => $query,     // ← beyaz listede yok, SESSİZCE DÜŞER
]);

// $params === ['remaining' => 3, 'limit' => 100]
```

```php
// Faz 1 — errors:export komutu (1.6)
foreach (ErrorCode::cases() as $case) {
    $catalog[$case->value] = [
        'status' => $case->status(),
        'params' => $case->allowedParams(),
    ];
}
```

> `cases()` enum'un hazır gelen statik metodudur — tüm case'leri dizi olarak verir.
> Katalog JSON'u bu sayede **elle** güncellenmez; enum değişince çıktı değişir.

---

## 7. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `'RESOURCE_NOT_FOUND'` düz string yazmak | Yazım hatası sessiz kalır | `ErrorCode::ResourceNotFound` |
| Yanıta `$code` nesnesini koymak | `json_encode` sorunu / sızıntı | `$code->value` |
| `status()`'a `default` eklemek | Yeni case eklenince sessizce yanlış durum döner | `default` **yazma** |
| `params`'ı `filterParams()`'sız geçmek | İç veri sızar (H9 ihlali) | Her zaman süz |
| Enum'a `message()` / `label()` eklemek | K20 ihlali — metin backend'e sızar | Metin frontend'in işi |
| Yayınlanmış bir kodu yeniden adlandırmak | Frontend çevirisi kırılır | Yeni kod **ekle** |
| `in_array(...)` üçüncü argümanı unutmak | Gevşek karşılaştırma sürprizi | `true` yaz |

---

## 8. Kendin dene

`php artisan tinker` içinde:

```php
use App\Enums\ErrorCode;

ErrorCode::ResourceNotFound->value;      // "RESOURCE_NOT_FOUND"
ErrorCode::ResourceNotFound->status();   // 404
ErrorCode::RateLimited->isRetryable();   // true
ErrorCode::ValidationFailed->isRetryable();  // false

// Beyaz liste çalışıyor mu?
ErrorCode::RsvpQuotaExceeded->filterParams(['remaining' => 3, 'sizinti' => 'gizli']);
// => ["remaining" => 3]     ← "sizinti" düştü

// String'den enum'a
ErrorCode::from('SLUG_TAKEN');           // ErrorCode::SlugTaken
ErrorCode::tryFrom('YOK');               // null (from() olsaydı exception)

// Kaç kod var?
count(ErrorCode::cases());               // 19
```

**Kasten kır:** `status()` metodundaki `self::ResourceNotFound => 404,` satırını
silip tinker'da tekrar çağır. `UnhandledMatchError` alacaksın — `match`'in eksik
durumu yakalaması budur. Sonra geri ekle.

---

## 9. Bu enum'u kimler tüketecek?

| Tüketici | Faz | Nasıl |
|---|---|---|
| `bootstrap/app.php` exception handler | 1 | Her hatayı zarfa çevirirken |
| `ExportErrorCodes` komutu | 1 | `cases()` ile katalog JSON'u üretir |
| `tests/Feature/HealthTest.php` | 1 | `assertJsonPath('error.code', ...)` |
| `LoginUserAction` | 2 | `InvalidCredentials` fırlatır |
| `InvitationPolicy` | 3 | Red → `ResourceNotFound` (403 değil) |
| `RsvpQuotaExceededException` | 5 | `RsvpQuotaExceeded` + `remaining` |
| `PaywallViolationException` | 7 | `PaywallTierInsufficient` + `requiredTier` |
| `GeminiProvider` | 8 | Ham hatayı yutar → `ProviderUnavailable` |

---

## 10. Sözlük

| Terim | Anlamı |
|---|---|
| **Backed enum** | Her case'in bir skaler değeri (`string`/`int`) olan enum |
| **Single source of truth** | Bir bilginin tanımlandığı tek yer |
| **Tell, Don't Ask** | Nesneye durumunu sorup dışarıda karar verme; kararı ona sor |
| **Beyaz liste** | "Yalnızca şunlar serbest" — varsayılan kapalı |
| **Kara liste** | "Şunlar yasak" — varsayılan açık, riskli |
| **Upstream (yukarı akış)** | Bizim çağırdığımız dış servis (Iyzico, Gemini) |
| **Gateway** | İsteği başka bir servise ileten aracı sunucu |
| **Idempotent / retryable** | Tekrarlanması güvenli olan istek |
| **RFC 9110** | Güncel HTTP semantiği standardı |
| **RFC 9457** | HTTP hata gövdesi standardı (Problem Details) — kullanmıyoruz |
| **User enumeration** | Hata farkından kayıtlı hesapları tespit etme açığı |

---

## 🆕 Faz 7 değişikliği — `PAYMENT_REQUIRED` artık `requiredTier` taşıyor

```diff
- self::PaywallTierInsufficient => ['requiredTier'],
+ self::PaywallTierInsufficient,
+ self::PaymentRequired => ['requiredTier'],
```

### Neden?

İki kod da **402** döner ama kullanıcının önündeki eylem farklıdır:

| Kod | Durum | Frontend'in çizeceği ekran |
|---|---|---|
| `PAYMENT_REQUIRED` | Hiç ödeme yok | Üç plan kartı, önerilen vurgulu |
| `PAYWALL_TIER_INSUFFICIENT` | Ödeme var, plan yetmiyor | Yükseltme akışı |

İkisinde de frontend **hangi planı** göstereceğini bilmek zorunda.
Göndermemek işlevsel bir zarar verirdi: kullanıcı hangi planı alacağını
bilemezse ödeme yapamaz.

Sızıntı mı? Hayır — `docs/08` §3.4 `requiredTier`'ı zaten **"herkese"**
sınıfına koymuştu: *"fiyat sayfası zaten herkese açık."*

### 🔴 Katalog yeniden üretilmeli

```powershell
php artisan errors:export
```

`composer check` zincirindeki `errors:export --check` (K34) bunu **zorluyor**:
katalog güncel değilse **testler hiç koşmaz** (fail fast). `contracts/error-codes.json`
repoya işlenir (K33).

### Faz 7'de kullanılmaya başlayan kodlar

| Kod | Durum | Nereden fırlıyor | Faz 1'den beri bekliyordu |
|---|---|---|---|
| `PAYMENT_REQUIRED` | 402 | `PaywallViolationException::noPurchase()` | ✅ |
| `PAYWALL_TIER_INSUFFICIENT` | 402 | `…::insufficientTier()` | ✅ |
| `INVITATION_ALREADY_PUBLISHED` | 409 | `InvitationAlreadyPublishedException` | ✅ |
| `PAYMENT_PROVIDER_ERROR` | 502 | `PaymentProviderException::rejected()` | ✅ |
| `PROVIDER_UNAVAILABLE` | 503 | `…::unavailable()` | ✅ |

Faz 1'de yazılan 19 kodun beşi bugün ilk kez gerçek bir çağıran buldu.

### Hâlâ kullanılmayan iki kod

| Kod | Neden duruyor |
|---|---|
| `SLUG_TAKEN` (409) | 🔴 **K40 onu geçersiz kıldı**: `invitations.id` zaten ULID ve paylaşılan linkin kendisi; ayrı bir slug ikinci bir kimlik olurdu. Silinmedi — bir kod adı yayınlandıktan sonra **sözleşmedir** (`docs/08` §5.1) ve frontend'in çeviri anahtarı kırılır |
| `INVITATION_LOCKED` (403) | *"Yayınlanmış davetiye düzenlenemez"* kuralı için ayrılmış; o kural henüz **verilmedi** |
| `TOKEN_EXPIRED` (401) | Sanctum süresiz token üretiyor; Faz 9'da süre gelirse |
