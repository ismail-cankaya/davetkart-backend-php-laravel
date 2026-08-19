# `app/Models/User.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Models/User.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.1 (fazın ilk dosyası)
> **Bağlantılı:** [`CLAUDE.md`](../../../../CLAUDE.md) §1, §3 ·
> [`docs/08-HATA-SOZLESMESI.md`](../../../08-HATA-SOZLESMESI.md) §3.1 ·
> [`SubscriptionTier.md`](../Enums/SubscriptionTier.md) (PHP temelleri orada başladı)

---

## 0. Bir dakikalık özet

Bu dosya `users` tablosunun **PHP'deki karşılığıdır**. Bir satır veri = bir `User`
nesnesi.

Faz 2 için dört şey öğretiyor:

| Ne | Nerede | Ne kazandırıyor |
|---|---|---|
| Hangi alanlar toplu doldurulabilir | `#[Fillable]` | Mass assignment saldırısını kapatır |
| Hangi alanlar JSON'a çıkamaz | `#[Hidden]` | Parola hash'inin kazara sızmasını engeller |
| Parola nasıl saklanır | `casts()` → `'hashed'` | Ham parola veritabanına **hiç** ulaşmaz |
| Token nasıl üretilir | `HasApiTokens` | `$user->createToken(...)` mümkün olur |

Buna kendi eklediğimiz bir madde daha var: **e-posta küçük harfe indirgenir.**

---

## 1. Neden fazın ilk dosyası bu?

Faz 2'nin dokuz dosyasının hepsi bu dosyaya bakar:

```
UserFactory      → hangi kolonlara sahte veri üreteceğim?
UserResource     → hangi alanları dışarı vereceğim?
RegisterRequest  → hangi alanları doğrulayacağım?
RegisterUserAction → User::create([...]) — hangi anahtarlar kabul edilir?
LoginUserAction  → parola nasıl karşılaştırılır?
AuthTest         → ne bekleyeceğim?
```

Bağımlılık oku hep bu dosyaya işaret ediyor. **Bağımlılık yönünde ilerlemek** —
önce kimseye ihtiyaç duymayanı yazmak — inşa sırasının temel kuralıdır.

---

## 2. PHP ve Laravel temelleri

### 2.1 `class User extends Authenticatable`

`extends`, TypeScript'teki `extends` ile aynı: **kalıtım**. `User`, üst sınıfın tüm
yeteneklerini devralır. Zincirin tamamı:

```
User
 └─ Illuminate\Foundation\Auth\User  (takma adı: Authenticatable)
     └─ Illuminate\Database\Eloquent\Model      ← veritabanı yetenekleri
         └─ ... implements Authenticatable, CanResetPassword ...
```

Yani `User` iki kimlik taşır: bir **Eloquent modeli** (kaydeder, sorgular) ve bir
**kimliği doğrulanabilir varlık** (`Auth::user()` bunu döndürür).

`use Illuminate\Foundation\Auth\User as Authenticatable;` satırındaki `as`, isim
çakışmasını çözer: dosyada zaten `User` adında bir sınıf tanımlıyoruz, ikinci bir
`User` adı olamaz. `as` ile ithal edilen sınıfa yerel bir takma ad veriyoruz.

### 2.2 Trait nedir?

```php
use HasApiTokens, HasFactory, Notifiable;
```

> ⚠️ Bu satırdaki `use`, dosyanın en üstündeki `use` satırlarıyla **aynı şey
> değildir**. Üstteki `use` = "bu sınıfı ithal et". Sınıfın **içindeki** `use` =
> "bu trait'in metotlarını bu sınıfa yapıştır".

**Trait**, PHP'nin çoklu kalıtım eksikliğini kapatan mekanizmadır. Bir sınıf yalnızca
tek bir sınıftan `extends` edebilir, ama istediği kadar trait kullanabilir. Trait,
gövdesi hazır metotlardan oluşan bir "kopyala-yapıştır paketi"dir — derleme anında
sınıfın içine kopyalanır.

