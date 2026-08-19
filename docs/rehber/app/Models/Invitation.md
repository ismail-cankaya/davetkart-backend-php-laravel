# `app/Models/Invitation.php`

> **Kod dosyası:** `app/Models/Invitation.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.4
> **Bağlantılı:** [`User.md`](User.md) · [`InvitationStatus.md`](../Enums/InvitationStatus.md)

---

## 1. Model nedir?

Migration tabloyu **kurar**, model onunla **konuşur**.

```php
$invitation = Invitation::find('01K3QX...');   // SELECT
$invitation->title = 'Yeni Baslik';
$invitation->save();                            // UPDATE
```

Arkasındaki desen **Active Record**: bir satır = bir nesne, ve o nesne kendi
kaydetme/silme yeteneğine sahiptir. Alternatifi Data Mapper'dır (Doctrine,
Hibernate) — orada nesne veritabanını bilmez, aradaki eşleyici bilir.

Laravel Active Record seçtiği için biz de **Repository Pattern kullanmıyoruz**
(K4): Eloquent zaten veri erişim katmanıdır, üstüne bir katman daha koymak
anlamsız bir aracı üretir.

### Tablo adını nereden biliyor?

Yazmadık. Eloquent sınıf adını çoğullaştırıp snake_case'e çevirir:
`Invitation` → `invitations`. Kurala uyduğun sürece `$table` tanımlamana gerek
kalmaz.

---

## 2. `use HasFactory, HasUlids, SoftDeletes;` — trait nedir?

PHP'de bir sınıf yalnızca **tek** bir sınıftan miras alabilir. Ama birden çok
yerden davranış toplamak gerekebilir. **Trait**, "sınıfın içine kopyalanan kod
bloğu"dur — çoklu mirasın sorunlarını yaşatmadan yetenek ekler.

| Trait | Ne katıyor |
|---|---|
| `HasFactory` | `Invitation::factory()` — test verisi üretimi (3.6) |
| `HasUlids` | Kayıt oluşturulurken `id`'yi otomatik ULID yapar |
| `SoftDeletes` | `delete()`'i `deleted_at` damgasına çevirir, sorgulara filtre ekler |

### `HasUlids` tam olarak ne yapıyor?

Üç iş:

```php
// Laravel kaynagi (basitlestirilmis)
public function getKeyType()      { return 'string'; }   // bigint degil
public function getIncrementing() { return false; }      // veritabani artirmiyor
// ve kayit olusturulurken:
$model->id = (string) Str::ulid();
```

Yani id'yi **PHP üretiyor**, veritabanı değil. Bu şaşırtıcı gelebilir ama bir
avantajı var: `save()` çağrılmadan **önce** kimliği bilirsin. İlişkili kayıtları
tek işlemde kurarken bu işe yarar.

`HasUlids` olmadan Eloquent `id`'yi otomatik artan sayı sanardı ve `INSERT`
sırasında kolonu hiç göndermezdi — `char(26)` bir kolon `NOT NULL` ihlali verirdi.

### `SoftDeletes` sorguları nasıl değiştiriyor?

Global bir **scope** ekliyor: her sorguya sessizce `WHERE deleted_at IS NULL`
ekleniyor.

```php
Invitation::all();              // silinmemişler
Invitation::withTrashed()->get();  // hepsi
Invitation::onlyTrashed()->get();  // yalnızca çöp kutusu
$invitation->restore();            // geri getir
```

> ⚠️ 3.3'te gördüğümüz ayrıntı: soft delete gerçek bir `DELETE` üretmediği için
> `timeline_events`'teki CASCADE **tetiklenmez**. Program adımları durur ve
> `restore()` ile davetiye tam hâliyle geri gelir.

---

## 3. 🔴 `#[Fillable([...])]` — mass assignment savunması

```php
#[Fillable([
    'category_id', 'preset_id', 'palette',
    'title', 'subtitle', ...
])]
```

### Problem: toplu atama (mass assignment)

Bir Action şöyle yazar:

```php
$invitation->fill($request->validated());
```

`fill()` gelen dizideki her anahtarı kolona yazmaya çalışır. Doğrulamadan geçmiş
bir alan fazladan geldiyse — ya da doğrulama kuralı bir gün gevşerse — istemci
**istediği kolonu** yazabilir hâle gelir.

Klasik saldırı:

```json
{ "title": "Dugunumuz", "status": "published", "user_id": 1 }
```

