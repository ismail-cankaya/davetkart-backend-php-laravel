# `app/Services/Payment/ShopierGateway.php`

> **Kod dosyaları:** `ShopierGateway.php` · `Shopier/ShopierConfirmation.php` · `Shopier/ShopierCallback.php`
> **Faz:** 12 — adım 12.6 · **Test:** `tests/Feature/ShopierGatewayTest.php`
> **Kararlar:** K94 · **K117** (onay kanalı) · K120 (kendi sürücümüz) · **K121** (kimlikler) ·
> **K123** (sır koruması) · K124 (tutar) · K125 (alıcı alanları) · **K127** (callback ayrı metot) ·
> **K170** (üretimde `callback` kipi yok, Faz 18.5 → §11) · **K180** (callback adresi `APP_URL`'den, Faz 19.1 → §12)
> **Formüller:** [`Shopier/ShopierSignature.md`](Shopier/ShopierSignature.md) · [`Shopier/ShopierAmount.md`](Shopier/ShopierAmount.md)

---

## 1. Büyük resim: kart bizim sunucumuza hiç gelmiyor

```
Tarayıcı ──POST /invitations/{id}/checkout──▶ API
                                              StartCheckoutAction (fiyat sunucuda)
                                              ShopierGateway::startCheckout
Tarayıcı ◀── {checkout:{type:'form', url, fields}} ──
Tarayıcı ──gizli form POST──▶ shopier.com/…/api_pay4.php    ◀── kart BURADA yazılır
                                     │
            ┌────────────────────────┴───────────────────────┐
            ▼ (tarayıcı)                                       ▼ (Shopier sunucusu)
 POST /api/public/payments/shopier/callback        POST /api/public/payments/shopier/osb
 parseCallback() → yönlendir (303)                 parseOsb() → 'paid' kararı (K117)
```

Kart numarası yalnızca Shopier'in sayfasında yazılıyor. Bizim kodumuz onu **hiçbir
zaman görmüyor**. PCI DSS açısından sonuç: kart verisine dokunan bir sunucumuz yok,
denetim kapsamının en küçük seviyesindeyiz.

## 2. Üç metot, üç kanal

| Metot | Kim çağırır | Ne döner | Durumu değiştirebilir mi? |
|---|---|---|---|
| `startCheckout(Order)` | `StartCheckoutAction` | `FormPostAction` | — |
| `parseCallback(Request)` | `ShopierCallbackController` | `ShopierCallback` | Yalnızca `callback` modunda |
| `parseOsb(Request)` / `parseNotification(Request)` | `ShopierOsbController` | `PaymentNotification` | **Evet** (her modda) |

## 3. 🔴 Onay kanalı: `ShopierConfirmation` (K117)

Callback imzası `random_nr + platform_order_id`'yi kapsıyor; **`status` imzanın
dışında.** Saldırı:

```
1. Reddedilecek bir kartla öde → Shopier tarayıcıya imzalı bir callback verir:
   platform_order_id=01J…  random_nr=482913  signature=GEÇERLİ  status=failed
2. DevTools'ta aynı isteği tekrarla, yalnızca status=success yap
3. İmza hâlâ geçerli (status imzada yok) → sipariş 'paid'
```

Savunma bir `if` değil, bir **mod**:

| Mod | Callback | OSB |
|---|---|---|
| `osb` (varsayılan) | Yalnızca yönlendirir. `ShopierCallback::$notification` **null** | `paid` kararını verir |
| `callback` | İmzası doğruysa durumu işler (riskli). **Yalnızca yerel/test; üretimde sürücü kurulmaz (K170, §11)** | Kuruluysa yine işler (idempotan) |

Neden enum ve neden sürücünün yanında? Değerler sihirli string olmasın (CLAUDE.md
§1). Ama bu bir alan kavramı değil, tek sürücünün ayarı: `app/Enums/`'a koymak onu
olduğundan daha geniş gösterirdi.

Bilinmeyen mod (`SHOPIER_CONFIRMATION=browser`) sürücüyü **kurmaz** (503). Sessizce
`osb`'ye düşmek, yanlış yazılmış bir `callback`'i "neden ödemeler onaylanmıyor"
gizemine çevirirdi; sessizce `callback`'e düşmek riski kimsenin seçmediği bir yerde
açardı.

## 4. Neden `parseCallback` ayrı bir metot? (K127)

Prompt iki seçenek bıraktı: arayüzün `parseNotification`'ı callback'i ayrıştırsın
ve `osb` modunda "ilerletmeyen" bir sonuç dönsün, ya da ayrı bir metot.

`PaymentNotification`'ın sözü: *"bu nesne var ise imzası doğrulanmış ve işlenebilir
bir bildirimdir"* (Faz 7). `osb` modundaki bir callback bu sözü **tutamaz**: imzası
doğru ama işlenmemeli. Onu `PaymentNotification` olarak döndürmek, sözleşmeye bir
istisna eklemek ("…ama bazen işleme") demekti ve `HandlePaymentCallbackAction`'ı
çağıran her yer o istisnayı hatırlamak zorunda kalırdı.

Bunun yerine callback'in kendi sonuç tipi var:

```php
final readonly class ShopierCallback {
    public string $orderId;                         // nereye yönlendirilecek
    public bool $succeeded;                          // hangi sayfa (imzasız ipucu)
    public ?PaymentNotification $notification;       // osb modunda HİÇ ÜRETİLMEZ
}
```

`osb` modunda bildirim nesnesi **hiç kurulmuyor**. Controller bir gün yanlışlıkla
`$action->handle(...)` çağırsa bile elinde işlenecek bir şey yok. Bu, "kontrol et"
yerine "imkânsız kıl" (K118'deki tagged union fikrinin aynısı).

Arayüzün `parseNotification(Request)`'ı ise Shopier için **OSB**'dir: sunucudan
sunucuya, bütün gövdesi imzalı, Fake'in webhook'unun gerçek karşılığı.

## 5. `startCheckout`: formun 29 alanı

| Grup | Alanlar | Kaynak |
|---|---|---|
| Kimlik | `API_key`, `website_index`, `platform_order_id` | config · `orders.id` |
| Ürün | `product_name` (`DavetKart Gold`), `product_type=1` (dijital) | `tier->label()` |
| Alıcı | `buyer_name`, `buyer_surname`, `buyer_email`, `buyer_account_age` | `users` |
| Yer tutucu | `buyer_id_nr`, `buyer_phone`, `billing_*`, `shipping_*` | config (K125) |
| Tutar | `total_order_value` (`199.50`), `currency=0` (TL) | `amount_minor` (K124) |
| Sabit | `platform=0`, `is_in_frame=0`, `current_language=0`, `modul_version` | SDK |
| Güvenlik | `random_nr`, `signature` | `random_int` · HMAC |
| Dönüş | `callback` | `APP_URL` + `CALLBACK_PATH` (K180, §12; Faz 19.1'e kadar `url(CALLBACK_PATH)`) |

Notlar:

- **`product_name` için `SubscriptionTier::label()`** kullanıldı. Bu metot Faz 0'dan
  beri hiçbir yerden çağrılmıyordu (Faz 7 açık kararı #4: "silinmeli mi?"). Artık bir
  çağıranı var; o açık soru kendiliğinden kapandı.
- **`random_nr`** her checkout'ta yeni (`random_int`, kriptografik). Aynı imzanın iki
  form arasında tekrar kullanılmasını zorlaştırır.
- **`expiresAt: null`**: Shopier oturum penceresi vermiyor; `StartCheckoutAction` kendi
  30 dakikalık penceresini (`order_expires_after_minutes`) zaten yazmıştı ve `null`
  onu ezmez.
- **`callback` neden `route()` değil?** Rota bir sonraki adımda (12.7) yazıldı; her
  adımın kendi başına yeşil kalması için yol bir sabit. `ShopierCallbackTest` sabit
  ile rotanın aynı adres olduğunu kilitliyor; biri değişirse test kırılır.

## 6. Kurucu: sır yoksa sürücü yok (K123)

```php
$this->apiSecret = $this->secret('api_secret') ?? throw PaymentProviderException::unavailable(self::NAME);
```

Boş bir sırla HMAC hesaplanabilir: `hash_hmac('sha256', $data, '')` geçerli bir değer
üretir. **Herkes** aynı değeri üretebilir. Yani boş sırla çalışan bir sürücü, imza
doğrulaması yapıyormuş gibi görünen ama hiçbir şeyi doğrulamayan bir sürücüdür.

Kontrol neden `resolvePaymentGateway`'de değil de kurucuda? İki yerden kuruluyor:
`PaymentGateway` çözümü (checkout) ve controller'ların doğrudan istediği
`ShopierGateway` (callback, OSB). Kurucu ikisinin de geçmek zorunda olduğu tek kapı.
`GeminiProvider::apiKey()` aynı fikri tembel (çağrı anında) uyguluyor (A8); burada
kurucuda, çünkü sırsız bir Shopier sürücüsünün yapabileceği **hiçbir** güvenli iş yok.

## 7. OSB: sıra ve eşleme

```
kimlik var mı? → imza (res + kullanıcı adı) → base64 + JSON → istest? → eşleme → tutar
```

- **`istest=1` + üretim** → 400. Shopier'in test bildirimiyle canlıda sipariş açılmasın.
- **Eşleme (`matchOsbToOrder`) 🔴 tahmin.** OSB'nin API ödemelerinde bizim
  `platform_order_id`'mizi taşıyıp taşımadığı bilinmiyor (§A-c boş). Kural: ULID olan
  `platform_order_id` ya da `orderid`. Shopier'in kendi sayısal numarası ULID olamaz,
  yani yanlış siparişle eşleşmez; eşleşmezse `null` + `critical`. Elle doğrulama adım
  7 bunu kesinleştirecek. Değişecek tek yer bu metot.
- **Tutar** (`price`) okunamazsa 400 + `critical`: doğrulanamayan ödeme `paid` yapılmaz.
  Okunursa `PaymentNotification::$amountMinor`'a girer ve
  `HandlePaymentCallbackAction` siparişinkiyle karşılaştırır (K126).

## 8. Log'da ne var, ne yok?

Var: sipariş kimliği, Shopier'in sipariş numarası, beklenen ve gelen tutar.
**Yok:** `API_SECRET`, OSB anahtarı, imza, `res` gövdesi (alıcının e-postası içinde).
Sentry'ye giden her şey log'dan geçer; log'a girmeyen Sentry'ye de gitmez.

## 9. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Callback'te `status=success` görüp siparişi `paid` yapmak | §3'teki saldırı |
| Tutarı `$amount / 100` ile biçimlemek | Float; bir gün imza geçersiz |
| `base64_decode($sig)` (gevşek) | Bozuk girdi "düzeltilir", deneme alanı açılır |
| OSB'de önce JSON'u çözüp sonra imzaya bakmak | İmzasız istek ayrıştırıcıyı çalıştırır (L1) |
| `PAYMENT_SUCCESS_URL`'i göreli bırakmak | 303 API'nin alan adına gider |
| Shopier'e giden adresi `url()` / `route()` ile üretmek | Adresin alan adı isteğin `Host`'undan gelir; istemci seçer (§12) |

## 10. Kendin dene

```php
php artisan tinker
>>> config(['payment.providers.shopier.api_key' => 'k', 'payment.providers.shopier.api_secret' => 's',
...         'payment.providers.shopier.osb.username' => 'u', 'payment.providers.shopier.osb.key' => 'o']);
>>> $o = App\Models\Order::factory()->create(['provider' => 'shopier']);
>>> $s = (new App\Services\Payment\ShopierGateway)->startCheckout($o);
>>> $s->action->fields()['total_order_value'];
>>> config(['payment.providers.shopier.api_secret' => '']);
>>> new App\Services\Payment\ShopierGateway;      // PaymentProviderException (503)
```

---

## 11. 🆕 Faz 18.5 (`SEC-03` · K170) — üretimde `callback` kipi yok

### Sorun

§3'teki saldırı `callback` kipinde **çalışıyordu** ve bu biliniyordu (K117: "bilinçli risk").
Varsayılan `osb` olduğu için risk kapalıydı; ama kapalı tutan tek şey `.env`'deki bir satırdı.
Denetim (`AUD-2026-11-12`, `SEC-03`) bunu şöyle özetledi: *bir imza yalnızca kapsadığı alanları
korur*. Callback imzası `random_nr + platform_order_id`'yi kapsıyor; kararı veren `status` ve
tutar dışarıda. İmza "bu sipariş kimliği Shopier'den geldi" der, "ödeme başarılı" demez.

### Çözüm: güvensiz seçenek üretimde imkânsız

```php
if ($this->confirmation === ShopierConfirmation::Callback && App::isProduction()) {
    Log::critical('Shopier callback confirmation mode refused in production');

    throw PaymentProviderException::unavailable(self::NAME);
}
```

Bu, §6'daki **fail-safe** desenin aynısı: sır eksikse sürücü kurulmuyordu, artık üretimde
`callback` kipi seçilmişse de kurulmuyor. Sonuç tek ve tanıdık: ödeme uçları **503
`PROVIDER_UNAVAILABLE`**, sipariş yazılmaz (checkout sürücüyü transaction'ın içinde ister).
Yeni hata kodu yok; izleme zaten bu kodu tanıyor.

| Neden böyle? | |
|---|---|
| **Neden kurucuda?** | Sürücü üç yoldan kuruluyor: checkout (`PaymentGateway` bağlaması), callback ve OSB controller'ları (doğrudan `ShopierGateway`). Kurucu üçünün de geçtiği tek kapı (§6). |
| **Neden 503, neden `osb`'ye düşmüyor?** | Sessizce `osb`'ye düşmek, birinin bilerek yazdığı bir ayarı yok saymak olurdu: "neden ödemeler onaylanmıyor" gizemi. Yapılandırma hatası **gürültülü** olmalı (K70). |
| **Neden `Log::critical`?** | 503 kullanıcıya yalnızca "sağlayıcı yok" der. Nedeni log söyler; sır eksikliğinden ayırt etmek için ayrı bir satır. Sürücü her kurulma denemesinde bir kez yazar (checkout, callback, OSB). |
| **Neden yalnızca üretimde?** | Yerel makinede ve testlerde `callback` kipi akışı uçtan uca denemenin yolu: Shopier'in OSB'si yerel makineye ulaşamaz. Orada sahte sır ve sahte kart var; kaybedilecek para yok. |
| **Neden tamamen silinmedi?** | OSB'nin API ile başlatılan ödemelerde gelip gelmediği **doğrulanmadı** (K94 ⚠️ geçici, elle doğrulama adım 7). Kip silinirse o soru cevaplanmadan bir seçenek kapanır. Adım 7 OSB'yi doğrularsa kipin tamamen kaldırılması ayrıca karara bağlanır. |

🔴 OSB adım 7'de **gelmezse** çözüm yine `callback` kipini üretimde açmak değildir. İmzasız bir
alana para kararı bağlanmaz; o durumda sunucudan sunucuya bir sorgu (Shopier'e "bu sipariş
ödendi mi?") ya da `status`'ü imzalayan bir formül gerekir ve bu yeni bir K kararıdır.

### Callback ucu ne yapar?

Sürücü kurulamazsa `ShopierCallbackController` kullanıcıyı `/odeme/hata`'ya yönlendirir (303,
`?order=` yok); hiçbir sipariş değişmez (adım 18.5.2, K172). Ayrıntı:
[controller kılavuzu](../../Http/Controllers/Api/V1/ShopierCallbackController.md), Faz 18.5 bölümü.

### Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Kontrolü `resolvePaymentGateway`'e koymak | Callback ve OSB controller'ları sürücüyü doğrudan istiyor; o yollar kontrolü atlar |
| `App::environment('local')` ile "yalnızca yerelde izin ver" demek | `testing` ortamı reddedilir, bütün `callback` kipi testleri kırılır; asıl soru "üretim mi?" |
| `APP_ENV`'i sunucuda `production` dışında bir şey yapmak (`staging`) | Kontrol devreden çıkar. Gerçek parayla çalışan her ortam `production`'dır (`docs/10` şablonu) |
| 503 yerine sessizce `osb`'ye düşmek | Yanlış yapılandırma görünmez olur |

### Testler ve mutantlar

`PaymentDriverResolutionTest`: `the_callback_confirmation_mode_is_refused_in_production` (üretim +
`callback` → 503, sipariş yok, `critical` log) · `the_callback_mode_still_works_outside_production`
· `osb_mode_works_in_production`.

| Mutant | Kırmızıya dönen |
|---|---|
| `if` bloğunu sil | `the_callback_confirmation_mode_is_refused_in_production` (503 yerine 201) |
| `&& App::isProduction()`'ı sil | `the_callback_mode_still_works_outside_production` ve `callback` kipinin bütün testleri |

### Kendin dene

```php
php artisan tinker
>>> config(['payment.providers.shopier.api_key' => 'k', 'payment.providers.shopier.api_secret' => 's',
...         'payment.providers.shopier.confirmation' => 'callback']);
>>> new App\Services\Payment\ShopierGateway;      // kurulur (yerel)
>>> app()->detectEnvironment(fn () => 'production');
>>> new App\Services\Payment\ShopierGateway;      // PaymentProviderException (503)
>>> // storage/logs/laravel.log: CRITICAL Shopier callback confirmation mode refused in production
```

---

## 12. 🆕 Faz 19.1 (`SEC-07` · K180) — callback adresi isteğe değil yapılandırmaya bakar

### Sorun

Faz 12'den beri forma şu yazılıyordu: `'callback' => url(self::CALLBACK_PATH)`. Laravel'in
`url()`'si mutlak adresin kökünü (şema + alan adı) **o anki istekten** alır: `Host` başlığından,
güvenilen bir vekil varsa `X-Forwarded-Host`'tan. İkisi de istemcinin yazdığı metindir. Checkout
isteğini `Host: evil.example` ile gönderen biri forma `http://evil.example/api/public/payments/shopier/callback`
yazdırabiliyordu. Ödemeden sonra Shopier tarayıcıyı bu adrese POST ettirir: imzalı
`platform_order_id`, `random_nr`, `signature`, `status`, `payment_id` alanları başka bir sunucuya
gider. Bu saldırının adı **host header injection**.

Önce / sonra (`the_shopier_callback_url_ignores_the_request_host`, 20 Kasım):

| Kod | `Host: evil.example` | Güvenilen vekil + `X-Forwarded-Host: evil.example` |
|---|---|---|
| `url(self::CALLBACK_PATH)` | `http://evil.example/api/public/payments/shopier/callback` | `https://evil.example/api/public/payments/shopier/callback` |
| `$this->callbackUrl()` | `https://davetkart.test/api/public/payments/shopier/callback` (testteki `APP_URL`) | aynı |

**Bugün neden sömürülemiyordu?** Denetim de doğrudan bir yol bulmadı:

1. Saldırgan yalnızca **kendi** checkout isteğinin başlığını değiştirebilir. Bu da yalnızca kendi
   formunu bozar. Başkasının formunu zehirlemek için arada önbelleğe alan bir katman gerekir (web
   cache poisoning). Checkout yanıtı kimlikli ve önbelleğe girmiyor.
2. Üretimde Nginx'in `default_server` bloğu tanımadığı alan adını `444` ile keser
   (`deploy/nginx/davetkart.conf`); Laravel'e yalnızca `davetkart.com` ulaşır.
3. `TRUSTED_PROXIES` boş ([`config/trustedproxy.md`](../../../config/trustedproxy.md)): `X-Forwarded-Host` okunmaz.

Yani bu düzeltme **derinlemesine savunma**. Üç kilit de bir gün gevşeyebilir: yeni bir alan adı,
bir yük dengeleyici, `TRUSTED_PROXIES=*`. O gün para akışının adresi istemcinin elinde olmasın.
Denetim bulguyu Symfony'nin 4 Kasım tavsiyesiyle ("Generated URLs Can Point to a Host Chosen by
the Client", `composer audit`) birlikte işaretledi. Paket güncellemesi Symfony'nin kendi açığını
kapatır; `url()`'nin isteğin host'unu kullanması ise bir açık değil, tasarımıdır. O yüzden bu
satırı biz değiştiriyoruz.

### Çözüm: dış adres = yapılandırma + sabit yol

```php
private function callbackUrl(): string
{
    return rtrim(Config::string('app.url'), '/').self::CALLBACK_PATH;
}
```

Projede dışarıya verilen öbür mutlak adresler zaten böyle (19.1'de `app/`, `config/`, `bootstrap/`,
`routes/` tarandı: `url(`, `route(`, `asset(`, `secure_url`, `->root()`, `getSchemeAndHttpHost`,
`getHttpHost`, `redirect()->to`, `URL::to`, `signedRoute`):

| Adres | Nereden | Kayıt |
|---|---|---|
| Shopier callback | `app.url` + `CALLBACK_PATH` | 🆕 K180 |
| Ödeme dönüş sayfaları (callback'in 303'ü) | `payment.return_urls` (`PAYMENT_SUCCESS_URL`, `PAYMENT_FAILURE_URL`) | K122 |
| Parola sıfırlama, "parolan değişti", "e-postan değişti" postaları | `davetkart.frontend.url` | Faz 10, 14 |
| E-posta değişikliği onay bağlantısı | `frontend.url` + **göreli** imza (`absolute: false`) | K144 |
| Medya adresleri | Disk `url`'i (`APP_URL/storage` ya da `AWS_URL`) | E1 |
| OSB adresi | Shopier panelinde elle yazılır; kodda üretilmez | — |

| Neden böyle? | |
|---|---|
| **Neden `route('public.payments.shopier.callback')` değil?** | `route()` da kökü istekten alır; sorun aynı kalır. Yolun rotayla aynı olduğunu `ShopierCallbackTest` zaten kilitliyor (§5). |
| **Neden küresel `URL::forceRootUrl(config('app.url'))` değil?** | Tek bir adres için uygulamadaki bütün `url()`/`route()` çağrılarının, ileride yazılacakların da davranışını değiştirir ve etkisi çağrının yanında görünmez. Açık üretimde "bu adres nereden geliyor?" sorusunun cevabı aynı satırda. |
| **Neden ortak bir yardımcı sınıf yok?** | Bugün istekten adres üreten tek yer burasıydı. İkinci bir kullanım çıkınca çıkarılır. |
| **Neden `rtrim`?** | `APP_URL=https://davetkart.com/` yazılırsa adres `//api/…` olurdu. |
| **Neden `frontend.url` değil `app.url`?** | Callback bir **API** ucu (`/api/public/…`); frontend sayfası değil. Üretimde ikisi aynı köken (`https://davetkart.com`), yerelde farklı (API `:8000`, frontend `:3000`). |

### ⚠️ Bedeli: `APP_URL` artık ödeme dönüşü için kritik

Eskiden `url()` isteğin host'unu kullandığı için `APP_URL` yanlış olsa da callback doğru çıkıyordu.
Artık `APP_URL` yanlışsa (`http://localhost`, eski `https://api.davetkart.com`) Shopier tarayıcıyı
yanlış adrese gönderir. Kullanıcı ödemeyi yapmış ama kırık bir sayfa görmüştür. Sipariş yine `paid`
olur, çünkü `osb` kipinde kararı OSB verir ve tarayıcı callback'i yalnızca yönlendirir (§3, K117).

- Üretimde `APP_URL=https://davetkart.com` olmalı. Yayın kaydı (Y8.3b, 26 Ekim) böyle yazıldığını
  söylüyor; yayından önce sunucudaki `.env`'de gözle doğrula.
- ⚠️ `docs/10-URETIM-ENV-SABLONU.md` hâlâ eski `APP_URL=https://api.davetkart.com`'u gösteriyor
  (`OPS-04`, Faz 19.6). Yeni bir sunucu o şablondan kurulursa ödeme dönüşü kırılır.

### Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Dış adres için `url()`, `route()`, `$request->root()`, `getSchemeAndHttpHost()` | Adresin alan adını istemci seçer |
| Testte `withHeader('Host', 'evil.example')` | **Etkisiz.** Laravel test isteğini mutlak adresten kurar; Symfony `Request::create` `HTTP_HOST`'u adresin host'uyla ezer. İstek yine `APP_URL`'e gider (denendi: callback `http://localhost:8000/…` çıktı). Mutlak adres ver: `postJson('http://evil.example/api/…')` |
| Yalnızca `Host` yolunu sınamak | `TRUSTED_PROXIES=*` olduğu gün ikinci yol `X-Forwarded-Host`; testin ikinci veri satırı |
| `APP_URL`'i sunucuda boş ya da eski bırakmak | Ödeme dönüşü kırılır (yukarıda) |

### Testler ve mutantlar

`PaymentDriverResolutionTest::the_shopier_callback_url_ignores_the_request_host`: uçtan uca HTTP
checkout, iki veri satırı (`Host: evil.example`; `TRUSTED_PROXIES=*` + `X-Forwarded-Host`).
`ShopierGatewayTest`'te beklenen değer `url()` yerine `app.url` + yol.

| Mutant | Sonuç |
|---|---|
| `$this->callbackUrl()` → `url(self::CALLBACK_PATH)` | ✅ öldü: iki veri satırı kırmızı (`http://evil.example/…`, `https://evil.example/…`) |

### Kendin dene

```php
php artisan tinker
>>> app('url')->setRequest(Illuminate\Http\Request::create('http://evil.example/api/payments/checkout'));
>>> url(App\Services\Payment\ShopierGateway::CALLBACK_PATH);
=> "http://evil.example/api/public/payments/shopier/callback"     // url() isteğin host'una uyar
>>> rtrim(config('app.url'), '/').App\Services\Payment\ShopierGateway::CALLBACK_PATH;
=> "http://localhost:8000/api/public/payments/shopier/callback"   // APP_URL'den (yerel .env)
```

Mutantı kendin öldür: `startCheckout`'ta `$this->callbackUrl()` yerine `url(self::CALLBACK_PATH)`
yaz → `php artisan test --filter=the_shopier_callback_url_ignores_the_request_host` kırmızı
(`evil.example`) → geri al.
