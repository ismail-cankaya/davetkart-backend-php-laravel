# `app/Http/Controllers/Api/V1/PublicPaymentWebhookController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/PublicPaymentWebhookController.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.14
> **Rota:** `POST /api/public/payments/webhook` — 🔴 auth **yok**

---

## 1. Sistemin üçüncü auth'suz yazma yolu

| Faz | Uç | Yazan | Savunma katmanları |
|---|---|---|---|
| 5 | LCV gönderimi | Anonim **misafir** | honeypot + hız sınırı + son tarih + kota |
| 6 | Medya yükleme | Anonim **misafir** | hız sınırı + MIME + tür izni + kota |
| **7** | **Webhook** | Bilinen bir **makine** | 🔴 **yalnızca imza** |

Tehdit modeli farklı olduğu için savunma da farklı:

- **Honeypot yok** — görünmez alan diye bir şey yok; gönderen bir tarayıcı değil
- **Kota yok** — meşru bildirim sayısı önceden bilinemez (sağlayıcı retry eder)
- **Kimlik var** — ama parola/token değil, **imza**

Tek katman olması bir zayıflık değil, doğru katman olmasının sonucu: imza
doğrulaması gönderenin kim olduğunu **kriptografik olarak** kanıtlar.

---

## 2. 🔴 CSRF muafiyeti yapılandırılmadı — yapısal

`docs/09` §Faz 7: *"🔒 İmza doğrulamalı, **CSRF muaf**."*

Laravel 11+ iskeletinde `VerifyCsrfToken` **yalnızca `web` middleware
grubunda** kayıtlı; `api` grubu onu hiç taşımaz. Yani muafiyet için yazılacak
bir satır **yok** — ve unutulabilecek bir ayar da yok.

Bu, **K12**'nin (auth'suz rotalar `/api/public/` altında) aynı fikridir:
bir güvenlik özelliğini *disipline* değil *yapıya* bağlamak.

> `bootstrap/app.php`'ye `$middleware->validateCsrfTokens(except: [...])`
> yazmak **gereksiz** ve zararlıdır: var olmayan bir muafiyet, okuyucuya CSRF
> korumasının burada geçerli olduğunu düşündürür.

---

## 3. 🔴 Neden `/api/public/` altında?

`docs/09` bu ucu `POST /api/payments/webhook` diye planlamıştı. **K12** onu
geçersiz kılıyor:

> *"Auth gerektirmeyen rotalar `/api/public/` öneki altında gruplanır. Bu
> **fail-safe** tasarımdır: `auth:sanctum` unutulursa bir kaynak herkese
> açılır. Önek, 'açık olmak'ı bir UNUTMANIN sonucu olmaktan çıkarıp AÇIKÇA
> İŞARETLENMİŞ bir istisna yapar."*

Sağlayıcı bu URL'yi bir kez yapılandırır; hangi yolda olduğu onun için
farksızdır. Bizim için ise `routes/api.php`'de auth'suz uçların **tek yerde**
toplanması demek.

---

## 4. 🔴 `getContent()`, `$request->all()` değil

```php
$payload = $request->getContent();
```

İmza **ayrıştırılmış diziden değil, ham bayt dizisinden** hesaplanır.

```
Sağlayıcı:  HMAC( '{"providerRef":"x","status":"paid"}' )
Biz:        HMAC( json_encode($request->all()) )
                  ↑ anahtar sırası? boşluk? sayı biçimi?
```

Laravel gövdeyi çözüp yeniden serileştirdiğinde anahtar sırası, boşluklar veya
`1.0` ↔ `1` gibi ayrıntılar değişebilir ve imza **"bazen" tutmazdı** — hata
ayıklaması en zor hata sınıfı.

**Kural:** imza neyin üzerinden hesaplandıysa, doğrulama da **tam olarak onun**
üzerinden yapılır.

---

## 5. 🔴 Her zaman 204 — sipariş bulunsa da bulunmasa da

```php
$action->handle($notification);

return response()->noContent();
```

`HandlePaymentCallbackAction` bilinmeyen bir referansta `null` döndürüyor. Yine
de 204 dönüyoruz, iki gerekçeyle:

| Gerekçe | Açıklama |
|---|---|
| **Sağlayıcı davranışı** | Webhook uçlarının evrensel kuralı *"aldım, bir daha gönderme"*dir. 404 dönersek sağlayıcı **sonsuza kadar retry** eder ve kuyruğunu doldurur |
| **Bilgi sızıntısı** | Yanıt farkından *"bu referans bizde var / yok"* öğrenilirdi (**L2**) |

