# `database/migrations/..._create_invitations_table.php`

> **Kod dosyası:** `database/migrations/2026_08_19_120000_create_invitations_table.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.2
> **Ön okuma:** `docs/rehber/kavramlar/veritabani-ve-migration.md` (migration nedir)

---

## 1. Bu dosya ne yapıyor?

Projenin **en önemli tablosunu** kuruyor. Bir kullanıcının oluşturduğu her davetiye
buraya bir satır olarak düşecek: tasarımı, metinleri, hangi modüllerin açık olduğu,
yayında olup olmadığı.

Bir migration iki metottan oluşur:

| Metot | Ne zaman çalışır | Ne yapar |
|---|---|---|
| `up()` | `php artisan migrate` | Değişikliği **uygular** |
| `down()` | `php artisan migrate:rollback` | Değişikliği **geri alır** |

`down()` yazmak isteğe bağlı değildir: yazmazsan geri dönemezsin ve yanlış bir
migration'ı düzeltmenin tek yolu veritabanını elle kurcalamak olur.

### Anonim sınıf sözdizimi

```php
return new class extends Migration
{
    // ...
};
```

Bu, PHP'nin **anonim sınıf** yazımıdır — adı olmayan, tek kullanımlık bir sınıf.
JavaScript'te `export default class extends Migration {...}` demeye benzer.

Neden adsız? Çünkü migration dosya adları zaman damgası taşır
(`2026_08_19_120000_...`) ve iki migration aynı sınıf adını kullanırsa PHP
"cannot redeclare class" der. Laravel 9'dan beri varsayılan yazım budur.

Sondaki **noktalı virgül** (`};`) zorunludur — bu bir sınıf tanımı değil, bir
`return` ifadesidir.

---

## 2. ULID birincil anahtar (K40)

```php
$table->ulid('id')->primary();
```

Normalde Laravel `$table->id()` yazar ve `1, 2, 3...` diye artan bir tam sayı
üretir. Burada bilerek yapmıyoruz.

### Neden ardışık sayı olmaz?

Davetiye linki misafirlerle paylaşılacak: `/invite/{id}`. Eğer id `101` olsaydı,
linki alan biri adres çubuğunda `102`, `103` yazarak **başkalarının davetiyelerini
gezerdi.** Buna *enumeration attack* (numaralandırma saldırısı) denir. Kimse
sistemi "hacklemiş" olmaz — sadece sayar.

Ardışık id ayrıca **iş bilgisi sızdırır**: bir rakip 1 Ocak'ta bir davetiye
oluşturup id'sine, 1 Şubat'ta bir tane daha oluşturup id'sine bakarak sizin ayda
kaç davetiye sattığınızı öğrenir.

### Neden UUID değil de ULID?

İkisi de tahmin edilemez. Fark, veritabanı indeksinde ortaya çıkar.

Veritabanı indeksi sıralı bir ağaçtır (B-tree). Yeni kayıtların anahtarı **artan**
sırada gelirse, her yeni satır ağacın sağ ucuna eklenir — ucuz bir işlem.
Anahtarlar rastgele gelirse, her ekleme ağacın ortasında bir sayfayı bölmek zorunda
kalır. Buna **sayfa parçalanması** (page split) denir; tablo büyüdükçe yazma
yavaşlar ve indeks şişer.

| | Tahmin edilemez | Zaman sıralı | Uzunluk |
|---|:---:|:---:|---|
| `bigint` (ardışık) | ❌ | ✅ | 8 bayt |
| UUID v4 | ✅ | ❌ | 36 karakter |
| **ULID** | ✅ | ✅ | **26 karakter** |

ULID'in ilk 48 biti milisaniye cinsinden zaman damgasıdır, kalan 80 bit rastgeledir.
Yani sıralanabilir **ve** tahmin edilemez. Örnek:

```
01K3QX8FVBN3K7YHTM5RWDPC4E
└────────┘└───────────────┘
  zaman        rastgele
