# `app/Services/Payment/FakeGateway.php`

> **Kod dosyaları:** `app/Services/Payment/FakeGateway.php` ·
> `app/Providers/AppServiceProvider.php` (`resolvePaymentGateway()`)
> **Faz:** 7 — Ödeme ve paywall, dosya 7.7
> **Arayüzü:** [`PaymentGateway.md`](PaymentGateway.md)
> **Karar:** **K8**

---

## 1. 🔴 Bu bir "stub" değil, çalışan bir sürücü

Sahte sürücü yazmanın iki yolu vardır:

```php
// ❌ Yer tutucu (stub): akışın hiçbir parçası sınanmaz
public function parseNotification(string $p, string $s): PaymentNotification
{
    return new PaymentNotification('ref', OrderStatus::Paid);   // her şeye "paid"
}

// ✅ Gerçek sürücü, sahte para
public function parseNotification(string $p, string $s): PaymentNotification
{
    $this->assertSignatureIsValid($p, $s);        // gerçek HMAC
    …                                             // gerçek JSON ayrıştırma
    return new PaymentNotification($ref, $this->translateStatus($raw));  // gerçek çeviri
}
```

Fark neden önemli? Stub yazsaydık **imza doğrulaması ve durum çevirisi
üretimde ilk kez** çalışırdı — yani en pahalı yerde, para akarken.

Faz 6'nın 26. dersinin ödeme hâli: *çalıştırılmayan kod, doğru olduğu varsayılan
koddur.* Bu sürücü sayesinde `PaywallTest` gerçek bir imzayı gerçekten
doğruluyor.

### Null Object Pattern ile karıştırma

| | Ne der | Nerede |
|---|---|---|
| **NullProvider** | "Hiçbir şey yapma" | Faz 8, AI sağlayıcısı |
| **FakeGateway** | "Her şeyi yap, yalnızca para alma" | Burada |

---

## 2. `startCheckout()` — ağ çağrısı yok

```php
return new CheckoutSession(
    providerRef: 'fake_'.Str::ulid()->toBase32(),
    redirectUrl: config('payment.return_urls.success').'?order='.$order->getKey(),
    expiresAt: now()->addMinutes(config('payment.order_expires_after_minutes'))->toImmutable(),
);
```

| Karar | Gerekçe |
|---|---|
| Ağ çağrısı yok | Testler ne yavaşlar ne kırılgan olur; CI internet istemez |
| `'fake_'` öneki | Referans, gerçek sağlayıcı referanslarından **bakışta** ayırt edilir |
| `Str::ulid()` | Kolon UNIQUE; çakışma pratikte imkânsız |
| `?order=` sorgusu | Geliştirme aracının siparişi bulup webhook'u tetikleyebilmesi için |

`toImmutable()`: `now()` `CarbonImmutable` döndürüyor (K23,
`AppServiceProvider::configureDates()`) ama `addMinutes()` zincirinin tipini
DTO'nun beklediği tipe **açıkça** sabitlemek PHPStan'a belirsizlik bırakmaz.

---

## 3. 🔴 İmza doğrulaması — webhook'un tek savunması

```php
$expected = hash_hmac('sha256', $payload, Config::string('app.key'));

if (! hash_equals($expected, $signature)) {
    throw new InvalidWebhookSignatureException;
}
```

### 3.1 HMAC nedir, düz hash neden yetmez?

**HMAC (Hash-based Message Authentication Code):** gövde ile **paylaşılan bir
sır** birlikte hash'lenir.

```
hash('sha256', $payload)                 → herkes hesaplayabilir → kimlik kanıtı DEĞİL
hash_hmac('sha256', $payload, $secret)   → yalnızca sırrı bilen hesaplayabilir ✅
```

Sır, hash'i bir **kimlik kanıtına** çevirir. Sağlayıcı ve biz aynı sırrı
biliriz; üçüncü bir taraf gövdeyi değiştirdiğinde geçerli imza üretemez.

### 3.2 🔴 `hash_equals()`, `===` değil

```php
$expected === $signature      // ❌ ilk farklı baytta durur
hash_equals($expected, $sig)  // ✅ her zaman tüm baytları karşılaştırır
```

