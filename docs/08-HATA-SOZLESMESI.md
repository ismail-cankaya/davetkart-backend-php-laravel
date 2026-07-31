# DavetKart Backend — Hata Sözleşmesi

> **Oluşturma:** 31 Temmuz 2026
> **Kayıt:** K20 (hata sözleşmesi) · K21 (backend tek dil)
> **Neyi değiştiriyor:** Backend artık API yanıtlarında **kullanıcıya gösterilecek
> metin döndürmez**. Yerine makine tarafından okunabilir **hata kodu** döner.
> `07-GELISTIRME-YOL-HARITASI.md` Faz 8'deki `SetLocaleFromHeader` middleware'i
> **iptal edilmiştir**.
> **Durum:** Tasarım onaylandı · Uygulama Faz 1'de (exception handler)

---

## 1. Karar ve gerekçe

### 1.1 Sorun: sorumluluk sızıntısı

Önceki tasarımda backend şunu döndürüyordu:

```json
{ "errors": { "email": ["E-posta alanı zaten kullanılıyor."] } }
```

Backend **ne olduğunu** bilir (benzersizlik kuralı ihlal edildi). **Nasıl
anlatılacağı** ise sunum katmanının kararıdır: hangi dil, hangi ton, hangi
gösterim biçimi (alan altı uyarı mı, toast mı).

Metin backend'den geldiğinde üç şey birden bozulur:

| Bozulan | Nasıl |
|---|---|
| Dil | Backend'in 10 dil taşıması gerekir; frontend zaten taşıyor — **tekrar** |
| Esneklik | Frontend metni değiştiremez, bağlama göre farklılaştıramaz |
| Test | Metin değişince test kırılır, oysa **davranış** aynıdır |

### 1.2 Çözüm

Backend **olayı** bildirir, frontend **anlatır**.

```
Backend:   "email alanı, unique kuralını ihlal etti"
Frontend:  t('validation.unique', { field: t('fields.email') })
             tr → "E-posta adresi zaten kullanılıyor"
             de → "E-Mail-Adresse wird bereits verwendet"
```

### 1.3 İkinci gerekçe: bilgi ifşasını azaltmak

🔴 **"Minimum bilgi" bayt sayısı değil, iç durumun ifşasıdır.**

Yanıt ne kadar açıklayıcıysa saldırganın sistem hakkında öğrendiği o kadar
artar. Hata kodu yaklaşımı bunu doğal olarak sınırlar: kod sabit bir
tanımlayıcıdır, iç durumu anlatmaz.

---

## 2. Zarf tasarımı

### 2.1 Üretim yanıtı (`APP_DEBUG=false`)

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "fields": {
      "guestCount": [{ "rule": "max", "params": { "max": 10 } }]
    }
  }
}
```

Tek zorunlu alan **`code`**. `fields` yalnızca doğrulama hatalarında bulunur.

### 2.2 Yerel yanıt (`APP_DEBUG=true`)

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "fields": { "guestCount": [{ "rule": "max", "params": { "max": 10 } }] },
    "debug": {
      "message": "The guest count field must not be greater than 10.",
      "exception": "Illuminate\\Validation\\ValidationException",
      "file": "app/Http/Requests/StoreRsvpRequest.php",
      "line": 42
    }
  }
}
```

🔴 **`debug` bloğu ortam bayrağına bağlı üretilir.** Üretimde kod hiç
çalışmaz — "unutulup açık kalması" mümkün değildir. Bu, güvenliği *disipline*
değil *yapıya* bağlamaktır.

### 2.3 İş kuralı hatası

```json
{
  "error": {
    "code": "PAYWALL_TIER_INSUFFICIENT",
    "params": { "requiredTier": "elit" }
  }
}
```

### 2.4 Alan adları camelCase

`fields` anahtarları **isteğin gönderdiği** adlardır: `guestCount`, `fullName`,
`mapUrl`. Doğrulama Resource katmanından önce çalışır; henüz snake_case
dönüşümü olmamıştır.

---

## 3. 🔴 Bilgi sızdırmama kuralları

### 3.1 Kullanıcı sayımı (user enumeration)

Saldırgan, hata mesajı farkından hangi e-postaların kayıtlı olduğunu öğrenebilir.
Elindeki 10.000 e-postalık listeyi forma girip hangilerinin "zaten kayıtlı"
dediğine bakar — kayıt formu bir **hesap tarayıcısına** dönüşür.