```

Laravel `$table->ulid()` ile bunu `char(26)` kolon olarak açar; değeri model
tarafında `HasUlids` trait'i üretecek (3.4).

### 🔴 ULID bir yetkilendirme değildir

Sık yapılan kavram hatası: "id tahmin edilemezse güvenlidir." Hayır.

ULID sadece **rastgele gezmeyi** engeller. Ama linki bir kez öğrenen (WhatsApp
grubuna düşen) herkes o davetiyeyi görebilir — zaten amaç bu. Asıl korumayı iki
ayrı mekanizma yapacak:

- `InvitationPolicy` (3.7): başka birinin **taslağını** okumaya çalışan token 404 alır
- `status = 'published'` kontrolü (Faz 4): yayınlanmamış davetiye linkle bile açılmaz

Buna **derinlemesine savunma** (defense in depth) denir: tek bir mekanizmaya
yaslanmak, o mekanizma yanıldığında sistemi çıplak bırakır.

---

## 3. Yabancı anahtar ve silme davranışı

```php
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
```

Üç parça:

| Parça | Ne yapar |
|---|---|
| `foreignId('user_id')` | `bigint unsigned` kolon açar (`users.id` ile aynı tip) |
| `->constrained()` | Adından `users` tablosunu **tahmin eder** ve FK kısıtı kurar |
| `->cascadeOnDelete()` | Kullanıcı silinirse davetiyeleri de silinir |

**Yabancı anahtar kısıtı ne kazandırır?** Var olmayan bir `user_id` ile satır
eklemeyi veritabanı seviyesinde imkânsız kılar. Uygulama kodunda bir hata olsa
bile *öksüz* (orphan) satır oluşamaz. Bu, Faz 2'de kurduğumuz **E2** kuralının
aynı ailesinden: bütünlük kuralı `if` ile değil kısıtla korunur.

**Neden `cascadeOnDelete`?** Alternatifi `restrictOnDelete` olurdu — davetiyesi
olan kullanıcı silinemez. KVKK'nın "unutulma hakkı" maddesi karşısında bu ters
teper: kullanıcı hesabını silmek istediğinde sistem "olmaz" derse veriyi
silememiş olursunuz. Cascade, silme talebini tek işlemde tamamlar.

---

## 4. 🔴 Neden sadece `status` kısıtlanıyor?

Tabloda dört tane "kapalı küme" görünen kolon var, ama yalnızca birine CHECK
kısıtı koyduk:

| Kolon | Değerler | CHECK var mı? |
|---|---|:---:|
| `status` | saved, published | ✅ |
| `palette` | midnight, stone | ❌ |
| `category_id` | dugun, kina, nisan… | ❌ |
| `preset_id` | moda-gece, dugun-1… | ❌ |

Bu bir dikkatsizlik değil, bilinçli bir **sahiplik** kararı.

> **Veritabanı kısıtı, backend'in sahibi olduğu kurallar için konur.**

`status` backend'in malıdır. Onu değiştiren tek şey backend'in kendi iş
kurallarıdır (`PublishInvitationAction`), ve **güvenlik sınırıdır**: Faz 4'te
misafir sayfası `WHERE status = 'published'` diyecek. Buraya beklenmedik bir
değer girerse taslak davetiye sızabilir. Kısıt, bu ihtimali veritabanı
seviyesinde kapatır.

`palette`, `category_id` ve `preset_id` ise **frontend kataloğunun anahtarlarıdır.**
`data.ts` içindeki `TEMPLATE_PRESETS` ve `EVENT_CATEGORIES` dizileri onların
doğruluk kaynağıdır. Bunları CHECK ile kısıtlarsak şu olur:

```
Tasarımcı yeni bir tema ekler        →  frontend'de tek satır
                                     →  backend'de MIGRATION + DEPLOY
