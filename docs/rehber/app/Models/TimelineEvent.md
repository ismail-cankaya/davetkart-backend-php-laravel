# `app/Models/TimelineEvent.php`

> **Kod dosyası:** `app/Models/TimelineEvent.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.5
> **Aynı adımda:** `app/Models/User.php`'e `invitations()` ilişkisi eklendi
> **Önceki:** [`Invitation.md`](Invitation.md)

---

## 1. Bu dosya ilişkinin diğer ucu

3.4'te `Invitation` tarafını yazdık:

```php
$this->hasMany(TimelineEvent::class)     // "bir davetiyenin cok adimi var"
```

Şimdi karşı taraf:

```php
$this->belongsTo(Invitation::class)      // "bir adim bir davetiyeye aittir"
```

İkisi **aynı** yabancı anahtarı (`timeline_events.invitation_id`) iki yönden
tarif ediyor. Hangi tarafın hangisi olduğunu ayırt etmenin kuralı basit:

> **Yabancı anahtar kolonu hangi tablodaysa, o taraf `belongsTo`'dur.**

| Model | Metot | Neden |
|---|---|---|
| `Invitation` | `hasMany(TimelineEvent)` | `invitation_id` **karşı** tabloda |
| `TimelineEvent` | `belongsTo(Invitation)` | `invitation_id` **bu** tabloda |

### Her iki ucu da yazmak zorunlu mu?

Hayır. İlişki tek yönden de çalışır. Ama karşı ucu yazmak iki şey kazandırır:

```php
$event->invitation->user_id;                    // sahibine ulasmak
$event->invitation()->update([...]);            // ust kaydi guncellemek
```

3.7'de `InvitationPolicy` yazarken bu yol işimize yarayacak: elimizde bir program
adımı varsa, sahibinin kim olduğunu tek adımda sorabileceğiz.

---

## 2. `#[Fillable]` — burada da bir alan bilerek yok

```php
#[Fillable(['time', 'title', 'description', 'sort_order'])]
```

Listede **`invitation_id` yok.** 3.4'te `user_id`'yi dışarıda bırakmamızla aynı
gerekçe: **aidiyet istemci kararı değildir.**

Doğru oluşturma yolu ilişki üzerinden geçer:

```php
$invitation->timelineEvents()->create([          // ✅
    'time' => '19:00', 'title' => 'Nikah', 'sort_order' => 0,
]);
```

Bu çağrı `invitation_id`'yi Eloquent'in kendisi doldurur — istemciden gelen
veriyle hiç temas etmez.

Yanlış yol:

```php
TimelineEvent::create([                          // ❌
    'invitation_id' => $request->input('invitationId'), ...
]);
```

Bu satır `invitation_id`'yi fillable listesine koymanı gerektirirdi ve o an
istemci **hangi davetiyeye adım ekleyeceğini kendisi seçer** hâle gelirdi.
Başkasının davetiyesine program adımı yazmak tek istek uzağa düşerdi.

> **Kalıp:** Alt kaydı her zaman üst kaydın ilişkisinden oluştur. Böylece
> aidiyet, doğrulanması gereken bir **girdi** olmaktan çıkıp yapısal bir
> **garanti**ye dönüşür.

---

## 3. `'sort_order' => 'integer'` — neden açıkça yazıyoruz?

Kolon zaten `smallint`. Cast gereksiz görünüyor.

Sebep, veritabanı sürücülerinin sayıları her zaman PHP sayısı olarak
döndürmemesidir; yapılandırmaya ve sürücü sürümüne göre `"3"` (metin) da
gelebilir. Cast bunu kesinleştirir.

Somut etkisi JSON yanıtında görünür:

```json
"sortOrder": 3      ✅  cast var
"sortOrder": "3"    ❌  cast yok, tip surucuye kalmis
```

3.4'te `show_*` alanlarına `boolean` cast'i eklerken de aynı gerekçeyi
kullanmıştık. Genel ilke:

> **Tip belirsizliğini sınırda çöz.** Belirsiz tip sistemin içine sızarsa,
> nerede patlayacağını kestiremezsin.

---

## 4. `HasFactory` neden yok?

`Invitation` modelinde var, burada yok. Unutulmadı — **bilerek** ertelendi.

