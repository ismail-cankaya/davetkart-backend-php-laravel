# `app/Http/Requests/Payment/StoreCheckoutRequest.php`

> **Kod dosyası:** `app/Http/Requests/Payment/StoreCheckoutRequest.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.13
> **Kardeşi:** [`../../Resources/OrderResource.md`](../../Resources/OrderResource.md)

---

## 1. FormRequest ne yapar, ne yapmaz?

```
rota → FormRequest → Controller → Action → Model → Resource → yanıt
        ▲
        └ BİÇİM doğrulanır. İş kuralı BURADA DEĞİL.
```

`CLAUDE.md` §1: doğrulama `FormRequest`'lere, iş kuralı Action'lara aittir.
Action'a gelen veri **saf ve güvenilir** kabul edilir.

---

## 2. 🔴 Gövdede fiyat alanı YOK

```php
return [
    'tier' => [...],
    'invitationId' => [...],
];
```

Frontend'in `CheckoutPayload` tipi de yalnızca `{ tier }` taşıyor. Ama asıl
mesele şu: **bir fiyat alanı doğrulanabilir bir şey değildir.**

```json
{ "tier": "elit", "price": 1 }
```

`integer`, `min:1`, `max:100000` — hepsini geçer. Biçimsel olarak kusursuz,
ticari olarak felaket. Çözüm doğrulama değil **mimaridir**: alan hiç kabul
edilmez, değer sunucudan okunur (`StartCheckoutAction` §4. katman).

> Aynı refleks Faz 3'te `Invitation`'ın `#[Fillable]` listesinde `status` ve
> `user_id`'nin bilerek olmamasıydı: bir alan "sunucunun malıysa" istekten hiç
> okunmaz.

---

## 3. 🔴 Geçerli plan listesi enum'dan — ama `Rule::enum` ile DEĞİL

```php
'tier' => ['required', 'string', 'in:'.implode(',', SubscriptionTier::values())],
```

İki karar var, ikisi de bilinçli.

### 3.1 Liste elle yazılmıyor

`'in:standart,gold,elit'` düz metin olarak yazılabilirdi. Yazılmadı: enum'a bir
plan eklendiğinde kural **sessizce eskirdi** — K39'un migration'daki CHECK
kısıtlarında kurduğu aynı ilke, doğrulama katmanında. `MediaKind::
guestUploadableValues()` Faz 6'da aynı kalıbı kurmuştu.

### 3.2 🔴 `Rule::enum()` neden kullanılmadı? (D6)

Laravel'in `Rule::enum(SubscriptionTier::class)` yardımcısı daha temiz
görünüyor. **Kullanılamaz** — ve gerekçe Faz 3'te zaten yazılmıştı.

**D6:** *"Kural **adı** sözleşmenin parçasıdır; kural nesnesi değil, string
kural."*

`ApiExceptionRenderer::fields()` ihlal edilen kuralların adını
`$e->validator->failed()`'ten okur (Faz 1, **ders 16**). Bir **kural nesnesi**
kullanıldığında bu dizinin anahtarı kuralın **sınıf adıdır**:

```json
{ "error": { "code": "VALIDATION_FAILED",
  "fields": { "tier": [{ "rule": "illuminate\validation\rules\enum" }] } } }
```

Üç şey birden bozulur:

| Bozulan | Nasıl |
|---|---|
| Sözleşme | Frontend'in çeviri anahtarı bir framework sınıf adı olur |
| Bilgi ifşası | Yanıt hangi framework'ü ve sürümü kullandığımızı söyler (`docs/08` §3.3) |
| Kararlılık | Laravel sınıfı taşırsa sözleşme **sessizce** kırılır |

Faz 2'de `RegisterRequest`'teki `Password::min(8)` tam olarak bu yüzden
`'min:8'` string'ine çevrilmişti. Aynı tuzak, yeni kılıkta.

`'in:'` kuralı ise `{"rule":"in","params":{"values":["standart","gold","elit"]}}`
üretir — frontend `t('validation.in')` diyebilir.

> **Ders 42 tersten:** bir kuralı uygulamadan önce gerekçesini kontrol et.
> `Rule::enum` "modern Laravel" göründüğü için cazipti; gerekçe kontrolü onu
> eledi.

## 4. 🔴 Davetiye kimliği neden gövdede değil?

İki uç var ve fark **URL'nin yapısındadır**:

```
POST /api/invitations/{invitation}/checkout   → TEKİL alım
POST /api/payments/checkout                   → PAKET alım (K42)
```