```

Yani sunum katmanındaki her değişiklik backend'i kilitler. Bu, iki tarafı
gereksiz yere birbirine bağlar (*coupling*). Bu kolonlarda tek koruma **uzunluk
sınırıdır**; içerik doğrulaması FormRequest'te (3.8) yapılacak ve orada bile
listeyi frontend'den değil `config/davetkart.php`'den okuyacağız — böylece yeni
kategori eklemek deploy değil, config değişikliği olur.

Genel ilke: **kısıt, değişme hızı yavaş ve sahipliği net olan veriye konur.**

---

## 5. CHECK kısıtını enum'dan beslemek

```php
$allowed = "'".implode("', '", InvitationStatus::values())."'";

DB::statement(
    "ALTER TABLE invitations
     ADD CONSTRAINT invitations_status_check CHECK (status IN ({$allowed}))"
);
```

### `implode()` ne yapıyor?

PHP'nin dizi → metin birleştiricisi. JavaScript'teki `Array.join()`.

```php
InvitationStatus::values()                    // ['saved', 'published']
implode("', '", ...)                          // saved', 'published
"'" . ... . "'"                               // 'saved', 'published'
```

Sonuç SQL'e `CHECK (status IN ('saved', 'published'))` olarak giriyor.

### Neden değerleri elle yazmıyoruz?

3.1'de `values()` metodunu tam bunun için yazmıştık. Elle yazsaydık:

```php
CHECK (status IN ('saved', 'published'))   // ❌ enum değişince burası eskir
```

Enum'a yarın bir `case` eklendiğinde kimse bu migration'ı hatırlamaz. Uygulama
yeni durumu üretmeye çalışır, veritabanı reddeder, ve hata mesajı
("violates check constraint") sebebi hiç anlatmaz. **Tek doğruluk kaynağı**
ilkesi tam olarak bu senaryoyu önlemek içindir.

### SQL'e metin birleştirmek tehlikeli değil mi?

Genelde **evet, çok tehlikelidir** — SQL enjeksiyonunun tanımı budur. Kural:

> Kullanıcıdan gelen hiçbir veri SQL metnine birleştirilmez; parametre olarak
> bağlanır (`?` veya `:isim`).

Burada güvenli olmasının sebebi, verinin kullanıcıdan **gelmemesidir**:
`InvitationStatus::values()` PHP kaynak kodunda yazılı sabitleri döndürür.
Kullanıcının bu değerlere dokunma yolu yoktur.

Yine de `DB::statement` gördüğün her yerde bu soruyu sormayı alışkanlık hâline
getir: *"bu metnin içine dışarıdan bir şey girebilir mi?"* Cevap "evet" veya
"emin değilim" ise, birleştirme yerine parametre kullan.

### Neden Laravel'in kendi metodu yok?

Laravel'in şema oluşturucusunda (schema builder) CHECK kısıtı için bir metot
bulunmuyor — çünkü CHECK ifadesi veritabanı motoruna göre değişen serbest SQL'dir.
Bu yüzden `DB::statement()` ile ham SQL yazıyoruz. `Schema::create()` bloğunun
**dışında**, tablo oluştuktan sonra çalışması gerekir.

### `down()` neden kısıtı silmiyor?

Kısıt tabloya aittir. `DROP TABLE` çalıştığında kısıt da onunla birlikte yok
olur. Ayrıca silmeye çalışmak gereksiz ve hataya açıktır.

---

## 6. Neden native `ENUM` değil, `VARCHAR + CHECK`? (K39)

PostgreSQL'in gerçek bir `ENUM` tipi var. Kullanmadık:

| | Native `ENUM` | `VARCHAR + CHECK` |
|---|---|---|
| Değer eklemek | `ALTER TYPE ... ADD VALUE` — Laravel şema oluşturucusu desteklemez, ham SQL şart | Kısıtı düşür, yenisini ekle — sıradan migration |
| Değer **silmek** | Pratikte imkânsız (tipi yeniden yaratmak gerekir) | Kısıtı değiştir, bitti |
| Transaction içinde | `ADD VALUE` bazı sürümlerde kısıtlı | Sorunsuz |
| Taşınabilirlik | PostgreSQL'e özgü | Her motorda çalışır |

Kazandığımız kesinlik ise aynı: geçersiz bir değer her iki durumda da reddedilir.
Asıl koruma zaten katmanlı:

```
FormRequest  →  Rule::enum()       (kullanıcı girdisini kapıda durdurur)
Model cast   →  InvitationStatus   (kod içinde metin hiç elimize geçmez)
CHECK kısıtı →  son savunma hattı  (kod hata yaparsa DB reddeder)
```

---

## 7. 🔴 İçerik alanları neden hepsi `nullable`?

```php
$table->string('title', 120)->nullable();
$table->string('venue', 180)->nullable();
```

İlk bakışta yanlış görünür: davetiyenin başlığı olmalı, değil mi?

Cevap **autosave'de** saklı. `hooks/useInvitationAutoSave.ts` her düzenlemeden
1,5 saniye sonra kaydediyor. Kullanıcı başlığı silip yenisini yazmak için
duraklarsa, o boş hâl sunucuya gider. `title` NOT NULL olsaydı:

```
Kullanıcı başlığı siler  →  1,5 sn geçer  →  POST gider  →  422 doğrulama hatası
                                                        →  editörde "kaydedilemedi"
