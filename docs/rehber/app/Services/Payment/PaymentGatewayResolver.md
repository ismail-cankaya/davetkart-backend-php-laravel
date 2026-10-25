# `app/Services/Payment/PaymentGatewayResolver.php` — sağlayıcıyı gerektiğinde kur

> **Faz:** 13, adım 13.5 · **Karar:** K136
> **Test:** `PaymentMethodGateTest::a_free_code_does_not_need_a_configured_provider`

---

## 1. Sorun

Faz 12'ye kadar `StartCheckoutAction` sağlayıcıyı **kurucusunda** istiyordu:

```php
public function __construct(private readonly PaymentGateway $gateway, ...) {}
```

Laravel bu Action'ı her kurduğunda `PaymentGateway`'i de kurar. `ShopierGateway`'in
kurucusu ise boş sırda 503 fırlatır (K123). Bugünkü üretim ayarı şöyle:

```dotenv
PAYMENT_METHODS=gift_code     # kart kapalı
PAYMENT_PROVIDER=shopier      # ama sürücü yine shopier
SHOPIER_API_SECRET=           # ve sır boş
```

Bu durumda **%100'lük hediye koduyla bedava sipariş** bile 503 alırdı: sipariş sağlayıcıya
hiç gitmeyecek olsa da sağlayıcı kurulmaya çalışılırdı.

## 2. Çözüm: ertele, değiştirme

```php
$gateway = $this->gateways->resolve();   // yalnızca ödenecek tutar varsa
```

`PaymentGatewayResolver` kurallardan **hiçbirini** taşımıyor. "Hangi sürücü?", "sır eksik mi?",
"bilinmeyen sürücü 503" kuralları `AppServiceProvider::resolvePaymentGateway()`'de kaldı.
Resolver yalnızca o bağlamayı **çağrı anına** erteliyor (lazy resolution).

Bunun iki faydası var:

- Testlerin `$this->app->bind(PaymentGateway::class, ...)` ile kurduğu sahte sağlayıcılar
  aynen çalışır, çünkü resolver aynı bağlamayı soruyor.
- Kart açıkken sır eksikse hata yine **gürültülü** (`an_unconfigured_provider_still_fails_loudly_when_the_card_is_on`).

## 3. "Container enjekte etmek service locator değil mi?"

Genelde öyledir ve kaçınılır: bir sınıf container'ı alıp istediğini çekerse bağımlılıkları
imzasında görünmez. Burada durum farklı. Resolver **tek bir** arayüzü, **tek bir** amaçla
çözüyor ve adı da bunu söylüyor. `StartCheckoutAction`'ın imzası hâlâ dürüst:
"bir sağlayıcıya ihtiyacım olabilir". Laravel'in kendi `Illuminate\Support\Lazy*`
yardımcıları ve "factory" kalıbı da aynı fikir.