| Endpoint | ❌ Yasak | ✅ Zorunlu |
|---|---|---|
| `POST /auth/login` | "Parola hatalı" / "Kullanıcı bulunamadı" ayrımı | Her iki durumda **`INVALID_CREDENTIALS`**, `fields` **yok** |
| `POST /auth/register` | `fields: {email: [{rule: "unique"}]}` | **`REGISTRATION_FAILED`**, `fields` **yok** |
| `POST /auth/forgot-password` | "Bu e-posta kayıtlı değil" | Her durumda **`202 Accepted`** — kayıtlıysa mail gider, değilse sessizce yutulur |

> **Zamanlama saldırısı:** Kullanıcı yoksa parola karşılaştırması hiç
> çalışmaz → yanıt ~250 ms daha hızlı döner. Saldırgan bunu ölçer. Savunma:
> kullanıcı bulunamasa bile sahte bir hash'e karşı doğrulama yapılır.
> `RegisterUserAction`/`LoginUserAction` yazılırken uygulanacak (Faz 2).

### 3.2 Kaynak varlığının ifşası

Başkasının davetiyesine erişim denemesinde **404** dönülür, 403 değil.

403 *"bu kaynak var ama senin değil"* der — kaynağın varlığını doğrular.
Saldırgan ULID uzayını tarayıp hangi davetiyelerin var olduğunu haritalayabilir.
404 *"böyle bir şey yok"* der, ayrım vermez.

> ⚠️ Bu, `CLAUDE.md`'deki *"yetki hatası 403"* kuralının **istisnasıdır**.
> 403 **sahiplik doğrulanmış ama işlem yasak** durumlarında kullanılır
> (örn. yayınlanmış davetiyeyi silme). Sahiplik **yoksa** 404.

### 3.3 Asla yanıta girmeyecekler

| Sızıntı | Nereye gider |
|---|---|
| Yığın izi (stack trace), dosya yolu, satır no | Sadece `debug` bloğu (yerel) |
| SQL sorgusu / veritabanı hata metni | Sadece log |
| Sağlayıcı ham hataları (ödeme, Gemini) | Sadece log; dışarı `PAYMENT_PROVIDER_ERROR` (502) veya `PROVIDER_UNAVAILABLE` (503) |
| Sürüm bilgisi (PHP, Laravel) | Hiçbir yere |
| Başka kullanıcıya ait herhangi bir alan | Hiçbir yere |

### 3.4 `params` beyaz listeyle

Her hata kodu, dışarı verdiği parametreleri **kendisi beyan eder**. Varsayılan:
hiçbiri.

🔴 **Beyaz liste belgede değil, kodda zorlanır.** `ErrorCode::filterParams()`
listede adı geçmeyen anahtarları sessizce düşürür. Bu kuralın hatırlanmasına
değil, çağrı yolunun üzerinde durmasına bağlıdır — bkz.
[`rehber/app/Enums/ErrorCode.md`](rehber/app/Enums/ErrorCode.md) §3.4.

| Parametre | Kime | Neden |
|---|---|---|
| `max`, `min`, `size` | Herkese | Kullanıcı sınırı zaten formda görüyor |
| `requiredTier` | Herkese | Fiyat sayfası zaten herkese açık |
| `remaining`, `limit` (LCV kotası) | 🔴 **Sadece davetiye sahibine** | Anonim misafir kota durumunu bilmemeli |
| `retryAfter` | Herkese | Standart HTTP davranışı |

---

## 4. HTTP durum kodu ↔ hata kodu

Durum kodu **kaba sınıflandırma**, `code` **ince ayrım**. İkisi birlikte çalışır.

| Durum | Anlam | Örnek kodlar |
|---|---|---|
| **400** | İstek biçimsel olarak bozuk | `MALFORMED_REQUEST` |
| **401** | Kimlik yok / geçersiz | `UNAUTHENTICATED`, `INVALID_CREDENTIALS`, `TOKEN_EXPIRED` |
| **402** | Ödeme gerekli | `PAYWALL_TIER_INSUFFICIENT`, `PAYMENT_REQUIRED` |
| **403** | Kimlik var, işlem yasak | `INVITATION_LOCKED`, `RSVP_DEADLINE_PASSED` |
| **404** | Kaynak yok **veya** senin değil | `RESOURCE_NOT_FOUND` |
| **409** | Durum çakışması | `INVITATION_ALREADY_PUBLISHED`, `SLUG_TAKEN` |
| **413** | Dosya çok büyük | `FILE_TOO_LARGE` |
| **422** | Doğrulama başarısız | `VALIDATION_FAILED` |
| **429** | Hız sınırı | `RATE_LIMITED` |
| **500** | Sunucu hatası (bizim kodumuz) | `SERVER_ERROR` |
| **502** | Yukarı akış **geçersiz yanıt döndü** | `PAYMENT_PROVIDER_ERROR` |
| **503** | Servis geçici olarak kullanılamıyor | `PROVIDER_UNAVAILABLE` |

