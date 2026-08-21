# `app/Actions/Invitation/UpdateInvitationAction.php`

> **Kod dosyası:** `app/Actions/Invitation/UpdateInvitationAction.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.10 (3/3)
> **Önce oku:** [`CreateInvitationAction.md`](CreateInvitationAction.md)

---

## 1. Oluşturmadan farkı

Yapı neredeyse aynı: transaction, sync, `load()`. İki fark var.

```php
$invitation->fill($attributes)->save();
```

`create()` yerine `fill()->save()`. Kayıt zaten var; sahibi de belli, dolayısıyla
ilişkiden geçmeye gerek yok.

🔴 Ama `#[Fillable]` koruması burada da çalışıyor: `fill()` yalnızca beyaz
listedeki alanları yazar. İstemci `status` göndermeyi başarsa bile (3.8 zaten
engelliyor) bu satır onu yok sayardı — **katmanlı savunma**.

---

## 2. Yetki kontrolü neden burada değil?

Bu Action bir davetiyeyi güncelliyor ama "bu kullanıcının mı?" diye **sormuyor**.

Çünkü soru 3.7'de cevaplandı: `InvitationPolicy` controller'da çalışıyor
(`authorizeResource`). Action'a ulaşan bir istek, yetki kapısını çoktan geçmiştir.

Buraya da koysaydık kural **iki yerde** olurdu — Policy yazmamızın gerekçesinin
tam tersi. Ve iki kopya zamanla ayrışır: biri güncellenir, diğeri unutulur.

> **İlke:** Bir kural iki katmanda da doğruysa, **sorumlu katmanı seç** ve
> diğerinde tekrarlama. Katmanlı savunma, aynı kuralı kopyalamak değil;
> **farklı türden** engelleri üst üste koymaktır.

`#[Fillable]` (mass assignment) ile Policy (sahiplik) farklı türden engellerdir —
o yüzden ikisi birlikte var.

---

## 3. `$timelineEvents === null` burada kritik

```php
$timelineChanged = $timelineEvents !== null
    && $this->syncTimelineEvents->handle($invitation, $timelineEvents);
```

Oluşturmada `null` ile `[]` aynı sonucu veriyordu. Burada **tamamen farklılar**:

| İstek | Sync | Sonuç |
|---|---|---|
| `timelineEvents` alanı yok | Çağrılmaz | Program **aynen kalır** |
| `timelineEvents: []` | Çağrılır | Program **tamamen silinir** |

Yalnızca başlığı güncelleyen kısmi bir istek düşün. `null`'ı `[]` gibi
davransaydık, o istek kullanıcının **tüm programını silerdi** — ve kullanıcı
neden sildiğini asla anlayamazdı.

Bu ayrım 3.8'deki `array_key_exists` kararının devamı: **"yok" ile "boş" farklı
bilgilerdir.**

### `&&` burada kısa devre yapıyor — sorun değil mi?

`$timelineEvents === null` ise `handle()` **hiç çağrılmaz**. Bu kez kısa devre
tam olarak istediğimiz şey.

`SyncTimelineEventsAction` §7'de kısa devrenin bir hataya yol açtığını görmüştük.
Fark: orada sağ tarafta **yan etkisi olan** bir çağrı vardı ve her durumda
çalışması gerekiyordu. Burada sağ tarafın çalışmaması **kararın kendisi**.

> Aynı dil özelliği bir yerde tuzak, başka yerde araç. Ayırt edici soru:
> *"sağ taraf her durumda çalışmalı mı?"*

---

## 4. `wasChanged()` ve bayat `updated_at`

```php
if ($timelineChanged && ! $invitation->wasChanged()) {
    $invitation->touch();
}
```

İnce ama gerçek bir sorunu çözüyor.

Kullanıcı **yalnızca** bir program adımının başlığını değiştirdi. Ne olur?

```
1. $attributes bos veya degismemis  → save() hicbir sey yazmaz
2. sync program satirini gunceller  → timeline_events degisti
3. invitations.updated_at           → DEGISMEDI  ⚠️
```

