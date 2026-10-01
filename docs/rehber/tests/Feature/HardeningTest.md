# `tests/Feature/HardeningTest.php`

> **Faz:** 9, dosyalar 9.12 (CORS) ve 9.13 (güvenlik başlıkları) · Faz 10'da 10.53 (HSTS varlığı)
> **Bu kılavuz:** Faz 10, adım 10.54. Test Faz 9'dan beri vardı, kılavuzu yoktu (K18 borcu).
> **9 test** · **Kurallar:** T6 · T16 · ders 33
> **Sınadığı kod:** [`config/cors.php`](../../../config/cors.md) · [`SecurityHeaders`](../../app/Http/Middleware/SecurityHeaders.md)

---

## 1. Bu dosya neyi kanıtlıyor?

Faz 9'un çoğu `composer check`'in göremeyeceği işlerdi (Redis, S3, gerçek kuyruk, sunucu).
Bu dosya o kuralın **istisnası**: CORS ve tarayıcı başlıkları bir HTTP yanıtının parçası,
yani test edilebilir. Test edilebileni elle doğrulamaya bırakmak elle doğrulamayı da
değersizleştirir; elle kontrol edilecek liste kısa kalmalı ki gerçekten yapılsın.

| Bölüm | Soru | Testler |
|---|---|---|
| CORS | Kim bizim yanıtımızı **JavaScript'le okuyabilir**? | 4 |
| Başlıklar | Tarayıcı yanıtı nasıl **yorumlasın**? | 3 |
| HSTS | Tarayıcı bu alan adına **hep HTTPS'le** mi gelsin? | 2 |

Bütün istekler `/api/ping`'e gidiyor (`HealthController`): veritabanına dokunmayan, kimlik
istemeyen en ucuz uç. Dosya bu yüzden `RefreshDatabase` kullanmıyor.

## 2. Başlıklar sunucuda hiçbir şey zorlamaz

Bu dosyadaki her başlık **tarayıcıya** verilen bir talimat. `curl` ile istek atan bir
saldırganı hiçbiri durdurmaz. Korunan şey kullanıcının tarayıcısı: üçüncü bir sitenin
bizim yanıtımızı okuması, bir iframe'e gömmesi ya da içerik tipini yanlış yorumlatması.
Testler de bu yüzden yanıtın **başlıklarına** bakıyor, sunucunun davranışına değil.

## 3. CORS (9.12)

| Test | İddia |
|---|---|
| `an_allowed_origin_receives_the_cors_header` | İzin listesindeki köken kendi adını `Access-Control-Allow-Origin`'de görür |
| 🔴 `an_unknown_origin_is_never_echoed_back` | Yabancı köken **geri yansıtılmaz** ve değer `*` değildir |
| `the_etag_header_is_exposed_to_cross_origin_readers` | `ETag` `Access-Control-Expose-Headers`'ta |
| `credentials_are_not_allowed` | `supports_credentials = false` |

### 3.1 🔴 "Başlık yok" değil, "başlık saldırganı taşımıyor" (ders 33)

İlk sürüm yabancı köken için `assertHeaderMissing('Access-Control-Allow-Origin')` diyordu
ve **kırmızı** yandı. Kusur kodda değil testin varsayımındaydı. `fruitcake/php-cors`
izin listesinde **tek** köken varsa başlığı koşulsuz gönderir (önbelleklenebilir yanıt
için). Tarayıcı değeri kendi kökeniyle karşılaştırır, eşleşmezse yanıtı okutmaz.

Korunan özellik bu yüzden *"başlığın saldırganın kökenini taşımaması"*. Test o özelliği
sınıyor ve izin listesi çoğaldığında da doğru kalıyor. İkinci iddia (`!== '*'`) asıl
regresyon muhafızı: Laravel'in varsayılanı `['*']` ve yayınlanan bir `cors.php`'nin o
varsayılana dönmesi, her sitenin API'yi çağırıp yanıtı okuyabilmesi demek.

### 3.2 ETag neden CORS testi?

ETag CORS'un *güvenli liste* başlıklarından değil: çapraz kökende JavaScript onu
**okuyamaz**. Okuyamazsa `If-None-Match` gönderemez, 304 hiç gelmez ve Faz 4'ün (public
davetiye) ve Faz 5'in (LCV paneli yoklaması) bant genişliği optimizasyonu sessizce ölür.
Hiçbir şey hata vermez; yalnızca her yoklama tam gövde indirir.

### 3.3 Geliştirmede CORS neden görünmüyor?

Geliştirmede Vite API'yi kendi kökeninden **proxy'liyor** (frontend `vite.config.ts`):
tarayıcı için istek aynı kökene gidiyor ve CORS hiç devreye girmiyor. CORS yalnızca
üretimde, frontend ve API farklı kökenlerdeyken (`davetkart.com` → `api.davetkart.com`)
anlam kazanıyor. Bu testler o yüzden önemli: geliştiricinin günlük akışı bu ayarı
**hiç sınamıyor**.

