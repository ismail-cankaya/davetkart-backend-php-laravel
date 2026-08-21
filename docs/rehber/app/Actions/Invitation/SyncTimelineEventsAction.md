# `app/Actions/Invitation/SyncTimelineEventsAction.php`

> **Kod dosyası:** `app/Actions/Invitation/SyncTimelineEventsAction.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.10 (1/3)
> **Kardeşleri:** [`CreateInvitationAction.md`](CreateInvitationAction.md) ·
> [`UpdateInvitationAction.md`](UpdateInvitationAction.md)

---

## 1. Fazın en ilginç problemi

Kullanıcı program akışında şunları yaptı: 2. adımın başlığını değiştirdi,
3. adımı sildi, sona yeni bir adım ekledi ve 1. ile 2.'nin yerini değiştirdi.

Sunucuya gelen istek bunların **hiçbirini** söylemiyor. Yalnızca **yeni liste**
geliyor:

```json
"timelineEvents": [
  { "id": "8",  "time": "19:00", "title": "Nikah" },
  { "id": "7",  "time": "17:00", "title": "Karsilama & Kokteyl" },
  { "id": null, "time": "23:00", "title": "Havai Fisek" }
]
```

Backend'in işi: veritabanındaki hâli bu listeye **eşitlemek**. Buna
**senkronizasyon** denir ve gerçek projelerde sürekli karşına çıkar.

---

## 2. Kolay yol neden yanlış?

En basit çözüm akla hemen gelir:

```php
$invitation->timelineEvents()->delete();          // ❌ hepsini sil
foreach ($events as $i => $event) {
    $invitation->timelineEvents()->create([...]); // yeniden ekle
}
```

Üç satır, hatasız çalışır. Ama:

**1. Her autosave'de tüm satırları yok edip yeniden yaratır.** Kullanıcı yazarken
1,5 saniyede bir: 4 `DELETE` + 4 `INSERT`. Yarım saatlik bir düzenleme oturumu
binlerce gereksiz yazma demektir.

**2. `id`'ler her seferinde değişir.** Frontend id'yi React `key` olarak
kullanıyor; her kayıtta yeni id gelirse React tüm satırları yeniden çizer,
kullanıcının imleci ve seçimi kaybolur.

**3. Yarın bir şey o satırlara bağlanırsa** (adım fotoğrafı, adım bazlı LCV)
bağlar kopar.

> **İlke:** Silip yeniden yaratmak, "durum değiştirme"yi "durum yok etme"yle
> karıştırmaktır. Kimliği olan veri korunur.

---

## 3. Doğru algoritma

```
1. Bu davetiyenin mevcut satirlarini TEK sorguda oku
2. Gelen listeyi sirayla dolas:
     id mevcut bir satirla eslesiyor mu?
        evet  → guncelle,  id'yi "korunanlar"a ekle
        hayir → yeni satir olustur, id'sini "korunanlar"a ekle
