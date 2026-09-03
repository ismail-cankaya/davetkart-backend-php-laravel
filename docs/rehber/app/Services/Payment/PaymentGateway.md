# `app/Services/Payment/PaymentGateway.php`

> **Kod dosyaları:** `app/Services/Payment/PaymentGateway.php` (arayüz) ·
> `CheckoutSession.php` · `PaymentNotification.php` (iki veri kabı)
> **Faz:** 7 — Ödeme ve paywall, dosya 7.6
> **Uygulaması:** [`FakeGateway.md`](FakeGateway.md) (7.7)
> **Karar:** **K8** — *"`PaymentGateway` arayüzü + `FakeGateway`; sağlayıcı
> anlaşması beklenmeden doğru akış kurulur. Strategy Pattern"*

---

## 1. Problem: bugün olmayan bir servise bugün ihtiyaç var

Faz 5'in `RsvpQuotaResolver`'ı ile **aynı sıralama problemi**, farklı eksen:

| | Faz 5 | Faz 7 |
|---|---|---|
| Eksik olan | Bir **veri kaynağı** (`subscriptions` tablosu) | Bir **dış servis** (Iyzico anlaşması) |
| Ne zaman gelecek | Faz 7 | Faz 9 |
| Çözüm | `RsvpQuotaResolver` arayüzü | `PaymentGateway` arayüzü |
| Değişecek olan | `AppServiceProvider`'daki bağlama satırı | Aynı |

