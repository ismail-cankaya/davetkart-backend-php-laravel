# `database/migrations/..._create_timeline_events_table.php`

> **Kod dosyası:** `database/migrations/2026_08_19_120100_create_timeline_events_table.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.3
> **Önceki:** [`create_invitations_table.md`](2026_08_19_120000_create_invitations_table.md)

---

## 1. Bu tablo ne tutuyor?

Davetiyenin **program akışını**. Editördeki "Program Adımı Ekle" bölümü buraya
yazıyor:

```
17:00  Karşılama & Kokteyl    Misafirlerimizi hoş geldin kokteyli ile karşılıyoruz.
19:00  Nikâh Töreni           Evet dediğimiz o büyülü ana tanıklık edin.
20:00  Akşam Yemeği           Özenle hazırlanan menümüz eşliğinde…
```

Bu, projedeki **ilk gerçek ilişki**. Şimdiye kadar tek tabloyla çalışıyorduk;
burada "bir davetiyenin **birden çok** program olayı vardır" bağını kuruyoruz.
Buna **bire-çok** (one-to-many) ilişki denir.

### Neden JSON kolonu değil de ayrı tablo?

`gift_options` dizisini `invitations.gift_options` içinde JSON olarak sakladık.
Program olayları için aynısını yapabilirdik. Yapmadık:

| Ölçüt | `gift_options` | `timeline_events` |
|---|---|---|
| Eleman sayısı | 3-5, sabit | Kullanıcı ekledikçe büyür |
| Eleman yapısı | Tek sayı | 4 alanlı nesne |
| Tek tek erişim gerekir mi? | Hayır | Evet — güncelleme, silme, sıralama |
| İleride ilişki alır mı? | Hayır | Olabilir (adım fotoğrafı vb.) |

Genel ölçüt: **elemanın kendi kimliği ve yaşam döngüsü varsa tablo, yoksa JSON.**

---

## 2. `foreignUlid` — üst tabloya bağlanmak

```php
$table->foreignUlid('invitation_id')->constrained()->cascadeOnDelete();
```

Faz 3.2'de `foreignId('user_id')` yazmıştık. Burada **`foreignUlid`** yazıyoruz.

Sebep basit ama kritik: **yabancı anahtar, işaret ettiği kolonla aynı tipte
olmalıdır.**

| Üst tablo | Birincil anahtarı | Yabancı anahtar metodu |
|---|---|---|
| `users` | `$table->id()` → `bigint` | `foreignId()` |
| `invitations` | `$table->ulid('id')` → `char(26)` | **`foreignUlid()`** |

`foreignId` yazsaydık `bigint` bir kolon açardı ve `char(26)` bir anahtara
bağlanamazdı — PostgreSQL kısıtı reddederdi:

```
foreign key constraint cannot be implemented
Key columns "invitation_id" and "id" are of incompatible types: bigint and character.
```

`->constrained()` tablo adını kolon adından türetir: `invitation_id` →
`invitations`. Ad kuralına uyduğun sürece tabloyu yazmana gerek kalmaz.

---

## 3. 🔴 CASCADE ile soft delete birlikte nasıl davranır?

`cascadeOnDelete()` = üst satır **silinirse** alt satırlar da silinir.

Ama `invitations` tablosunda `softDeletes()` var. İkisi birlikte ince bir davranış
üretiyor ve bunu bilmek gerekiyor:

| İşlem | SQL'de ne olur | `timeline_events`'e etkisi |
|---|---|---|
| `$invitation->delete()` (soft) | `UPDATE ... SET deleted_at = now()` | ❌ **Hiçbir şey** — cascade tetiklenmez |
| `$invitation->forceDelete()` | `DELETE FROM invitations ...` | ✅ Alt satırlar silinir |
| Kullanıcı hesabını siler | `users` → cascade → `invitations` → cascade | ✅ Zincirleme siler |

**Ve bu doğru davranıştır.** Soft delete'in amacı geri alınabilirlik: kullanıcı
davetiyeyi çöp kutusuna attı, yarın geri isteyebilir. Program adımları da
silinseydi, geri gelen davetiye boş bir programla dönerdi.

> **Genel ders:** İki mekanizmayı yan yana koyduğunda "birlikte nasıl
> davranıyorlar?" diye sormak zorundasın. Ayrı ayrı doğru olan iki karar,
> birleşince beklenmedik bir davranış üretebilir.

---

## 4. `sort_order` — neden saate göre sıralamıyoruz?

İlk refleks: "zaten saat var, `ORDER BY time` yeter."

Yetmez, üç sebeple:

**1. Saat boş olabilir.** Kullanıcı adımı ekler, başlığı yazar, saati sonra
girer. Bu arada autosave çalışır ve `time` `null` gider. `ORDER BY` bu satırı
nereye koyacak?

**2. Gece yarısını geçen etkinlikler.** `23:00 Havai fişek` → `01:00 After
party`. Metin olarak `01:00 < 23:00`; sıralama tersine döner.

**3. Sıra bir kullanıcı kararıdır.** Editörde adımlar listede göründüğü sırayla
yazılır. Kullanıcı iki adımın yerini değiştirmek isterse, saatlerini değiştirmek
zorunda kalmamalı.

Bu yüzden sıra **açıkça** saklanıyor. `unsignedSmallInteger` = 0–65.535 arası,
2 bayt. Program adımı için fazlasıyla yeterli.

### Neden `(invitation_id, sort_order)` UNIQUE değil?

Cazip görünüyor: "aynı davetiyede iki adım aynı sırada olmasın."

Ama yeniden sıralama sırasında **geçici çakışma kaçınılmazdır**. 1. ve 2. adımın
yerini değiştirmek istersen:

```
1 → 2   (artık iki tane 2 var)  💥 UNIQUE ihlali
2 → 1
```

Bunu aşmak için iki aşamalı güncelleme (önce geçici değerlere taşı, sonra yerine
koy) gerekirdi — kazandığından fazlasına mal olur. Sıra bir **görüntüleme
tercihidir**, veri bütünlüğü kuralı değil. Kısıt, bütünlük kuralları içindir
(3.2 §4'teki sahiplik ölçütünün aynısı).

---

## 5. `time` neden `VARCHAR(8)`, `TIME` değil?

PostgreSQL'in gerçek bir `time` tipi var. Kullanmadık.

Frontend `<input type="time">` kullanıyor; tarayıcı her zaman `"HH:MM"` biçiminde
bir **metin** gönderiyor (`"19:00"`). Bu değerle yaptığımız tek şey onu ekranda
göstermek — üzerinde hesap yapmıyoruz, sıralamada kullanmıyoruz (§4).

| | `TIME` tipi | `VARCHAR(8)` |
|---|---|---|
| Hesap/karşılaştırma | ✅ Mümkün | ❌ Metin karşılaştırması |
| Boş değer | `null` | `null` |
| Geçersiz girdi | Veritabanı reddeder | FormRequest reddeder (3.8) |
| Tarayıcıdan gelen değeri **birebir** saklama | Dönüşüm var | ✅ Aynen |

İhtiyacımız olmayan bir yetenek için dönüşüm katmanı eklemek gereksiz. Biçim
denetimi 3.8'de `date_format:H:i` kuralıyla yapılacak — **doğrulama uygulamanın
işi, saklama veritabanının.**

> Karar değişirse maliyeti düşük: `VARCHAR` → `TIME` dönüşümü tek migration.
> Ters yön de öyle. Bu, "geri dönülebilir karar" sınıfındandır ve bu tür
> kararlarda uzun uzun düşünmek zaman kaybıdır.

---

## 6. Birincil anahtar neden ULID değil?

3.2'de `invitations.id` için ULID seçmiştik. Burada sıradan `$table->id()`
(artan `bigint`) yazıyoruz. Çelişki değil — ölçüt farklı.

ULID'i **adres çubuğunda görünen** kimlikler için seçtik: ardışık sayı olsaydı
misafir `/invite/102` diye gezerdi. Program olayları **hiçbir zaman URL'de
geçmez**; her zaman davetiyenin içine gömülü gelirler:

```json
{ "data": { "id": "01K3QX...", "invitation": {
    "timelineEvents": [ { "id": "7", "time": "19:00", ... } ] } } }
