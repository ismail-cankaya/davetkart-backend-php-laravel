# `app/Services/Payment/CheckoutAction.php` · `RedirectAction.php` · `FormPostAction.php`

> **Faz:** 12 — Shopier ile kartla ödeme, adım 12.1
> **Karar:** **K118** (E2) — *"`CheckoutSession` bir URL değil bir `CheckoutAction` taşır"*
> **Kullananlar:** [`PaymentGateway.md`](PaymentGateway.md) · [`ShopierGateway.md`](ShopierGateway.md) ·
> [`../../Http/Resources/OrderResource.md`](../../Http/Resources/OrderResource.md)

---

## 1. Problem: "ödeme sayfasına git" tek biçimli değil

Faz 7'de sağlayıcı ne derse desin cevap bir **adres**ti (`redirectUrl`). Shopier'in
klasik akışı farklı çalışıyor: ödeme sayfası bir adrese **gidilerek** değil,
imzalı alanlar taşıyan bir **form POST** edilerek açılıyor.

| Sağlayıcı | Tarayıcı ne yapar |
|---|---|
| `FakeGateway`, hediye siparişi | `GET /odeme/basarili?order=…` |
| Shopier | `POST https://www.shopier.com/ShowProduct/api_pay4.php` + 29 alan |

## 2. Neden iki sınıf, tek sınıf değil?

İlk akla gelen çözüm:

```php
// ❌ İki boş alanlı tek sınıf
final readonly class CheckoutSession {
    public ?string $redirectUrl;
    public ?string $formUrl;
    public ?array  $formFields;
}
```

Bu sınıf **anlamsız durumları** da temsil edebilir: üçü de dolu, üçü de boş,
`formUrl` var ama `formFields` yok… Her okuyan kod bu durumları ayrıca sınamak
zorunda kalır ve biri unutur.

Doğru çözüm bir **tagged union**: "ya şu ya bu", ikisi birden asla.

```
          CheckoutAction (arayüz)
           ├── RedirectAction(url)
           └── FormPostAction(url, fields)
```

Bu desenin kuralı: *geçersiz durumları temsil edilemez yap* (make illegal states
unrepresentable). Kontrol "çalışma anında bir `if`" olmaktan çıkar, "tipin kendisi"
olur. TypeScript tarafında birebir karşılığı var (12.10):

```ts
type CheckoutAction =
  | { type: 'redirect'; url: string }
  | { type: 'form'; url: string; fields: Record<string, string> };
```

### PHP'de neden `interface`, `enum` değil?

PHP'de "sealed" sınıf yok. Değer taşıyan bir enum da yok (Rust'taki gibi). En yakın
araç: bir arayüz + `final` sınıflar. Bedeli: derleyici "üçüncü bir uygulama
yazılamaz" demiyor. Okuyan taraf (`OrderResource`) bu yüzden `instanceof`
eşleştirmesinin sonunda bir `LogicException` fırlatıyor. Bilinmeyen bir tür
sessizce yanlış bir JSON üretmesin, gürültüyle kırılsın.

### Neden `public` özellik değil de `url()` metodu?

`phpstan.neon`'da `phpVersion: 80300`. Arayüzde özellik bildirmek (property hooks)
PHP 8.4'ün özelliği. 8.3 sözdizimiyle arayüz yalnızca **metot** isteyebilir.

## 3. `FormPostAction` kurucusunun iki sınavı

```php
new FormPostAction('https://…', ['total_order_value' => 399]);   // ❌ InvalidArgumentException
new FormPostAction('https://…', ['total_order_value' => '399.00']); // ✅
```

1. **Her değer string.** HTML formunda sayı yok: tarayıcı her şeyi metin olarak
   gönderir. İmza da metin üzerinden hesaplanır. `399` (int) JSON'da `399`,
   `'399.00'` ise `"399.00"` olur. İmzanın içindeki metin ile forma yazılan metin
   **aynı bayt dizisi** olmalı (E8). Tek tip, tek yorum.
2. **Adres mutlak bir http(s) adresi.** Form başka bir siteye gidiyor. Göreli bir
   adres (`/odeme`) bizim sitemize POST ederdi.

`RedirectAction` göreli adres kabul eder, çünkü frontend kendi rotasına gidebilir
(`/odeme/basarili?order=…`). Boş adresi reddeder.

## 4. 🔴 Değişmezlik neden bir güvenlik özelliği?

`readonly` sınıf kurulduktan sonra değişmez. Alanların bir kısmı **imzanın
içinde** (`platform_order_id`, `total_order_value`, `currency`, `random_nr`).
Nesne değiştirilebilseydi, imzası doğru ama tutarı farklı bir form üretmek mümkün
olurdu. Shopier onu reddederdi, ama hata bizde doğmuş ve en pahalı yerde (ödeme
sayfasında) fark edilmiş olurdu.

## 5. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Alan değerini `(int)` bırakmak | Kurucu reddeder. Reddetmeseydi JSON'da sayı, imzada metin olurdu |
| `FormPostAction`'a göreli adres vermek | Kurucu reddeder |
| `instanceof` zincirine `else` ile varsayılan kol yazmak | Yeni bir tür eklendiğinde sessizce `redirect` gibi davranırdı. Varsayılan kol **fırlatmalı** |

## 6. Kendin dene

```php
php artisan tinker
>>> new App\Services\Payment\FormPostAction('/odeme', ['a' => 'b']);
// InvalidArgumentException: ... absolute http(s) URL
>>> $a = new App\Services\Payment\FormPostAction('https://example.com/pay', ['a' => 'b']);
>>> $a->fields();
=> ["a" => "b"]
>>> new App\Services\Payment\RedirectAction('  ');
// InvalidArgumentException
```
