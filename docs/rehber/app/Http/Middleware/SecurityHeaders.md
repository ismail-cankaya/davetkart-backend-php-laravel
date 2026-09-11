# `app/Http/Middleware/SecurityHeaders.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.12
> **İlgili:** [`ForceJsonResponse.md`](ForceJsonResponse.md) · [`SetEtag.md`](SetEtag.md) ·
> [`../../../config/cors.md`](../../../config/cors.md)
> **Kurallar:** **M3** (middleware sırası) · **B10** (kalite kapısının bağımlı
> olduğu her şey sürümlenir) · **B6**

---

## 1. Bu başlıklar **sunucuda hiçbir şey zorlamaz**

En baştan net olalım: buradaki her başlık **tarayıcıya verilen bir
talimattır**. Bir saldırgan `curl` ile istek atarsa hiçbiri onu durdurmaz.
Korudukları şey **kullanıcının tarayıcısıdır** — yani üçüncü bir sitenin bizim
yanıtımızı kötüye kullanması.

Bunu bilmek, hangi sorunun çözülüp hangisinin çözülmediğini ayırmayı sağlar
(**B6**).

---

## 2. Neden middleware, neden nginx değil?

İkisi de çalışır. Middleware seçildi, üç sebeple:

| Sebep | Açıklama |
|---|---|
| **Sürümlenir** | Başlıklar kodla birlikte git'te durur. `nginx.conf` sunucuda yaşar ve bir sunucu taşımasında **sessizce geride kalır** — 9.2'de öğrendiğimiz **B10**'un tam konusu |
| **Test edilebilir** | `HardeningTest` her başlığı sınar. nginx yapılandırması `composer check`'in görüş alanının dışındadır |
| **Her ortamda çalışır** | Paylaşımlı hostingde nginx'e erişim olmayabilir (**K80**) |

---

## 3. Altı başlık, altı gerekçe

### `X-Content-Type-Options: nosniff`

Tarayıcı `Content-Type`'ı "tahmin etmeye" çalışmasın. Yüklenen bir dosya
`image/jpeg` diye servis edilirken içinde HTML varsa, sniffing açıkken tarayıcı
onu **sayfa gibi çalıştırabilir**.

🔴 Bu doğrudan **K55**'in tarayıcı ayağı: *yüklenenler bugün web kökü altında*
(`public/storage` sembolik bağı). S3'e geçiş bunu yapısal olarak kapatacak;
bu başlık o güne kadar köprü.

### `X-Frame-Options: DENY`

Yanıtlar hiçbir `<iframe>` içinde gösterilemez — **clickjacking**. Saf bir JSON
API'de gömülmesi gereken hiçbir şey yok, dolayısıyla `SAMEORIGIN` değil `DENY`.

### `Referrer-Policy: no-referrer`

🔴 Bu projede özel bir anlamı var. Davetiye URL'leri **ULID** taşıyor
(K13/K40) ve o ULID **paylaşılan linkin kendisidir** — yani bir sırdır. Misafir
davetiyeden bir dış bağlantıya tıklarsa, `Referer` başlığıyla davetiye kimliği
üçüncü tarafa sızardı.

> K13 ULID'i *"ardışık integer'da misafir `/invite/1,2,3` gezer"* diye seçmişti.
> Tahmin edilemez bir kimlik, **sızdırılırsa** tahmin edilebilir olmaktan
> farksızdır.

### `X-XSS-Protection: 0`

🔴 Değer **sıfır** ve bu bir yazım hatası değil. Eski tarayıcıların
XSS-Auditor'ları kendileri açık doğurduğu için modern tavsiye onu **açmak
değil kapatmaktır**. Kalan tarayıcılarda buggy davranışı devre dışı bırakır.

> Bir güvenlik başlığının "açık" olması her zaman daha güvenli değildir.
> Ders 22'nin (*"sistemler bir yerden sertleşince başka bir yerden esner"*)
> tarayıcı tarafı.

### `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'`

Saf JSON API: hiçbir kaynak yüklenmemeli, hiçbir yere gömülmemeli. `'none'`
burada gerçekten doğru — bir HTML uygulamasında bu satır **her şeyi kırardı**.

`frame-ancestors`, `X-Frame-Options`'ın modern karşılığı; ikisi birlikte
gönderiliyor çünkü eski tarayıcılar yalnızca ilkini anlıyor.

### `Strict-Transport-Security` — 🔴 yalnızca HTTPS üzerinden

```php
if ($request->secure()) { … }
```