```

Kullanıcı hiçbir şey yanlış yapmadı; şema fazla katı olduğu için hata gördü.

Doğru okuma şudur: **kaydetmek ile yayınlamak farklı olgunluk seviyeleridir.**

| An | Ne beklenir |
|---|---|
| Kaydetme (autosave) | Hiçbir şey — yarım veri normaldir |
| Yayınlama (Faz 7) | Eksiksizlik: başlık, tarih, mekân dolu olmalı |

Bu, Faz 2'de kurulan **D3** kuralının aynı biçimidir: *kalite kuralı yalnızca
üretim anında uygulanır, okuma anında değil.* Burada da: **eksiksizlik kuralı
yayın anında uygulanır, kayıt anında değil.**

`PublishInvitationAction` (Faz 7) bu kontrolü yapacak ve eksikse
`INVITATION_INCOMPLETE` hata kodu dönecek.

NOT NULL kalanlar: `user_id` (sahipsiz davetiye anlamsızdır), `status`
(varsayılanı var), ve katalog anahtarları (sihirbaz onları her zaman doldurur).

---

## 8. Kolon seçimlerinin gerekçeleri

### `show_*` — neden altı ayrı boolean? (K6)

Altısını tek bir JSON kolonunda tutmak cazip görünür. Ama paywall doğrulaması
sunucuda **SQL ile** yapılacak:

```sql
-- Elit gerektiren modülü kullanan davetiyeler
SELECT * FROM invitations WHERE show_gallery = true OR show_gift = true;
```

JSON içindeyse bu sorgu ya çok yavaş olur ya da indekslenemez. Sorgulanacak veri
kolon olur; sorgulanmayacak veri JSON olabilir. Bizim hibrit stratejimiz budur.

### `gift_options` — neden `jsonb`?

Hediye tutarları (`[500, 1000, 2500]`) sorgulanmayacak küçük bir dizidir.
Ayrı bir tablo açmak, her okumada bir `JOIN` maliyeti demek olurdu.

PostgreSQL'de `json` ile `jsonb` farkı: `json` metni **olduğu gibi** saklar,
`jsonb` **ayrıştırılmış ikili** biçimde saklar. `jsonb` biraz daha yavaş yazar,
belirgin biçimde hızlı okur ve indekslenebilir. Varsayılan tercih `jsonb`'dir.

### `event_at` — `timestamp`, `date` değil

Frontend `'2026-09-12T19:00'` gönderiyor: tarih **ve** saat. `rsvp_deadline`
ise yalnızca gün bilgisi taşıyor (`'yyyy-MM-dd'`), o yüzden `date`.

⚠️ **Açık soru (Faz 4/9):** Bu saat bir *duvar saati*dir — "mekânda saat 19:00".
Kullanıcı başka bir saat diliminden davetiyeye bakarsa geri sayım sayacı ne
göstermeli? Şimdilik olduğu gibi saklıyoruz; sayaç Faz 4'te ele alınacak.

### `iban` — neden 34 karakter?

ISO 13616 standardı IBAN'ın en fazla 34 karakter olabileceğini söyler (Türkiye'de
26). Standardın üst sınırını yazmak, yurt dışı hesapları da kabul eder.

⚠️ **Faz 4 için not:** `iban`, `bank_name` ve `account_holder` alanları misafire
açık yanıta **yalnızca `show_gift = true` ise** girmeli. Aksi hâlde kullanıcının
kapattığı bir modülün verisi sızar. Bu, 3.9'daki `InvitationResource`'un ve Faz
4'teki public resource'un sorumluluğu.

### `softDeletes()` — silinen satır gerçekten silinmez

`deleted_at` adında bir zaman damgası kolonu ekler. Eloquent bir kaydı
"sildiğinde" satırı yok etmez, bu kolona zamanı yazar; sonraki tüm sorgular
`WHERE deleted_at IS NULL` filtresini otomatik ekler.

Neden: kullanıcı yanlışlıkla sildiğinde geri alınabilir; ödemeye bağlı bir
davetiye silinirse muhasebe kaydı öksüz kalmaz.

---

## 9. İndeks stratejisi

```php
$table->index(['user_id', 'status']);
```

**İndeks nedir?** Kitabın sonundaki dizin gibi. İndeks yoksa veritabanı
"tüm tabloyu tara" (full table scan) yapar — 100 satırda fark edilmez, 100.000
satırda sayfa donar.

**Neden bileşik (iki kolonlu)?** Dashboard sorgusu şuna benzeyecek:

```sql
SELECT * FROM invitations WHERE user_id = 42 AND status = 'published';
```

Bileşik indeksin **soldan başlama kuralı** (leftmost prefix) vardır: `(user_id,
status)` indeksi şu sorgulara hizmet eder:

| Sorgu | İndeks kullanılır mı? |
|---|:---:|
| `WHERE user_id = 42` | ✅ (sol önek) |
| `WHERE user_id = 42 AND status = 'saved'` | ✅ (tam eşleşme) |
| `WHERE status = 'saved'` | ❌ (soldaki kolon yok) |

Yani tek indeksle iki sorgu deseni karşılanıyor. Sıralama önemlidir: `(status,
user_id)` yazsaydık "bu kullanıcının davetiyeleri" sorgusu indekssiz kalırdı.

`id` için ayrıca indeks yazmadık — birincil anahtarlar zaten otomatik olarak
indekslidir.

---

## 10. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `$table->id()` bırakmak | Ardışık id → enumeration attack | `$table->ulid('id')->primary()` |
| 2 | `DB::statement`'ı `Schema::create` bloğunun **içine** yazmak | Tablo henüz yok, `ALTER TABLE` patlar | Bloktan sonra |
| 3 | `down()` yazmamak | `migrate:rollback` çalışmaz | `Schema::dropIfExists()` |
| 4 | İçerik alanlarını NOT NULL yapmak | Autosave 422 döndürür, editör "hata" gösterir | Hepsi `nullable()` |
| 5 | CHECK değerlerini elle yazmak | Enum değişince kısıt sessizce eskir | `InvitationStatus::values()` |
| 6 | `json` kullanmak | Okuma yavaş, indekslenemez | `jsonb` |
| 7 | Migration'ı çalıştırdıktan sonra **düzenlemek** | Veritabanı ile dosya ayrışır | Yeni migration yaz veya `migrate:fresh` |
| 8 | `foreignId()` yerine `unsignedBigInteger()` + FK unutmak | Öksüz satırlar oluşur | `->constrained()` |
| 9 | İndeksi `(status, user_id)` sırasıyla yazmak | Dashboard sorgusu indekssiz kalır | Seçici kolon solda |

### 7. maddenin ayrıntısı

Migration'lar **çalıştırıldıktan sonra değiştirilmez.** Laravel `migrations`
tablosunda hangi dosyaların koştuğunu tutar; dosyayı değiştirsen bile tekrar
çalıştırmaz. Geliştirme aşamasında doğru hamle:

```powershell
php artisan migrate:fresh      # tüm tabloları düşür, baştan kur (VERİ GİDER)
```

Üretimde bu asla yapılmaz; orada yeni bir migration yazılır. `AppServiceProvider`
zaten üretimde yıkıcı komutları engelliyor (Faz 0).

---

## 11. Kendin dene

```powershell
php artisan migrate
```

Beklenen çıktı:

```
INFO  Running migrations.
2026_08_19_120000_create_invitations_table ..................... DONE
```

pgAdmin 4'te `davetkart` → Schemas → public → Tables → `invitations` altında
kolonları görmelisin. Kısıtı doğrulamak için `tinker`:

```php
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Gecerli durum
DB::table('invitations')->insert([
    'id' => '01K3QX8FVBN3K7YHTM5RWDPC4E',
    'user_id' => User::first()->id,
    'status' => 'saved',
    'category_id' => 'dugun',
    'preset_id' => 'moda-gece',
    'palette' => 'midnight',
    'created_at' => now(),
    'updated_at' => now(),
]);
// => true

