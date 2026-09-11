# `config/cors.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.12
> **İlgili:** [`../app/Http/Middleware/SetEtag.md`](../app/Http/Middleware/SetEtag.md) ·
> [`../app/Http/Middleware/SecurityHeaders.md`](../app/Http/Middleware/SecurityHeaders.md)
> **Kararlar:** **K7** (Polling + ETag) · **K46** (ETag middleware) · **E6** (ortam farkı env'e)

---

## 1. Bu dosya Laravel ile **gelmez**

Laravel 11+ `config/cors.php`'yi yayınlamaz. `HandleCors` middleware'i global
yığında **kayıtlıdır** ve ayarını `vendor/laravel/framework/config/cors.php`'den
okur. Oradaki varsayılan:

```php
'allowed_origins' => ['*'],     // 🔴 HERKES
'exposed_headers' => [],        // 🔴 ETag OKUNAMAZ
```

Faz 9'a kadar ikisi de görünmedi çünkü `vite.config.ts`'teki proxy sayesinde
tarayıcı açısından istekler **aynı kaynaktan** geliyordu ve CORS hiç devreye
girmedi. **Üretimde proxy yok.**

> Bir varsayılanın zararsız görünmesi, onu hiç incelemediğiniz anlamına
> gelebilir. Bu dosya sekiz faz boyunca vardı ve kimse okumadı.

---

## 2. `allowed_origins`: neden `*` yanlış?

Token'ımız `Authorization` başlığında gidiyor, çerez yok — yani klasik CSRF
geçerli değil. O hâlde `*` neden yanlış?

Çünkü `*`, **herhangi bir sitedeki JavaScript'in bizim API'mizi çağırıp yanıtı
okumasına** izin verir. Kullanıcının token'ını ele geçirmiş bir saldırgan,
kendi sayfasından rahatça çalışabilir; ayrıca auth gerektirmeyen `/api/public/`
uçlarımızın tamamı (davetiye içeriği, LCV gönderimi) herhangi bir siteden
sürülebilir hâle gelir.

> Bir savunmayı *"zaten başka bir savunma tutuyor"* diye açmak, **L1**'in
> (katmanlı savunma) tam tersidir. Katmanlar birbirinin yerine geçmez.

### Liste neden `env()`'den geliyor?

```php
explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))
```

Bu, `config/davetkart.php`'deki `release_window_days`'in **tersi** durumu:

| | `release_window_days` | `CORS_ALLOWED_ORIGINS` |
|---|---|---|
| Ne | Ticari söz | 🔴 **Ortam farkı** |
| Geliştirme / üretim | Aynı olmalı | **Farklı olmak zorunda** |
| Yeri | Repo (literal) | `.env` |

`env()`'in doğru kullanımı budur: ortamlar arasında farklılaşması **gereken**
şey env'e gider (**E6**).

---

## 3. 🔴 `exposed_headers` — bu satır olmadan Faz 4 ve Faz 5 sessizce ölür

```php
'exposed_headers' => ['ETag'],
```

CORS'un bir **güvenli liste** (safelist) kavramı var: çapraz kaynaklı bir
yanıtta JavaScript yalnızca şu başlıkları okuyabilir —
`Cache-Control`, `Content-Language`, `Content-Length`, `Content-Type`,
`Expires`, `Last-Modified`, `Pragma`.

**`ETag` bu listede yok.**

Yani `SetEtag` middleware'i (K46) başlığı göndermeye devam eder, tarayıcı onu
alır, ama `axios` `response.headers.etag` için `undefined` görür. Sonuç:

| Uç | `exposed_headers` boşken |
|---|---|
| `GET /api/public/invitations/{id}` | Her istekte **tam gövde** — cache hiç çalışmaz |
| LCV polling (15 sn'de bir) | Her poll **tam gövde** |

K7'nin (*"Reverb yerine Polling + ETag"*) bütün gerekçesi, 304'ün ağdan
tasarruf etmesiydi. Bu tek satır olmadan o tasarruf **sıfırdır** — ve hiçbir
hata mesajı çıkmaz, hiçbir test kırılmaz. Testler aynı süreçte koşar; orada
CORS diye bir şey yoktur.

> Faz 6'nın `storage:link` dersinin ikizi: *bir savunma/optimizasyon, yalnızca
> testlerin göremediği bir katmanda yaşıyorsa, orada olup olmadığını elle
> doğrulamak zorundasın.* Farkı: bunu **test edebildik** (`HardeningTest`),
> çünkü CORS başlıkları aynı süreçte üretiliyor.

---

## 4. `max_age` neden 0 değil?

Varsayılan `0`, her gerçek istekten önce bir **preflight** (`OPTIONS`) isteği
demek — yani istek sayısı **ikiye katlanır**. LCV polling'i 15 saniyede bir
koştuğu için bu, saatte 240 yerine 480 istek eder.

24 saat, preflight sonucunu tarayıcıda tutar. Değiştirmemiz gerektiğinde tek
maliyet, tarayıcıların eski izni bir gün daha hatırlaması.

---

## 5. `supports_credentials` neden `false` kalmalı?

Sanctum'u **token modunda** kullanıyoruz: `Bearer` başlığı, çerez yok.
`true` yapmak üç şey yapar:

1. Tarayıcıya çerez/kimlik göndermesini söyler — kullanmadığımız bir kanal.
2. `allowed_origins` içinde `*` kullanmayı **spec gereği yasaklar** (yan
   fayda, ama biz zaten `*` kullanmıyoruz).
3. CSRF yüzeyini geri getirir.

> **Kullanılmayan bir yetenek, kapalı bir yetenektir.** Faz 8'in **L8**'i
> (*"cevapladığı bir soru olmayan katman çıkarılır"*) konfigürasyon tarafında.

---

## 6. Bu dosyanın YAPMADIKLARI (B6)

| Yapmaz | Nerede |
|---|---|
| Kimlik doğrulamak | `auth:sanctum` |
| Sunucu tarafında bir şey engellemek | 🔴 **Hiçbir yerde** — CORS bir **tarayıcı** kuralıdır; `curl` hepsini yok sayar |
| Hız sınırı | `throttle` kovaları |
| HTTPS zorlamak | `SecurityHeaders` (HSTS) + sunucu yapılandırması |

🔴 En sık yanlış anlaşılan nokta ikinci satır: **CORS bir yetkilendirme
mekanizması değildir.** Yalnızca *tarayıcıdaki başka bir sayfanın* yanıtı
okumasını engeller. API'nin gerçek savunması `auth:sanctum`, Policy'ler ve
hız sınırlarıdır.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `allowed_origins => ['*']` bırakmak | Her site API'yi çağırıp yanıtı okur |
| 2 | `exposed_headers`'ı boş bırakmak | 🔴 ETag okunamaz; K7/K46 sessizce ölür (§3) |
| 3 | `supports_credentials => true` | Kullanılmayan bir kimlik kanalı + CSRF yüzeyi |
| 4 | Origin'i `config/` içine literal yazmak | Ortam farkı repoya gömülür (**E6**) |
| 5 | CORS'u güvenlik duvarı sanmak | `curl` hepsini yok sayar (§6) |
| 6 | Sondaki `/` ile origin yazmak | `https://site.com/` eşleşmez; origin'de yol yoktur |

---

## 8. Kendin dene

```powershell
# İzinli origin — Access-Control-Allow-Origin dönmeli
curl.exe -i -H "Origin: http://localhost:5173" http://127.0.0.1:8000/api/ping

# Yabancı origin — başlık GELMEMELİ
curl.exe -i -H "Origin: https://kotu-site.example" http://127.0.0.1:8000/api/ping

# 🔴 ETag gerçekten açığa çıkıyor mu?
curl.exe -i -H "Origin: http://localhost:5173" http://127.0.0.1:8000/api/public/invitations/<ULID>
# Access-Control-Expose-Headers: ETag  satırını ara
```

En kesin kanıt tarayıcıda:

```js
// Frontend'in gerçek origin'inden, DevTools konsolunda
const r = await fetch('https://api.davetkart.com/api/public/invitations/XXX');
r.headers.get('etag');    // 🔴 null dönerse exposed_headers eksik demektir
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Origin** | Şema + alan adı + port üçlüsü (`https://site.com:443`) |
| **Preflight** | Tarayıcının asıl istekten önce attığı `OPTIONS` sorusu |
| **Safelist** | JavaScript'in izinsiz okuyabildiği başlıklar listesi |
| **Same-origin policy** | Bir sayfanın başka bir origin'in yanıtını okuyamaması kuralı |
