# Faz 7'nin dört yeni exception'ı

> **Kod dosyaları:**
> `app/Exceptions/PaywallViolationException.php` ·
> `app/Exceptions/InvitationAlreadyPublishedException.php` ·
> `app/Exceptions/InvalidWebhookSignatureException.php` ·
> `app/Exceptions/PaymentProviderException.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.5
> **Birlikte değişenler:** `app/Enums/ErrorCode.php` (`PAYMENT_REQUIRED`
> artık `requiredTier` taşıyor) · `contracts/error-codes.json`
> **Ön koşul:** [`HasErrorCode.md`](HasErrorCode.md) — bu dördü de o arayüzü
> uygular

---

## 0. Neden dördü tek adımda?

`CLAUDE.md` çalışma ritmi "tek dosya" der; bu dördü **tek mantıksal modüldür**:
hepsi aynı arayüzü (`HasErrorCode`) uygular, hiçbiri diğerine bağımlı değildir
ve dördü birden yazılmazsa `PaymentGateway` arayüzü (7.6) var olmayan bir
sınıfa referans verirdi — **kural 7** bunu yasaklıyor.

🔴 Faz 5'te kurulan **H11'in yeni hâli** burada ilk kez sınandı: bu dört sınıfın
hiçbiri `ApiExceptionRenderer`'a dokunmadı. Arayüz olmasaydı dört `match` kolu
eklemek gerekirdi ve biri unutulduğunda **istemci hatası sunucu hatası gibi**
(500) görünürdü.

---

## 1. `PaywallViolationException` — fazın kalbi

### 1.1 İki kurucu, iki kod, aynı durum kodu

```php
PaywallViolationException::noPurchase($required);          // PAYMENT_REQUIRED           402
PaywallViolationException::insufficientTier($req, $owned); // PAYWALL_TIER_INSUFFICIENT  402
```

`docs/08` §4'ün cümlesi: **"Durum kodu kaba sınıflandırma, `code` ince
ayrım."** İşte tam olarak o.

| Kod | Kullanıcının durumu | Frontend'in çizeceği ekran |
|---|---|---|
| `PAYMENT_REQUIRED` | Hiç ödeme yok | Üç plan kartı, önerilen vurgulu |
| `PAYWALL_TIER_INSUFFICIENT` | Ödeme var, plan yetmiyor | "Yükselt" akışı, alt planlar kapalı |

HTTP durum kodu ikisi için de 402 — çünkü HTTP'nin söyleyebileceği şey
"ödeme gerekli"den ibarettir. İnce ayrım bizim sözleşmemizde.

### 1.2 `requiredTier` her ikisinde de gider

`ErrorCode::allowedParams()` bu fazda değişti:

```php
self::PaywallTierInsufficient,
self::PaymentRequired => ['requiredTier'],
```

Sızıntı mı? Hayır — `docs/08` §3.4 zaten `requiredTier`'ı **"herkese"**
sınıfına koymuştu, gerekçesi: *"fiyat sayfası zaten herkese açık."* Ve
göndermemek işlevsel bir zarar verirdi: kullanıcı hangi planı alacağını
bilemezse ödeme yapamaz.

> 🔴 Katalog değişti → `contracts/error-codes.json` **yeniden üretilmelidir**.
> `composer check` zincirindeki `errors:export --check` (K34) bunu zaten
> zorluyor: dosya güncel değilse **testler hiç koşmaz**.

### 1.3 Kurucu neden `private`?

```php
private function __construct(ErrorCode $code, SubscriptionTier $requiredTier, string $message)
```

Dışarıdan `new PaywallViolationException(ErrorCode::ServerError, …)` yazmak
mümkün değil. Kod ile senaryo arasındaki eşleme **sınıfın şeklinde** korunuyor,
bir yorumda değil. `MediaQuotaExceededException` (Faz 6) ve
`InvalidCredentialsException` (Faz 2, A2) aynı deseni kurmuştu.

### 1.4 `errorParams()` enum değil **değer** döndürür

```php
return ['requiredTier' => $this->requiredTier->value];   // 'elit', enum değil
```

Zarf JSON'a serileştirilir ve sözleşme string bekler (`types.ts` →
`SubscriptionTier = 'standart' | 'gold' | 'elit'`). Enum nesnesi gönderilseydi
`json_encode` onu (backed enum olduğu için) yine değere çevirirdi — ama bu bir
**tesadüf**tür, sözleşme tesadüfe bağlanmaz.

---

## 2. `InvitationAlreadyPublishedException` — 409 neden doğru?

```
403 → "yetkin yok"        (kullanıcıya yapacak bir şey bırakmaz)
409 → "kaynak zaten o durumda"  ✅
```

### 🔴 Neden sessizce 200 dönmüyoruz?

İdempotans cazip görünüyor: *"zaten yayında, öyleyse başarılı say."* Reddedildi:

Yayın **ücretli** bir eylemdir ve yan etkileri vardır (`published_at` damgası,
cache temizliği, ileride bildirim). "Zaten yayında"yı başarı gibi göstermek
kullanıcıya **iki kez yayınladığını** ve muhtemelen **iki kez ödediğini**
düşündürür. Belirsizliği gizlemek yerine adlandırmak doğru olan.

> Karşılaştır: webhook'ta idempotans **isteniyor** (7.15) çünkü orada tekrar
> eden taraf bir **makinedir** ve niyeti "aynı sonucu teyit etmek"tir. Aynı
> teknik soru, farklı çağıran, farklı cevap — ders 42.

---

## 3. `InvalidWebhookSignatureException` — 🔴 404 bir savunmadır

Webhook ucu **auth'suzdur**. Tek savunma imza doğrulamasıdır — honeypot yok
(görünmez alan diye bir şey yok), kota yok (meşru bildirim sayısı belirsiz).

| Kod | Ne öğretir | Karar |
|---|---|---|
| 401 | "İmzan kontrol edildi ve tutmadı" + frontend oturum düşürür | ❌ |
| 403 | Ucun **varlığını** doğrular (H7'nin gerekçesi) | ❌ |
| 400 | Bozuk gövde ile sahte imzayı **ayırt ettirir** | ❌ |
| **404** | Hiçbir ayrım vermez | ✅ |

Meşru sağlayıcı bu yanıtla hiç karşılaşmaz. Karşılaşan yalnızca deneyen
taraftır ve ona öğretecek bir şey yoktur — Faz 5'in honeypot'u ile aynı fikir
(**L2**: *reddin kendisi bilgi sızıntısıdır*).

Gerçek sebep `getMessage()` içinde, yani **log'da ve yerel `debug` bloğunda**
açık; yanıtta yok. K20 §3.1'in Faz 2'de kurduğu desen.

---

## 4. `PaymentProviderException` — 502 ile 503'ün ayrımı (K27)

```php
PaymentProviderException::rejected($e);            // 502
PaymentProviderException::unavailable('iyzico');   // 503
```

RFC 9110 üç ayrı yeri işaret eder ve izleme alarmları bu ayrıma göre
yönlendirilir:

| Durum | Sorun nerede | Bizim karşılığımız |
|---|---|---|
| 500 | **Bizim kodumuzda** — yakalanmamış hata | Bilinmeyen `Throwable` |
| 502 | **Yukarı akışta** — cevap verdi ama hatalı | Sağlayıcı işlemi reddetti |
| 503 | **Bu serviste** — geçici olarak veremiyoruz | Sağlayıcı yapılandırılmamış / erişilemiyor |

Bilinen bir hatayı 500 ile bildirmek, grafikte **gerçek 500'lerin arasına
karışır** ve alarmı köreltir.

### `retryAfter` neden ikisinde de dönüyor ama biri sessizce düşüyor?

```php
public function errorParams(): array
{
    return ['retryAfter' => self::RETRY_AFTER_SECONDS];
}
```

`ErrorCode::allowedParams()`:

- `PROVIDER_UNAVAILABLE` → `['retryAfter']` ✅ çıkar
- `PAYMENT_PROVIDER_ERROR` → `[]` ❌ **sessizce düşer**

Bu bir kaza değil, **H12'nin çalıştığının kanıtıdır**: exception "verilebilir"
der, `ErrorCode` "çıkabilir" der, dar olan kazanır. RFC 9110 §10.2.3
`Retry-After`'ı 429 ve 503 ile eşler — beyaz liste standardı **kodda** zorluyor.

### `previous` neden taşınıyor?

```php
parent::__construct($message, 0, $previous);
```

Sağlayıcının ham hatası (HTTP gövdesi, sürücü exception'ı) **yanıta girmez**
(H8) ama kaybolmaz da: `previous` zinciri log'a ve yerel `debug` bloğuna gider.
Üretimde teşhis edilemeyen bir ödeme hatası, teşhis edilemeyen bir gelir
kaybıdır.

### `RETRY_AFTER_SECONDS` neden config'te değil?

Bu bir **iş ayarı** değil, bir HTTP nezaket değeri. Config'e konsaydı
"ayarlanması gereken bir şey" gibi görünür ve kimse dokunmadığı için ölü bir
anahtar olurdu. Faz 5'in **ders 46**'sı (*"geçici olanı geçici görünen bir yere
koy"*) burada ters yönde uygulanıyor: **kalıcı olanı kalıcı görünen yere koy.**

---

## 5. Dördünün ortak sözleşmesi

```php
final class X extends RuntimeException implements HasErrorCode
{
    public function errorCode(): ErrorCode;
    public function errorParams(): array;
}
```

| | Kod | Durum | `params` |
|---|---|---|---|
| `PaywallViolationException::noPurchase()` | `PAYMENT_REQUIRED` | 402 | `requiredTier` |
| `PaywallViolationException::insufficientTier()` | `PAYWALL_TIER_INSUFFICIENT` | 402 | `requiredTier` |
| `InvitationAlreadyPublishedException` | `INVITATION_ALREADY_PUBLISHED` | 409 | — |
| `InvalidWebhookSignatureException` | `RESOURCE_NOT_FOUND` | 404 | — |
| `PaymentProviderException::rejected()` | `PAYMENT_PROVIDER_ERROR` | 502 | — (düşer) |
| `PaymentProviderException::unavailable()` | `PROVIDER_UNAVAILABLE` | 503 | `retryAfter` |

Altı senaryo, dört sınıf, **sıfır renderer değişikliği**.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `implements HasErrorCode` yazmayı unutmak | `default` koluna düşer → 500 |
| 2 | Kurucuyu `public` bırakmak | Kod/senaryo eşlemesi yorumla korunur, bozulur |
| 3 | İmza hatasına 401 dönmek | Frontend interceptor'ı oturum düşürür + saldırgana sinyal |
| 4 | "Zaten yayında"yı 200 saymak | Kullanıcı iki kez ödediğini sanır |
| 5 | Sağlayıcının ham hatasını mesaja koymak | H8 ihlali; `getMessage()` yerel `debug`'a çıkar |
| 6 | `ErrorCode` değiştirip `errors:export` koşmamak | `composer check` **testlerden önce** kırılır (K34) |
| 7 | 502 yerine 503 kullanmak | İzleme alarmı yanlış ekibi uyandırır (K27) |

---

## 7. Kendin dene

```php
// php artisan tinker
use App\Exceptions\{PaywallViolationException, PaymentProviderException};
use App\Enums\SubscriptionTier;

