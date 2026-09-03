# `app/Http/Controllers/Api/V1/PaymentController.php`

> **Kod dosyaları:** `PaymentController.php` ·
> `InvitationController::publish()` (aynı adımda eklendi)
> **Faz:** 7 — Ödeme ve paywall, dosya 7.14
> **Kardeşi:** [`PublicPaymentWebhookController.md`](PublicPaymentWebhookController.md)

---

## 1. Controller ne yapar?

`CLAUDE.md` §1: *"Controller'lar sadece gelen isteği ilgili Action'a
yönlendirmekten ve Resource dönmekten sorumludur (3-8 satır)."*

```
rota → FormRequest (biçim) → Controller (yetki + yönlendirme) → Action (iş kuralı) → Resource
```

Bu controller'ın üç metodu da bu şablonu izliyor: `Gate::authorize` → Action →
Resource.

---

## 2. İki checkout metodu, K42'nin iki kolu

```php
forInvitation()  →  POST /api/invitations/{invitation}/checkout   (tekil)
forAccount()     →  POST /api/payments/checkout                   (paket)
```

### Neden iki uç?

Aidiyet **URL'nin yapısında** duruyor (**N1**). Gövdede `invitationId`
taşınsaydı, "bu davetiye senin mi" sorusu bir rota bağlaması yerine elle
yazılmış bir sorguya bağlı olurdu — ve elle yazılan şey sessizce yanlış
olabilir (Faz 4, **ders 36**).

`docs/09` düz bir `POST /api/payments/checkout` öngörmüştü. Faz 6 aynı kararı
medya uçlarında zaten değiştirmişti; bu, o kararın ikinci uygulaması.

### Neden tek Action?

İki Action yazmak, aralarındaki dört ortak katmanı (yeterlilik, sunucu fiyatı,
sipariş yazımı, telafi) **kopyalamak** olurdu — **C3**: aynı sözleşmeyi üreten
iki uç tek yerden üretir. Tek fark `?Invitation $invitation` parametresidir.

---

## 3. `Gate::authorize('publish', $invitation)` — checkout'ta neden 'publish'?

Bir davetiye için plan satın almak, **yalnızca yayınlayabileceğin davetiye
için** anlamlıdır. İkinci bir ability (`purchase`) tanımlamak aynı kuralın
(`owns()`) ikinci kopyası olurdu — **P1**: sahiplik kuralı tek yerde.

Reddin karşılığı **404**'tür: `ApiExceptionRenderer`,
`AuthorizationException`'ı `RESOURCE_NOT_FOUND`'a çevirir (**H7**). Yani
"başkasının davetiyesi" ile "var olmayan davetiye" **ayırt edilemez** — iki
farklı sebep, aynı yanıt.

> `authorizeResource` kullanılmıyor: Laravel 11+ taban controller'ı boş ve
> `$this->middleware()` metodu yok (Faz 3, ders 28).

---

## 4. `InvitationController::publish()` — neden ayrı bir ability?

```php
Gate::authorize('publish', $invitation);
```

`update` de sahiplik soruyor; ikisi bugün **aynı** cevabı veriyor. Yine de
ayrı, çünkü **niyetleri** farklı.

`docs/08`'in kod kataloğunda kullanılmayan bir kod duruyor:
`INVITATION_LOCKED` (403). O kod bir gün *"yayınlanmış davetiye
düzenlenemez"* kuralı için kullanılacak. O gün `update` kilitlenecek ama
`publish` kilitlenmemeli. Aynı ability'yi paylaşıyor olsalardı ikisi
**birlikte** kilitlenirdi.

> **Ders:** iki kuralın bugün aynı cevabı vermesi, aynı kural oldukları
> anlamına gelmez.

### 🔴 `->load('timelineEvents')` neden şart?

`PublishInvitationAction` satırı **kilitleyip yeniden okuyor**
(`lockForUpdate()->firstOrFail()`), yani dönen örnek rota bağlamasının
yüklediği ilişkileri **taşımıyor**.

`InvitationPayloadResource` `timelineEvents`'e `whenLoaded` olmadan erişir
(Faz 3, 3.9: sözleşme bu anahtarı zorunlu kılar). Yüklenmezse katı kip
yerelde `LazyLoadingViolationException` fırlatır; üretimde ise sessiz bir
N+1 olurdu.

### Yanıt neden 200 ve tam kayıt?

Frontend'in editörü aynı `InvitationResource`'u okuyup durumu `published`
olarak gösterebilsin diye. Ayrı bir "yayınlandı" zarfı **ikinci bir
sözleşme** olurdu (C2: zarf istisnaları ad ad tanımlıdır ve bu onlardan biri
değil).

---

## 5. 201 mi 200 mü?

```php
->setStatusCode(JsonResponse::HTTP_CREATED)   // checkout
```

Checkout **yeni bir kaynak** (sipariş satırı) yaratır → **201**.
Publish **var olan bir kaynağın durumunu** değiştirir → **200**.

`POST /api/invitations` de 201 dönüyor (Faz 3); tutarlı.

---

## 6. Bu controller'ın YAPMADIKLARI (B6)

| Yapmaz | Nerede |
|---|---|
| Fiyat hesaplamak | `StartCheckoutAction` |
| Plan yeterliliğine karar vermek | `StartCheckoutAction` / `PublishInvitationAction` |
| Hata yanıtı üretmek | `ApiExceptionRenderer` (**H10**: Action/Controller exception fırlatır, yanıt üretmez) |
| Sipariş listelemek | **Hiçbir yerde** — "siparişlerim" ucu yok (bugün ihtiyaç yok) |

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Davetiye kimliğini gövdeden almak | Aidiyet istemcinin sözüne kalır (N1) |
| 2 | `publish` için `update` ability'sini kullanmak | İleride ikisi birlikte kilitlenir |
| 3 | `->load('timelineEvents')` unutmak | `LazyLoadingViolationException` (yerelde) / N+1 (üretimde) |
| 4 | Controller'da `try/catch` ile 402 üretmek | H10 ihlali; biçim kararı tek yerde |
| 5 | İki checkout için iki Action yazmak | Dört katman kopyalanır (C3) |
| 6 | Publish için 201 dönmek | Yeni kaynak yaratılmıyor |

---

## 8. Kendin dene

```bash
TOKEN=... ; INV=...

# Paket alım
curl -X POST http://127.0.0.1:8000/api/payments/checkout \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -d '{"tier":"gold"}'
# 201 {"data":{"orderId":"01J…","tier":"gold","status":"pending","redirectUrl":"…"}}

# Tekil alım
curl -X POST http://127.0.0.1:8000/api/invitations/$INV/checkout ... -d '{"tier":"elit"}'

# Ödemesiz yayın denemesi
curl -X POST http://127.0.0.1:8000/api/invitations/$INV/publish -H "Authorization: Bearer $TOKEN" ...
# 402 {"error":{"code":"PAYMENT_REQUIRED","params":{"requiredTier":"elit"}}}
```

---

## 9. Sırada ne var?

**Webhook ucu** — [`PublicPaymentWebhookController.md`](PublicPaymentWebhookController.md).