| Trait | Ne ekler | Faz 2'de nerede kullanılır |
|---|---|---|
| `HasApiTokens` | `createToken()`, `tokens()`, `currentAccessToken()` | `RegisterUserAction`, `LoginUserAction`, `RevokeTokenAction` |
| `HasFactory` | `User::factory()` | `AuthTest` |
| `Notifiable` | `notify()` — mail/SMS gönderimi | Henüz yok (parola sıfırlama ileride) |

Alfabetik sıralama zorunlu değil, ama Pint'in yeniden düzenleme ihtimaline karşı
tutarlı tutuyoruz.

### 2.3 Attribute (öznitelik) nedir?

```php
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
```

`#[...]` sözdizimi PHP 8'in **attribute** özelliğidir: koda iliştirilen, çalışma
anında okunabilen meta veri. TypeScript'teki decorator'ların PHP karşılığıdır.

Laravel 13 öncesinde aynı bilgi property olarak yazılırdı:

```php
protected $fillable = ['name', 'email', 'password'];
protected $hidden = ['password', 'remember_token'];
```

**İkisi eşdeğerdir.** İnternetteki eğitimlerin neredeyse tamamı property biçimini
gösterir — gördüğünde şaşırma. Attribute biçimini tercih ediyoruz çünkü Laravel 13
iskeletinin ürettiği biçim bu; iskeletle çelişmemek gereksiz sürtünmeyi önler.

### 2.4 Accessor ve Mutator

```php
protected function setEmailAttribute(string $value): void
{
    $this->attributes['email'] = mb_strtolower(trim($value));
}
```

Eloquent, model üzerinde belirli adlandırma kalıplarına uyan metotlar arar ve o
kolona erişilirken bu metodu **araya sokar**.

| Yön | Adı | Metot kalıbı | Ne zaman çalışır |
|---|---|---|---|
| **Mutator** | yazma | `set{Kolon}Attribute` | `$user->email = '...'` yapıldığında |
| **Accessor** | okuma | `get{Kolon}Attribute` | `$user->email` okunduğunda |

Burada yalnızca mutator var: veri **giderken** dönüştürülüyor, geldiği gibi
okunuyor.

`$this->attributes` modelin ham kolon değerlerini tutan dizidir. Mutator içinde
`$this->email = ...` yazmak **sonsuz döngü** yaratırdı (mutator kendini çağırır);
bu yüzden doğrudan diziye yazılır.

### 2.4.1 İki sözdizimi — ve neden eskisini seçtik

Laravel 9 ile modern bir alternatif geldi:

```php
// Modern biçim — Laravel dokümantasyonunun önerdiği
protected function email(): Attribute
{
    return Attribute::set(fn (string $v): string => mb_strtolower(trim($v)));
}
```

İkisi **işlevsel olarak eşdeğerdir**. Bu projede klasik biçim kullanılıyor;
gerekçesi bir mimari tercih değil, araç zincirinin bir kısıtıdır — ayrıntısı
[§3.6](#36-e-posta-normalizasyonu-ve-larastan-ile-yaşanan-çatışma)'da.

> ⚠️ `Illuminate\Database\Eloquent\Casts\Attribute` (accessor/mutator) ile
> §2.3'teki `Illuminate\Database\Eloquent\Attributes\Fillable` (PHP 8
> özniteliği) **karıştırılmamalı**. Benzer isimli, tamamen farklı iki mekanizma.

---

## 3. Alınan kararlar

### 3.1 `$fillable` neden `first_name` + `last_name`? (K35)

İskelet migration'ında kolon tek ve `name`'di; `03-MIMARI-PLAN.md` ise `full_name`
diyordu. Faz 2 girişinde ikisi de bırakılıp **ad ve soyad ayrıldı**.

Gerekçe tek cümleyle: **birleştirmek güvenlidir, ayırmak değildir.**