`HasFactory` kullanmak için docblock'ta fabrikayı bildirmek gerekiyor:

```php
/** @use HasFactory<TimelineEventFactory> */
```

`TimelineEventFactory` sınıfı henüz yok (3.6'da yazılacak). Şimdi yazsaydık
PHPStan bu adımda kırılırdı:

```
Class Database\Factories\TimelineEventFactory not found.
```

Ve **her adım `composer check` yeşil bitmek zorunda.** Bir dosyayı "sonra
düzelirim" diyerek kırık bırakmak, Faz 2'nin kapanışında yaşadığımız şeydir:
iki test aylarca kırmızı kaldı, kimse fark etmedi.

> **Kural:** Henüz var olmayan bir sınıfa referans verme. Bağımlılık sırası,
> dosya yazma sırasını belirler.

3.6'da fabrika yazıldığında trait ve docblock birlikte eklenecek.

---

## 5. `User` modeline eklenen ilişki

Aynı adımda `app/Models/User.php`'e şu eklendi:

```php
/** @return HasMany<Invitation, $this> */
public function invitations(): HasMany
{
    return $this->hasMany(Invitation::class);
}
```

Bu ilişki 3.4'ün gerektirdiği şeydi: `Invitation`'ın `#[Fillable]` listesinde
`user_id` olmadığı için, davetiye **ancak** buradan oluşturulabiliyor:

```php
$user->invitations()->create([...]);     // user_id'yi Eloquent doldurur
```

Ayrıca dashboard sorgusunun ve `InvitationPolicy`'nin (3.7) dayanağı bu ilişki
olacak.

### 🔴 Neden burada sıralama yok, `timelineEvents()`'te vardı?

3.4'te program adımlarının sıralamasını ilişkinin içine gömmüştük:

```php
$this->hasMany(TimelineEvent::class)->orderBy('sort_order');   // ✅ gomulu
$this->hasMany(Invitation::class);                             // ✅ gomulu DEGIL
```

Tutarsızlık gibi görünüyor ama ölçüt net:

| | Program adımları | Davetiyeler |
|---|---|---|
| Sıra ne ifade ediyor? | **Anlamın parçası** — program bir akıştır | **Sunum tercihi** — hangi ekranda nasıl listeleneceği |
| Sırasız göstermek anlamlı mı? | Hayır, program bozulur | Evet — ada göre, tarihe göre, duruma göre |
| Unutulursa ne olur? | Yanlış program gösterilir | Hiçbir şey; çağıran zaten sıralamayı seçiyor |

Anlamın parçası olan kural veriye en yakın yerde durur. Sunum tercihi olan kural
ise **çağıranın** işidir — ilişkiye gömersek dashboard'un sıralamayı değiştirmesi
zorlaşır (`reorder()` gerekir).

> Genel soru: *"bu kural olmadan veri yanlış mı olur, yoksa sadece farklı mı
> görünür?"* Yanlış oluyorsa modele, farklı görünüyorsa çağırana ait.

---

## 6. `belongsTo` ilişkisinde N+1 tuzağı

```php
foreach ($events as $event) {
    echo $event->invitation->title;      // ⚠️ her döngüde BİR sorgu
}
```

100 adım = 1 + 100 sorgu. Buna **N+1 problemi** denir ve web uygulamalarındaki
en yaygın performans hatasıdır.

Çözümü **eager loading**:

```php
$events = TimelineEvent::with('invitation')->get();   // 2 sorgu, 101 degil
```

İyi haber: bu projede fark etmeden yapman imkânsız. `AppServiceProvider`'da
Faz 0'da açtığımız `Model::shouldBeStrict()` **tembel yüklemeyi exception'a
çevirir** — yerelde N+1 yazdığın an patlar:

```
LazyLoadingViolationException: Attempted to lazy load [invitation] on model [TimelineEvent]
```

> Hatayı üretimde değil laptop'ta yakalamak, Faz 0'ın tüm gerekçesiydi.

Bizim asıl yönümüz ters olacak: davetiyeyi okurken adımlarını da isteyeceğiz.
3.9'da `whenLoaded('timelineEvents')` ile bunu yöneteceğiz.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `invitation_id`'yi fillable'a koymak | İstemci hangi davetiyeye yazacağını seçer | İlişkiden oluştur |
| 2 | `TimelineEvent::create([...])` | Aidiyet doğrulanmamış girdi olur | `$invitation->timelineEvents()->create()` |
| 3 | `TimelineEvent::find($id)` | Başkasının satırını bulur (IDOR) | `$invitation->timelineEvents()->find($id)` |
| 4 | Var olmayan fabrikaya docblock referansı | PHPStan kırılır, adım yeşil bitmez | Bağımlılık sırasına uy |
| 5 | `hasMany` ile `belongsTo`'yu karıştırmak | "Column not found: invitation_id" | FK kolonu hangi tablodaysa orası `belongsTo` |
| 6 | Döngü içinde `$event->invitation` | N+1 (bizde exception) | `with('invitation')` |
| 7 | `@return BelongsTo` (generic'siz) | PHPStan level 6 kırılır | `BelongsTo<Invitation, $this>` |

---

## 8. Kendin dene

Artık iki model de var; 3.4'ün kılavuzundaki denemeler de çalışır.

```powershell
php artisan tinker
```

```php
use App\Models\User;
use App\Enums\InvitationStatus;

$user = User::first();

$inv = $user->invitations()->create([
    'category_id' => 'dugun',
    'preset_id'   => 'moda-gece',
    'palette'     => 'midnight',
    'title'       => 'Dugunumuz',
    'gift_options'=> [500, 1000, 2500],
    'event_at'    => '2026-09-12 19:00',
]);

$inv->id;          // => "01K3..."  (26 karakter, ULID)
$inv->user_id;     // => 1          ✅ iliski doldurdu, biz yazmadik
$inv->status;      // => InvitationStatus::Saved

// Program adimlarini SIRASIZ ekle — iliski siralamayi kendisi yapmali
$inv->timelineEvents()->create(['time' => '22:00', 'title' => 'Eglence', 'sort_order' => 2]);
$inv->timelineEvents()->create(['time' => '17:00', 'title' => 'Karsilama', 'sort_order' => 0]);
$inv->timelineEvents()->create(['time' => '19:00', 'title' => 'Nikah', 'sort_order' => 1]);

$inv->timelineEvents()->pluck('title');
// => ["Karsilama", "Nikah", "Eglence"]      ✅ sort_order'a gore, ekleme sirasina degil

// Aidiyet garantisi
$inv->timelineEvents()->first()->invitation_id === $inv->id;   // => true

// 🔴 Toplu atama savunmasi
$e = $inv->timelineEvents()->first();
$e->fill(['invitation_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'title' => 'Degisti']);
$e->invitation_id === $inv->id;    // => true    ✅ yok sayildi
$e->title;                          // => "Degisti"  ✅ izinli alan gecti

// 🔴 K23 kaniti (3.4)
$a = $inv->event_at;
$a->subDays(3)->equalTo($a);        // => false   ✅ orijinal bozulmadi

// Temizlik
$inv->forceDelete();                // CASCADE: adimlar da gider
```

```powershell
composer check
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **`belongsTo`** | Yabancı anahtarı **bu** tablonun taşıdığı ilişki ucu |
| **`hasMany`** | Yabancı anahtarı **karşı** tablonun taşıdığı ilişki ucu |
| **İlişkinin iki ucu** | Aynı yabancı anahtarın iki modelden tarifi |
| **N+1 problemi** | Döngü içinde ilişki okuyarak satır sayısı kadar sorgu üretmek |
| **Eager loading** | İlişkiyi önceden, tek sorguda yüklemek (`with()`) |
| **Lazy loading** | İlişkiyi ilk erişimde yüklemek — bizde exception |
| **Bağımlılık sırası** | Bir dosyanın, referans verdiği sınıflardan sonra yazılması gerekliliği |

---

## 10. Sırada ne var?

**3.6 — `InvitationFactory` + `TimelineEventFactory` + `DatabaseSeeder`**

Test verisi üretimi. Orada:

- `HasFactory` trait'i her iki modele de eklenir (§4'te ertelenen iş)
- Fabrikalar **deterministik** olur — rastgele veri, kararsız test demektir
- `DatabaseSeeder` ile `php artisan migrate:fresh --seed` çalışır hâle gelir
- İlişkili fabrika: davetiye üretilince program adımları da üretilsin mi?