İz kaybolmuyor: Action bilinmeyen referansı `Log::warning` ile kaydediyor ve
bu uyarının **artması** bir yapılandırma hatasının işaretidir.

### 204 neden 200 değil?

Gövdede söylenecek bir şey yok. Sağlayıcı yanıtın **içeriğini** okumaz,
yalnızca durum kodunu. Boş gövde göndermek en dürüst yanıttır — `logout`
ucunun (Faz 2) aynı kararı.

---

## 6. Hız sınırı: ne var, ne yok (B6)

| Katman | Durum |
|---|---|
| `throttleApi` (60/dk, IP başına) | ✅ **var** — grup middleware'i olarak (Faz 5) |
| `throttle:media` benzeri özel kova | ❌ yok |

Özel bir kova tanımlanmadı çünkü **meşru trafik öngörülemez**: bir kampanya
gününde yüzlerce bildirim gelebilir ve dar bir limit gerçek ödemeleri
düşürürdü.

> ⚠️ Genel 60/dk sınırı IP anahtarlı. Sağlayıcı **tek bir çıkış IP'sinden**
> yoğun bildirim gönderirse bu tavana çarpabilir ve 429 alır. Sağlayıcılar
> 429'da retry eder, yani veri kaybolmaz — ama üretimde sağlayıcı IP'lerini
> limitten muaf tutmak gerekecek. **Faz 9 borcu**, `FAZ-7.md` §9'da kayıtlı.

---

## 7. Bu controller'ın YAPMADIKLARI (B6)

| Yapmaz | Nerede / Neden |
|---|---|
| İmza doğrulamak | `PaymentGateway::parseNotification()` — sürücüye özgü |
| Durum geçişine karar vermek | `HandlePaymentCallbackAction` + `OrderStatus` |
| Davetiye yayınlamak | 🔴 Ödeme ≠ yayın; yayın bir **kullanıcı** kararıdır (7.11 §8) |
| Sağlayıcının IP'sini doğrulamak | Faz 9 — imza zaten kimlik kanıtı |

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `$request->all()` ile imza doğrulamak | İmza "bazen" tutmaz |
| 2 | Bilinmeyen referansta 404 dönmek | Sağlayıcı sonsuza kadar retry eder |
| 3 | Ucu `/api/public/` dışına koymak | K12'nin fail-safe grubu delinir |
| 4 | CSRF muafiyetini elle yapılandırmak | Var olmayan bir korumayı varmış gibi gösterir |
| 5 | Controller'da imza kontrolü yazmak | Sürücüye özgü mantık HTTP katmanına sızar |
| 6 | Yanıtta sipariş durumu döndürmek | Sağlayıcıya iç durumumuzu öğretir |

---

## 9. Kendin dene

```powershell
$payload = '{"providerRef":"fake_XXXX","status":"paid"}'
$key = (Select-String -Path .env -Pattern '^APP_KEY=(.*)$').Matches.Groups[1].Value
# İmza için tinker daha kolay:
```

```php
// php artisan tinker
$payload = json_encode(['providerRef' => 'fake_XXXX', 'status' => 'paid']);
hash_hmac('sha256', $payload, config('app.key'));
```

```bash
curl -X POST http://127.0.0.1:8000/api/public/payments/webhook \
  -H "Content-Type: application/json" -H "X-Signature: <yukarıdaki>" \
  -d '{"providerRef":"fake_XXXX","status":"paid"}'
# 204

# 🔴 İmzasız:
curl -X POST http://127.0.0.1:8000/api/public/payments/webhook \
  -H "Content-Type: application/json" -d '{"providerRef":"fake_XXXX","status":"paid"}'
# 404  {"error":{"code":"RESOURCE_NOT_FOUND"}}
```

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Webhook** | Dış servisin bize HTTP isteği atarak olay bildirmesi |
| **CSRF** | Kullanıcının oturumunu kullanarak istek attıran saldırı |
| **HMAC** | Sır ile hesaplanan, kimlik kanıtı sağlayan hash |
| **Ham gövde (raw body)** | İstek gövdesinin ayrıştırılmamış bayt hâli |
| **Fail-safe** | Hata durumunda güvenli tarafa düşen tasarım |

---

## 11. Sırada ne var?

**7.15 — `routes/api.php`.** Dört yeni uç: ikisi auth'lu checkout, biri
publish, biri auth'suz webhook.