İlk tasarımda kimlik gövdedeydi (`invitationId`). Vazgeçildi — **N1** (Faz 3):

> *"Alt kayıt her zaman üst kaydın ilişkisinden oluşturulur."*

Faz 6 aynı kararı medya uçlarında bir kez daha vermişti ve `docs/09`'un düz
`POST /media/upload` önerisini geçersiz kılmıştı:

> *"Düz bir `/media/upload` ucu olsaydı davetiye kimliği gövdeden gelirdi —
> yani **istemcinin sözüne kalırdı**."*

Kimlik URL'de olunca üç şey bedavaya gelir:

| | Gövdede kimlik | URL'de kimlik ✅ |
|---|---|---|
| Var olmayan kayıt | Elle sorgu + `firstOrFail` | **Rota bağlaması** → 404 |
| Biçimsiz kimlik | `'ulid'` doğrulama kuralı | **`whereUlid()`** → rota hiç eşleşmez (O6) |
| Aidiyet | Controller'da elle | `Gate::authorize('publish', $invitation)` |

### `exists` kuralı da böylece hiç doğmuyor

Kimlik gövdede kalsaydı `exists:invitations,id` cazip olurdu ve bir açık
yaratırdı:

| | Var olmayan kimlik | Başkasının kimliği |
|---|---|---|
| `exists` ile | **422** | 200, sonra Gate → 404 |
| URL + Gate ile | 404 | 404 |

`exists` iki durumu **ayırt edilebilir** yapar; saldırgan ULID yağdırarak
hangi davetiyelerin var olduğunu haritalayabilir. Faz 2'nin **A1** kuralının
(*"auth uçlarında `unique`/`exists` kullanılmaz"*) IDOR eksenine taşınmış hâli
ve `docs/08` §3.2'nin doğrudan uygulaması.

> **Ders (Faz 4, 41):** doğru katmanda alınmış bir karar umulmadık bir yerde
> ikinci kez işe yarar. N1 Faz 3'te program adımları için yazılmıştı; Faz 6'da
> medyayı, Faz 7'de siparişi kurtardı.

## 5. Yardımcı metotlar: tip sınırda daralır

```php
public function tier(): SubscriptionTier
```

Controller'ın `$request->validated()['tier']` yazması gerekseydi:

- Tip `mixed` olurdu, PHPStan level 8 şikâyet ederdi
- Dönüşüm (`SubscriptionTier::from`) çağıran her yerde tekrarlanırdı

**Ders 29**: *tip belirsizliğini sınırda çöz.* Faz 3'ün
`invitationAttributes()` / `timelineEvents()` metotları da aynı işi yapıyordu.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Gövdeye `price`/`amount` eklemek | Kullanıcı kendi fiyatını yazar |
| 2 | `'in:standart,gold,elit'`i elle yazmak | Enum değişince kural sessizce eskir |
| 2b | `Rule::enum()` kullanmak | Sınıf adı hata zarfına sızar (D6) |
| 3 | Davetiye kimliğini gövdeye taşımak | Aidiyet istemcinin sözüne kalır (N1) |
| 4 | Kimlik gövdedeyken `exists` eklemek | Kimlik uzayı taranabilir olur |
| 5 | `authorize()` içine sahiplik yazmak | Reddi 403 olur; H7 404 istiyor |
| 6 | Controller'da `$request->all()` kullanmak | D5 ihlali; enjekte edilen alanlar geçer |

---

## 7. Kendin dene

```bash
# Geçersiz plan
curl -X POST http://127.0.0.1:8000/api/payments/checkout \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -d '{"tier":"platinum"}'
# 422  {"error":{"code":"VALIDATION_FAILED",
#        "fields":{"tier":[{"rule":"in","params":{"values":["standart","gold","elit"]}}]}}}

# 🔴 Biçimsiz kimlik ROTAYA HİÇ ULAŞMAZ (whereUlid)
curl -X POST http://127.0.0.1:8000/api/invitations/abc/checkout ... -d '{"tier":"gold"}'
# 404 RESOURCE_NOT_FOUND

# 🔴 Başkasının davetiyesi ile var olmayan davetiye AYNI yanıtı verir
curl -X POST http://127.0.0.1:8000/api/invitations/01arz3ndektsv4rrffq69g5fav/checkout ...
# 404 RESOURCE_NOT_FOUND
```

---

## 8. Sırada ne var?

**7.14 — `PaymentController` + `PublicPaymentWebhookController`.** İki uç, iki
tehdit modeli: biri `auth:sanctum` arkasında, diğeri internete açık ve tek
savunması imza.