```

Enumeration riski yok, dolayısıyla ULID'in maliyetini (26 karakter vs 8 bayt)
ödemek için sebep yok.

**Peki sözleşmedeki "id alanları string olmalı" kuralı ne olacak?** O kural
**API sınırında** geçerli, veritabanında değil. `TimelineEventResource` (3.9)
`(string) $this->id` diyerek `7` → `"7"` çevirecek — `UserResource`'un Faz 2'de
yaptığının aynısı.

> Veritabanı tipi bir **depolama** kararı, API tipi bir **sözleşme** kararıdır.
> İkisini birbirine zincirlemek gereksiz kısıt üretir.

---

## 7. 🔴 Kimliği kim üretir? (K44)

Bu, tablonun tasarımını değil ama **senkronizasyon sözleşmesini** belirliyor.

### Devraldığımız durum

`components/create/TimelineEditor.tsx`:

```ts
const addEvent = () =>
  commit([...events, { id: `tl-${Date.now()}`, time: '20:00', title: '', description: '' }]);
```

Ve varsayılan program (`data.ts`): `tl-1`, `tl-2`, `tl-3`, `tl-4`.

Yani tarayıcı kendi id'lerini üretiyor ve sunucuya gönderiyor. İki sorun:

1. Sunucuya, sunucunun hiç üretmediği kimlikler geliyor
2. `tl-1` **her davetiyede** aynı — gelen id evrensel olarak benzersiz değil

### Karar: kimliği **backend üretir**

`timeline_events.id` yalnızca veritabanı tarafından atanır. Tarayıcı bir kimlik
uyduramaz.

Sözleşme açık iki duruma indirgeniyor:

| İstekte gelen | Anlamı | Backend ne yapar |
|---|---|---|
| `id: null` (veya alan hiç yok) | Bu **yeni** bir adım | Satır oluşturur, id'yi kendisi üretir |
| `id: "7"` | Mevcut 7 numaralı adım | Satırı günceller |
| Listede olmayan mevcut satır | Kullanıcı sildi | Satırı siler |

Kazandığı şey **kesinlik**: backend "bu id gerçek mi, uydurma mı?" diye tahmin
yürütmez. Tahmine dayalı mantık, tahminin yanıldığı gün sessizce yanlış davranır.

### Frontend'in yine de bir anahtara ihtiyacı var

Bu kaçınılmaz: React bir listeyi çizerken her elemana `key` ister, ve yeni
eklenen adımın henüz sunucu kimliği yoktur.

Çözüm ikisini **ayırmak**: yerel anahtar tarayıcıda kalır, `id` alanı sunucuya
aittir.

```ts
// ❌ eski: yerel anahtar id gibi davranıyor ve sunucuya gidiyor
{ id: `tl-${Date.now()}`, ... }

