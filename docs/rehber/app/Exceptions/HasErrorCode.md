# `app/Exceptions/HasErrorCode.php`

> **Kod dosyası:** `app/Exceptions/HasErrorCode.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.5
> **Birlikte değişenler:** `ApiExceptionRenderer.php`,
> `RegistrationFailedException.php`, `InvalidCredentialsException.php`
> **Kaynağı:** `FAZ-4.md` §9.2 — *"`HasErrorCode` arayüzü | üçüncü exception
> (`RsvpQuotaExceededException`) geliyor"*

---

## 1. Hangi problemi çözüyor?

Faz 1'de **H11** diye bir kural konmuştu:

> Her yeni exception `ApiExceptionRenderer::resolveCode()`'a eklenir.
> Eklenmezse `SERVER_ERROR` (500) döner — istemci hatası sunucu hatası gibi
> görünür.

Bu kural doğruydu ama **bir hatırlama yükü** yaratıyordu. Faz 5'te iki yeni
exception geliyor; onlarla birlikte zincir şöyle olacaktı:

```php
$e instanceof RegistrationFailedException  => ErrorCode::RegistrationFailed,
$e instanceof InvalidCredentialsException  => ErrorCode::InvalidCredentials,
$e instanceof RsvpDeadlinePassedException  => ErrorCode::RsvpDeadlinePassed,
$e instanceof RsvpQuotaExceededException   => ErrorCode::RsvpQuotaExceeded,
// Faz 7: PaywallViolationException...
// Faz 8: AssistantQuotaException...
```

Her satır aynı şeyi söylüyor: *"bu sınıfın kodu şudur."* Ve bu bilgi
exception'ın **kendisine** ait; renderer'ın onu ezberlemesi için bir sebep yok.

🔴 Asıl mesele satır sayısı değil, **unutmanın bedelinin sessiz olması**. Yeni
bir exception yazıp kolu eklemeyi unutan biri hiçbir uyarı almaz; sadece bir
gün üretimde `500` görür.

---

## 2. Arayüz nedir? (PHP temeli)

Bir **interface** (arayüz), gövdesiz metot imzalarından oluşan bir sözleşmedir:

```php
interface HasErrorCode
{
    public function errorCode(): ErrorCode;
    public function errorParams(): array;
}
```

Bir sınıf `implements HasErrorCode` yazdığında **iki metodu da yazmak
zorundadır**; yazmazsa PHP dosyayı yüklerken *fatal error* verir.

Sınıf kalıtımından (`extends`) farkı:

| | `extends` | `implements` |
|---|---|---|
| Kaç tane? | PHP'de **bir** üst sınıf | İstediğin kadar arayüz |
| Ne taşır? | Davranış (kod) | Sadece sözleşme (imza) |
| Ne der? | "Bu bir X **türüdür**" | "Bu **X yapabilir**" |

Bizim exception'larımız zaten `RuntimeException`'dan türüyor (`extends`).
Arayüz o kalıtımı bozmadan üstüne bir **yetenek** ekliyor.

Bu, **marker + contract** (işaretleyici + sözleşme) denen desendir: renderer
artık "bu sınıf hangisi?" diye değil, **"bu nesne kendi kodunu biliyor mu?"**
diye soruyor.

---

## 3. Renderer'daki tek kol

```php
$e instanceof HasErrorCode => $e->errorCode(),
```

`instanceof` bir nesnenin belirli bir sınıf **veya arayüz** olup olmadığını
sorar. Arayüzle çalıştığı için tek kol, sınırsız sayıda exception'a hizmet eder.

### Kolun yeri neden orada?

**H13** diyordu ki: *`match (true)` kolları özelden genele sıralanır.*

| Sıra | Kol | Neden burada |
|---|---|---|
| 1 | `ValidationException` | En özel; ayrıca `fields` üreten **tek** yol |
| 2 | **`HasErrorCode`** | Bizim kendi exception'larımız — framework'ünkilerden önce |
| 3 | `AuthenticationException`, `ThrottleRequests`… | Framework'ün tip taşıyan hataları |
| 4 | `HttpExceptionInterface` | Genel: yalnızca durum kodu taşır |
| 5 | `default` | Bilinmeyen → 500 |

`HasErrorCode`'u en sona koysaydık, `HttpExceptionInterface`'i de uygulayan bir
exception yazdığımız gün kodumuz yerine durum kodundan geri eşleme
kullanılırdı — ve bu **sessizce** olurdu. Faz 1'de `ThrottleRequestsException`
ile tam olarak bu tuzağa dikkat çekilmişti (`retryAfter` kaybolurdu).

---

## 4. `errorParams()` — izin değil, öneri

```php
if ($e instanceof HasErrorCode) {
    return $e->errorParams();
}
```

Bu metodun döndürdüğü dizi **doğrudan yanıta gitmez**. `render()` içinde şu
satırdan geçer:

```php
$params = $code->filterParams($this->params($e));
```

**H12**: `params` her zaman `ErrorCode::filterParams()`'tan geçirilir. Yani bir
exception "şu değeri de ver" dese bile, `ErrorCode::allowedParams()` beyaz
listesinde adı yoksa **sessizce düşer**.

🔴 Bu çift kapı bilinçli. Arayüz eklemek, güvenliği tek bir sınıfın iyi
niyetine bağlamamalı:

- **Exception** der ki: "bunlar verilebilir."
- **ErrorCode** der ki: "bunlardan şunlar dışarı çıkabilir."

İkisi aynı fikirde olmazsa dar olan kazanır. Bu, H9'un *"beyaz liste belgede
değil kodda zorlanır"* ilkesinin bozulmadan kalmasını sağlar.

---

## 5. Eski iki exception neden de bu arayüze taşındı?

`RegistrationFailedException` ve `InvalidCredentialsException` zaten
çalışıyordu. Yine de arayüze taşındılar, çünkü **iki mekanizmayı yan yana
tutmak C3'ün uyardığı şeydir**: aynı işi yapan iki yol varsa, yeni gelen
hangisini kullanacağını bilemez ve zamanla ikisi ayrışır.

Davranış **birebir aynı** kaldı:

| Exception | Önce | Sonra |
|---|---|---|
| `RegistrationFailedException` | match kolu → `REGISTRATION_FAILED` (422) | `errorCode()` → `REGISTRATION_FAILED` (422) |
| `InvalidCredentialsException` | match kolu → `INVALID_CREDENTIALS` (401) | `errorCode()` → `INVALID_CREDENTIALS` (401) |

**H6 korundu ve güçlendi.** Auth hataları `fields` taşımaz — çünkü `fields`
yalnızca `ValidationException` kolunda üretiliyor. Ayrıca ikisinin de
`errorParams()` metodu **boş dönüyor** ve docblock'unda neden boş kalması
gerektiği yazılı. Yani gelecekte biri oraya bir değer koymak isterse, kuralı
**okumadan** yapamaz.

> 🔴 Bu değişikliğin doğruluğunu `AuthTest`'teki 15 test kanıtlar. Faz 5 bu
> makinede doğrulanamadığı için, evdeki `composer check` koşusunda **ilk
> bakılacak yer** burasıdır: `php artisan test --filter=AuthTest`.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Yeni exception'a arayüzü uygulamayı unutmak | `default` koluna düşer → 500. Aynı eski tuzak, yeni kılıkta |
| 2 | `HasErrorCode` kolunu `HttpExceptionInterface`'ten sonra koymak | Kendi kodun yerine durum kodundan geri eşleme kullanılır (H13) |
| 3 | `errorParams()`'ın beyaz listeyi atlattığını sanmak | Atlatmaz; `filterParams()` yolun üzerindedir (H12) |
| 4 | Arayüzü `ErrorCode` yerine `string` döndürecek şekilde yazmak | Sihirli string geri gelir; yazım hatası çalışma anına kaçar |
| 5 | Arayüze `httpStatus(): int` eklemek | İkinci doğruluk kaynağı. Durum kodu `ErrorCode::status()`'un tekelinde |
| 6 | Exception'a kullanıcıya gösterilecek Türkçe mesaj koymak | K20 ihlali. `getMessage()` yalnızca log ve yerel `debug` bloğu içindir |

---

## 7. Kendin dene

```php
// php artisan tinker
$e = new App\Exceptions\RsvpQuotaExceededException();