```
first_name="Ayşe"  last_name="Nur Kaya"   →   "Ayşe Nur Kaya"    ✅ kayıpsız
"Ayşe Nur Kaya"                            →   ad? soyad?         ❌ bilinemez
```

Tek kolonda tutulan "Ayşe Nur Kaya" için ad "Ayşe" mi "Ayşe Nur" mü olduğu
**hiçbir algoritmayla** çıkarılamaz. Yanlış bölünen veri geri kazanılamaz. Buna
karşılık iki kolondan tek string üretmek her zaman mümkündür.

Ayrı kolonun somut kazançları: fatura ve resmî belge soyadı tek başına ister
(Faz 7), soyada göre sıralama/arama mümkün olur, "Sayın Kaya" hitabı kurulabilir.

🔴 **Bunun sözleşmeye yansıması asimetriktir:**

| Yön | Değişti mi | Neden |
|---|---|---|
| **İstek** `POST /auth/register` | ✅ `{firstName, lastName, ...}` | Veri kullanıcıdan **iki alan olarak** toplanmalı |
| **Yanıt** `{user, token}` | ❌ `fullName` aynı kaldı | `UserResource` birleştirerek üretiyor |

Yani frontend'in okuma tarafı (`Header`, `DashboardPage`, `LoginPage`) hiç
kırılmıyor; yalnızca `RegisterPage` formu ve `RegisterPayload` tipi değişiyor.

**Birleştirme bu dosyada yapılmaz.** Modele bir `fullName` accessor'ı eklemek
cazip görünür ama `CLAUDE.md` §1 nettir: snake_case → camelCase dönüşümünün
yapıldığı **tek yer** `app/Http/Resources/`'tur. İki yerde dönüşüm yapılabiliyorsa,
er ya da geç ikisi birbirinden ayrışır.

> **Genel ilke:** İç isimlendirme ile dış sözleşme arasına bir **çeviri katmanı**
> koyduysan (bizde Resource), iç modeli dış sözleşmeye benzetmek zorunda değilsin.
> Veritabanı ayrıntılı, sözleşme sade olabilir — katmanın varlık sebebi budur.

### 3.2 `$fillable` — beyaz liste, kara liste değil

`CLAUDE.md` §3: *"Modellerde kesinlikle `$guarded = []` kullanılamaz."* Nedeni somut
bir saldırıdır.

**Mass assignment (toplu atama)** şudur:

```php
User::create($request->validated());   // dizideki her anahtar bir kolona yazılır
```

Kullanıcı isteği şöyle gelirse ne olur?

```json
{ "name": "Ali", "email": "a@b.com", "password": "12345678", "is_admin": true }
```

`$guarded = []` (= "hiçbir alan korumalı değil") olsaydı, `is_admin` kolonu varsa
kullanıcı **kendini yönetici yapardı**. Beyaz listeyle bu imkânsız: `is_admin`
listede olmadığı için Eloquent onu sessizce atar.

| Yaklaşım | Yeni kolon eklendiğinde varsayılan davranış |
|---|---|
| Kara liste (`$guarded`) | **Açık** — unutulursa sızar |
| Beyaz liste (`$fillable`) | **Kapalı** — unutulursa çalışmaz |

Fark, hata anındaki sonuçtadır. Kara listede unutmanın bedeli **güvenlik açığı**,
beyaz listede **çalışmayan özellik**. İkincisi test tarafından yakalanır, birincisi
yakalanmaz.

> Bu, güvenlik mühendisliğinde **fail-safe default** ilkesidir: bir mekanizma
> bozulduğunda güvenli tarafa düşmelidir. Faz 1'in `/api/public/` öneki kararı (K12)
> ve `ErrorCode::filterParams()` beyaz listesi (H9) de aynı ilkenin uygulamalarıdır.

### 3.3 `$hidden` — ikinci savunma hattı

`#[Hidden(['password', 'remember_token'])]`, model JSON'a çevrilirken bu alanları
atar.