3. Korunanlar listesinde OLMAYAN mevcut satirlari sil
```

Üç işlem de gerekli: **ekle**, **güncelle**, **sil**. Hiçbiri gereksiz yere
çalışmıyor.

---

## 4. 🔴 Güvenlik: aidiyet yapısal olarak garanti

```php
$existing = $invitation->timelineEvents()->get()->keyBy('id')->all();
```

Bu tek satır fazın en önemli güvenlik kararlarından biri.

`$invitation->timelineEvents()` ilişkisi sorguya `WHERE invitation_id = ?`
koşulunu **otomatik** ekler. Dolayısıyla `$existing` dizisinde **başka bir
davetiyenin satırı bulunamaz.**

Sonuç: gelen id ne olursa olsun — Mehmet'in gerçek satır id'si bile olsa —
Ayşe'nin davetiyesini işlerken `$existing` içinde bulunamaz, `matchExisting()`
`null` döner ve **yeni satır** açılır. Mehmet'in satırına hiçbir durumda
yazılmaz.

Yanlış yazım şöyle olurdu:

```php
$current = TimelineEvent::find($id);      // ❌ tum tabloda arar
$current->update($attributes);            //    baskasinin satirini EZER
```

Bu, 3.7'de kapattığımız IDOR'un alt kaynak düzeyindeki hâlidir: **üst kaynağın
sahipliği doğrulanmış olsa bile, alt kaynağın üst kaynağa aidiyeti ayrıca
doğrulanmalıdır.**

Ve dikkat: bu savunmayı bir `if` yazarak kurmuyoruz. **Sorgunun kapsamı** kuruyor
— unutulması mümkün olmayan tek savunma türü budur.

### Bir de performans faydası

Her adım için ayrı `->find($id)` çağırsaydık 20 adımlık bir program 20 sorgu
ederdi. Tek `get()` ile hepsi belleğe geliyor; eşleştirme dizi araması oluyor.

---

## 5. `matchExisting()` — K44'ün uygulanışı

```php
private function matchExisting(array $existing, mixed $id): ?TimelineEvent
{
    if (! is_string($id) || ! ctype_digit($id)) {
        return null;
    }

    return $existing[(int) $id] ?? null;
}
```

Üç durumu tek yerde çözüyor:

| Gelen `id` | `ctype_digit` | Sonuç |
|---|---|---|
| `null` | — | Yeni satır (K44'ün normal yolu) |
| `"tl-1755612340912"` | `false` | Yeni satır (geçiş dönemi, eski frontend) |
| `"7"` ama bu davetiyede yok | `true` ama bulunamaz | Yeni satır (bayat id) |
| `"7"` ve bu davetiyede var | `true`, bulunur | **Güncelle** |

`ctype_digit()` bir metnin **yalnızca rakamlardan** oluşup oluşmadığına bakar.
`"7"` → `true`, `"tl-1"` → `false`, `""` → `false`, `"-3"` → `false`.

`is_numeric()` de kullanabilirdik ama o `"7.5"` ve `"1e3"` gibi şeylere de `true`
der; birincil anahtar için gereksiz genişlik.

### Bayat id neden hata değil?

Kullanıcı davetiyeyi iki sekmede açmış olabilir. Birinde bir adımı siler,
diğerinin autosave'i artık var olmayan bir id gönderir.

- **Hata döndürseydik:** ikinci sekme sonsuza kadar 422 alır, autosave kilitlenir,
  kullanıcı hiçbir şey kaydedemez.
- **Yeni satır açarsak:** kullanıcının ekranında gördüğü program kaydedilir.

İkincisi kullanıcının niyetine daha yakın. 3.8'de `exists` kuralını bu yüzden
yazmamıştık.

---

## 6. `sort_order` istemciden gelmiyor

```php
'sort_order' => $index,
```

`$index` `foreach` içindeki dizi konumudur (0, 1, 2…). İstemci `sortOrder`
göndermiyor ve göndermemeli — 3.9'da bu alanı yanıta da koymamıştık.

Neden konumdan okuyoruz? Çünkü **sıra zaten dizinin kendisinde var.** İstemci
ayrıca `sortOrder` gönderseydi iki bilgi çelişebilirdi:

```json
[ { "sortOrder": 5, ... },      ← dizide 0. sirada ama 5 diyor
  { "sortOrder": 2, ... } ]     ← hangisine inanalim?
```

Tek doğruluk kaynağı: dizinin sırası. Gidiş ve dönüş simetrik.

---

## 7. `isDirty()` ve kısa devre tuzağı

```php
$current->fill($attributes);

if ($current->isDirty()) {
    $changed = true;
}