Ödeme yapmadan yayına geçmek ve başkasının davetiyesini kendine almak — tek
istekle.

### Çözüm: beyaz liste

`#[Fillable]` listesindeki alanlar **dışında** hiçbir şey toplu atamayla
yazılamaz. Listede olmayan bir anahtar sessizce yok sayılır.

🔴 Listede **bilerek olmayan** üç alan:

| Alan | Neden dışarıda | Kim yazacak |
|---|---|---|
| `user_id` | Sahiplik istemci kararı değildir | İlişki: `$user->invitations()->create(...)` |
| `status` | Yayına geçmek ödeme gerektirir | `PublishInvitationAction` (Faz 7) |
| `published_at` | `status` ile birlikte, aynı akışta | `PublishInvitationAction` |

Bu üçü modele **ancak açıkça** yazılabilir:

```php
$invitation->status = InvitationStatus::Published;   // tek tek atama — fill() degil
```

Yani "kaza eseri" değil, "bilerek" yazılıyor. Fark budur.

### Neden `$guarded = []` yasak?

`$guarded = []` "hiçbir şey korunmasın, her alan doldurulabilir" demektir. Kara
liste yaklaşımıdır ve **yanlış yönde başarısız olur**:

| Yaklaşım | Yeni kolon eklenince |
|---|---|
| Beyaz liste (`fillable`) | Kolon **kapalı** doğar; unutursan özellik çalışmaz — fark edersin |
| Kara liste (`guarded`) | Kolon **açık** doğar; unutursan açık oluşur — fark etmezsin |

Güvenlikte doğru varsayılan **kapalı**dır. `CLAUDE.md` §3 bu yüzden `$guarded = []`
kullanımını yasaklıyor.

### `#[Fillable]` özniteliği ile `protected $fillable` farkı

İkisi aynı işi yapar. Laravel 13 öznitelik (attribute) biçimini getirdi ve bu
projede `User` modelinde de o kullanılıyor — tutarlılık için burada da öyle.

Öznitelikler PHP 8'in `#[...]` sözdizimidir: sınıfa iliştirilmiş, çalışma anında
okunabilen üstveri. TypeScript'teki decorator'ların PHP karşılığı sayılabilir.

---

## 4. `casts()` — veritabanı tipi ile PHP tipi arasındaki köprü

Veritabanı sadece birkaç ilkel tip bilir. PHP'de ise enum, tarih nesnesi, dizi
istiyoruz. `casts()` iki yönlü çeviriyi tanımlar.

```
Okurken:   'published'  →  InvitationStatus::Published
Yazarken:  InvitationStatus::Published  →  'published'
```

### `status` → enum

```php
'status' => InvitationStatus::class,
```

Bu satır sayesinde kodun hiçbir yerinde `'published'` metni geçmez:

```php
if ($invitation->status === InvitationStatus::Published) { ... }   // ✅
if ($invitation->status === 'published') { ... }                   // ❌ HER ZAMAN false
```

İkincisi bir nesneyi metinle karşılaştırdığı için sessizce `false` döner —
3.1'in sık hatalar tablosundaki 3. madde.

### Tarihler → `immutable_*` (K23)

```php
'event_at' => 'immutable_datetime',
'rsvp_deadline' => 'immutable_date',
```

Sıradan `'datetime'` cast'i **değiştirilebilir** (mutable) `Carbon` üretir ve
Carbon'un metotları nesnenin kendisini değiştirir:

```php
$son = $invitation->rsvp_deadline;
$uyari = $son->subDays(3);      // ⚠️ $son DA degisti — ikisi ayni nesne

$son->isPast();                 // artik 3 gun onceki tarihi soruyor
```

Bunun somut sonucu: LCV son tarihi hesabında **yanlış kişiye "süre doldu"**
demek. `immutable_*` cast'i `CarbonImmutable` üretir; her metot yeni bir kopya
döndürür ve orijinal bozulmaz.

`immutable_date` ile `immutable_datetime` farkı: ilki saat kısmını atar
(`rsvp_deadline` bir gündür, an değil).

### `gift_options` → `array`

```php
'gift_options' => 'array',
```

Kolon `jsonb`. Bu cast okurken `json_decode`, yazarken `json_encode` yapar:

```php
$invitation->gift_options = [500, 1000, 2500];   // veritabanina JSON gider
$invitation->gift_options[0];                     // => 500 (PHP dizisi olarak gelir)
```

