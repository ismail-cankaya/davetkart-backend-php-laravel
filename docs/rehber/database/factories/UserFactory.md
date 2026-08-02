# `database/factories/UserFactory.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `database/factories/UserFactory.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.2
> **Bağlantılı:** [`app/Models/User.md`](../../app/Models/User.md) ·
> [`create_users_table.md`](../migrations/0001_01_01_000000_create_users_table.md) ·
> [`phpunit.md`](../../phpunit.md) · [`php-dili.md`](../../kavramlar/php-dili.md)

---

## 0. Bir dakikalık özet

Bu dosya **sahte kullanıcı üretir**. Testlerde ve seeder'larda kullanılır.

```php
User::factory()->create();              // 1 kullanıcı, veritabanına yazılır
User::factory()->count(50)->create();   // 50 kullanıcı
User::factory()->unverified()->create(); // e-postası doğrulanmamış
```

Tek işi budur ve **üretim kodunda asla kullanılmaz** — `app/` altındaki hiçbir
dosya factory çağırmaz.

---

## 1. Neden factory? Elle yazsak olmaz mı?

Olur, ama üç sorun doğar. `AuthTest` içinde şöyle yazdığımızı düşün:

```php
$user = User::create([
    'first_name' => 'Ali',
    'last_name'  => 'Veli',
    'email'      => 'ali@test.com',
    'password'   => 'sifre123',
]);
```

| Sorun | Nasıl ortaya çıkar |
|---|---|
| **Tekrar** | 30 testte bu 6 satır 30 kez yazılır |
| **Kırılganlık** | Yeni bir `NOT NULL` kolon eklendiğinde **30 test birden** kırılır |
| **Yanlış geçen test** | `ali@test.com` sabittir; ikinci kullanıcı üreten test `UNIQUE` ihlali verir |

Factory üçünü birden çözer: tanım **tek yerdedir**, yeni kolon **bir kez**
eklenir, e-posta her seferinde **farklı** üretilir.

> **Tasarım deseni:** Bu bir **Factory (Fabrika)** desenidir — nesne üretme
> bilgisini kullanan yerden ayırıp tek bir sınıfa toplar. Testler "bana bir
> kullanıcı ver" der; *nasıl* üretildiğini bilmez.

### 1.1 🔴 Factory ile Seeder karıştırılmamalı

| | **Factory** | **Seeder** |
|---|---|---|
| Ne yapar | Bir nesnenin **nasıl** üretileceğini tarif eder | Veritabanını **doldurur** |
| Kim çağırır | Testler, seeder'lar | `php artisan db:seed` |
| Kaç kayıt | Sen söylersin | Senaryoyu kendi kurar |
| Nerede | `database/factories/` | `database/seeders/` |

