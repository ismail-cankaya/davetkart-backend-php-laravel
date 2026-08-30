# `app/Models/Rsvp.php`

> **Kod dosyası:** `app/Models/Rsvp.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.3
> **Birlikte değişen:** `app/Models/Invitation.php` → `rsvps()` ilişkisi
> **Kardeş dosya:** [`TimelineEvent.md`](TimelineEvent.md) — aynı desenin
> Faz 3'teki hâli

---

## 1. Bu dosya ne işe yarar?

`rsvps` tablosunun PHP tarafındaki yüzü. Bir Eloquent modeli üç şey söyler:

1. **Hangi alanlar toplu atamayla doldurulabilir** (`#[Fillable]`)
2. **Alanların PHP'deki tipi ne** (`casts()`)
3. **Başka hangi tablolarla ilişkili** (`invitation()`)

Model **iş kuralı taşımaz**. Kota hesabı, son tarih kontrolü, IP hash'leme —
hepsi Action'ın işi (`CLAUDE.md` §1). Model yalnızca veriyi doğru tipte ve
doğru sınırlarla tutar.

---

## 2. 🔴 `#[Fillable]` — bu projedeki en kritik beyaz liste

```php
#[Fillable(['guest_name', 'guest_count', 'status', 'menu_preference', 'message'])]
```

**Toplu atama (mass assignment)** şu demektir:

```php
$rsvp = Rsvp::create($request->validated());   // dizideki HER anahtar kolona yazılır
```

Bu kolaylık, beyaz liste olmadan bir güvenlik açığıdır. Listede olmayan iki alan
bilinçli olarak dışarıda:

| Alan | Neden listede yok |
|---|---|
| `invitation_id` | Aidiyet **ilişkiden** kurulur: `$invitation->rsvps()->create(...)`. Gövdeden okunsaydı bir misafir, başkasının davetiyesine yanıt yazabilirdi |
| `ip_hash` | Sunucu hesaplar. İstemciden gelen bir "IP" veri değil, **iddiadır** |

🔴 Diğer tablolarda bu koruma bir güvenlik ağıdır; **burada savunmanın
kendisidir.** `rsvps`, sistemdeki tek **auth'suz yazma** yoludur: gövdeyi yazan
kişinin kim olduğunu bilmiyoruz.

`$guarded = []` yazmak **S3** ile yasak — ve sebebi tam olarak budur.

### `status` neden listede? (Invitation'la asimetri)

`Invitation` modelinde `status` **bilerek** `#[Fillable]` dışındaydı; burada
içeride. Çelişki değil, aynı sorunun iki farklı cevabı:

| | `invitations.status` | `rsvps.status` |
|---|---|---|
| Kime ait? | **Sunucuya** — yayın akışının kararı | **Misafire** — verdiği cevabın kendisi |
| İstemci belirleyebilir mi? | ❌ Hayır, paywall'ı atlatırdı | ✅ Evet, zaten sorduğumuz soru bu |

**Ders:** aynı kolon adı, aynı kural anlamına gelmez. Soru "bu alanın **sahibi**
kim?" — ve cevabı tabloya göre değişir.

---

## 3. `casts()` — tip belirsizliğini sınırda çöz

```php
'status' => RsvpStatus::class,
'guest_count' => 'integer',
```

**`status`:** Veritabanında `'attending'` metni durur; PHP'de
`RsvpStatus::Attending` enum örneği olarak okunur. Cast olmasaydı her
karşılaştırmada `$rsvp->status === 'attending'` gibi sihirli string yazmak
gerekirdi.

**`guest_count`:** PostgreSQL sürücüsü `smallint` değerini duruma göre `"3"`
(string) döndürebilir. Bu cast olmadan:

```php
$toplam += $rsvp->guest_count;   // "3" + "4" PHP'de çalışır ama...
$rsvp->guest_count === 3;        // false!  ("3" !== 3)
```

Faz 3'ün **29. dersi** buydu: *tip belirsizliğini sınırda çöz.* Aynı ders
`InvitationPolicy`'de `user_id` cast'i olarak karşımıza çıkmıştı — ve orada
eksik olsaydı **hiç kimse kendi davetiyesine erişemezdi**. Burada eksik olsaydı
kota hesabı sessizce yanlış çalışırdı.

---

## 4. `HasUlids` ne yapıyor?

```php
use HasFactory, HasUlids;
```

`HasUlids` üç şeyi birden ayarlar:

1. Kayıt oluşturulurken `id`'yi otomatik doldurur (`Str::ulid()`).
2. `getIncrementing()` → `false` (anahtar artmıyor).
3. `getKeyType()` → `'string'`.

🔴 İkinci maddenin bir yan etkisi var ve Faz 3'te bir tuzağa dönüşmüştü:
`Model::getCasts()` birincil anahtarı **yalnızca artan anahtarlı modellerde**
otomatik `int`'e çevirir. ULID kullanan modellerde bu otomatik cast gelmez —
`Invitation`'a bu yüzden elle `'user_id' => 'integer'` eklenmişti.

