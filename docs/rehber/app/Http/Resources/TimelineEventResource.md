# `app/Http/Resources/TimelineEventResource.php`

> **Kod dosyası:** `app/Http/Resources/TimelineEventResource.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.9 (3/3)
> **Önce oku:** [`InvitationPayloadResource.md`](InvitationPayloadResource.md)

---

## 1. Dört alan, iki karar

```php
return [
    'id' => (string) $this->id,
    'time' => $this->time ?? '',
    'title' => $this->title ?? '',
    'description' => $this->description ?? '',
];
```

`types.ts → TimelineEvent` de tam olarak bu dördünü tanımlıyor. Ama iki şey
bilinçli olarak **yok**.

---

## 2. 🔴 `sort_order` neden dışarı çıkmıyor?

Kolonu var, dolu, ve sıralamanın kaynağı. Yine de yanıtta yer almıyor.

Sebep: **sıra zaten dizinin kendisinde ifade ediliyor.**

```json
"timelineEvents": [
  { "id": "7", "time": "17:00", "title": "Karsilama" },   ← 0. eleman
  { "id": "8", "time": "19:00", "title": "Nikah" }        ← 1. eleman
]
```

İlişki `->orderBy('sort_order')` ile sıralı geliyor (3.4), dolayısıyla dizi
sırası **doğru sıradır**. `sortOrder` alanını da göndersek aynı bilgi iki yerde
durur — ve ikisi çelişirse frontend hangisine inanacak?

İki doğruluk kaynağı üretmemek, E1'in koleksiyon düzeyindeki karşılığıdır.

Frontend zaten sıralamayı diziden okuyor: `TimelineEditor` elemanları geldiği
sırayla çiziyor, `sortOrder` diye bir alan hiç kullanmıyor.

### Peki sıra sunucuya nasıl geri dönüyor?

Aynı şekilde: dizinin **konumundan**. 3.10'daki senkronizasyon gelen listeyi
sırayla dolaşıp `sort_order`'ı indeksten yazacak:

```
gelen[0] → sort_order = 0
gelen[1] → sort_order = 1
```

Yani `sort_order` bir **iç temsil**; API sözleşmesine hiç çıkmıyor.

> **İlke:** Bir bilgi yapının kendisinde zaten varsa, ayrıca alan olarak
> gönderilmez.

---

## 3. `id` neden `(string)`?

Kolon `bigint` (3.3 §6), sözleşme ise tüm id'lerin metin olmasını istiyor —
frontend `id: string` bekliyor.

`InvitationResource`'ta cast yoktu çünkü ULID zaten metindi. Burada gerekli.

Bu cast aynı zamanda **K44'ün gidiş-dönüş simetrisini** kuruyor:

```
Sunucu → "7"  →  frontend saklar  →  istek gelir "7"  →  3.10 esler
```

Sayı olarak gönderseydik, JSON'da `7` ile `"7"` farklı tiplerdir ve 3.8'in
`'string'` doğrulama kuralı onu reddederdi.

---

## 4. `null` → `''` (yine sözleşme uyumu)

Kolonlar `nullable` (3.3), `types.ts` ise üçünü de zorunlu `string` tanımlıyor.

```ts
time: string;
title: string;
description: string;
```

`null` gönderseydik `TimelineEditor`'daki `value={event.title}` ifadesi React'te
kontrolsüz input uyarısı üretirdi ve kullanıcı o alana yazdığı anda imleç
davranışı bozulurdu.

`InvitationPayloadResource` §4'teki gerekçenin aynısı: **veritabanı "değer yok"
diyebilir, sözleşme diyemez.**

---

## 5. Neden ayrı bir sınıf, neden gömülü dizi değil?

`InvitationPayloadResource` içinde şöyle de yazabilirdik:

```php
'timelineEvents' => $this->timelineEvents->map(fn ($e) => [
    'id' => (string) $e->id, 'time' => $e->time ?? '', ...
])->all(),                                                        // ❌
```

Çalışır. Ama:

- Faz 4'te misafire açık sürüm de aynı dönüşümü isteyecek → kopyalanır
- Beyaz liste kuralı (C1) burada görünmez hâle gelir, gözden kaçar
- Dönüşümü test etmek için üst Resource'u kurmak gerekir

Ayrı sınıf, dönüşümü **tek yerde** ve **tek başına test edilebilir** tutuyor
(C3). Dört alanlık bir sınıf için bile geçerli olan gerekçe budur.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `sortOrder` alanını da göndermek | İki doğruluk kaynağı; çelişince belirsizlik | Dizi sırası yeter |
| 2 | `id`'yi sayı göndermek | 3.8'in `string` kuralı geri dönüşte reddeder | `(string)` |
| 3 | `null` göndermek | React kontrolsüz input uyarısı | `?? ''` |
| 4 | Dönüşümü üst Resource'a gömmek | Faz 4'te kopyalanır | Ayrı sınıf |
| 5 | `created_at` / `invitation_id` eklemek | Sözleşmede yok, sızıntı | Beyaz liste |

---

## 7. Kendin dene

```php
use App\Models\Invitation;
use App\Http\Resources\TimelineEventResource;

$inv = Invitation::factory()->withTimeline(3)->create();
$inv = Invitation::query()->with('timelineEvents')->find($inv->id);

$out = TimelineEventResource::collection($inv->timelineEvents)
    ->toArray(request());

$out;
// => [ {id: "1", time: "19:00", title: "...", description: "..."}, ... ]

// Sira dizinin kendisinde
count($out);                                   // => 3

// sort_order sizmadi
array_key_exists('sortOrder', $out[0]);        // => false
array_key_exists('sort_order', $out[0]);       // => false
array_key_exists('invitation_id', $out[0]);    // => false

// id metin
gettype($out[0]['id']);                        // => "string"

Invitation::query()->forceDelete();
```

```powershell
composer check
```

---

## 8. Sırada ne var?

**3.10 — Action katmanı:** `CreateInvitationAction`,
`UpdateInvitationAction`, `SyncTimelineEventsAction`.

Orada fazın en ilginç algoritması var: gelen listeyi mevcut satırlarla eşleyip
**ekle / güncelle / sil** kararı vermek — ve bunu K44'ün sözleşmesiyle,
başkasının satırına hiç dokunmadan yapmak.