> 🔴 **401 ile 403 ayrımı ihlal edilemez.** Frontend `api.ts` interceptor'ı
> 401'de oturumu düşürüyor. Yanlış kod kullanıcıyı sistemden atar.

### 4.1 5xx ailesinin ayrımı (RFC 9110)

İlk tasarımda tüm sağlayıcı hataları 503'e konmuştu. Sektör pratiğine göre
düzeltildi — üçü **farklı yeri** işaret eder ve izleme (monitoring) alarmları bu
ayrıma göre yönlendirilir:

| Durum | Sorun nerede | Örnek |
|---|---|---|
| **500** | **Bizim kodumuzda** | Yakalanmamış `TypeError` |
| **502** | **Yukarı akışta** — cevap verdi ama hatalı | Iyzico "işlem reddedildi" döndü |
| **503** | **Bu serviste** — geçici olarak veremiyoruz | Gemini'ye hiç ulaşılamıyor, bakım |

Ödeme akışında biz bir **gateway**'iz: isteği Iyzico'ya iletiyoruz. Iyzico hata
döndüğünde sorun bizde değil, aracılık ettiğimiz serviste — bu 502'nin tanımıdır.
503 demek kendi sunucumuzun çöktüğünü bildirmek olurdu.

**`Retry-After` başlığı** 429 ve 503 ile birlikte gönderilir (RFC 9110 §10.2.3).
Bu yüzden `PROVIDER_UNAVAILABLE` da `retryAfter` parametresi taşır.

### 4.2 Neden RFC 9457 (Problem Details) kullanılmıyor?

HTTP hata gövdeleri için bir RFC standardı var:

```json
{ "type": "...", "title": "You do not have enough credit.", "status": 403 }
```

Kullanmıyoruz: `title` ve `detail` alanları **insan tarafından okunabilir metin**
zorunlu kılar — K20'nin tam olarak yasakladığı şey. Standarda uymak için İngilizce
cümle üretip frontend'in onu görmezden gelmesini beklemek ölü kod üretir.

Bizim zarfımız RFC 9457'nin **makine tarafından okunabilir** çekirdeğini (`code`,
`status`) alır, metin kısmını atar.

---

## 5. `ErrorCode` enum'u — tek doğruluk kaynağı

```php
// app/Enums/ErrorCode.php  (Faz 1'de yazılacak)
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case RsvpQuotaExceeded = 'RSVP_QUOTA_EXCEEDED';
    // ...

    public function status(): int { /* HTTP durum kodu */ }

    /** Bu kodun disari verebilecegi parametreler (beyaz liste). */
    public function allowedParams(): array { /* ... */ }
}
```

**Neden enum?** `CLAUDE.md` §1: *"sihirli string kullanılmamalıdır."* Kodda
`'RSVP_QUOTA_EXCEEDED'` düz metni asla yazılmaz — `ErrorCode::RsvpQuotaExceeded`
yazılır. Yazım hatası **çalışma anında değil, anında** hata verir. IDE
otomatik tamamlar, "bu kod nerede kullanılıyor" araması kesin sonuç döndürür.

**Durum kodu enum'un içinde:** Her `code`'un tek bir HTTP karşılığı vardır. Bunu
enum'a koymak, controller'larda `if` zinciri yazmayı gereksizleştirir.

### 5.1 Kod adlandırma kuralları

| Kural | Örnek |
|---|---|
| `SCREAMING_SNAKE_CASE` | `RSVP_QUOTA_EXCEEDED` |
| `<ALAN>_<DURUM>` kalıbı | `INVITATION_ALREADY_PUBLISHED` |
| Teknik değil **iş** dili | ✅ `SLUG_TAKEN` · ❌ `UNIQUE_CONSTRAINT_23505` |
| İç yapıyı ele vermez | ❌ `USER_TABLE_LOCK_TIMEOUT` |