Normal karşılaştırma **erken çıkar**. Saldırgan yanıt süresini ölçerek imzayı
bayt bayt bulabilir — buna **zamanlama saldırısı (timing attack)** denir.
`hash_equals()` sabit sürede çalışır.

Faz 2'de `LoginUserAction` aynı dersi vermişti (**A4**: *güvenlik kodunda kısa
devre yasak*) ve ders 22 sistemlerin "bir yerden sertleşince başka bir yerden
esnediğini" göstermişti. Burada esneme **yok**, çünkü savunma baştan doğru
fonksiyonla yazıldı.

### 3.3 Sır neden `APP_KEY`?

- Repoya sır yazılmaz (`CLAUDE.md` §3) — `APP_KEY` zaten `.env`'de
- Testlerde hazır: `phpunit.xml` bir `APP_KEY` sağlıyor, sahte imza
  hesaplanabiliyor
- Gerçek sürücüde bu değer
  `config('payment.providers.iyzico.webhook_secret')` olacak — `config/payment.php`
  o anahtarı Faz 0'dan beri taşıyor

### 3.4 Sıra: önce imza, sonra gövde (L1)

```php
$this->assertSignatureIsValid($payload, $signature);   // 1
$decoded = json_decode($payload, true);                // 2
```

Ters sırada imzasız bir istek bile **JSON ayrıştırma** yaptırırdı ve hata
farkından *"gövden bozuk ama imzan sorulmadı"* bilgisi sızardı. **L1**: en ucuz
ve en çok eleyen katman en başta.

---

## 4. `translateStatus()` — sözlük sınırda çevrilir

```php
return match ($raw) {
    'paid' => OrderStatus::Paid,
    'failed' => OrderStatus::Failed,
    'refunded' => OrderStatus::Refunded,
    default => throw new BadRequestHttpException("Unknown provider status '{$raw}'."),
};
```

Sağlayıcıların kendi sözlüğü vardır: `'SUCCESS'`, `'CAPTURED'`, `4`. Çeviri
**sürücünün** işidir; sınırdan sonra sistemde tek sözlük konuşulur.

### `pending` neden kabul edilmiyor?

Webhook bir **sonuç** bildirimidir. "Hâlâ bekliyor" diyen bir bildirim hiçbir
geçişi tetiklemez ve `OrderStatus::canTransitionTo()` tarafından zaten
reddedilirdi — ama burada reddetmek hatayı **kaynağına yakın** tutar.

### Neden 400, 404 değil?

İmza **geçerli** — yani gönderen gerçekten sağlayıcı. Ona "böyle bir şey yok"
demek yanlış bilgi vermektir; "gövdeni anlamadım" demek doğru olan. Sessizlik
(§3, 404) yalnızca **kimliği doğrulanmamış** taraf içindir.

`BadRequestHttpException` yeni bir sınıf gerektirmez: `HttpExceptionInterface`
uyguladığı için `ApiExceptionRenderer::fromStatus(400)` onu
`MALFORMED_REQUEST`'e çevirir (Faz 1'de yazılmış geri eşleme).

---

## 5. Bağlama: `resolvePaymentGateway()`

```php
$this->app->bind(PaymentGateway::class, $this->resolvePaymentGateway(...));
```

### 5.1 `$this->method(...)` nedir?

PHP 8.1'in **first-class callable syntax**'ı: metodu çağırmadan bir closure'a
çevirir. `fn ($app) => $this->resolvePaymentGateway($app)` ile aynı, daha az
gürültülü. `configureRateLimiting()` bunu Faz 5'te zaten kullanıyordu.

### 5.2 🔴 Closure kayıt anında değil, çözüm anında çalışır

Sürücü seçimi doğrudan `register()` gövdesine yazılsaydı, hatalı bir
`PAYMENT_PROVIDER` değeri **uygulamanın açılışını** kırardı. Oysa kırması
gereken yalnızca ödeme uçlarıdır: sağlık sondası, davetiye okuma ve LCV
çalışmaya devam etmelidir.

**Hata yarıçapını (blast radius) daraltmak**: bir yapılandırma hatası, ona
bağımlı olmayan uçları düşürmemeli.