Factory bir **tarif**, seeder bir **yemektir**. Seeder genellikle factory'yi
çağırır (Faz 3'te `DatabaseSeeder` bunu yapacak).

Ve ikisi de **migration değildir**: migration yapıyı, bunlar veriyi üretir.

---

## 2. PHP ve Laravel temelleri

### 2.1 `@extends Factory<User>` — generic docblock

```php
/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
```

PHP'nin generic'i yoktur; bu satır **PHPStan içindir** (bkz.
[`php-dili.md`](../../kavramlar/php-dili.md) §13). "Bu fabrika `User` üretir"
demektir. Sayesinde PHPStan `User::factory()->create()` ifadesinin `User`
döndürdüğünü bilir ve `$user->first_name` erişimini doğrulayabilir.

Laravel'in modeli bulma yolu ise ayrıdır ve **isim kuralına** dayanır:

```
Database\Factories\UserFactory   →   App\Models\User
                    ^^^^                        ^^^^
```

Bu yüzden `User.php` içinde `/** @use HasFactory<UserFactory> */` satırı vardı —
iki taraf birbirini işaret eder.

### 2.2 `static` property ve `??=`

```php
protected static ?string $passwordHash = null;
// ...
'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
```

`static` property **sınıfa aittir, örneğe değil**: 50 kullanıcı üretsen de
bellekte tek bir `$passwordHash` vardır.

`??=` (null coalescing assignment) şunun kısasıdır:

```php
if (static::$passwordHash === null) {
    static::$passwordHash = Hash::make(self::PASSWORD);
}
return static::$passwordHash;
```

Yani hash **yalnızca ilk çağrıda** üretilir, sonrakiler hazır değeri alır.

> Buna **memoization** (hatırlama) denir: pahalı bir hesabın sonucunu saklayıp
> tekrar kullanmak. React'teki `useMemo` ile aynı fikirdir.

**Neden bu kadar önemli?** Parola hash'lemesi **kasıtlı olarak yavaştır** —
saldırganı yavaşlatmak için (bkz. [`User.md`](../../app/Models/User.md) §3.4).
Bir hash ~100 ms sürerse, 50 kullanıcı üreten bir test 5 saniye boyunca yalnızca
hash hesaplar. Memoization bunu 100 ms'e indirir.

> ⚠️ Laravel'in ürettiği iskelet `protected static ?string $password;` yazar —
> **başlangıç değeri olmadan**. Bu tipli property'lerde "uninitialized" durumu
> yaratır; `??=` bu durumda çalışır ama okuması kafa karıştırıcıdır ve statik
> analiz araçlarını zorlar. `= null` yazmak aynı işi yapar, niyeti açık eder.

### 2.3 `public const PASSWORD`

```php
public const PASSWORD = 'password';
```

`CLAUDE.md` §1: *"sihirli string kullanılmamalıdır."*

Bu sabit olmasaydı `AuthTest` şöyle olurdu:

```php
$this->postJson('/api/auth/login', [
    'email' => $user->email,
    'password' => 'password',      // ❌ bu string nereden geliyor?
]);
```

Okuyan kişi `'password'` değerinin factory'de gömülü olduğunu bilmek zorunda
kalır. Sabitle bağ **açık** olur:

```php
'password' => UserFactory::PASSWORD,   // ✅ kaynağı belli
```

Yarın parolayı değiştirirsen testler kendiliğinden uyar.

### 2.4 `fake()` ve Faker

```php
fake()->firstName()
fake()->lastName()
fake()->unique()->safeEmail()
```

`fake()` bir **Faker** üreticisi döndürür — sahte ama gerçekçi veri üreten
kütüphane (`fakerphp/faker`, `require-dev`'de).

| Çağrı | Örnek çıktı |
|---|---|
| `firstName()` | `"Ayşe"` |
| `lastName()` | `"Yıldırım"` |
| `safeEmail()` | `"ayse.yildirim@example.org"` |
| `unique()->safeEmail()` | Aynısı ama **aynı koşuda tekrar etmez** |

🔴 **`safeEmail()` neden `email()` değil?** `safeEmail()` yalnızca RFC 2606'da
test için ayrılmış alan adlarını kullanır (`example.org`, `example.com`). Gerçek
bir alan adı üretilirse, ileride mail gönderen bir test **gerçek birine** posta
yollayabilir.

**`APP_FAKER_LOCALE=tr_TR`** ayarı sayesinde isimler Türkçedir. Bu bilinçli bir
karardır (`08-HATA-SOZLESMESI.md` §10): Türkçe karakterli veriyle test etmek
karakter kodlaması (UTF-8) ve alan uzunluğu sorunlarını **erken** gösterir.
`VARCHAR(60)` sınırımızın Türkçe isimlerde yeterli olduğunu böyle görüyoruz.

> `unique()` yalnızca **o Faker örneği** içinde tekrarsızlık sağlar. Veritabanı
> düzeyindeki garanti `UNIQUE` kısıtından gelir — ikisi ayrı savunmalardır.

### 2.5 State metodu — `unverified()`

```php
public function unverified(): static
{
    return $this->state(fn (array $attributes): array => [
        'email_verified_at' => null,
    ]);
}
```

`state()` varsayılan tanımın **üstüne yazar** ve `$this` benzeri yeni bir fabrika
döndürür. Bu yüzden zincirlenebilir:

```php
User::factory()->unverified()->count(3)->create();
```

Dönüş tipi `static` — "çağrıldığı sınıfın tipi". Alt sınıf yapılsa da doğru tip
döner (*late static binding*).

Closure `$attributes` alır: mevcut değerlere bakarak karar verebilirsin. Burada
gerekmedi ama Faz 3'te `InvitationFactory` bunu kullanacak (`published` durumu
`published_at`'i de doldurmalı).

---

## 3. Alınan kararlar

### 3.1 Kolonlar `first_name` + `last_name` (K35)

İskelet `'name' => fake()->name()` üretiyordu. Migration 2.0'da bölündüğü için
factory de bölündü.

`fake()->name()` **tek string** üretir ("Dr. Ayşe Yıldırım" gibi — bazen unvan da
ekler). `firstName()` ve `lastName()` ayrı ayrı çağrılınca hem unvan sorunu
kalkar hem veri gerçekten ayrık olur.

### 3.2 Hash'i factory üretiyor — modelin cast'i çifte hash yapmaz mı?

Haklı bir endişe. `User` modelinde şu cast var:

```php
'password' => 'hashed',      // atanan değeri hash'ler
```

Factory ise zaten hash'lenmiş bir değer veriyor. Çifte hash olsaydı hiçbir giriş
çalışmazdı.

**Olmuyor.** Laravel'in `hashed` cast'i önce `Hash::isHashed($value)` kontrolü
yapar; değer zaten bir hash biçimindeyse **olduğu gibi geçirir**.

> Bu, `User.md` §4'teki *"Action içinde `Hash::make()` çağırma"* uyarısıyla
> çelişmez. Orada anlatılan tehlike **niyet** hatasıydı: Action ham parolayı
> alır ve modele ham vermelidir. Factory ise hash'i **bilinçli** olarak
> memoization için üretiyor — cast'in geçirgenliği bu kullanımı mümkün kılıyor.

### 3.3 `email_verified_at` doldurulu geliyor

Varsayılan kullanıcı **doğrulanmış** sayılır. Sebebi pratiktir: Faz 2'de e-posta
doğrulama akışı yok, testlerin çoğu "giriş yapabilen normal kullanıcı" ister.

Doğrulanmamış hâl istisnadır ve `unverified()` ile açıkça istenir.

> **Genel ilke:** Factory'nin varsayılanı **en sık kullanılan hâl** olmalıdır.
> İstisnalar state metoduyla adlandırılır. Aksi hâlde her test bir sürü alanı
> elle ezmek zorunda kalır ve okunurluk kaybolur.

---

## 4. Kullanım — `make()` ile `create()` farkı

| Çağrı | Ne yapar |
|---|---|
| `User::factory()->make()` | Nesne üretir, **veritabanına yazmaz** |
| `User::factory()->create()` | Üretir **ve kaydeder** (`id` dolu döner) |
| `User::factory()->count(3)->create()` | 3 kayıt, `Collection` döner |
| `User::factory()->create(['email' => 'x@y.com'])` | Bir alanı ezerek üret |
| `User::factory()->unverified()->create()` | State ile |
| `User::factory()->raw()` | Nesne değil **dizi** döndürür |

`make()` ne işe yarar? Veritabanına dokunmayan testlerde hız kazandırır —
Faz 1'in **T8** kuralı (`RefreshDatabase` yalnızca gerçekten gerekli yerde) ile
aynı düşünce.

`raw()` ise `postJson()` gövdesi hazırlarken kullanışlıdır:

```php
$payload = User::factory()->raw();     // dizi — istek gövdesi olarak gönderilebilir
```

---

## 5. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| Üretim kodunda `factory()` çağırmak | Sahte veri gerçek veritabanına sızar | Yalnızca test ve seeder |
| Testte sabit e-posta yazmak | İkinci kayıtta `UNIQUE` ihlali | `fake()->unique()->safeEmail()` |
| `create()` yerine `make()` kullanıp sorgulamak | Kayıt veritabanında yok | Veritabanı lazımsa `create()` |
| Testte `'password'` string'ini elle yazmak | Sihirli string | `UserFactory::PASSWORD` |
| `$passwordHash`'i `static` yapmamak | Her kullanıcı için yeniden hash → yavaş test | `static` + `??=` |
| Yeni `NOT NULL` kolonu factory'ye eklememek | Tüm testler `not null violation` | Kolon → aynı commit'te factory |
| `fake()->email()` kullanmak | Gerçek alan adına posta gidebilir | `safeEmail()` |
| `state()` içinde `return` unutmak | Fabrika `null` döner | `fn () => [...]` zaten döndürür |

---

## 6. Kendin dene

```powershell
php artisan tinker
```

**1. Bellekte üret (veritabanına yazmaz):**

```php
$u = App\Models\User::factory()->make();
$u->first_name;      // "Ayşe" gibi — Türkçe (APP_FAKER_LOCALE)
$u->last_name;
$u->id;              // null — henüz kaydedilmedi
```

**2. Veritabanına yaz:**

```php
$u = App\Models\User::factory()->create();
$u->id;              // 1 — artık var
App\Models\User::count();
```

**3. Memoization gerçekten çalışıyor mu?**

```php
$a = App\Models\User::factory()->create();
$b = App\Models\User::factory()->create();
$a->password === $b->password;    // true — aynı hash paylaşıldı
```

İkisinin hash'i aynı çünkü parolaları da aynı (`UserFactory::PASSWORD`) ve hash
bir kez üretildi.

**4. Parola doğrulanabiliyor mu?** — 2.8'de `LoginUserAction` bunu yapacak:

```php
Hash::check(Database\Factories\UserFactory::PASSWORD, $a->password);   // true
```

**5. State çalışıyor mu?**

```php
App\Models\User::factory()->unverified()->create()->email_verified_at;   // null
```

**6. Temizlik:**

```powershell
php artisan migrate:fresh
```

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Factory deseni** | Nesne üretme bilgisini tek sınıfta toplama |
| **Fixture** | Testin ihtiyaç duyduğu hazır veri |
| **Faker** | Sahte ama gerçekçi veri üreten kütüphane |
| **State** | Fabrikanın varsayılanını değiştiren adlandırılmış varyant |
| **Memoization** | Pahalı hesabın sonucunu saklayıp tekrar kullanma |
| **Static property** | Örneğe değil sınıfa ait, tek kopya olan alan |
| **Late static binding** | `static` dönüş tipi — çağrılan sınıfın tipi |
| **Fluent API** | Zincirlenebilir metot çağrıları (`->count()->create()`) |
| **Seeder** | Veritabanını senaryoyla dolduran sınıf |

---

## 8. Bağlantılar

| İlgili | Nerede |
|---|---|
| Bu fabrikanın modeli | [`app/Models/User.md`](../../app/Models/User.md) |
| Kolonların tanımı | [`create_users_table.md`](../migrations/0001_01_01_000000_create_users_table.md) |
| Test ortamı ayarları | [`phpunit.md`](../../phpunit.md) |
| Test kuralları (T1-T9) | [`fazlar/FAZ-0.md`](../../fazlar/FAZ-0.md) §4.4 · [`FAZ-1.md`](../../fazlar/FAZ-1.md) §4.4 |
| Docblock/generic anlatımı | [`kavramlar/php-dili.md`](../../kavramlar/php-dili.md) §13 |
| Sıradaki dosya | `app/Http/Resources/UserResource.php` (2.3) |