$current->save();
```

`isDirty()` = "bellekteki hâli veritabanından okunandan farklı mı?" Yalnızca
gerçekten değişen satırları saymak için kullanıyoruz.

Bunu tek satıra sıkıştırmak cazip:

```php
$changed = $changed || $current->fill($attributes)->isDirty();   // ❌
```

**Bu bozuk.** PHP'nin `||` operatörü kısa devre yapar: `$changed` zaten `true`
ise sağ taraf **hiç çalışmaz** — yani `fill()` çağrılmaz ve o satırın
değişiklikleri sessizce kaybolur.

Faz 2'de aynı tuzağı `LoginUserAction`'da görmüştük (A4): orada kısa devre bir
güvenlik savunmasını çökertiyordu, burada bir veri güncellemesini.

> **Genel ders:** Yan etkisi olan bir ifadeyi mantıksal operatörün sağına yazma.
> "Gereksiz iş yapma" sezgisi, o işin gerekli olduğu yerde tehlikelidir.

---

## 8. Silme: boş liste neden "hepsini sil" demek?

```php
$deleted = $invitation->timelineEvents()->whereNotIn('id', $keptIds)->delete();
```

`$keptIds` boşsa Laravel `WHERE 1 = 1` üretir, yani ilişkideki tüm satırlar
silinir.

Bu **istenen davranıştır**: kullanıcı programdaki tüm adımları kaldırdıysa
`timelineEvents: []` gönderir ve hepsi gitmelidir.

🔴 Buradaki incelik 3.8'de kurulmuştu: istekte alan **hiç yoksa** bu Action
**çağrılmaz** (`UpdateInvitationAction` `null` kontrolü yapar). Yani:

| İstek | Bu Action | Sonuç |
|---|---|---|
| `timelineEvents` yok | Çağrılmaz | Program aynen kalır |
| `timelineEvents: []` | Çağrılır | Hepsi silinir |

`null` ile `[]`'i aynı saymak, kısmi bir güncellemenin kullanıcının programını
sessizce silmesi demekti.

### `ORDER BY` sorun çıkarmıyor mu?

`timelineEvents()` ilişkisinde `->orderBy('sort_order')` var (3.4). PostgreSQL
`DELETE ... ORDER BY` desteklemez. Laravel kaynağına baktım
(`Query/Grammars/Grammar.php`):

```php
public function compileDelete(Builder $query)
{
    $table = $this->wrapTable($query->from);
    $where = $this->compileWheres($query);
    // ... orders HIC KULLANILMIYOR
}
```

Sıralama silme sorgusuna girmiyor. Tahmin etmek yerine kaynağa bakmak yine beş
dakika sürdü.

---

## 9. Neden `bool` döndürüyor?

```php
return $changed || $deleted > 0;
```

`UpdateInvitationAction` bunu `updated_at` için kullanacak: yalnızca program
değiştiyse davetiye satırı "kirli" olmaz ve son güncelleme zamanı bayat kalırdı.
Ayrıntısı [`UpdateInvitationAction.md`](UpdateInvitationAction.md) §4'te.

Action'ın **saf veri** döndürmesi (yanıt değil) `CLAUDE.md` §1'in kuralıdır.
`bool` de saf veridir.

---

## 10. Neden savunma kodu yok?

Gelen dizide `time` bir dizi olabilir mi? Boş metin olabilir mi?

Olamaz — ve bunu kontrol **etmiyoruz**:

| Endişe | Kim çözdü |
|---|---|
| `time` bir dizi olabilir | 3.8: `'string'` kuralı |
| `time` geçersiz saat olabilir | 3.8: `date_format:H:i` |
| `title` boş metin olabilir | `ConvertEmptyStringsToNull` middleware → `null` |
| Beklenmeyen alanlar gelebilir | 3.8: `validated()` yalnızca kuralı olanları verir |

Bu, `CLAUDE.md` §1'in Action kuralıdır: *Action'a gelen veri saf ve güvenilir
kabul edilir.* Ve Faz 2'nin **20. dersi**: *savunma kodu yazmadan önce
framework'ün ne yaptığını oku.*

Her katmanda yeniden doğrulamak "daha güvenli" hissettirir ama iki doğruluk
kaynağı üretir: kural değiştiğinde ikisinden birini güncellemeyi unutursun.

---

## 11. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Sil-ve-yeniden-yarat | Her autosave'de id'ler değişir, React çizimi bozulur | Gerçek senkronizasyon |
| 2 | `TimelineEvent::find($id)` | Başkasının satırı ezilir (IDOR) | İlişki üzerinden |
| 3 | Adım başına `find()` | 20 adım = 20 sorgu | Tek `get()` |
| 4 | `$changed \|\| $x->fill(...)->isDirty()` | Kısa devre → `fill()` çalışmaz | Ayrı satır |
| 5 | Bayat id'de hata fırlatmak | Autosave kilitlenir | Yeni satır aç |
| 6 | `sort_order`'ı istemciden almak | İki doğruluk kaynağı | `$index` |
| 7 | `null` ile `[]`'i aynı saymak | Kısmi güncelleme programı siler | Çağıran ayırır |
| 8 | Action içinde doğrulama | İki doğruluk kaynağı | FormRequest'e güven |

---

## 12. Kendin dene

```php
use App\Models\Invitation;
use App\Actions\Invitation\SyncTimelineEventsAction;