"Ama biz zaten `UserResource` kullanıyoruz, model doğrudan JSON'a çevrilmeyecek ki?"
Doğru — ve `$hidden` yine de duruyor. Çünkü:

- Faz 1'de silinen varsayılan `/user` rotası **ham modeli döndürüyordu**. Böyle bir
  satır bir gün yanlışlıkla geri gelebilir.
- `dd($user)`, log kaydı, `toArray()` çağrısı — modelin serileştirildiği başka
  yollar vardır.

Tek bir savunmaya güvenmek yerine katmanlamak (**defense in depth**) ucuz bir
sigortadır: 1 satır kod, 0 çalışma anı maliyeti.

### 3.4 `casts()` ve `'password' => 'hashed'`

`casts()`, bir kolonun PHP tarafındaki tipini bildirir.

| Kolon | Cast | Etkisi |
|---|---|---|
| `email_verified_at` | `datetime` | Veritabanından gelen string, `CarbonImmutable` nesnesine dönüşür (K23) |
| `password` | `hashed` | Atanan ham parola **yazılmadan önce** hash'lenir |

🔴 **`hashed` cast'i Faz 2'nin en kritik satırlarından biridir.** Şu kodu mümkün
kılar:

```php
User::create([
    'first_name' => 'Ali',
    'last_name' => 'Veli',
    'email' => 'a@b.com',
    'password' => 'gizli-parola',   // ham parola
]);
// Veritabanına giden: $argon2id$v=19$m=65536,t=4,p=1$...
```

Ham parola veritabanına **hiçbir yoldan** ulaşamaz, çünkü dönüşüm modelin içinde,
kaydetme yolunun üzerindedir. Unutulabilecek bir adım değil — yapının parçası.

Hangi algoritma kullanılıyor? Cast, varsayılan hash sürücüsünü çağırır; o da
`config/hashing.php`'de **Argon2id**'dir (K32).

> **Neden Argon2id, bcrypt değil?** bcrypt yalnızca CPU zamanı tüketir. Bir saldırgan
> GPU veya ASIC ile binlerce denemeyi paralel çalıştırabilir. Argon2id ek olarak
> **bellek** tüketir (varsayılan 64 MB). GPU'da 10.000 paralel denemenin 640 GB RAM
> istemesi, saldırının ekonomisini bozar. Bu, "yavaş hash" fikrinin bellek eksenine
> taşınmış hâlidir.

### 3.5 `HasApiTokens` — Sanctum token mimarisi

