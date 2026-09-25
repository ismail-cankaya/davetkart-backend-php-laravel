# `app/Http/Middleware/RejectMalformedInput.php`

> **Eklendi:** 25 Eylül 2026 — `RsvpTest` denetiminin **D-1** kararı (seçenek A)
> **İlgili:** [`ForceJsonResponse.md`](ForceJsonResponse.md) ·
> [`../../Exceptions/ApiExceptionRenderer.md`](../../Exceptions/ApiExceptionRenderer.md) ·
> [`../../../tests/Feature/RsvpTest.md`](../../../tests/Feature/RsvpTest.md) §4.1–4.2
> **Kurallar:** **K20** (hata sözleşmesi) · **M3** (middleware sırası) · **O6**

---

## 1. Ne yapar?

Biçimsel olarak **bozuk** bir isteği, doğrulamaya hiç ulaşmadan
`400 MALFORMED_REQUEST` ile reddeder. İki bozukluğa bakar:

| Bozukluk | Bugüne kadar ne oluyordu | Şimdi |
|---|---|---|
| **Yarım JSON** (`{"guestName": "Şeyma", "status": "atten`) | Laravel çözemediği gövdeyi sessizce **boş** sayıyordu → `422` + üç alanda `required`. İstemciye "adı göndermedin" deniyordu, oysa gönderdi | `400` |
| **NUL baytı** (`"Z\u0000eynep"`) | Doğrulama PHP'de tam dizeyi görüyordu (`min:2` geçer), PostgreSQL dizeyi ilk `\0`'da **kesiyordu** → satırda `"Z"`, yanıtta `201` | `400` |

`docs/08` §4: *"**400** — İstek biçimsel olarak bozuk — `MALFORMED_REQUEST`"*.
Kod katalogda vardı, onu üreten bir yol yoktu.

---

## 2. Neden `\0` PostgreSQL'de kesiliyor?

PostgreSQL'in C istemci kütüphanesi (libpq) metin parametrelerini **C dizesi**
olarak gönderir. C dizesi ilk `\0`'da biter. PHP dizesi uzunluğunu ayrıca
bildiği için `\0` taşıyabilir, PostgreSQL'in `text` tipi taşıyamaz. `jsonb`
ise `\u0000`'ı hiç kabul etmez: orada kesme değil **500** olur. Bu yüzden
yalnızca değerler değil **anahtarlar** da taranır.

---

## 3. Neden alan başına kural değil de middleware?

| | Middleware (seçilen) | Her FormRequest'te kural |
|---|---|---|
| Yeni uç eklenince | **Kendiliğinden korunur** (fail-safe) | Biri kuralı hatırlamalı |
| Yarım JSON | Aynı kapı çözer | Çözemez — FormRequest boş gövde görür |
| Kapsam | Davetiye başlığı, kayıt adı, e-posta… `api` grubundaki **her** uç | Yalnızca kuralı yazılan alan |

`\0`'ı bir tarayıcı formuna **yazamazsın**. Gönderen ya bir bot ya da bozuk bir
istemcidir, alan bazında kullanıcı dostu bir hata mesajına ihtiyaç yok.

---

## 4. Nerede, hangi sırada?

`bootstrap/app.php`:

```php
$middleware->appendToGroup('api', RejectMalformedInput::class);
$middleware->prependToPriorityList(SubstituteBindings::class, RejectMalformedInput::class);
```

İkinci satır, Laravel'in middleware'leri **öncelik listesine** göre yeniden
sıralamasından yararlanır. Sonuç:

```text
ForceJsonResponse → throttle:api → (throttle:rsvp) → RejectMalformedInput → SubstituteBindings → controller
```

| Neden **throttle'dan sonra**? | Bozuk istek yağdıran bir bot da kovayı doldursun |
|---|---|
| Neden **SubstituteBindings'ten önce**? | Bozuk istek veritabanına **hiç sorgu açtırmasın** (**O6**'nın gerekçesi) |

---

## 5. Yanıtı kim üretir?

Middleware yanıt **üretmez**, `BadRequestHttpException` fırlatır.
`ApiExceptionRenderer` 400'ü `MALFORMED_REQUEST`'e eşler. Biçim kararı böylece
yine tek yerde kalır (**K20**). `HttpException` Laravel'in rapor-dışı listesinde
olduğu için bu 400'ler Sentry'ye gitmez.

---

## 6. Ne yapmaz?

- **Boş gövdeyi** bozuk saymaz: gövdesiz bir `DELETE` veya `POST` normal akar.
- `multipart/form-data` yüklemelerinin **dosya içeriğine** bakmaz; yalnızca
  metin alanlarını tarar.
- Geçerli ama "yanlış şekilli" JSON'a (`123`, `"metin"`) karışmaz. O,
  doğrulamanın işidir (`422`).

---

## 7. Kendin dene

```powershell
php artisan test --filter="a_nul_byte_is_rejected|a_truncated_json_body"
```

Beklenen: **5 vaka, 5 yeşil**. Elle mutasyon: `handle()`'daki `if`'i
`if (false)` yap. İki test de kırmızıya dönmeli. Sonra geri al.