$e = PaywallViolationException::noPurchase(SubscriptionTier::Elit);
$e->errorCode()->value;         // 'PAYMENT_REQUIRED'
$e->errorCode()->status();      // 402
$e->errorParams();              // ['requiredTier' => 'elit']

$p = PaymentProviderException::rejected();
$p->errorCode()->status();                                  // 502
$p->errorParams();                                          // ['retryAfter' => 60]
$p->errorCode()->filterParams($p->errorParams());           // [] 🔴 beyaz liste düşürdü

$u = PaymentProviderException::unavailable('iyzico');
$u->errorCode()->filterParams($u->errorParams());           // ['retryAfter' => 60]
```

```powershell
php artisan errors:export
git diff contracts/error-codes.json     # PAYMENT_REQUIRED params dolmuş olmalı
```

**Mutasyon denemesi (kural 14):** `PaywallViolationException`'daki
`implements HasErrorCode`'u sil. `php artisan test --filter=PaywallTest`
çalıştır. Paywall testleri `402` yerine `500` görüp kırılmalı.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Adlandırılmış kurucu** | `new` yerine anlamı taşıyan statik üretim metodu |
| **Marker + contract** | Davranış değil, bir yeteneği bildiren arayüz deseni |
| **`previous`** | Bir exception'ı doğuran alttaki exception (zincir) |
| **Beyaz liste** | Varsayılan kapalı; yalnızca sayılanlar açık |
| **RFC 9110** | Güncel HTTP semantiği standardı |
| **User enumeration** | Yanıt farkından kayıtlı kimlikleri çıkarma saldırısı |

---

## 9. Sırada ne var?

**7.6 — `app/Services/Payment/PaymentGateway.php`.** Sağlayıcıyı arayüzün
arkasına almak (K8, Strategy Pattern) ve iki saf veri kabı: `CheckoutSession`,
`PaymentNotification`.