Bu trait `personal_access_tokens` tablosuyla çalışır (migration Faz 0'da koştu).

```php
$token = $user->createToken('web')->plainTextToken;
// "7|kJ8xQ2mF..." biçiminde bir string
```

Token'ın anatomisi:

```
7 | kJ8xQ2mF9pL3nR...
↑        ↑
id    rastgele 40 karakter
```

🔴 **Veritabanında ham token saklanmaz** — `hash('sha256', $rastgele)` saklanır.
Parola hash'lemesiyle aynı mantık: veritabanı sızarsa token'lar kullanılamaz olur.
Ham hâli yalnızca `createToken()` çağrısının döndüğü anda görülür; ikinci kez
üretilemez.

Bu, K5'i (JWT değil Sanctum) mümkün kılan özelliktir: token sunucuda **kayıtlı**
olduğu için silinebilir. JWT kendi kendini doğrular, sunucu onu geri alamaz —
frontend'in `useAuthStore.logout()` çağrısı sunucu tarafında iptal beklediği için
JWT bu projeye uymuyordu.

### 3.6 E-posta normalizasyonu ve Larastan ile yaşanan çatışma

Bu, yol haritasında yazmayan, önerilen bir eklemedir. Gerekçesi:

PostgreSQL'de `VARCHAR` karşılaştırması **harf duyarlıdır**. `UNIQUE` kısıtı da
öyle. Yani:

```
Ismail@Gmail.com     ← kayıt 1
ismail@gmail.com     ← kayıt 2 — UNIQUE kısıtı ENGELLEMEZ
```

Aynı posta kutusu için iki hesap. Kullanıcı hangi yazımla kaydolduğunu hatırlamaz,
girişi başarısız olur, destek talebi açar.

Mutator bunu **veri katmanında** çözer: hangi yoldan gelirse gelsin (kayıt formu,
seeder, tinker, ileride bir admin paneli) kolona yalnızca küçük harf yazılabilir.

> ⚠️ **Bu tek başına yetmez.** `RegisterRequest` (dosya 2.4) `unique:users,email`
> kuralını çalıştırdığında sorguyu **ham girdiyle** yapar. `Ismail@Gmail.com`
> gönderilirse `unique` kuralı "böyle bir kayıt yok" der, sonra mutator küçültür ve
> insert **veritabanı seviyesinde** patlar (500 hatası).
> Çözüm: `RegisterRequest::prepareForValidation()` içinde aynı normalizasyonu
> doğrulamadan **önce** uygulamak. 2.4'te yazacağız.

Neden `strtolower` değil `mb_strtolower`? `strtolower` bayt bazlı çalışır ve
ASCII dışı karakterleri bozabilir. `mb_` öneki "multibyte" demektir — UTF-8'i doğru
işler. E-postalar pratikte ASCII olsa da, doğru olanı varsayılan seçmek ucuzdur.

#### 3.6.1 🔴 Neden klasik mutator sözdizimi? — bir araç kısıtının izini sürmek

İlk yazımda modern `Attribute` biçimi kullanılmıştı ve `composer check` kırıldı:

```
Access to an undefined property App\Http\Resources\UserResource::$email
```

İlginç olan şu: `id`, `first_name`, `last_name` sorunsuz çalışıyordu — **yalnızca
`email`** bulunamıyordu. Yani sorun `@mixin` veya şema okuma değildi; bu kolona
özgü bir şeydi. Tek farkı da mutator'a sahip olmasıydı.

Larastan'ın kaynağında iki ayrı eklenti modelin property'lerini sağlar:

```php
// ModelPropertyExtension — property'yi migration şemasından üretir
public function hasProperty(...): bool
{
    if ($this->modelPropertyHelper->hasAccessor($class, $name, strictGenerics: false)) {
        return false;                    // ← "bunu ben sağlamıyorum, accessor sağlasın"
    }
    return $this->modelPropertyHelper->hasDatabaseProperty($class, $name);
}

// ModelAccessorExtension — property'yi accessor metodundan üretir
public function hasProperty(...): bool
{
    return $this->modelPropertyHelper->hasAccessor($class, $name, strictGenerics: true);
}
```

Ve `hasAccessor()`, `strictGenerics: true` iken dönüş tipinin **generic olmasını**
şart koşar:

```php
if ($returnType->getObjectClassReflections() === []
    || ! $returnType->getObjectClassReflections()[0]->isGeneric()) {
    return false;
}
```

Bizim metodumuz `: Attribute` döndürüyordu — generic parametresiz. Sonuç:

| Eklenti | Kararı | Sebebi |
|---|---|---|
| `ModelPropertyExtension` | ❌ Sağlamadı | "Bir accessor var, onun işi" (gevşek kontrol geçti) |
| `ModelAccessorExtension` | ❌ Sağlamadı | "Generic bildirilmemiş" (katı kontrol kaldı) |

**İkisi de kenara çekildi ve property ortada kaldı.** Hata mesajı "undefined
property" derken aslında bunu anlatıyordu.

**Çözüm seçenekleri ve neden bu:**

| Seçenek | Değerlendirme |
|---|---|
| `@return Attribute<string, string>` yazmak | `Attribute::set()` gerçekte `Attribute<never, string>` döndürür; şablon parametreleri değişmez (invariant) olduğu için bu sefer *dönüş tipi uyuşmazlığı* hatası alınır |
| Kimliksel bir `get` de yazmak | Çalışır ama hiçbir iş yapmayan kod — yalnızca aracı susturmak için |
| **Klasik `setEmailAttribute()`** ✅ | `hasAccessor()` önce `email()` adlı metodu arar; klasik biçimde böyle bir metot **yok**, dolayısıyla şema eklentisi kenara çekilmez ve `email` normal bir kolon olarak sağlanır |

Üçüncüsü seçildi. Klasik biçim Laravel'de hâlâ tam desteklidir ve kullanımdan
kaldırılmamıştır; yalnızca dokümantasyon yenisini öne çıkarır.

> **İki genel ders:**
> 1. *"Neden A çalışıyor da B çalışmıyor?"* sorusu neredeyse her zaman en hızlı
>    yoldur. Burada `first_name` çalışıp `email` çalışmaması, aramayı tek bir
>    farka indirdi.
> 2. Bir aracın hata mesajı **belirtiyi** söyler, **sebebi** değil. Aracın
>    kaynağını okumak — `vendor/` klasörü emrindedir — tahmin etmekten hızlıdır.
>    Bu dosyada iki kez yanlış tahmin edildikten sonra kaynağa bakıldı ve mesele
>    tek okumada çözüldü.

### 3.7 Silinen satır

```php
// use Illuminate\Contracts\Auth\MustVerifyEmail;
```

İskeletten gelen yorum satırıydı. Faz 1'in **R4** kuralı ("framework iskeletinden
gelen örnek satırlar silinir") aynı mantıkla burada da geçerli: yorum satırı hâline
gelmiş kod, "belki lazım olur" diye bırakılan bir belirsizliktir. E-posta doğrulaması
gerçekten gerekirse git geçmişinden değil, dokümantasyondan yeniden yazılır.

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| Action içinde `Hash::make($password)` çağırmak | **Çift hash** — parola hash'i tekrar hash'lenir, hiçbir giriş çalışmaz | Ham parolayı ver, cast halleder |
| `$guarded = []` yazmak | Mass assignment açığı | `#[Fillable([...])]` beyaz listesi |
| `$fillable`'a yeni kolon eklemeyi unutmak | Alan sessizce kaydedilmez, hata da vermez | Yeni kolon → aynı commit'te `$fillable` |
| Modeli doğrudan `response()->json($user)` ile döndürmek | `first_name` snake_case sızar, `fullName` hiç üretilmez | Her zaman `UserResource` |
| Modele `fullName` accessor'ı eklemek | Dönüşüm iki yere dağılır, zamanla ayrışır | Birleştirme yalnızca `UserResource`'ta (CLAUDE.md §1) |
| `email` mutator'ına `null` atamak | `TypeError` — `string` tip bildirimi | Kolon `NOT NULL`; null atanmamalı |
| Token'ı `$user->createToken('x')` diye kullanmak | `NewAccessToken` nesnesi döner, string değil | `->plainTextToken` |
| `#[Fillable]` yerine `#[Attributes\Attribute]` aramak | Farklı mekanizma | §2.4'teki uyarıya bak |
| Trait'i `use` ile sınıfın **dışında** yazmak | "Class not found" veya trait uygulanmaz | Trait `use`'u sınıf gövdesinin içindedir |

---

## 5. Kendin dene

Sunucunun çalışmasına gerek yok; `tinker` uygulamayı yükleyip REPL açar.

```powershell
php artisan tinker
```

**1. Parola gerçekten hash'leniyor mu?**

```php
$u = new App\Models\User([
    'first_name' => 'Test',
    'last_name'  => 'Kullanici',
    'email'      => 'T@Test.COM',
    'password'   => 'abc12345',
]);
$u->password;
// $argon2id$v=19$m=65536,t=4,p=1$... — 'abc12345' DEĞİL
```

**2. E-posta küçüldü mü?**

```php
$u->email;
// "t@test.com"
```

**3. Beyaz liste çalışıyor mu?**

```php
$u2 = new App\Models\User(['first_name' => 'X', 'is_admin' => true]);
$u2->is_admin;
// null — anahtar sessizce atıldı
```

**4. `$hidden` çalışıyor mu?**

```php
$u->toArray();
// password ve remember_token YOK
```

**5. Token üretimi** (veritabanına yazar, `davetkart` DB'sinde kalır):

```php
$saved = App\Models\User::factory()->create();
$t = $saved->createToken('deneme');
$t->plainTextToken;                       // "1|xxxx..." — sadece şimdi görünür
$t->accessToken->token;                   // sha256 hash — DB'de duran bu
```

Çıkmak için `exit`.

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Trait** | Metot gövdelerini birden çok sınıfa paylaştıran PHP yapısı |
| **Attribute (`#[...]`)** | PHP 8 ile gelen, koda iliştirilen meta veri |
| **Accessor / Mutator** | Bir kolonu okurken / yazarken araya giren dönüştürücü |
| **Cast** | Kolonun PHP tarafındaki tipini bildirme mekanizması |
| **Mass assignment** | Bir diziden birden çok kolonu tek seferde doldurma |
| **Beyaz liste** | "Yalnızca şunlar serbest" — varsayılan kapalı |
| **Fail-safe default** | Mekanizma bozulduğunda güvenli tarafa düşmesi ilkesi |
| **Defense in depth** | Tek savunmaya güvenmeyip katmanlamak |
| **Argon2id** | Bellek de tüketen modern parola hash algoritması |
| **Active Record** | Bir sınıfın hem veriyi hem veritabanı erişimini taşıdığı desen (Eloquent) |
| **REPL** | Yazdığın ifadeyi anında çalıştıran etkileşimli kabuk (`tinker`) |

---

## 6.5 Faz 3 eklentisi — `invitations()` ilişkisi

Faz 3'ün 3.5 adımında bu modele tek bir metot eklendi:

```php
/** @return HasMany<Invitation, $this> */
public function invitations(): HasMany
{
    return $this->hasMany(Invitation::class);
}
```

**Neden gerekti?** `Invitation` modelinin `#[Fillable]` listesinde `user_id`
**yok** — sahiplik istemci kararı olmadığı için. Dolayısıyla davetiye ancak
buradan oluşturulabiliyor:

```php
$user->invitations()->create([...]);   // user_id'yi Eloquent doldurur
```

Aidiyet böylece doğrulanması gereken bir **girdi** olmaktan çıkıp yapısal bir
**garanti**ye dönüşüyor.

**Neden sıralama gömülü değil?** `Invitation::timelineEvents()` ilişkisinde
`->orderBy('sort_order')` var, burada yok. Ölçüt: program adımlarının sırası
**anlamın parçası** (program bir akıştır), davetiyelerin sırası ise bir **sunum
tercihi** (dashboard tarihe göre, ada göre veya duruma göre listeleyebilir).

> Sorulacak soru: *"bu kural olmadan veri yanlış mı olur, yoksa sadece farklı mı
> görünür?"* Yanlış oluyorsa modele, farklı görünüyorsa çağırana ait.

Ayrıntı: [`TimelineEvent.md`](TimelineEvent.md) §5.

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| Bu fazın planı | [`docs/09-TUM-FAZLAR-PLANI.md`](../../../09-TUM-FAZLAR-PLANI.md) §Faz 2 |
| Enumeration savunması | [`docs/08-HATA-SOZLESMESI.md`](../../../08-HATA-SOZLESMESI.md) §3.1 |
| Kod standartları | [`CLAUDE.md`](../../../../CLAUDE.md) §1, §3 |
| Önceki faz özeti | [`fazlar/FAZ-1.md`](../../fazlar/FAZ-1.md) |
| Sıradaki dosya | `database/factories/UserFactory.php` (2.2) |