Frontend `updatedAt` alanını *"son sunucu güncellemesi"* diye gösteriyor
(`types.ts`). Kullanıcı bir şey değiştirir, "son kaydetme" saati eskide kalır ve
"kaydolmadı mı?" diye endişelenir.

`touch()` yalnızca `updated_at` kolonunu günceller.

### `isDirty()` ile `wasChanged()` farkı

Sık karıştırılır ve **zamanlaması** farklıdır:

| Metot | Sorusu | Ne zaman |
|---|---|---|
| `isDirty()` | Kaydedilmemiş değişiklik var mı? | `save()`'ten **önce** |
| `wasChanged()` | `save()` gerçekten bir şey değiştirdi mi? | `save()`'ten **sonra** |

Burada `save()` zaten çalıştı, dolayısıyla doğru soru `wasChanged()`.
`isDirty()` yazsaydık her zaman `false` dönerdi (kayıt sonrası temizdir) ve
`touch()` **her seferinde** çalışırdı — gereksiz bir yazma daha.

### Neden koşullu, neden her zaman `touch()` değil?

`save()` bir şey değiştirdiyse `updated_at` zaten güncellendi. Üstüne `touch()`
çağırmak ikinci bir `UPDATE` sorgusu demektir — autosave 1,5 saniyede bir
çalışırken bu iki katı yazma yükü olurdu.

---

## 5. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `null` ile `[]`'i aynı saymak | Kısmi güncelleme **programı siler** | Ayır |
| 2 | Action'da yetki kontrolü | İki doğruluk kaynağı | Policy (3.7) |
| 3 | `isDirty()` kullanmak (save sonrası) | Her zaman `false`, gereksiz `touch()` | `wasChanged()` |
| 4 | Koşulsuz `touch()` | Her istekte iki `UPDATE` | Koşullu |
| 5 | `update($attributes)` + ayrı sync, transaction'sız | Yarım durum | `DB::transaction` |
| 6 | `load()` unutmak | Yanıtta bayat program | `load('timelineEvents')` |

---

## 6. Kendin dene

```php
use App\Models\Invitation;
use App\Actions\Invitation\UpdateInvitationAction;

$inv = Invitation::factory()->withTimeline(2)->create(['title' => 'Eski']);
$action = app(UpdateInvitationAction::class);

// 1) Yalnizca baslik degisti, programa DOKUNMA
$action->handle($inv, ['title' => 'Yeni'], null);
$inv->fresh()->title;                          // => "Yeni"
$inv->fresh()->timelineEvents()->count();      // => 2   ✅ program korundu

// 2) 🔴 Bos dizi: kullanici tum adimlari sildi
$action->handle($inv, [], []);
$inv->fresh()->timelineEvents()->count();      // => 0   ✅ hepsi silindi

// 3) updated_at bayat kalmiyor mu?
$inv2 = Invitation::factory()->withTimeline(1)->create();
$once = $inv2->updated_at;
$adimId = (string) $inv2->timelineEvents()->value('id');

sleep(1);
$action->handle($inv2, [], [['id' => $adimId, 'title' => 'Sadece program degisti']]);

$inv2->fresh()->updated_at->greaterThan($once);
// => true   ✅ touch() calisti

Invitation::query()->forceDelete();
```

İkinci ve üçüncü denemeler bu dosyanın iki kararının kanıtı.

```powershell
composer check
```

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **`fill()`** | Beyaz listedeki alanları modele yazma (kaydetmez) |
| **`isDirty()`** | Kaydedilmemiş değişiklik var mı (save öncesi) |
| **`wasChanged()`** | `save()` gerçekten değişiklik yazdı mı (save sonrası) |
| **`touch()`** | Yalnızca `updated_at`'i güncelleme |
| **Katmanlı savunma** | Farklı türden engelleri üst üste koyma |

---

## 8. Sırada ne var?

**3.11 — `InvitationController` + rotalar**

Katmanları birleştiren yer. Orada:

- `authorizeResource` ile Policy bağlanması
- ⚠️ Rota **sırası**: `{invitation}` sabit rotaları yutar
- Route model binding'in ULID ile çalışması
- Controller'ın 3-8 satır kalması (`CLAUDE.md` §1)
- 🔴 `with('timelineEvents')` — 3.9'un kararının karşılığı