// Gecersiz durum — CHECK devreye girmeli
DB::table('invitations')->where('status', 'saved')->update(['status' => 'draft']);
// => QueryException: new row for relation "invitations" violates
//    check constraint "invitations_status_check"   ✅ kisit calisiyor

// Temizlik
DB::table('invitations')->delete();
```

Geri alma denemesi:

```powershell
php artisan migrate:rollback     # tablo düşer
php artisan migrate              # yeniden kurulur
```

Ardından kalite kapısı:

```powershell
composer lint
composer check
```

---

## 12. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Migration** | Veritabanı şemasındaki bir değişikliği kodla ifade eden, sürüm kontrolüne giren dosya |
| **Anonim sınıf** | Adı olmayan, tanımlandığı yerde örneklenen sınıf |
| **ULID** | *Universally Unique Lexicographically Sortable Identifier* — 26 karakter, zaman sıralı, tahmin edilemez |
| **Enumeration attack** | Ardışık kimlikleri sırayla deneyerek başkalarının kaynaklarına ulaşma |
| **Sayfa parçalanması** (*page split*) | Rastgele anahtar eklemenin B-tree indeksinde yarattığı bölünme maliyeti |
| **Yabancı anahtar** (*foreign key*) | Bir kolonun başka tablodaki satıra işaret ettiğini veritabanına bildiren kısıt |
| **Cascade delete** | Ana kayıt silinince ona bağlı kayıtların da silinmesi |
| **CHECK kısıtı** | Bir kolonun alabileceği değerleri sınırlayan veritabanı kuralı |
| **`jsonb`** | PostgreSQL'in ayrıştırılmış, indekslenebilir JSON tipi |
| **Soft delete** | Satırı yok etmek yerine "silindi" olarak işaretlemek |
| **Bileşik indeks** | Birden çok kolonu birlikte indeksleyen yapı |
| **Leftmost prefix** | Bileşik indeksin yalnızca soldan başlayan kolon kümeleri için kullanılabilmesi |
| **Derinlemesine savunma** | Tek mekanizmaya güvenmeyip birbirini yedekleyen katmanlar kurmak |
| **SQL enjeksiyonu** | Kullanıcı girdisinin SQL metnine karışarak sorgunun anlamını değiştirmesi |

---

## 13. Sırada ne var?

**3.3 — `..._create_timeline_events_table.php`**

Davetiyenin program akışı (nikâh 16:30, yemek 19:00…). Orada üç yeni konu var:

- `invitation_id` ULID yabancı anahtarı — `foreignId` değil `foreignUlid`
- `sort_order` — kullanıcının sürükleyerek değiştirdiği sıra
- `CASCADE` — davetiye silinince olayları da düşmeli
