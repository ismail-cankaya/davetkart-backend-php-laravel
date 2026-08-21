# `app/Http/Resources/InvitationResource.php`

> **Kod dosyası:** `app/Http/Resources/InvitationResource.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.9 (1/3)
> **Kardeşleri:** [`InvitationPayloadResource.md`](InvitationPayloadResource.md) ·
> [`TimelineEventResource.md`](TimelineEventResource.md)
> **Bağlantılı:** [`UserResource.md`](UserResource.md) — Resource temelleri orada

---

## 1. Üç sınıf, tek yanıt

3.9 bir dosya değil bir **aile**. Sebebi frontend sözleşmesinin iç içe olması:

```json
{ "data": {
    "id": "01K3QX...",              ← InvitationResource
    "status": "saved",
    "updatedAt": "2026-08-19T15:04:05+03:00",
    "invitation": {                  ← InvitationPayloadResource
      "title": "Dugunumuz",
      "showGift": false,
      "timelineEvents": [            ← TimelineEventResource
        { "id": "7", "time": "19:00", "title": "Nikah", "description": "" }
      ]
    }
} }
```

Her sınıf **bir sözleşme parçasından** sorumlu:

| Sınıf | Sorumluluğu | `types.ts` karşılığı |
|---|---|---|
| `InvitationResource` | Sunucu üstverisi + sarmal | `InvitationRecord` |
| `InvitationPayloadResource` | Kullanıcının tasarımı | `Invitation` |
| `TimelineEventResource` | Tek program adımı | `TimelineEvent` |

Tek sınıfa sığdırsaydık 30 satırlık bir `toArray()` çıkardı ve Faz 4'te misafire
açık sürümü yazarken hepsini kopyalamak zorunda kalırdık.

---

## 2. Üstveri / tasarım ayrımı neden önemli?

3.8'de istek gövdesini `{ invitation: {...} }` diye sarmıştık. Yanıt aynı ayrımı
korur ve bu **simetri tesadüf değil**:

| Alan grubu | Kim yazar | İstekte | Yanıtta |
|---|---|---|---|
| `id`, `status`, `updatedAt` | **Sunucu** | ❌ gönderilemez | ✅ döner |
| `title`, `showGift`, … | Kullanıcı | ✅ gönderilir | ✅ döner |

Frontend `save()` çağrısında yalnızca `invitation` nesnesini geri gönderir; ne
göndereceğini düşünmesi gerekmez — **yapı ona söyler.** Düz bir gövdede
"hangilerini geri göndermemeliyim?" diye bir kural ezberlemesi gerekirdi.

---

## 3. `id` neden `(string)` cast'i almıyor?

`UserResource`'ta `(string) $this->id` yazmıştık, burada düz `$this->id`.

Çünkü `invitations.id` **zaten metin**: ULID, `char(26)` bir kolon (K40). Cast
yazmak gereksiz bir işlem olurdu.

`TimelineEventResource`'ta ise cast var — orada kolon `bigint`. Aynı sözleşme
kuralı ("id'ler metindir"), farklı kolon tipleri, dolayısıyla farklı kod.

> Sözleşme kuralı sabittir; onu sağlamanın yolu her kaynakta aynı olmak zorunda
> değildir.

---

## 4. `status` neden `->value`?

```php
'status' => $this->status->value,
```

Model cast'i (3.4) `status`'ü `InvitationStatus` **enum nesnesine** çeviriyor.
JSON'a nesne yazamayız; ham değeri (`'saved'`) istiyoruz.

`->value` yazmayı unutursan yanıt şuna döner:

```json
"status": {}
```

Hata çıkmaz, test `assertJsonStructure` ile yazılmışsa geçebilir bile — ama
frontend `status === 'published'` karşılaştırmasında sessizce yanılır. 3.1'in
sık hatalar tablosundaki 2. madde tam olarak buydu.

---

## 5. `updatedAt` neden ISO-8601?

```php
'updatedAt' => $this->updated_at?->toIso8601String(),
// => "2026-08-19T15:04:05+03:00"
```

`types.ts` bunu *"ISO timestamp of the last server-side update"* diye tanımlıyor.
ISO-8601 **saat dilimi taşır**, yani tarayıcı kullanıcının yerel saatine doğru
çevirebilir.

⚠️ Dikkat: bir sonraki dosyada `date` alanı için **farklı** bir biçim
kullanacağız (`Y-m-d\TH:i`, saat dilimsiz). İki alan, iki farklı okuyucu:

| Alan | Kim okuyor | Biçim | Neden |
|---|---|---|---|
| `updatedAt` | JS `Date` nesnesi | ISO-8601 | Yerel saate çevrilmeli |
| `date` | `<input type="datetime-local">` | `Y-m-d\TH:i` | Input saat dilimi kabul **etmez** |

Ayrıntısı [`InvitationPayloadResource.md`](InvitationPayloadResource.md) §3'te.

`?->` burada gerçekten gerekli: `updated_at` teorik olarak `null` olabilir ve
sonucu `??` ile yakalamıyoruz — `LoginUserAction.md` §3.1'deki ayrımın doğru
tarafı.

---

## 6. `new InvitationPayloadResource($this->resource)`

```php
'invitation' => new InvitationPayloadResource($this->resource),
```

`$this->resource` sarmalanan **modelin kendisidir**. Aynı `Invitation` nesnesini
ikinci bir Resource'a veriyoruz; o da ondan farklı alanları çıkarıyor.

İç içe Resource'lar `data` sarmalı **almaz**. Sarmal yalnızca en dışta, HTTP
yanıtı üretilirken eklenir:

```json
{ "data": { "invitation": { ... } } }      ✅
{ "data": { "invitation": { "data": {...} } } }   ❌ boyle OLMAZ
```

---

## 7. Zarf kuralı — C2

Bu uç `{ data: ... }` zarfıyla döner. Faz 2'de auth uçları **zarfsız** dönüyordu
(`{user, token}`).

**C2:** *zarf istisnası ad ad tanımlıdır ve gerekçesiyle taşınır.* İstisna
listesi:

| Uç | Zarf | Gerekçe |
|---|---|---|
| `POST /auth/register` | ❌ yok | `services/auth.ts` doğrudan `data.user` okuyor |
| `POST /auth/login` | ❌ yok | Aynı |
| Diğer her şey | ✅ `{data:...}` | Laravel Resource varsayılanı |

`/api/invitations` listede yok, dolayısıyla zarflı. Bunu ayrıca kodlamamıza gerek
yok — Resource döndürünce Laravel zaten ekler.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `'status' => $this->status` | JSON'a `{}` çıkar | `->value` |
| 2 | `(string) $this->id` (ULID'de) | Gereksiz işlem | Zaten metin |
| 3 | `updatedAt`'i `format()` ile yazmak | Saat dilimi kaybolur | `toIso8601String()` |
| 4 | Üç sınıfı tek dosyada birleştirmek | Faz 4'te kopyalamak gerekir | Ayrı tut |
| 5 | İç içe Resource'a `->response()` demek | Çift sarmal | Doğrudan `new` |

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Resource** | Modeli API sözleşmesine çeviren sınıf |
| **Zarf** (*envelope*) | Yanıtı saran dış anahtar (`data`) |
| **`$this->resource`** | Resource'un sarmaladığı ham model |
| **ISO-8601** | Saat dilimi taşıyan standart tarih biçimi |
| **`@mixin`** | PHPStan'e "bu sınıf şu modelin alanlarını taşır" demek |

---

## 10. Sırada ne var?

[`InvitationPayloadResource.md`](InvitationPayloadResource.md) — 24 alanlık
tasarım verisi, `phoneBackground` türetimi, iki tarih biçimi ve 🔴 **hediye
verisinin neden burada maskelenmediği.**