// ✅ yeni: sunucuya null gider, React ayrı bir alanla çizer
{ id: null, localKey: `tl-${Date.now()}`, ... }
```

Bu iş frontend'e düşüyor ve `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md`'ye
eklenecek. Backend geçiş döneminde de doğru davranır: tanımadığı bir id gelirse
onu yok sayıp yeni satır açar.

### 🔴 Sözleşme, güvenlik kontrolünün yerine geçmez

Sözleşmenin netleşmesi **istemciye güvenilebileceği anlamına gelmez.** Saldırgan
istediği id'yi gönderebilir — sözleşme onu bağlamaz.

Bu yüzden eşleştirme sorgusu her zaman ilişki üzerinden kurulur:

```php
$invitation->timelineEvents()->find($id);   // ✅ WHERE invitation_id = ? AND id = ?
TimelineEvent::find($id);                   // ❌ baskasinin satirini bulur
```

İkincisiyle, kötü niyetli bir istek başkasının davetiyesindeki bir adımın id'sini
gönderip **o satırı ezebilir**. Sahibi olduğun davetiyeye, başkasının satırını
"güncellettirmiş" olursun.

Bu, IDOR'un daha az fark edilen biçimidir: **üst kaynağın sahipliği doğrulanmış
olsa bile, alt kaynağın üst kaynağa aidiyeti ayrıca doğrulanmalıdır.** 3.8
(doğrulama) ve 3.10 (senkronizasyon) bunu birlikte uygulayacak.

---

## 8. İndeks

```php
$table->index(['invitation_id', 'sort_order']);
```

Tek bir sorgu deseni var:

```sql
SELECT * FROM timeline_events WHERE invitation_id = '01K3QX...' ORDER BY sort_order;
```

Bileşik indeks ikisini birden karşılar: `invitation_id` ile satırları bulur,
`sort_order` zaten indeks içinde sıralı olduğu için veritabanı ayrıca sıralama
adımı çalıştırmaz. Buna **kapsayan sıralama** denir ve `ORDER BY`'ın maliyetini
sıfırlar.

`invitation_id`'nin solda olması şart (leftmost prefix — 3.2 §9).

`foreignUlid(...)->constrained()` zaten yabancı anahtar için ayrı bir indeks
oluşturur; bizimki onun üstüne sıralamayı ekler.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `foreignId('invitation_id')` yazmak | `bigint` ↔ `char(26)` uyumsuzluğu, migration patlar | `foreignUlid()` |
| 2 | Gelen id'yi doğrudan güvenmek | Başkasının satırı ezilir (IDOR) | İlişki üzerinden ara |
| 3 | `ORDER BY time` | Boş saat ve gece yarısı sıralamayı bozar | `ORDER BY sort_order` |
| 4 | `(invitation_id, sort_order)` UNIQUE | Yeniden sıralamada geçici çakışma → hata | Kısıt koyma |
| 5 | `title` NOT NULL | Yeni adım boş başlıkla doğar → autosave 422 | `nullable()` |
| 6 | Soft delete'in cascade tetikleyeceğini sanmak | Alt satırlar beklenenden farklı davranır | `forceDelete()` tetikler |
| 7 | Her kayıtta hepsini silip yeniden eklemek | 1,5 sn'de bir 4 DELETE + 4 INSERT; id'ler değişir | Gerçek senkronizasyon (3.10) |
| 8 | Migration adının sıralamasını gözden kaçırmak | `invitations` yokken FK kurulamaz | Zaman damgası **sonra** gelmeli |

---

## 10. Kendin dene

```powershell
php artisan migrate
```

```
2026_08_19_120100_create_timeline_events_table ................. DONE
```

`tinker` ile ilişkiyi ve cascade'i sına:

```php
use App\Models\User;
use Illuminate\Support\Facades\DB;

