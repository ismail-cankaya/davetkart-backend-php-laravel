# `tests/Feature/PaymentDriverResolutionTest.php`

> **Faz:** 12 — adım 12.8 · **Sınadığı:** `AppServiceProvider::resolvePaymentGateway` + `ShopierGateway` kurucusu (K70, K123)

## Sorusu

*"Yapılandırma yanlışsa ne olur?"* Cevap her zaman aynı olmalı: **gürültü**. Ödeme uçları
503 `PROVIDER_UNAVAILABLE`, `orders` tablosuna satır yazılmaz, sistemin geri kalanı
(davetiye okuma, LCV) çalışır.

## Testler

| Test | Kanıtladığı |
|---|---|
| `the_configured_drivers_resolve` | `shopier` → `ShopierGateway`, `fake` → `FakeGateway` |
| `a_shopier_checkout_returns_a_signed_form` | **Uçtan uca**: HTTP checkout → `checkout.type=form`, `redirectUrl` YOK, sipariş `provider=shopier` ve `provider_ref=id`, imza tutuyor, sır yanıtta yok |
| `a_misconfigured_provider_is_503_and_writes_no_order` (4 veri) | Bilinmeyen sürücü, boş sır, anahtar yok, `osb` modunda OSB yok |
| `callback_mode_resolves_without_osb_credentials` | `callback` modunda OSB isteğe bağlı |
| 🆕 `the_callback_confirmation_mode_is_refused_in_production` | Faz 18.5 (`SEC-03`, K170): üretim + `callback` → 503 `PROVIDER_UNAVAILABLE`, sipariş yok, `critical` log yazıldı |
| 🆕 `the_callback_mode_still_works_outside_production` | Regresyon: test ortamında `callback` kipiyle checkout 201 + form |
| 🆕 `osb_mode_works_in_production` | Regresyon: üretimde `osb` kipi kurulur, checkout 201 + form |
| 🆕 `the_shopier_callback_url_ignores_the_request_host` (2 veri) | Faz 19.1 (`SEC-07`, K180): `Host: evil.example` ve `TRUSTED_PROXIES=*` + `X-Forwarded-Host: evil.example` ile checkout; formdaki `callback` `APP_URL` + yol |

## 🔴 "Sipariş yazılmaz" neden önemli?

`StartCheckoutAction` sürücüyü kurucusunda alıyor (autowiring). Sürücü kurulamazsa
Action hiç kurulmuyor, yani sipariş satırı hiç yazılmıyor. Kontrol çağrı anında
(`startCheckout` içinde) olsaydı sipariş yazılır, sonra `failed` işaretlenirdi: her
yapılandırma hatası veritabanında bir çöp satır bırakırdı. `assertDatabaseCount('orders', 0)`
bu sıralamayı kilitliyor.

## 🆕 Faz 18.5 — üretim ortamını testte nasıl kuruyoruz?

```php
$this->app->detectEnvironment(fn (): string => 'production');
```

`App::isProduction()` her çağrıda ortamı kapsayıcıdan okur; test içinde değiştirmek yeterli.
Açılışta bir kez okunan ayarlar (`Model::shouldBeStrict`, `DB::prohibitDestructiveCommands`)
değişmez, yani test yalnızca çalışma anındaki kontrolü sınar. Aynı yöntem `ShopierOsbTest`'in
`istest` testinde de kullanılıyor.

Üç test bir üçlü: biri kuralı (üretim + `callback` → 503), ikisi kuralın **dar** olduğunu
(üretim dışında `callback`, üretimde `osb` çalışıyor) kilitler. Yalnızca birincisi olsaydı
"her yerde 503" diyen bir mutant da geçerdi. Mutantlar: [`ShopierGateway.md`](../../app/Services/Payment/ShopierGateway.md) §11.

## 🆕 Faz 19.1 — isteğin host'unu testte nasıl değiştiriyoruz?

```php
Config::set('app.url', 'https://davetkart.test');
$this->postJson('http://evil.example'.route('payments.checkout', absolute: false), ['tier' => 'gold']);
```

- **Neden mutlak adres, neden `withHeader('Host', …)` değil?** Laravel test isteğini her zaman
  mutlak bir adresten kurar (`prepareUrlForRequest` → `url()`), Symfony `Request::create` de
  `HTTP_HOST`'u o adresin host'uyla **ezer**. `withHeader('Host', 'evil.example')` sessizce yok
  sayılır: istek `APP_URL`'in host'una gider ve test hiçbir şey sınamamış olur. Bu, mutant
  denemesinde ayrı bir veri satırıyla görüldü (callback `http://localhost:8000/…` çıktı).
- **İkinci veri satırı:** `Config::set('trustedproxy.proxies', '*')` + `X-Forwarded-Host`. Laravel'in
  `TrustProxies` ara katmanı listeyi **her istekte** sıfırlayıp config'ten yeniden kurar; ayar bir
  sonraki teste sızmaz.
- **`app.url` neden testte ayarlanıyor?** Beklenen değer `.env`'deki `APP_URL`'e bağlı kalmasın ve
  saldırganın host'undan açıkça farklı olsun.

Mutant (`url(self::CALLBACK_PATH)`'e geri dönüş) iki satırı da kırmızıya çevirir:
[`ShopierGateway.md`](../../app/Services/Payment/ShopierGateway.md) §12.
