# `database/migrations/0001_01_01_000000_create_users_table.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `database/migrations/0001_01_01_000000_create_users_table.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.0 (K35 kararıyla plana sonradan eklendi)
> **Bağlantılı:** [`docs/03-MIMARI-PLAN.md`](../../../03-MIMARI-PLAN.md) §3.2-3.3 ·
> [`app/Models/User.md`](../../app/Models/User.md) ·
> [`fazlar/FAZ-0.md`](../../fazlar/FAZ-0.md) §4.2 (V1-V4 kuralları)

---

## 0. Bir dakikalık özet

Bu dosya `users` tablosunun **yapısını** tanımlar. Projenin ilk migration'ıdır ve
Laravel iskeletiyle hazır geldi; biz tek bir satırını değiştirdik:

```diff
- $table->string('name');
+ $table->string('first_name', 60);
+ $table->string('last_name', 60);
```

Değişikliğin gerekçesi K35'tir: ad ve soyad ayrı tutulur, çünkü tek kolondan
ayrıştırmak **geri dönülemez biçimde** kayıplıdır.

---

## 1. Migration nedir, neden düz SQL yazmıyoruz?

Bir veritabanı tablosunu üç yolla oluşturabilirsin:

| Yol | Sorunu |
|---|---|
| pgAdmin'de elle tıklamak | Yaptığın hiçbir yerde **kayıtlı değil**. Sunucuda tekrarlayamazsın |
| `.sql` dosyası yazmak | Sırayı, hangisinin koştuğunu, geri almayı sen takip edersin |
| **Migration** | Sıra, geçmiş ve geri alma **framework'ün sorumluluğu** |

Migration, şema değişikliğinin **versiyon kontrolüne giren kod hâlidir**. Ekibe
yeni katılan biri `php artisan migrate` yazar ve veritabanı tam olarak senin
makinendeki hâle gelir.

Laravel koşan migration'ları `migrations` adlı bir tabloda tutar. `migrate`
komutu her çalıştığında "bu dosya daha önce koştu mu?" diye bakar — aynı dosya
iki kez uygulanmaz.

### Dosya adındaki tarih neden var?

```
0001_01_01_000000_create_users_table.php
2026_07_28_210412_create_personal_access_tokens_table.php
```

Migration'lar **dosya adına göre alfabetik** sırayla koşar. Tarih öneki bu yüzden
var: `invitations` tablosu `users`'a foreign key verecek, dolayısıyla `users`
önce oluşmalı.

`0001_01_01` gerçek bir tarih değil — Laravel'in çekirdek tablolarını **her
zaman en başa** koymak için seçtiği yapay bir değerdir.

---

## 2. PHP temelleri

### 2.1 İsimsiz sınıf (anonymous class)

```php
return new class extends Migration
{
    // ...
};
```

Bu dosya bir **sınıf tanımlamaz**, bir sınıf **örneği döndürür**. `new class
extends X { ... }` PHP 7'den beri var: adı olmayan, tek seferlik bir sınıf.
JavaScript'teki `new (class extends X {})` ile aynı fikir.

Neden isimsiz? Çünkü aynı adı iki migration'da kullanma riski ortadan kalkar.
Eskiden `class CreateUsersTable extends Migration` yazılırdı; iki geliştirici
aynı adla migration üretince PHP "Cannot declare class ... already in use"
diye patlıyordu.

Sondaki noktalı virgülü (`};`) unutma — bu bir ifadedir, sınıf bildirimi değil.