$userId = User::first()->id;
$invId  = '01K3QX8FVBN3K7YHTM5RWDPC4E';

DB::table('invitations')->insert([
    'id' => $invId, 'user_id' => $userId, 'status' => 'saved',
    'category_id' => 'dugun', 'preset_id' => 'moda-gece', 'palette' => 'midnight',
    'created_at' => now(), 'updated_at' => now(),
]);

DB::table('timeline_events')->insert([
    ['invitation_id' => $invId, 'time' => '17:00', 'title' => 'Karsilama', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
    ['invitation_id' => $invId, 'time' => '19:00', 'title' => 'Nikah',     'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
]);

DB::table('timeline_events')->count();
// => 2

// Olmayan bir davetiyeye baglamayi dene — FK kisiti reddetmeli
DB::table('timeline_events')->insert([
    'invitation_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
    'title' => 'Hayalet', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
// => QueryException: violates foreign key constraint   ✅

// CASCADE: ust satiri GERCEKTEN sil
DB::table('invitations')->where('id', $invId)->delete();
DB::table('timeline_events')->count();
// => 0   ✅ alt satirlar da gitti
```

```powershell
composer check
```

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Bire-çok ilişki** | Bir üst kayda birden çok alt kaydın bağlanması |
| **Üst / alt tablo** (*parent / child*) | İlişkide işaret edilen ve işaret eden taraf |
| **`foreignUlid`** | ULID tipinde yabancı anahtar kolonu açan Laravel metodu |
| **CASCADE** | Üst satır silinince alt satırların da silinmesi |
| **Soft delete** | Satırı silmek yerine `deleted_at` damgalamak |
| **`sort_order`** | Kullanıcının belirlediği sırayı açıkça saklayan kolon |
| **Kapsayan sıralama** | `ORDER BY`'ın indeks sayesinde ek maliyet üretmemesi |
| **Senkronizasyon** (*sync*) | Gelen listeyi mevcut satırlarla eşleyip ekle/güncelle/sil kararı vermek |
| **IDOR** | Başkasının kaynağına kimliğini tahmin ederek/göndererek erişmek |

---

## 12. Sırada ne var?

**3.4 — `app/Models/Invitation.php`**

Tablolar hazır; şimdi PHP tarafındaki karşılıkları. Modelde şunlar olacak:

- `HasUlids` trait'i — id'yi kim üretiyor?
- `$fillable` beyaz listesi (`$guarded = []` **yasak**)
- `casts()` — `status` → `InvitationStatus`, `gift_options` → dizi,
  `event_at` → `CarbonImmutable` (K23)
- `timelineEvents()` ilişkisi — `sort_order`'a göre sıralı
- `user()` ilişkisi