Üç seçenek vardı (Faz 5'teki tabloyla birebir):

| Seçenek | Sonuç |
|---|---|
| **A** — Action doğrudan Iyzico SDK'sını çağırsın | Anlaşma olmadan **hiçbir şey** yazılamaz. Testler ağa çıkar, CI kırılgan olur |
| **B** — Ödemeyi Faz 9'a ertele | Paywall'sız bir "bedava yayın" yolu açılır — K47'nin Faz 4'te engellediği şey |
| **C** ✅ — Araya bir **arayüz** koy | Bugün `FakeGateway`, yarın `IyzicoGateway`. Değişen tek satır bağlamadır |

---

## 2. Strategy Pattern nedir?

**Strateji deseni:** Aynı işi yapmanın birden çok yolu varsa, her yolu ayrı
bir sınıfa koy, hepsini aynı arayüzün arkasına al, hangisinin kullanılacağına
**dışarıda** karar ver.

```
                 ┌─────────────────┐
   StartCheckout │ PaymentGateway  │  ← Action YALNIZCA bunu tanır
      Action ───→│   (arayüz)      │
                 └────────┬────────┘
                          │
            ┌─────────────┼──────────────┐
       FakeGateway   IyzicoGateway   PayTRGateway
       (bugün)        (Faz 9)         (belki hiç)
```

Alternatifi bir `if` zinciridir ve neden kötü olduğu somuttur:

```php
// ❌ Her yeni sağlayıcı bu dosyayı DEĞİŞTİRİR (Open/Closed ihlali)
if ($provider === 'iyzico')      { /* 40 satır */ }
elseif ($provider === 'paytr')   { /* 40 satır */ }
elseif ($provider === 'fake')    { /* 5 satır  */ }
```

Bu blok zamanla üç sağlayıcının kurallarını **birbirine bulaştırır**; bir
sağlayıcı için yapılan düzeltme diğerini bozar. Ayrı sınıflarda böyle bir temas
yüzeyi yoktur.

### SOLID karşılıkları

| Harf | Karşılığı |
|---|---|
| **S** | Her sürücü tek bir sağlayıcıyı bilir |
| **O** | Yeni sağlayıcı **eklenir**, var olan kod **değişmez** |
| **L** | Her sürücü arayüzün sözünü tutar; çağıran hangisi olduğunu bilmez |
| **I** | Üç metot. "İade", "taksit", "3D Secure" **bilerek yok** — yazılsalardı her sürücü boş gövde yazardı |
| **D** | Action somut sınıfa değil **soyutlamaya** bağlı |

---

## 3. Üç metot, üç sorumluluk

### 3.1 `name(): string`

Sürücü **kendi adını** söyler ve bu ad `orders.provider` kolonuna yazılır.

Neden `config('payment.default')` okunmuyor? **F4** (Faz 6, `media.disk`):

> Config **"şu an nereye yazıyoruz"**, kolon **"o kayıt nereye yazılmıştı"**
> sorusunu cevaplar.

Sağlayıcı değiştiği gün eski siparişler hâlâ hangi sürücüyle ödendiklerini
bilir. Sürücü kendi adını söylediği için config ile kolon **asla ayrışamaz**.

### 3.2 `startCheckout(Order $order): CheckoutSession`

🔴 **Sipariş önce veritabanında oluşur, sonra buraya gelir.** Ters sıra şu
deliği açardı:

```
1. Sağlayıcıda oturum aç      ✅ (kullanıcı ödeyebilir)
2. Veritabanına yaz           ❌ (hata)
→ ÖDENMİŞ AMA KAYDI OLMAYAN bir ödeme
```

Faz 6'nın **F3** kuralı (*"dosya sistemi transaction'a dâhil değildir"*) burada
ikinci kez karşımıza çıkıyor: **dış servis de transaction'a dâhil değildir.**
Sıra, geri alınamayan işi **en sona** koyacak şekilde seçilir.

### 3.3 `parseNotification(string $payload, string $signature)`

🔴 Bu metodun asıl işi **çevirmek değil doğrulamaktır.**

```php
public function parseNotification(string $payload, string $signature): PaymentNotification;
```

Neden `Request` almıyor? Arayüz `app/Services/` altında ve **HTTP bilmemeli**:

| `Request` alsaydı | İki string alınca |
|---|---|
| Her sürücü testi sahte bir `Request` kurar | Test iki string verir |
| Sürücü hangi başlığı okuyacağını **kendi** bilir → kural iki yere dağılır | Başlık adı tek yerde (`config/payment.php` → controller) |
| Kuyruktan/konsoldan çağrılamaz | Her yerden çağrılabilir |

---

## 4. `CheckoutSession` — neden dizi değil sınıf?

```php
final readonly class CheckoutSession
{
    public function __construct(
        public string $providerRef,
        public string $redirectUrl,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
```

**DTO (Data Transfer Object):** davranışı olmayan, yalnızca veri taşıyan sınıf.

| Dizi döndürseydik | Sınıf döndürünce |
|---|---|
| `$s['redirectURL']` yazım hatası **çalışma anında** `null` | PHPStan **yazarken** yakalar |
| Hangi anahtarlar var, belgeye bakmak gerekir | İmzada yazılı |
| Yeni sürücü farklı anahtar üretebilir | Tip zorlar |

`readonly`: kurucudan sonra hiçbir alan değişemez. Bir ödeme oturumunun
kimliği yolda değişemez — değişmezlik burada bir kolaylık değil bir **güvenlik
özelliğidir**. K23'ün (`CarbonImmutable`) aynı fikri, farklı tipte.

> **PHP temeli — constructor property promotion:** Kurucu parametresinin
> önüne `public` yazmak, aynı adda bir özellik tanımlar ve otomatik atar.
> `public string $providerRef` üç satırlık boilerplate'i tek satıra indirir.

---

## 5. `PaymentNotification` — 🔴 varlığı bir güvenlik iddiasıdır

```php
final readonly class PaymentNotification
{
    public function __construct(
        public string $providerRef,
        public OrderStatus $status,
    ) {}
}
```

Elinde bir `PaymentNotification` tutan kod, **imzanın doğrulandığını
varsayabilir** — çünkü onu üretebilen tek yer `parseNotification()`'dır ve
orada imza kontrolü çağrı yolunun üzerindedir.

Bu, `ErrorCode::filterParams()`'ın (H12) aynı fikridir: bir kuralı
*hatırlanması gereken bir adım* olmaktan çıkarıp **geçilmesi zorunlu bir
kapıya** dönüştürmek.

### Ham gövde neden taşınmıyor?

Taşınsaydı aşağıdaki katmanlar "ben bir daha bakayım" diyerek **ayrışan ikinci
bir yorum** üretebilirdi (**C3**). Sınırda çözülen belirsizlik içeride
yeniden açılmaz (ders 30).

### `status` neden `OrderStatus`?

Sağlayıcının kendi sözlüğü vardır: `'SUCCESS'`, `'CAPTURED'`, `'basarili'`,
`4`. Bu sözlüğü **bizim** dilimize çevirmek sürücünün işidir. Sınırdan sonra
tek bir sözlük konuşulur — `CLAUDE.md` §1'in "sihirli string yasağı"nın dış
servis sınırındaki hâli.

---

## 6. Bu arayüzün YAPMADIKLARI (B6)

| Yapmaz | Nerede yapılır |
|---|---|
| Siparişi oluşturmak/güncellemek | `StartCheckoutAction` · `HandlePaymentCallbackAction` |
| Yayın hakkına karar vermek | `PublishInvitationAction` |
| HTTP yanıtı üretmek | Controller (K3) |
| Hız sınırı / yetki | Rota katmanı |
| İade, taksit, 3D Secure | **Hiçbir yerde** — bugün ihtiyaç yok (I harfi) |

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Arayüze `refund()`, `installments()` eklemek | Her sürücü boş gövde yazar (Interface Segregation ihlali) |
| 2 | `parseNotification(Request $r)` yazmak | Servis katmanı HTTP'ye bağlanır, test zorlaşır |
| 3 | Sürücüde `Order`'ı kaydetmek | Sorumluluk karışır; Action'ın işi (K3) |
| 4 | `name()` yerine `config('payment.default')` okumak | Config ile kolon ayrışır (F4) |
| 5 | Önce sağlayıcıda oturum açıp sonra DB'ye yazmak | Ödenmiş ama kaydı olmayan ödeme |
| 6 | DTO yerine ilişkisel dizi döndürmek | Yazım hatası çalışma anına kaçar |
| 7 | `PaymentNotification`'a ham gövdeyi koymak | İkinci bir yorum kaynağı doğar (C3) |

---

## 8. Kendin dene

```php
// php artisan tinker
$gateway = app(App\Services\Payment\PaymentGateway::class);
get_class($gateway);          // App\Services\Payment\FakeGateway  (7.7'den sonra)
$gateway->name();             // 'fake'

$s = new App\Services\Payment\CheckoutSession('ref-1', 'https://example.test/pay');
$s->providerRef;              // 'ref-1'
$s->providerRef = 'x';        // 🔴 Error: Cannot modify readonly property
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Strategy Pattern** | Aynı işin farklı yollarını ayrı sınıflara koyup tek arayüzün arkasına almak |
| **DTO** | Davranışı olmayan, veri taşıyan sınıf |
| **`readonly`** | Kurucudan sonra değiştirilemeyen özellik/sınıf |
| **Property promotion** | Kurucu parametresine görünürlük yazarak özellik tanımlama |
| **Seam (dikiş yeri)** | İleride değişeceği bilinen yerde bilerek bırakılan ayrılma çizgisi |
| **Webhook** | Dış servisin bize HTTP isteği atarak olay bildirmesi |
| **Open/Closed** | Genişlemeye açık, değişikliğe kapalı olma ilkesi |

---

## 10. Sırada ne var?

**7.7 — `app/Services/Payment/FakeGateway.php`** ve arayüzün konteynere
bağlanması: sürücü seçimi `config('payment.default')`'tan okunur, bilinmeyen
sürücü **sessizce** değil `PROVIDER_UNAVAILABLE` (503) ile reddedilir.
