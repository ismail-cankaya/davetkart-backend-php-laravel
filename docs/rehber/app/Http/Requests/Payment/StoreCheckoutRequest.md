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

## 3. `Rule::enum()` — geçerli plan listesi enum'dan

```php
'tier' => ['required', Rule::enum(SubscriptionTier::class)],
```

Elle `'in:standart,gold,elit'` yazılabilirdi. Yazılmadı: enum'a bir plan
eklendiğinde kural **sessizce eskirdi** — K39'un migration'daki CHECK
kısıtlarında kurduğu aynı ilke, doğrulama katmanında.

> ⚠️ **D6** (Faz 3): *"kural **adı** sözleşmenin parçasıdır."* `Password::min(8)`
> kural nesnesi sınıf adı sızdırdığı için `'min:8'` string'ine çevrilmişti.
> `Rule::enum` neden sorun değil? Çünkü ürettiği kural adı `Illuminate\…\Enum`
> değil **`enum`**'dur — hata zarfına `{"rule": "enum"}` diye çıkar, sınıf adı
> sızmaz. Kuralı kopyalamadan önce gerekçesini kontrol etmek (ders 42) burada
> "hayır, bu güvenli" cevabını verdi.

`Rule::enum` değeri enum'a **çevirmez**; `validated()` hâlâ string döndürür.
Dönüşüm `tier()` metodunda açıkça yapılıyor — Action enum bekler, sihirli
string değil.

---

## 4. 🔴 `invitationId`'de `exists` kuralı neden YOK?

```php
'invitationId' => ['sometimes', 'nullable', 'string', 'ulid'],
```

`exists:invitations,id` eklemek doğal görünür. Eklenmedi:

| Kural | Var olmayan kimlik | Başkasının kimliği |
|---|---|---|
| `exists` ile | **422** | 200 (sonra Gate 404) |
| `exists` olmadan | 404 (Gate) | 404 (Gate) |

`exists` ile iki durum **ayırt edilebilir** hâle gelir ve saldırgan
`POST /payments/checkout` gövdesine ULID yağdırarak **hangi davetiyelerin var
olduğunu** haritalayabilir.

Bu, Faz 2'nin **A1** kuralının (*"auth uçlarında `unique`/`exists`
kullanılmaz"*) IDOR eksenine taşınmış hâli ve `docs/08` §3.2'nin doğrudan
uygulaması: **sahiplik yoksa 404, ayrım verilmez.**

Aidiyet sorusu `Gate::authorize('publish', $invitation)` ile controller'da
soruluyor — yani **rota bağlaması** kaydı çözemezse zaten 404 dönüyor.

### `'ulid'` kuralı ne katıyor?

Biçim kontrolü bir **maliyet savunmasıdır** (**O6**): biçimsiz bir kimlik
veritabanına hiç ulaşmaz. Rotadaki `whereUlid()` ile aynı fikir — ama burada
kimlik gövdede geldiği için rota kısıtı devreye giremez.

---

## 5. Yardımcı metotlar: tip sınırda daralır

```php
public function tier(): SubscriptionTier
public function invitationId(): ?string
```

Controller'ın `$request->validated()['tier']` yazması gerekseydi:

- Tip `mixed` olurdu, PHPStan level 8 şikâyet ederdi
- Dönüşüm (`SubscriptionTier::from`) çağıran her yerde tekrarlanırdı

**Ders 29**: *tip belirsizliğini sınırda çöz.* Faz 3'ün
`invitationAttributes()` / `timelineEvents()` metotları da aynı işi yapıyordu.

`invitationId()` içindeki `=== ''` kontrolü savunmadan çok tip daraltmadır:
`ConvertEmptyStringsToNull` global middleware'i (Faz 2, ders 20) boş string'i
zaten `null`'a çeviriyor.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Gövdeye `price`/`amount` eklemek | Kullanıcı kendi fiyatını yazar |
| 2 | `'in:standart,gold,elit'` yazmak | Enum değişince kural sessizce eskir |
| 3 | `invitationId`'ye `exists` eklemek | Kimlik uzayı taranabilir olur |
| 4 | `'ulid'` kuralını atlamak | Biçimsiz kimlik veritabanına gider (O6) |
| 5 | `authorize()` içine sahiplik yazmak | Reddi 403 olur; H7 404 istiyor |
| 6 | Controller'da `$request->all()` kullanmak | D5 ihlali; enjekte edilen alanlar geçer |

---

## 7. Kendin dene

```bash
# Geçersiz plan
curl -X POST http://127.0.0.1:8000/api/payments/checkout \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -d '{"tier":"platinum"}'
# 422  {"error":{"code":"VALIDATION_FAILED","fields":{"tier":[{"rule":"enum"}]}}}

# Biçimsiz kimlik
curl ... -d '{"tier":"gold","invitationId":"abc"}'
# 422  fields.invitationId[0].rule = "ulid"

# Var olmayan ama geçerli biçimli kimlik
curl ... -d '{"tier":"gold","invitationId":"01arz3ndektsv4rrffq69g5fav"}'
# 🔴 404 RESOURCE_NOT_FOUND  — 422 DEĞİL (ayrım verilmiyor)
```

---

## 8. Sırada ne var?

**7.14 — `PaymentController` + `PublicPaymentWebhookController`.** İki uç, iki
tehdit modeli: biri `auth:sanctum` arkasında, diğeri internete açık ve tek
savunması imza.