### 5.3 🔴 Bilinmeyen sürücü sessiz bir varsayılana düşmez

```php
default => throw PaymentProviderException::unavailable($default),
```

Cazip alternatif: *"bulamazsan `fake` kullan."* **Reddedildi** — çünkü
üretimde `IYZICO_API_KEY` eksik olduğu gün sistem her ödemeyi sahte olarak
**başarılı** sayardı. Bir yapılandırma hatası sessizce **bedava yayına**
dönüşürdü.

Aynı refleks Faz 5'te `TierRsvpQuotaResolver`'da vardı: *"kota okunamıyorsa
kotasız devam etmek, ödemeli bir sınırın sessizce kalkması demektir."*
Güvenlikte varsayılan **kapalı** olmalıdır (fail-safe, K12'nin aynı fikri).

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | İmza kontrolünü `===` ile yapmak | Zamanlama saldırısıyla imza bayt bayt bulunur |
| 2 | Düz `hash()` kullanmak | İmzayı herkes hesaplar; savunma yok olur |
| 3 | Önce JSON ayrıştırıp sonra imza bakmak | İmzasız istek iş yaptırır + bilgi sızar (L1) |
| 4 | Bilinmeyen sürücüde `fake`'e düşmek | Yapılandırma hatası bedava yayına dönüşür |
| 5 | Sürücü seçimini `register()` gövdesine yazmak | Yanlış config tüm uygulamayı düşürür |
| 6 | Sahte sürücüyü boş gövdeli stub yapmak | İmza/çeviri ilk kez üretimde çalışır |
| 7 | Sırrı config dosyasına düz metin yazmak | Repoya sır girer (`CLAUDE.md` §3) |

---

## 7. Kendin dene

```php
// php artisan tinker
use App\Services\Payment\PaymentGateway;

$g = app(PaymentGateway::class);
$g->name();                     // 'fake'

$payload = json_encode(['providerRef' => 'fake_x', 'status' => 'paid']);
$sig = hash_hmac('sha256', $payload, config('app.key'));

$n = $g->parseNotification($payload, $sig);
$n->providerRef;                // 'fake_x'
$n->status;                     // OrderStatus::Paid

// 🔴 Tek bayt bozulunca:
$g->parseNotification($payload, $sig.'0');
// App\Exceptions\InvalidWebhookSignatureException

// 🔴 Bilinmeyen sürücü:
config(['payment.default' => 'stripe']);
app()->forgetInstance(PaymentGateway::class);
app(PaymentGateway::class);
// PaymentProviderException: Payment provider 'stripe' is not available.
```

**Mutasyon denemesi (kural 14):** `hash_equals(...)` yerine
`$expected === $signature` yaz — testler **yeşil kalır**. 🔴 Bu, bu tablonun
kabul ettiği boşluktur: zamanlama saldırısı tek süreçli bir testte
kurulamaz (**T15** ailesi). Elle doğrulama betiğinde de yeri yok; koruma
yalnızca kod incelemesiyle korunur. **B6**: bir savunmanın neyi kapatmadığı
da yazılır.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **HMAC** | Sır ile birlikte hesaplanan, kimlik kanıtı sağlayan hash |
| **Zamanlama saldırısı** | Yanıt süresi farkından gizli değeri çıkarma |
| **`hash_equals()`** | Sabit sürede string karşılaştırması |
| **Stub** | İş yapmayan, yalnızca imzayı dolduran sahte uygulama |
| **First-class callable** | `$this->m(...)` — metodu closure'a çeviren PHP 8.1 sözdizimi |
| **Fail-safe** | Hata durumunda **kapalı/güvenli** tarafa düşmek |
| **Blast radius** | Bir hatanın etkilediği alanın genişliği |

---

## 9. Sırada ne var?

**7.8 — `app/Services/Pricing/TierResolver.php`.** Frontend'in
`getRequiredTier()` fonksiyonunun **sunucu ikizi**: davetiyenin açık
modüllerinden gereken planı hesaplar. K6'nın (`show_*` ayrı kolonlar) Faz
3'te ödenmiş bedeli burada karşılığını buluyor.