Burada aynı sorun **yok**, çünkü `Rsvp`'nin yabancı anahtarı (`invitation_id`)
zaten ULID, yani string. Ama bilmek gerekiyor: model bir trait kullandığında
onun **ne değiştirdiğini** bilmeden yazılan karşılaştırma sessizce yanlış olur.

Bir ikinci kazanç daha var: `HasUlids::newUniqueId()` gerçek bir ULID üretir ve
bunun için **veritabanına yazmak gerekmez**. 5.7'de honeypot savunması tam
olarak bunu kullanacak.

---

## 5. `Invitation::rsvps()` — sıralama neden yok?

```php
public function rsvps(): HasMany
{
    return $this->hasMany(Rsvp::class);
}
```

`timelineEvents()` ilişkisinde `->orderBy('sort_order')` vardı. Burada yok.
Fark, Faz 3'te ayırt edilen şeyin aynısı:

| İlişki | Sıra ne? | Nerede tanımlı |
|---|---|---|
| `timelineEvents` | **Anlamın parçası** — program 17:00'den önce 19:00'u gösteremez | İlişkinin içinde; çağıran unutamasın |
| `rsvps` | **Sunum tercihi** — panel en yeniyi üstte ister, kota hiç sıralamaz | Çağıranda |

Kota sorgusu `SUM(guest_count)` yapıyor; oraya gereksiz bir `ORDER BY` koymak
her hesapta sıralama maliyeti demek olurdu.

---

## 6. Neden `$dispatchesEvents` yok?

`Invitation` modeli `updated`/`deleted`/`restored` olaylarını
`InvitationChanged`'e bağlıyor, çünkü misafire açık **cache** girdisinin
düşmesi gerekiyor (Faz 4, K48).

`Rsvp` için buna gerek yok:

- Yeni bir LCV yanıtı, misafirin gördüğü davetiye gövdesini **değiştirmez** —
  `PublicInvitationResource` LCV verisi taşımıyor.
- Sahibin listesi cache'lenmiyor; tazeliği **ETag + polling** sağlıyor (K46).

Olay yazsaydık, hiçbir dinleyicisi olmayan bir olayımız olurdu — ders 26.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `invitation_id`'yi `#[Fillable]`'a eklemek | Misafir, başkasının davetiyesine yanıt yazabilir |
| 2 | `ip_hash`'i fillable yapmak | İstemci kendi "IP"sini uydurur; hız sınırı ve tekrar tespiti çöker |
| 3 | `$guarded = []` yazmak | S3 ihlali; tüm kolonlar açılır |
| 4 | `guest_count` cast'ini unutmak | `SUM` ve `===` sessizce yanlış çalışır |
| 5 | Kota hesabını modele koymak | `CLAUDE.md` §1: iş kuralı Action'da. Model "fat model"a dönüşür |
| 6 | `Rsvp::create([...])` ile doğrudan yazmak | Aidiyet garantisi kaybolur; **N1**: alt kayıt her zaman üstün ilişkisinden oluşturulur |
| 7 | İlişkiye `->latest()` gömmek | Kota sorgusu da sıralar; gereksiz maliyet ve gizli davranış |

---

## 8. Kendin dene

```php
// php artisan tinker
$inv = App\Models\Invitation::factory()->create();

// 1) İlişki üzerinden oluşturma — DOĞRU yol (N1)
$rsvp = $inv->rsvps()->create([
    'guest_name' => 'Can Dogan',
    'guest_count' => 2,
    'status' => App\Enums\RsvpStatus::Attending,
    'ip_hash' => str_repeat('a', 64),   // Action bunu kendisi hesaplayacak
]);

$rsvp->invitation_id === $inv->id;   // true — gövdeden değil ilişkiden geldi
$rsvp->status;                       // RsvpStatus enum örneği
$rsvp->guest_count === 2;            // true  (cast çalışıyor)

// 2) Beyaz listeyi delmeye çalış
$rsvp->fill(['invitation_id' => 'baska-bir-ulid']);
$rsvp->invitation_id;                // DEĞİŞMEDİ — sessizce atıldı
```

> ⚠️ Katı kip (`Model::shouldBeStrict()`) geliştirmede açık olduğu için, listede
> olmayan alanı doldurmaya çalışmak sessizce atılmak yerine **exception**
> fırlatabilir. Bu iyi bir şey: hatayı laptopta patlatır, üretimde değil (S1).

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Model** | Bir veritabanı tablosunun nesne karşılığı (Active Record deseni) |
| **Toplu atama** | Diziden birden çok alanı tek çağrıda doldurma |
| **Beyaz liste** | "Yalnızca şunlar serbest" — varsayılan kapalı |
| **Cast** | Veritabanı değerinin PHP tipine çevrilme kuralı |
| **Trait** | Sınıflara metot/özellik ekleyen yeniden kullanılabilir blok |
| **`HasMany` / `BelongsTo`** | Bire-çok ilişkinin iki ucu |
| **Fat model** | İş kurallarının modele yığıldığı, test edilmesi zor tasarım |