$e instanceof App\Exceptions\HasErrorCode;   // true
$e->errorCode();                             // ErrorCode::RsvpQuotaExceeded
$e->errorCode()->status();                   // 403
$e->errorParams();                           // []

// Beyaz liste hâlâ yolun üzerinde mi?
App\Enums\ErrorCode::RsvpQuotaExceeded->allowedParams();
// ['remaining', 'limit']  ← izin VAR ama exception vermiyor (H9)

App\Enums\ErrorCode::RsvpQuotaExceeded->filterParams(['remaining' => 5, 'gizli' => 'x']);
// ['remaining' => 5]      ← 'gizli' sessizce düştü
```

**Mutasyon denemesi (kural 14):** `ApiExceptionRenderer`'daki
`$e instanceof HasErrorCode => $e->errorCode(),` satırını sil.
`php artisan test --filter=RsvpTest` çalıştır. Kota ve son tarih testleri
`403` yerine `500` görüp kırılmalı. Kırılmıyorsa testler durum kodunu
doğrulamıyordur.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Interface (arayüz)** | Gövdesiz metot imzalarından oluşan sözleşme |
| **`implements`** | "Bu sınıf şu sözleşmeyi yerine getirir" |
| **`instanceof`** | Nesnenin bir sınıf/arayüz tipinde olup olmadığını sorar |
| **Marker interface** | Davranış değil, bir yeteneği/işareti belirten arayüz |
| **Polimorfizm** | Farklı sınıfların aynı çağrıya kendi cevabını vermesi |
| **Tek doğruluk kaynağı** | Bir bilginin yalnızca tek yerde tanımlı olması |
| **Beyaz liste** | Varsayılan kapalı; yalnızca sayılanlar açık |

---

## 9. Sırada ne var?

**5.6 — `RsvpQuotaResolver`.** Kota limitinin nereden okunacağı. `TierResolver`
Faz 7'de yazılacak, ama `SubmitRsvpAction` bugün bir sayıya ihtiyaç duyuyor —
arada bir **dikiş yeri** (seam) bırakacağız.

| İlgili | Nerede |
|---|---|
| Zarf üreteci | [`ApiExceptionRenderer.md`](ApiExceptionRenderer.md) |
| Kod kataloğu | [`../Enums/ErrorCode.md`](../Enums/ErrorCode.md) |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