### 2.2 `Schema::create` ve closure

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    // ...
});
```

`Schema` bir **facade**'dır: konteynerdeki şema oluşturucuya statik görünümlü
kısayol (Faz 1 terim sözlüğü).

İkinci parametre bir **closure**'dır — adı olmayan, değişken gibi taşınabilen
fonksiyon. Laravel boş bir `Blueprint` nesnesi üretip bu fonksiyona verir; sen
üzerine kolonları eklersin; fonksiyon bittiğinde Laravel `Blueprint`'i okuyup
`CREATE TABLE ...` SQL'ini üretir.

> Bu bir **Builder (İnşacı) deseni**dir: karmaşık bir nesne adım adım
> kurulur, sonuç ürünü inşacı üretir. Sen SQL yazmazsın — ne istediğini
> tarif edersin, SQL'i sürücü üretir. `Blueprint` PostgreSQL için `VARCHAR(60)`,
> MySQL için de `VARCHAR(60)` üretir; farklılıklar sürücünün derdidir (V4).

### 2.3 Zincirleme (method chaining)

```php
$table->string('email')->unique();
```

`string()` `ColumnDefinition` döndürür, `unique()` de aynı nesneyi döndürür —
bu yüzden yan yana dizilebilirler. `->nullable()`, `->default(...)`,
`->index()` de aynı şekilde eklenir.

Okuma yönü soldan sağa bir cümledir: *"email adında bir string kolon, ve bu
kolon benzersiz olsun."*

---

## 3. Alınan kararlar

### 3.1 Yeni migration değil, mevcut dosya düzenlendi

Alışılmış kural şudur: **koşmuş bir migration değiştirilmez**, üstüne yeni bir
migration yazılır (`rename_name_column_on_users_table`). Sebep: senin
veritabanın zaten değişti, ama takım arkadaşının/sunucunun veritabanı değişmedi;
dosyayı düzenlersen onların şeması hiçbir zaman güncellenmez.

Burada bu kuralı **bilerek** uygulamadık. Koşulları kontrol et:

| Koşul | Durum |
|---|---|
| Üretim ortamı var mı? | ❌ Yok |
| Başka geliştirici var mı? | ❌ Yok (tek geliştirici — K2) |
| Tabloda korunması gereken veri var mı? | ❌ Yok (Faz 2 henüz kullanıcı üretmedi) |

Üçü de "hayır" olduğu için düzenleme güvenlidir ve **daha temizdir**: hiç
yayınlanmamış bir tablo için "önce `name` yaptım, sonra iki kolona böldüm"
diyen bir geçmiş, okuyanı yanıltan gürültüden ibarettir.

> 🔴 **Bu izin Faz 3'ten sonra biter.** `invitations` tablosu oluştuğu ve içine
> gerçek veri girdiği andan itibaren, şema değişikliği **yeni migration** ile
> yapılır. Faz 6'daki `add_media_foreign_keys_to_rsvps_table` bunun planlı bir
> örneğidir: Faz 5'in eksik FK'si silinerek değil, **eklenerek** tamamlanır.

### 3.2 `VARCHAR(60)` — sınır neden var?

`$table->string('first_name')` yazsaydık `VARCHAR(255)` üretilirdi (Laravel
varsayılanı). Biz 60 dedik.

PostgreSQL'de `VARCHAR(60)` ile `VARCHAR(255)` arasında **disk farkı yoktur** —
ikisi de değişken uzunlukta saklanır. Kazanç depolama değil, **kısıt**.

```
Katman 1:  RegisterRequest  →  'firstName' => ['required', 'string', 'max:60']
Katman 2:  Veritabanı       →  VARCHAR(60)
```

Doğrulama unutulur, atlanır, seeder'dan geçilmez, tinker'dan girilir — ama
veritabanı kısıtı **atlanamaz**. Bu, `$fillable` beyaz listesi ve
`ErrorCode::filterParams()` ile aynı desenin üçüncü örneğidir: kuralı hatırlamaya
değil, **yolun üzerinde durmasına** bağlamak (*defense in depth*).

Neden 60? İnsan ismi için fazlasıyla yeterli, saldırganın 200 KB'lık bir isim
göndermesi için ise yetersiz.

### 3.3 İkisi de `NOT NULL`

`nullable()` yazmadık, yani iki alan da zorunlu.

Bunun bir bedeli var: tek isimli kültürler (bazı Endonezya, İzlanda kullanımları)
soyadı alanını dolduramaz. Kabul ediyoruz, çünkü:

- Hedef pazar Türkiye; soyadı yasal olarak zorunlu.
- Faz 7'de fatura kesilecek ve fatura soyadı **ister**.

> Bu bir "doğru/yanlış" değil, bir **kapsam kararıdır**. Uluslararası kullanıcı
> hedeflenirse `last_name`'i `nullable()` yapmak ve `UserResource`'ta
> birleştirmeyi `trim()` ile korumak yeterli olur — geriye dönük uyumlu bir
> değişikliktir. Şimdi katı olmak, sonra gevşetmek kolaydır; tersi zordur.

### 3.4 İsim kolonlarına indeks konmadı

`INDEX(last_name)` cazip görünür ama **hiçbir sorgu şu an isme göre filtrelemiyor**.

İndeksin bedeli bedava değildir: her `INSERT` ve `UPDATE`'te indeks ağacı da
güncellenir, disk yeri tutar. Kullanılmayan indeks saf maliyettir.

`03-MIMARI-PLAN.md`'de planlanan indekslerin hepsinin somut bir sorgusu var —
`INDEX(user_id, status)` dashboard listesi için, `UNIQUE(public_slug)` public
erişim için. İndeks **ölçülen bir ihtiyaca** cevap olarak eklenir, ihtimale karşı
değil (YAGNI).

### 3.5 `down()` metoduna dokunulmadı

```php
public function down(): void
{
    Schema::dropIfExists('users');
    // ...
}
```

`up()` ileri, `down()` geri alır. `php artisan migrate:rollback` `down()`'u
çalıştırır.

Tabloyu komple düşürdüğü için kolon değişikliğimiz `down()`'u etkilemedi.

---

## 4. Şimdi ne çalıştırılacak?

```powershell
php artisan migrate:fresh
```

`migrate:fresh` **tüm tabloları düşürür** ve migration'ları baştan koşar.
`migrate` tek başına yetmez — Laravel bu dosyanın zaten koştuğunu düşünür ve
değişikliği fark etmez.

| Komut | Ne yapar |
|---|---|
| `migrate` | Yalnızca **koşmamış** migration'ları uygular |
| `migrate:rollback` | Son grubu geri alır (`down()`) |
| `migrate:fresh` | 🔴 Her tabloyu düşürür, hepsini baştan koşar — **veri gider** |

> Şu an `davetkart` veritabanında yalnızca boş iskelet tablolar var, kayıp yok.
> Faz 3'ten sonra bu komut serbestçe kullanılamaz.
>
> Üretimde ise **çalıştırılamaz**: Faz 0'da `AppServiceProvider` içine konan
> `DB::prohibitDestructiveCommands()` komutu yapısal olarak engeller (V3). Kural
> bir belgeye değil, koda yazılmıştır.

Test veritabanı (`davetkart_test`) için ayrıca bir şey yapmana gerek yok —
`RefreshDatabase` trait'i her test koşusunda şemayı kendisi kurar (T1).

---

## 5. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| Düzenleme sonrası `migrate` çalıştırmak | Hiçbir şey olmaz, "Nothing to migrate" | `migrate:fresh` |
| İsimsiz sınıfın sonundaki `;` unutmak | Parse error | `};` |
| Koşmuş migration'ı üretimde düzenlemek | Sunucu şeması hiç güncellenmez | Yeni migration yaz |
| `Blueprint` yerine `Schema` üzerinde kolon aramak | "Call to undefined method" | Kolonlar closure'daki `$table`'a eklenir |
| `string('x', 60)` yerine `string('x')` bırakmak | Sessizce `VARCHAR(255)` | Sınırı açıkça yaz |
| Sürüme özgü SQL (`DB::statement`) kullanmak | Barındırıcının PostgreSQL sürümünde patlar | Blueprint API'si (V4) |
| Migration'a `User::create(...)` yazmak | Model ileride değişir, eski migration kırılır | Veri üretimi seeder'ın işi |

---

## 6. Kendin dene

**1. Şema gerçekten değişti mi?**

```powershell
php artisan migrate:fresh
php artisan db:table users
```

Beklenen çıktı `first_name` ve `last_name` satırlarını içerir, `name` içermez.

**2. Uzunluk kısıtı gerçekten var mı?** `tinker` içinde:

```php
DB::table('users')->insert([
    'first_name' => str_repeat('a', 61),
    'last_name'  => 'Test',
    'email'      => 'x@y.com',
    'password'   => 'x',
]);
```

PostgreSQL `value too long for type character varying(60)` hatası vermeli.
Doğrulama katmanını hiç kullanmadan, veritabanının kendi başına savunma
yaptığını görüyorsun — §3.2'nin somut kanıtı.

**3. Geri alma çalışıyor mu?**

```powershell
php artisan migrate:rollback
php artisan migrate
```

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Migration** | Şema değişikliğinin versiyon kontrolüne giren kod hâli |
| **Schema** | Veritabanının yapısı (tablolar, kolonlar, kısıtlar) |
| **Blueprint** | Laravel'in tablo tarifini tuttuğu nesne |
| **Builder deseni** | Karmaşık nesnenin adım adım kurulması |
| **Anonymous class** | Adı olmayan, tek seferlik sınıf |
| **Facade** | Konteynerdeki nesneye statik görünümlü kısayol |
| **Rollback** | Migration'ı geri alma (`down()`) |
| **`NOT NULL`** | Kolonun boş bırakılamayacağını söyleyen kısıt |
| **Defense in depth** | Aynı kuralı birden çok katmanda zorlamak |
| **YAGNI** | *You Aren't Gonna Need It* — ihtimale karşı kod yazmama ilkesi |

---

## 8. Bağlantılar

| İlgili | Nerede |
|---|---|
| Veri modeli ve K35 gerekçesi | [`docs/03-MIMARI-PLAN.md`](../../../03-MIMARI-PLAN.md) §3.2-3.3 |
| Veritabanı kuralları (V1-V4) | [`fazlar/FAZ-0.md`](../../fazlar/FAZ-0.md) §4.2 |
| Bu tablonun modeli | [`app/Models/User.md`](../../app/Models/User.md) |
| Sıradaki dosya | `app/Models/User.php` (2.1 — revizyon) |