⚠️ Bir tuzak: dizinin **içindeki** bir elemanı doğrudan değiştiremezsin.

```php
$invitation->gift_options[] = 5000;   // ❌ ise yaramaz — sessizce kaybolur
```

Sebep: `gift_options` bir özellik değil, cast'in ürettiği geçici bir dizidir.
Doğrusu diziyi bütün olarak yeniden atamaktır.

### `show_*` → `boolean`

PostgreSQL sürücüsü boolean kolonları her zaman PHP `bool` olarak döndürmez;
bazı yapılandırmalarda `'t'`/`'f'` ya da `1`/`0` gelir. Cast bunu kesinleştirir.

Neden önemli: JSON yanıtına `"showGift": 1` yerine `"showGift": true` çıkmalı.
Frontend `boolean` bekliyor; `1` truthy olduğu için çoğu yerde çalışır ama
`=== true` karşılaştırması yapan bir satırda sessizce yanılır.

> Genel ilke: **tip belirsizliğini sınırda çöz.** Belirsiz tip sistemin içine
> girerse, nerede patlayacağını kestiremezsin.

---

## 5. İlişkiler

### `user()` — `BelongsTo`

```php
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

"Bu davetiye bir kullanıcıya **aittir**." Yabancı anahtarı **bu** tablo taşıyor
(`invitations.user_id`), o yüzden `belongsTo`.

Eloquent anahtar adını metot adından türetir: `user()` → `user_id`.

```php
$invitation->user;        // ⚡ User nesnesi (sorgu calisir)
$invitation->user();      // ilişki nesnesi (sorgu kurulur, calismaz)
```

Parantezsiz **özellik** erişimi sorguyu çalıştırır ve sonucu saklar; parantezli
**metot** erişimi sorguyu daha da daraltmanı sağlar
(`$invitation->user()->where(...)`).

### `timelineEvents()` — `HasMany`

```php
public function timelineEvents(): HasMany
{
    return $this->hasMany(TimelineEvent::class)->orderBy('sort_order');
}
```

"Bir davetiyenin **birden çok** program adımı vardır." Yabancı anahtar **karşı**
tabloda (`timeline_events.invitation_id`), o yüzden `hasMany`.

#### Sıralama neden ilişkinin içinde?

Alternatif, her çağrı yerinde yazmaktı:

```php
$invitation->timelineEvents()->orderBy('sort_order')->get();
```

Sorun: **bir yerde unutulursa sıra rastgele olur.** SQL'de `ORDER BY` yoksa
satır sırası garanti değildir — geliştirmede doğru görünüp üretimde bozulan
klasik hatalardan biridir.

Sıra bu veri için isteğe bağlı bir tercih değil, **anlamın parçası** (§3.3 §4).
Anlamın parçası olan bir kural, veriye en yakın yerde durmalı.

Bedeli: "sırasız istiyorum" demek zorlaşır. Bu durumda `reorder()` kullanılır —
ama böyle bir ihtiyacımız yok.

#### 🔴 Sahiplik kontrolü bu ilişkiden geçer

3.10'un senkronizasyonunda program adımı ararken **her zaman** ilişki üzerinden
gidilir:

```php
$invitation->timelineEvents()->find($id);   // ✅ WHERE invitation_id = ? AND id = ?
TimelineEvent::find($id);                   // ❌ baskasinin satirini bulur
```

İlişki, sorguya sahiplik koşulunu **otomatik** ekler. Bu yüzden ilişkiyi kullanmak
sadece kısa yazım değil, aynı zamanda bir güvenlik alışkanlığıdır.

---

## 6. `HasMany<TimelineEvent, $this>` — bu docblock ne?

```php
/** @return HasMany<TimelineEvent, $this> */
```

PHP'nin kendisi **generic** (tip parametresi) desteklemez. PHPStan destekler ve
seviye 6'dan itibaren (K22) ilişkilerin neyi döndürdüğünü bilmek ister.

- Birinci parametre: ilişkinin döndürdüğü model
- İkinci parametre: ilişkinin sahibi (`$this`)

Bu bildirim olmadan PHPStan `$invitation->timelineEvents` ifadesinin tipini
bilemez ve sonraki her satırda kör kalır. Yazınca `->first()->title` gibi
zincirlerde yazım hatalarını yakalar.

> Faz 2'nin **19. dersi** burada geçerli: *docblock, üst sınıftakinden daha iyi
> bilgi taşımıyorsa yazılmamalıdır.* Burada taşıyor — `HasMany` tek başına hangi
> modeli döndürdüğünü söylemiyor.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `$guarded = []` | Her kolon toplu atamaya açılır | `#[Fillable([...])]` |
| 2 | `user_id`'yi fillable'a koymak | İstemci sahipliği değiştirir | İlişkiden oluştur |
| 3 | `status`'ü fillable'a koymak | Ödemesiz yayına geçilir | `PublishInvitationAction` |
| 4 | `HasUlids` unutmak | `id` boş gider, NOT NULL ihlali | Trait'i ekle |
| 5 | `'datetime'` cast'i (K23 ihlali) | `subDays()` orijinali bozar → yanlış son tarih | `immutable_datetime` |
| 6 | `$inv->gift_options[] = 5000` | Sessizce kaybolur | Diziyi bütün olarak ata |
| 7 | `$inv->status === 'published'` | Her zaman `false` | `=== InvitationStatus::Published` |
| 8 | `TimelineEvent::find($id)` | Başkasının satırını bulur (IDOR) | `$invitation->timelineEvents()->find($id)` |
| 9 | `@return HasMany` (generic'siz) | PHPStan level 6 kırılır | `HasMany<TimelineEvent, $this>` |

---

## 8. Kendin dene

Bu dosya tek başına test edilemez — `TimelineEvent` modeli (3.5) henüz yok, o
yüzden `timelineEvents()` çağrısı hata verir. Şimdilik diğerlerini sına:

```php
php artisan tinker
```

```php
use App\Models\User;
use App\Models\Invitation;
use App\Enums\InvitationStatus;

$user = User::first();

$inv = $user->invitations()->create([        // ← User'a bu iliski 3.5'te eklenecek
    'category_id' => 'dugun',
    'preset_id' => 'moda-gece',
    'palette' => 'midnight',
    'title' => 'Dugunumuz',
    'gift_options' => [500, 1000, 2500],
    'event_at' => '2026-09-12 19:00',
]);

$inv->id;                    // => "01K3QX8FVBN3K7YHTM5RWDPC4E"  (26 karakter)
$inv->status;                // => InvitationStatus::Saved        (enum, metin degil)
$inv->gift_options;          // => [500, 1000, 2500]              (PHP dizisi)
get_class($inv->event_at);   // => "Carbon\CarbonImmutable"

// K23 kaniti: orijinal bozulmuyor
$a = $inv->event_at;
$b = $a->subDays(3);
$a->equalTo($b);             // => false   ✅ iki AYRI nesne

// Toplu atama savunmasi
$inv->fill(['status' => 'published', 'user_id' => 999]);
$inv->status;                // => InvitationStatus::Saved   ✅ yok sayildi
$inv->user_id;               // => degismedi                 ✅
```

Son iki satır bu dosyanın en önemli kanıtı: **listede olmayan alan sessizce
düşer.**

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Model** | Bir veritabanı tablosuyla konuşan PHP sınıfı |
| **Active Record** | Satırın kendi kaydetme/silme yeteneğine sahip olduğu desen |
| **Trait** | Sınıfa kopyalanan, yeniden kullanılabilir kod bloğu |
| **Öznitelik** (*attribute*) | `#[...]` sözdizimiyle sınıfa iliştirilen üstveri |
| **Mass assignment** | Bir diziyle birden çok kolonu tek seferde doldurmak |
| **Beyaz liste / kara liste** | İzin verilenleri / yasaklananları saymak |
| **Cast** | Veritabanı değeri ile PHP tipi arasındaki iki yönlü çeviri |
| **Global scope** | Her sorguya otomatik eklenen koşul (`deleted_at IS NULL`) |
| **`BelongsTo`** | Yabancı anahtarı **bu** tablonun taşıdığı ilişki |
| **`HasMany`** | Yabancı anahtarı **karşı** tablonun taşıdığı ilişki |
| **Generic** | Tip parametresi; PHP'de yalnızca docblock ile ifade edilir |

---

## 10. Sırada ne var?

**3.5 — `app/Models/TimelineEvent.php`**

Karşı taraf. Orada:

- `belongsTo(Invitation::class)` — ilişkinin diğer ucu
- `#[Fillable]` — `invitation_id` **listede olmayacak** (aynı gerekçe: sahiplik
  ilişkiden gelir)
- `User` modeline `invitations()` ilişkisinin eklenmesi