🔴 **Kod adı bir kez yayınlandıktan sonra sözleşmedir.** Yeniden adlandırmak,
API alanı yeniden adlandırmakla aynı kırıcılıktadır — frontend'in çeviri
anahtarı kırılır.

---

## 6. Katalog senkronizasyonu

**Sorun:** Backend `PAYWALL_TIER_INSUFFICIENT` kodunu eklerse ve frontend'in
çeviri dosyasında karşılığı yoksa, kullanıcı ham kodu görür veya boş ekran.

**Çözüm:** `php artisan errors:export` komutu enum'dan JSON üretir:

```json
{
  "generatedAt": "2026-07-31T12:00:00Z",
  "codes": {
    "VALIDATION_FAILED":         { "status": 422, "params": [] },
    "PAYWALL_TIER_INSUFFICIENT": { "status": 402, "params": ["requiredTier"] }
  }
}
```

Frontend bu dosyadan çeviri anahtarlarını türetir ve eksikleri tespit eder.

**Neden paylaşılan tip paketi değil?** `davetkart-contracts` denendi ve
kaldırıldı (hiç doldurulmadı). Tek yönlü üretim (backend → JSON) iki repoyu
birbirine **bağlamaz**: dosya kopyalanır, bağımlılık oluşmaz.

---

## 7. Frontend'e düşen iş

Ayrıntı: `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md`

1. `locales/<dil>/errors.json` — kod → metin
2. `locales/<dil>/validation.json` — kural adı → metin, `fields.*` alan etiketleri
3. `services/api.ts` içinde `toDisplayError(error)` yardımcısı
4. 🔴 **Bilinmeyen kod için yedek metin** — backend yeni kod eklediğinde boş
   ekran gösterilmemeli
5. `types.ts` → `ApiError` tipi

---

## 8. Yol haritasına etkisi

| Faz | Değişiklik |
|---|---|
| **1** | `ErrorCode` enum + `bootstrap/app.php` exception handler + `ForceJsonResponse`. Faz 1 büyüdü |
| **2** | Auth hataları kodla döner; **enumeration ve zamanlama savunması** burada |
| 3 | `InvitationPolicy` reddi → **404** (403 değil) |
| 5 | `RsvpQuotaExceededException` → `RSVP_QUOTA_EXCEEDED`; `remaining` sadece sahibe |
| 7 | `PaywallViolationException` → 402 + `requiredTier` |
| **8** | 🔴 `SetLocaleFromHeader` middleware **iptal** — backend tek dil |

---

## 9. Test stratejisi

```php
// ✅ Davranışa bakar — metin değişse de geçer
$response->assertStatus(422)
    ->assertJsonPath('error.code', 'VALIDATION_FAILED')
    ->assertJsonPath('error.fields.guestCount.0.rule', 'max');

// ✅ Sızıntı testi — üretim kipinde debug bloğu YOK
config(['app.debug' => false]);
$response->assertJsonMissingPath('error.debug');

// ✅ Enumeration testi — kayıtlı ve kayıtsız e-posta AYNI yanıtı verir
$this->postJson('/api/auth/login', ['email' => $existing, 'password' => 'yanlis'])
    ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
$this->postJson('/api/auth/login', ['email' => 'yok@yok.com', 'password' => 'yanlis'])
    ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
```

**Bitti ölçütü:** Hiçbir testte kullanıcıya gösterilecek **metin** doğrulanmaz.
Metin frontend'in işidir; backend testi yalnızca kod, durum ve alan adı bilir.

---

## 10. Geçersiz kılınanlar

| Ne | Durum |
|---|---|
| `lang/tr/validation.php` | **Silindi** — API metin döndürmüyor. Kılavuzu referans olarak duruyor |
| `APP_LOCALE=tr` | **Geri alındı** → `en` |
| Faz 8 `SetLocaleFromHeader` | **İptal** |
| `07` §6 "Alan adları camelCase, dönüşüm Resource'ta" | **Geçerli** — hata `fields` anahtarları da camelCase |

> `APP_FAKER_LOCALE=tr_TR` **kalıyor.** O, API dilinden bağımsızdır: sahte
> **test verisi** üretir. Türkçe isimlerle test etmek karakter kodlaması ve alan
> uzunluğu sorunlarını erken gösterir.