---

## 10. Sırada ne var?

**5.4 — `StoreRsvpRequest`.** Misafirin gönderdiği gövdenin doğrulanması ve
honeypot alanı. Orada "doğrulama neyi yakalar, neyi yakalayamaz" ayrımını
göreceğiz: doğrulama biçim denetler, **iş kuralı** denetlemez.

| İlgili | Nerede |
|---|---|
| Tablo | [`../../database/migrations/2026_08_28_120000_create_rsvps_table.md`](../../database/migrations/2026_08_28_120000_create_rsvps_table.md) |
| Durum enum'u | [`../Enums/RsvpStatus.md`](../Enums/RsvpStatus.md) |
| Kardeş model | [`TimelineEvent.md`](TimelineEvent.md) |

---

## 🆕 Faz 6 eklemesi — iki medya ilişkisi

```php
public function photoMedia(): BelongsTo
{
    return $this->belongsTo(Media::class, 'photo_media_id');
}

public function videoMedia(): BelongsTo
{
    return $this->belongsTo(Media::class, 'video_media_id');
}
```

### 🔴 `#[Fillable]` listesi neden genişlemedi?

Liste hâlâ beş alan:

```php
#[Fillable(['guest_name', 'guest_count', 'status', 'menu_preference', 'message'])]
```

`photo_media_id` ve `video_media_id` **bilerek dışarıda**. Sebep, `ip_hash` ve
`invitation_id`'nin dışarıda olma sebebinden farklı ve daha ince:

| Alan | Neden fillable değil |
|---|---|
| `ip_hash` | Değeri **sunucu üretir**; istemciden gelen bir "IP" veri değil yalandır |
| `invitation_id` | Aidiyet **ilişkiden** kurulur (`$invitation->rsvps()->make()`) — **N1** |
| **`photo_media_id`** | 🔴 Değer istemciden **gelir**, ama **doğrulanmadan** atanamaz |

Üçüncüsü yeni bir kategori. Misafir `photoMediaId` gönderecek (6.19) ve o
kimlik gerçek bir medyayı işaret edebilir — **ama başkasının davetiyesindeki**
bir medyayı.

Toplu atama (`$rsvp->fill($validated)`) bu soruyu **atlar**. Kimlik doğrudan
kolona yazılır, veritabanı kısıtı da onu kabul eder (medya gerçekten var).
Sonuç: bir misafir, komşu bir davetiyeye yüklenmiş fotoğrafı kendi yanıtına
iliştirebilir.

Bu yüzden `SubmitRsvpAction` (6.20) önce **sahiplik sorar**, sonra açıkça yazar:

```php
$rsvp->photo_media_id = $this->resolveGuestMedia($invitation, $photoMediaId, MediaKind::RsvpPhoto);
```

**E7**'nin ailesi: *sunucunun sahip olduğu alanın değerini sunucu kodu söyler.*
Burada değer istemciden gelse de **kararı** sunucu veriyor.

> Yabancı anahtar kısıtı (6.17) *"böyle bir medya var mı?"* sorusunu cevaplar.
> *"Bu medya SANA ait mi?"* sorusunu cevaplayamaz — o bir iş kuralıdır ve
> Action'a aittir.

### Kolon adı neden açıkça yazıldı?

```php
$this->belongsTo(Media::class, 'photo_media_id');
```

Laravel `photoMedia` ilişki adından `photo_media_id`'yi zaten **doğru** tahmin
ederdi. Yine de yazıldı: iki ilişki **aynı tabloya** bakıyor ve hangisinin hangi
kolonu kullandığı okunduğunda görülmeli. Konvansiyona güvenmenin maliyeti sıfır
olmadığı tek durum, iki şeyin birbirine karışabildiği durumdur.

### Neden `hasOne` değil `belongsTo`?

Yabancı anahtar **`rsvps` tablosunda** duruyor. `belongsTo`, "kimliği bende olan"
tarafın ilişkisidir. `hasOne` yazsaydık Laravel `media.rsvp_id` diye bir kolon
arardı — yoktu, ve olmamalı: aynı medya teorik olarak birden çok yerden
referans alınabilir.

### Eager loading borcu

`RsvpResource` bu ilişkilere erişecek (6.21). `Model::preventLazyLoading()`
geliştirmede **açık** olduğu için, liste ucu `with()` kullanmak zorunda:

```php
$invitation->rsvps()->with(['photoMedia', 'videoMedia'])->latest()->get();
```

Aksi hâlde 50 LCV = **101 sorgu** (N+1) ve yerelde `LazyLoadingViolationException`.
Faz 3'ün 3.9 kararıyla aynı: `with()` çağrısı **controller'ın** işidir.