## 4. Güvenlik başlıkları (9.13)

| Test | İddia |
|---|---|
| `every_response_carries_the_hardening_headers` | `nosniff` · `DENY` · `no-referrer` · `X-XSS-Protection: 0` |
| `the_content_security_policy_denies_everything` | `default-src 'none'` ve `frame-ancestors 'none'` |
| 🔴 `error_responses_carry_the_headers_too` | Eşleşmeyen rotanın 404'ünde de başlıklar var |

Değerlerin gerekçeleri `SecurityHeaders.md` §3'te. Bu dosya için önemli olan üçüncü test:
middleware `api` grubuna değil **global** yığına eklendi (`bootstrap/app.php` →
`append()`). Grup middleware'i rota eşleşmeyen isteklerde hiç çalışmaz (Faz 2, ders 21);
grupta olsaydı 404 gövdesi başlıksız döner ve bir iframe'e gömülebilirdi. Test bu kararı
kilitliyor: middleware gruba taşınırsa kırmızı.

## 5. HSTS: yokluk ve varlık (T6)

| Test | İstek | İddia |
|---|---|---|
| `hsts_is_absent_over_plain_http` | `http://…/api/ping` | Başlık **yok** |
| 🆕 `hsts_is_present_over_https` | `https://localhost/api/ping` | `max-age=31536000; includeSubDomains` |

HSTS **geri alınamaz**: tarayıcı başlığı bir kez görürse alan adını bir yıl boyunca
HTTPS'e zorlar. Yerel geliştirmede (`http://davetkart.test`) yanlışlıkla gönderilse
geliştirici *"sitem açılmıyor"* diye saatlerce arar. Middleware bu yüzden yalnızca
`$request->secure()` iken gönderiyor; yokluk testi o koşulu kilitliyor.

### 5.1 🆕 Varlık testi neden Faz 10'a kadar yoktu?

Faz 9'un yorumu: *"Varlığının kanıtı yalnızca gerçek bir https isteğiyle alınabilir — elle
doğrulama."* Doğru değildi. Laravel'in test istemcisi tam adres kabul ediyor:

```php
$this->getJson('https://localhost/api/ping')
```

Symfony isteği kurarken şemayı görür ve `HTTPS=on` sunucu değişkenini ayarlar;
`$request->secure()` `true` döner. Ağ, sertifika ya da TLS yok. Sınanan şey
middleware'in **koşulu ve değeri**. `TEST-DENETIMI` §2 bunu buldu: HSTS bloğu silinse
dosya yeşil kalıyordu.

Testin **görmediği** şey: üretimde TLS'i yük dengeleyici (ALB, Cloudflare) sonlandırır ve
PHP'ye istek `http` olarak gelir. `secure()`'un orada doğru dönmesi `X-Forwarded-Proto`'ya
ve TrustProxies'e bağlı; o `TrustedProxiesTest`'in konusu. Üretimde bir kez gözle bakmak
hâlâ değerli (`SecurityHeaders.md` §7).

## 6. 🔴 Mutasyon tablosu (T16)

| Mutasyon | Kırılan test |
|---|---|
| `allowed_origins` → `['*']` | `an_unknown_origin_is_never_echoed_back` |
| `exposed_headers` → `[]` | `the_etag_header_is_exposed_…` |
| `supports_credentials` → `true` | `credentials_are_not_allowed` |
| `Referrer-Policy` satırı silindi | `every_response_carries_…` |
| CSP `'none'` → `'self'` | `the_content_security_policy_…` |
| `SecurityHeaders` `api` grubuna taşındı | `error_responses_carry_the_headers_too` |
| `if ($request->secure())` → `if (true)` | `hsts_is_absent_over_plain_http` |
| 🆕 HSTS bloğu silindi | `hsts_is_present_over_https` |
| 🆕 `max-age` 1 güne indirildi | `hsts_is_present_over_https` |
| 🆕 `includeSubDomains` silindi | `hsts_is_present_over_https` |

On satırın hepsi 1 Ekim 2026'da `mutate.php` ile koşturuldu (İsmail'in makinesi, PHP 8.5);
her mutasyon tam olarak tablodaki testi kırdı. 🆕 işaretli üçü 10.53'ten önce **yeşildi**.

## 7. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Yabancı köken için `assertHeaderMissing` | Tek kökenli listede kırmızı yanar; kusur kodda sanılır (§3.1) |
| CORS'u geliştirme tarayıcısında denemek | Vite proxy'si yüzünden hiç devreye girmez (§3.3) |
| HSTS'i "elle doğrulanır" diye bırakmak | Blok silinse kimse fark etmez (§5.1) |
| Başlık testini yalnızca 200 yanıtında yapmak | Grup middleware'i hatası görünmez (§4) |

## 8. Kendin dene

```powershell
php artisan test tests/Feature/HardeningTest.php
```

Mutasyon: `app/Http/Middleware/SecurityHeaders.php`'de `max-age=31536000`'i `86400` yap →
`hsts_is_present_over_https` kırmızı olmalı. Geri al.