HTTP üzerinden gönderilirse tarayıcılar onu zaten yok sayar (spec). Koşul yine
de yazıldı:

> Yerel geliştirmede (`http://davetkart.test`) yanlışlıkla iş görürse tarayıcı
> o alan adını **aylarca** HTTPS'e zorlar ve geliştirici *"sitem açılmıyor"*
> diye saatlerce arar. HSTS **geri alınamaz** — tarayıcı önbelleğini elle
> temizlemek gerekir.

**Bir başlık geri alınamıyorsa, gönderilmesi bir karar olmalıdır.**

`max-age=31536000` (1 yıl) + `includeSubDomains`. `preload` **yazılmadı**:
preload listesine girmek, çıkması aylar süren bir taahhüttür ve alan adı
kesinleşmeden verilmez.

---

## 4. Neden `append()`, neden `api` grubuna değil?

```php
$middleware->append(SecurityHeaders::class);   // GLOBAL yığın
```

İki sebep ve ikincisi Faz 2'de öğrenilmişti:

1. `/up` sağlık sondası `api` grubunda **değil** — o da tarayıcıya gider.
2. 🔴 **Ders 21**: *rota eşleşmezse grup middleware'i hiç çalışmaz.* `api`
   grubuna eklenseydi `/api/bilinmeyen-yol` isteği **başlıksız** 404 dönerdi
   ve o 404 gövdesi bir iframe'e gömülebilirdi.

`append()` — `prepend()` değil — çünkü bu middleware **yanıt üretildikten
sonra** çalışıyor. `ForceJsonResponse` yığının **başında** (M3), bu **sonunda**.

---

## 5. Bu middleware'in YAPMADIKLARI (B6)

| Yapmaz | Nerede |
|---|---|
| HTTPS'e yönlendirmek | Sunucu (nginx/Forge) ya da `TrustProxies` + `APP_URL` |
| CORS | `config/cors.php` |
| Yanıt gövdesini denetlemek | Resource beyaz listeleri (C1/C5) |
| `curl`'ü durdurmak | 🔴 **Hiçbir şey** — bunlar tarayıcı talimatları (§1) |
| Yüklenen dosyaların web kökünde olmasını düzeltmek | 🔴 **K55 hâlâ açık** — S3 göçü |

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | HSTS'i koşulsuz göndermek | Yerel alan adı aylarca HTTPS'e kilitlenir (§3) |
| 2 | `preload` eklemek | Çıkması aylar süren taahhüt |
| 3 | `X-XSS-Protection: 1` yazmak | Buggy auditor'lar açık doğurur |
| 4 | `api` grubuna eklemek | Eşleşmeyen rotaların 404'ü başlıksız kalır (ders 21) |
| 5 | `prepend()` kullanmak | Yanıt henüz yok; başlık eklenecek nesne yok |
| 6 | CSP'yi bir HTML uygulamasından kopyalamak | `'none'` her şeyi kırar / gevşek CSP hiçbir şey korumaz |
| 7 | Başlıkları nginx'e yazıp repoda iz bırakmamak | Sunucu taşımasında sessizce kaybolur (**B10**) |

---

## 7. Kendin dene

```powershell
curl.exe -i http://127.0.0.1:8000/api/ping
```

Altı başlıktan beşini görmelisin — **HSTS hariç**, çünkü istek `http`.
Hata yolunda da olmalı:

```powershell
curl.exe -i http://127.0.0.1:8000/api/boyle-bir-yol-yok
```

🔴 HSTS'in varlığı yalnızca **gerçek bir HTTPS isteğiyle** görülebilir; elle
doğrulama betiğine bu yüzden ayrı bir adım olarak giriyor. Üretimde:

```bash
curl -sI https://api.davetkart.com/api/ping | grep -i strict-transport
```

**Mutasyon denemesi (T16):** `Referrer-Policy` satırını sil →
`every_response_carries_the_hardening_headers` **kırmızıya dönmeli**.
`$request->secure()` koşulunu kaldır → `hsts_is_absent_over_plain_http`
kırmızıya dönmeli.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **MIME sniffing** | Tarayıcının içerik tipini beyandan bağımsız tahmin etmesi |
| **Clickjacking** | Görünmez bir iframe ile kullanıcıya istemediği tıklamayı yaptırmak |
| **CSP** | Content Security Policy — sayfanın hangi kaynakları yükleyebileceği |
| **HSTS** | Tarayıcıya "bu alan adına hep HTTPS ile gel" talimatı |
| **Preload listesi** | Tarayıcılarla birlikte gelen, HSTS'i ilk ziyaretten önce uygulayan liste |