$inv = Invitation::factory()->withTimeline(3)->create();
$sync = app(SyncTimelineEventsAction::class);

$ilk = $inv->timelineEvents()->pluck('id')->all();   // => [1, 2, 3]

// 1) Guncelle + yeni ekle + birini sil (2 numarayi listeye koymuyoruz)
$sync->handle($inv, [
    ['id' => (string) $ilk[0], 'time' => '18:00', 'title' => 'Karsilama'],
    ['id' => null,             'time' => '23:00', 'title' => 'Havai Fisek'],
    ['id' => (string) $ilk[2], 'time' => '20:00', 'title' => 'Yemek'],
]);

$inv->load('timelineEvents');
$inv->timelineEvents->pluck('title')->all();
// => ["Karsilama", "Havai Fisek", "Yemek"]     ✅ sira listeden geldi

$inv->timelineEvents->pluck('sort_order')->all();
// => [0, 1, 2]

$inv->timelineEvents->contains('id', $ilk[1]);
// => false    ✅ listede olmayan silindi

$inv->timelineEvents->contains('id', $ilk[0]);
// => true     ✅ guncellenen KORUNDU, id'si degismedi

// 2) 🔴 Baskasinin satirini ezmeyi dene
$baskasi = Invitation::factory()->withTimeline(1)->create();
$kurbanId = $baskasi->timelineEvents()->value('id');

$sync->handle($inv, [
    ['id' => (string) $kurbanId, 'title' => 'ELE GECIRILDI'],
]);

$baskasi->load('timelineEvents');
$baskasi->timelineEvents->first()->title;
// => degismedi    ✅ baska davetiyenin satirina dokunulmadi

$inv->load('timelineEvents');
$inv->timelineEvents->pluck('title')->all();
// => ["ELE GECIRILDI"]   ← kendi davetiyesinde YENI satir olarak acildi

// 3) Bos liste hepsini siler
$sync->handle($inv, []);
$inv->timelineEvents()->count();     // => 0

Invitation::query()->forceDelete();
```

İkinci deneme bu dosyanın asıl kanıtı: gelen id gerçek bir satıra ait olsa bile
**başka bir davetiyenin verisine dokunulmuyor.**

```powershell
composer check
```

---

## 13. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Senkronizasyon** | Gelen listeyi mevcut kayıtlara eşitleme (ekle/güncelle/sil) |
| **`keyBy()`** | Koleksiyonu bir alana göre anahtarlama |
| **`isDirty()`** | Modelin kaydedilmemiş değişikliği var mı |
| **Kısa devre** | `\|\|` ve `&&`'in gerektiğinde sağ tarafı atlaması |
| **`ctype_digit`** | Metnin yalnızca rakamlardan oluşup oluşmadığı |
| **Bayat id** (*stale*) | Artık var olmayan bir kayda işaret eden kimlik |
| **Kapsam** (*scope*) | Sorguya otomatik eklenen sınırlayıcı koşul |

---

## 14. Sırada ne var?

[`CreateInvitationAction.md`](CreateInvitationAction.md) — sahipliğin ilişkiden
gelmesi ve transaction sınırının neden orada çizildiği.
