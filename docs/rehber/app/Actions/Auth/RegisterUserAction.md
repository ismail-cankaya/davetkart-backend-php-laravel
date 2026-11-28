# `app/Actions/Auth/RegisterUserAction.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Actions/Auth/RegisterUserAction.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.5b — **projenin ilk gerçek iş kuralı**
> **Bağlantılı:** [`RegistrationFailedException.md`](../../Exceptions/RegistrationFailedException.md) ·
> [`RegisterRequest.md`](../../Http/Requests/Auth/RegisterRequest.md) ·
> [`app/Models/User.md`](../../Models/User.md) · [`CLAUDE.md`](../../../../../CLAUDE.md) §1

---

## 0. Bir dakikalık özet

Projede yazdığımız **ilk iş kuralı** bu. Üç şey yapıyor:

```
1. Kullanıcıyı oluştur            User::create(...)
2. İlk API token'ini üret         $user->createToken(...)
3. E-posta çakıştıysa reddet      throw RegistrationFailedException
```

Ve bir şeyi **yapmıyor**: HTTP bilmiyor. `Request` almıyor, `response()`
döndürmüyor, durum kodu tanımıyor.

---

## 1. "Action" nedir? Laravel'de böyle bir şey var mı?

**Yok.** Laravel'in `Action` diye bir kavramı yoktur — bu bizim mimari
kararımızdır (K3). Bu yüzden `make:action` komutu da yoktur; `make:class`
kullanıyoruz.

**Tanımı:** Bir Action, **tek bir kullanıcı işlemini** baştan sona yapan sınıftır.
"Kullanıcı kaydet", "davetiye yayınla", "LCV gönder" — her biri bir Action.

### 1.1 Alternatifleri neden reddettik?

| Yaklaşım | Sorunu |
|---|---|
| İş kuralını **Controller**'a yazmak | Controller şişer; iş kuralı HTTP olmadan test edilemez |
| **Fat Service** (`UserService` — 20 metot) | Sınıf büyüdükçe metotlar birbirine dolanır; "bu metot nerede kullanılıyor?" cevaplanamaz |
| **Repository Pattern** | Eloquent zaten Active Record. Üstüne konan katman anlamsız aracı üretir (K4) |
| **Action** ✅ | Bir sınıf = bir işlem. Adı ne yaptığını söyler, tek `public` metodu vardır |

`CLAUDE.md` §1 bunu bağlayıcı kılıyor: *"Repository Pattern ve Fat Service
KESİNLİKLE YASAKTIR."*

### 1.2 Action'ın üç kesin kuralı

```php
final class RegisterUserAction
{
    public function handle(array $attributes): array { ... }
}
```

| Kural | Bu dosyada nasıl görünüyor |
|---|---|
| **Tek eylem** (SRP) | Yalnızca `handle()`. Kayıt dışında bir şey yapmaz |
| **Doğrulama yok** | `$attributes` temiz kabul edilir; `if (empty(...))` yok |
| **HTTP yanıtı yok** | `response()->json()` yok — hata için `throw` |

Üçüncüsünün derin gerekçesi: Action **ne olduğunu** bilir, **nasıl anlatılacağını**
bilmez. Aynı sınıf yarın bir konsol komutundan (`php artisan user:create`) veya
bir kuyruk işinden çağrılabilir. Orada "HTTP 422" diye bir kavram yoktur.

---

## 2. PHP ve Laravel temelleri

### 2.1 `DB::transaction()` — ya hepsi ya hiçbiri

```php
return DB::transaction(function () use ($attributes): array {
    $user = User::create($attributes);                        // 1. INSERT
    return ['user' => $user, 'token' => $user->createToken(...)->plainTextToken];
    //                                    ↑ 2. INSERT (personal_access_tokens)
});
```

**İşlem (transaction)**, birden çok veritabanı yazmasını **tek bir bütün** hâline
getirir. Closure hatasız biterse hepsi kalıcı olur (`COMMIT`); içeriden bir
exception çıkarsa hepsi geri alınır (`ROLLBACK`).

**Neden burada gerekli?** İki `INSERT` var. `createToken` başarısız olsaydı,
transaction olmadan şu durum oluşurdu:

```
users tablosu:                    ✅ Ayşe kaydedildi
personal_access_tokens:           ❌ token yok
HTTP yanıtı:                      500
```

Kullanıcı hata görür, tekrar kayıt olmayı dener → `REGISTRATION_FAILED`
("e-posta zaten kayıtlı") → **çıkmaz sokak**. Hesabı var ama bunu bilmiyor,
giriş yapmayı denemesi gerektiğini tahmin edemez.

> 🔴 Dikkat: burada asıl tehlike *veri bozulması* değil, **kullanıcının çıkmaza
> girmesi**. Transaction kararı verirken sorulacak soru "veri tutarsız kalır mı?"
> değil, **"yarım kalan durum kimin için ne anlama gelir?"** olmalıdır.

### 2.2 `use ($attributes)` — closure'ın dış dünyayı görmemesi

```php
function () use ($attributes): array { ... }
```

PHP'de closure, dışarıdaki değişkenleri **otomatik görmez** (JavaScript'in
aksine). `use` ile açıkça içeri almalısın — bkz.
[`php-dili.md`](../../../kavramlar/php-dili.md) §10.1.

### 2.3 Değişkensiz `catch`

```php
} catch (UniqueConstraintViolationException) {
```

PHP 8 ile geldi: exception nesnesini kullanmayacaksan `$e` yazmana gerek yok.
Burada gerçekten kullanmıyoruz — yakalayıp **kendi** exception'ımızı fırlatıyoruz.

### 2.4 `private const TOKEN_NAME`

Sihirli string yasağı (`CLAUDE.md` §1). Şu an tek yerde kullanılıyor ama 2.8'de
`LoginUserAction` da token üretecek; ikisinin aynı etiketi kullanması bir
sözleşmedir.

---

## 3. Alınan kararlar

### 3.1 🔴 Benzersizlik `unique` kuralıyla değil, veritabanı kısıtıyla

`RegisterRequest`'te `unique:users,email` **yok** — sebebi enumeration savunması
([`RegisterRequest.md`](../../Http/Requests/Auth/RegisterRequest.md) §3.1).
Kontrol buraya taşındı ve **daha iyi bir yere** taşındı.

**Neden daha iyi? Yarış koşulu (race condition).** `unique` kuralıyla yapsaydık:

```
Zaman   İstek A                        İstek B
─────   ────────────────────────       ────────────────────────
 t1     unique kontrolü: temiz ✓
 t2                                    unique kontrolü: temiz ✓
 t3     INSERT → başarılı
 t4                                    INSERT → 💥 UNIQUE ihlali → 500
```

Kontrol ile yazma arasında bir **boşluk** var. Bu boşluk mikrosaniyeler sürer
ama aynı anda iki kayıt denemesi olduğunda gerçekleşir ve yakalanması en zor
hata türüdür — yerelde asla üretilemez.

Bizim yaklaşımımızda boşluk **yok**: kontrol ile yazma **aynı işlemdir**.
Veritabanı `INSERT`'ü ya kabul eder ya `23505` fırlatır; arada başka kimse
giremez.

```php
try {
    $user = User::create($attributes);          // kontrol = yazma
} catch (UniqueConstraintViolationException) {
    throw RegistrationFailedException::emailTaken();
}
```

> **Genel ilke:** Eşzamanlılıkta *"önce sor, sonra yap"* (check-then-act) bir
> hata kalıbıdır. Doğrusu *"yap, hata olursa yakala"*. Veritabanı kısıtları
> atomiktir; uygulama kodundaki `if` değildir.
>
> Aynı desen Faz 7'de tekrar edecek: `orders.provider_ref` UNIQUE kısıtı,
> webhook'un iki kez işlenmesini `if (already_processed)` kontrolüyle değil
> veritabanı seviyesinde engelleyecek.

### 3.2 `UniqueConstraintViolationException` — özel mi genel mi?

`QueryException` de yakalanabilirdi. Yakalamadık, çünkü o **her** veritabanı
hatasını kapsar: bağlantı kopması, sözdizimi hatası, `NOT NULL` ihlali...
Hepsini `REGISTRATION_FAILED` diye raporlamak, gerçek sunucu hatalarını
gizlerdi — üstelik istemciye "yeniden dene" dedirtirdi.

Laravel'in `Connection` sınıfı bu ayrımı bizim için yapıyor:

```php
// PostgresConnection.php
protected function isUniqueConstraintError(Exception $exception)
{
    return '23505' === $exception->getCode();
}
```

`23505` PostgreSQL'in `unique_violation` SQLSTATE kodudur. Laravel bunu görünce
genel `QueryException` yerine özel `UniqueConstraintViolationException`
fırlatır.

> ⚠️ **Bilinen sınır:** Bu `catch` bloğu, `users` tablosundaki **herhangi bir**
> UNIQUE ihlalini e-posta çakışması sayar. Şu an tablodaki tek UNIQUE kısıt
> `email` olduğu için doğru. Yarın ikinci bir UNIQUE kolon eklenirse (örneğin
> `username`) bu varsayım sessizce yanlışlanır. O gün kısıt adına bakan bir
> kontrol gerekecek.

### 3.3 Parola burada hash'lenmiyor

```php
$user = User::create($attributes);    // 'password' => 'gizli1234'  ← HAM
```

`Hash::make()` çağrısı **yok** ve olmamalı. Hash'leme `User` modelinin
`casts()` metodundaki `'password' => 'hashed'` satırıyla, atama anında yapılır.

Buraya `Hash::make()` yazsaydık **çift hash** olurdu: hash'lenmiş değer tekrar
hash'lenir, hiçbir giriş çalışmaz ve sebebi bulunması çok zordur (kod doğru
görünür, veri yanlıştır).

> **Genel ilke:** Bir dönüşüm **yolun üzerinde** duruyorsa, çağrı yerlerinde
> tekrarlanmamalıdır. Yol üzerindeki dönüşüm unutulamaz; tekrarlanan dönüşüm
> ise sessizce iki kez uygulanır.

### 3.4 Dönüş tipi neden dizi?

```php
/** @return array{user: User, token: string} */
```

Action iki şey üretiyor: kullanıcı **ve** token. PHP'de bir metot tek değer
döndürebilir.

| Seçenek | Değerlendirme |
|---|---|
| Sadece `User` döndür, token'ı controller üretsin | 🔴 Token üretimi kaydın parçası; controller'a iş kuralı sızar |
| `readonly` DTO sınıfı | Daha tipli, ama Faz 2 için fazladan bir dosya |
| **Şekilli dizi** ✅ | `array{...}` docblock'u PHPStan tarafından **denetlenir** |

`array{user: User, token: string}` gerçek bir tip güvencesi sağlar: controller'da
`$result['tokne']` yazarsan PHPStan yakalar. Yani "sadece dizi" değil, **denetlenen
dizi**.

> Faz 5'te `SubmitRsvpAction` daha fazla değer döndürecek. Üç-dört alanı geçtiği
> anda DTO'ya geçmek doğru olur — o zaman gerçek bir kazanç olacak.

### 3.5 `final` ve tek `public` metot

Sınıfın dış yüzeyi tek bir metot: `handle()`. Bu bilinçli — küçük yüzey, ucuz
değişim. `final` ise tasarlanmamış kalıtımı reddediyor
([`php-dili.md`](../../../kavramlar/php-dili.md) §3.2).

**Neden `handle()`?** Laravel ekosisteminde yerleşik ad (Job, Command, Middleware
hepsi `handle` kullanır). Tutarlılık, okuyanın tahmin etmesini sağlar.

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `Hash::make($password)` çağırmak | Çift hash — hiçbir giriş çalışmaz | Cast halleder (§3.3) |
| Action'da yeniden doğrulamak | İki doğruluk kaynağı | Veri temiz kabul edilir |
| `response()->json()` döndürmek | H10 ihlali; HTTP'ye bağlanır | `throw` |
| `where('email', ...)->exists()` ile ön kontrol | Yarış koşulu (§3.1) | Kısıt ihlalini yakala |
| `QueryException` yakalamak | Gerçek sunucu hataları gizlenir | `UniqueConstraintViolationException` |
| Transaction'ı atlamak | Yarım kayıt → kullanıcı çıkmazda | `DB::transaction` |
| `use ($attributes)` unutmak | `Undefined variable` | Closure dışarıyı görmez |
| Controller'da token üretmek | İş kuralı controller'a sızar | Action'ın parçası |

---

## 5. Kendin dene

Rota hâlâ yok — ama Action **HTTP'siz test edilebilir**. Bu, bu mimarinin
kazandırdığı şeyin ta kendisi.

```powershell
php artisan tinker
```

**1. Kayıt çalışıyor mu?**

```php
$action = new App\Actions\Auth\RegisterUserAction;

$r = $action->handle([
    'first_name' => 'Ayse',
    'last_name'  => 'Yildirim',
    'email'      => 'ayse@ornek.test',
    'password'   => 'gizli1234',
]);

$r['user']->id;
$r['token'];                  // "1|kJ8x..." — ham token, yalnızca ŞİMDİ görünür
```

**2. Parola gerçekten Argon2id ile hash'lendi mi?**

```php
str_starts_with($r['user']->password, '$argon2id$');    // true
Hash::check('gizli1234', $r['user']->password);         // true
```

**3. 🔴 Aynı e-posta ikinci kez:**

```php
$action->handle([
    'first_name' => 'Baska', 'last_name' => 'Kisi',
    'email' => 'ayse@ornek.test', 'password' => 'baska1234',
]);
// App\Exceptions\RegistrationFailedException: Registration failed: email already exists.
```

Mesajın *"e-posta zaten var"* dediğine dikkat et — bu **sunucu tarafı** bilgidir.
İstemciye giden yalnızca `REGISTRATION_FAILED` olacak.

**4. Büyük harfli e-posta çakışıyor mu?** (mutator'ın kanıtı)

```php
$action->handle([
    'first_name' => 'Ucuncu', 'last_name' => 'Kisi',
    'email' => 'AYSE@Ornek.TEST', 'password' => 'ucuncu1234',
]);
// yine RegistrationFailedException — küçültülüp aynı kayda düştü
```

**5. Transaction geri aldı mı?** Başarısız denemeden sonra tablo temiz mi:

```php
App\Models\User::count();     // 1 — ikinci ve üçüncü deneme iz bırakmadı
```

**6. Temizlik:**

```powershell
php artisan migrate:fresh
```

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Action** | Tek bir kullanıcı işlemini yapan sınıf (bu projenin mimari kararı) |
| **SRP** | Single Responsibility — bir sınıf, bir sorumluluk |
| **Transaction** | Ya hepsi ya hiçbiri çalışan veritabanı işlem bloğu |
| **COMMIT / ROLLBACK** | İşlemi kalıcı kılma / geri alma |
| **Race condition** | Eşzamanlı işlemlerin sıraya bağlı hatalı sonuç üretmesi |
| **Check-then-act** | "Önce sor sonra yap" — eşzamanlılıkta hatalı kalıp |
| **Atomik** | Bölünemez; ya tamamen olur ya hiç olmaz |
| **SQLSTATE** | Standart veritabanı hata kodu (`23505` = unique ihlali) |
| **DTO** | Veri taşımak için kullanılan basit nesne |
| **Şekilli dizi** | `array{a: int, b: string}` — PHPStan'ın denetlediği dizi tipi |

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| Fırlattığı exception | [`RegistrationFailedException.md`](../../Exceptions/RegistrationFailedException.md) |
| Girdiyi hazırlayan | [`RegisterRequest.md`](../../Http/Requests/Auth/RegisterRequest.md) |
| Parola hash'i | [`app/Models/User.md`](../../Models/User.md) §3.4 · [`config/hashing.md`](../../../config/hashing.md) |
| Katman kuralları | [`CLAUDE.md`](../../../../../CLAUDE.md) §1 |
| Sıradaki dosya | `app/Http/Controllers/Api/V1/AuthController.php` (2.6) |

---

## 🆕 Faz 14 (14.9 · K143): token `IssueApiTokenAction`'dan

Token artık bu sınıfta değil, `IssueApiTokenAction`'da üretiliyor: hangi cihazdan açıldığı
(`user_agent`) da yazılıyor. İmzaya `?string $userAgent` eklendi; controller
`$request->userAgent()` veriyor. Ayrıntı: [`IssueApiTokenAction.md`](IssueApiTokenAction.md).


## 🆕 Faz 19 (19.14 · K191, SEC-09) — kayıtta doğrulama postası

Transaction commit edildikten **sonra** `SendEmailVerificationAction` çağrılır, `VerifyEmailNotification` kuyruğa girer.
İçeride gönderilseydi rollback'te var olmayan bir hesaba posta giderdi. Oturum (token) yine hemen açılır; doğrulanmamış
hesap taslak hazırlayabilir, ödeme ve yayın `VerifiedEmailGuard`'da bekler. Yanıtta `user.emailVerified: false`.

## 🆕 Faz 21 (21.2 · `SEC-15` · K266): kayıt ucu kayıtlı adresi söylüyor — kabul edilen risk

**Durum.** Var olan adresle kayıt `422 REGISTRATION_FAILED`, yeni adresle `201` döner. `fields` yok (H6), ama durum kodu
farkı adresin DavetKart'ta kayıtlı olduğunu söyler. Giriş (`INVALID_CREDENTIALS`) ve sıfırlama (`202`) uçları bunu
kapatmıştı; zincirin en zayıf halkası kayıt.

**Karar (varsayılan B).** Sözleşme aynı kalır (K11: kayıt `{user, token}` döner ve oturum açar). Tam kapatma için kayıt
her zaman `202 {status: "check_email"}` dönmeli, oturum ancak doğrulama bağlantısından sonra açılmalıydı; bu frontend
akışını ve e2e'yi değiştiren ayrı bir iş (K266 "tam kapatma").

**Frenler (bugün kodda olan, 21.2'de testle kilitlendi):**

| Fren | Nerede | Test |
|---|---|---|
| Adres + IP başına dakikada 5 (kayıtlı adresle deneme de sayılır) | `throttle:auth` → `AppServiceProvider::authLimits()` | `attempts_on_a_taken_address_fill_the_address_and_ip_bucket` |
| IP başına dakikada 20 (çok adres taraması) | aynı | `scanning_many_addresses_from_one_ip_is_capped` |
| İki yolda da Argon2id hash | `User::create()` → `hashed` cast'i parolayı **INSERT'ten önce** hash'ler; çakışma INSERT'te çıkar | `the_password_is_hashed_even_when_the_address_is_taken` |
| `fields` yok | `RegistrationFailedException` | `AuthTest::register_does_not_reveal_that_the_email_is_taken` |

Kova sayıları login'le aynı, çünkü kayıt ve giriş aynı `auth` limiter'ını paylaşır (aynı adres + IP için ortak kova).

**Yanıt süresi.** 422 yolu 201 yolundan ancak birkaç milisaniye kısa: ikisinde de en pahalı adım (Argon2id, ~200 ms) aynı.
201 yolu fazladan token satırı ve doğrulama postasının kuyruk satırını yazar. Bu fark **test edilmedi**: süre ölçen test
CI makinesinin yüküne göre kırılgandır ve zaten durum kodu farkı süreden çok daha net bir sinyal. Korunan şey yapı:
"önce adres var mı diye bak, varsa hash'lemeden dön" kısa yolunu `the_password_is_hashed_even_when_the_address_is_taken`
yakalar (`tests/Support/CountingHasher` ile `Hash::make` sayılır).

**Neyi kapatmaz (B6).** Çok IP'li bir saldırgan IP başına dakikada 20 adres tarayabilir; hesap başına kova (21.1) bunu
durdurmaz, çünkü tarama her adresi bir kez dener.

## 🆕 Faz 22 (22.1 · K276) — doğrulama ödemeyi beklemez

Yukarıdaki "ödeme ve yayın `VerifiedEmailGuard`'da bekler" cümlesi geçersiz: guard kaldırıldı. Doğrulanmamış hesap ödeme
yapar ve yayınlar; yalnızca ilk yayın indirimi doğrulama ister (`FirstPublishEligibility`). Panel bandı "hesap erişimini
kaybetmemek için doğrula" hatırlatmasıdır, engel değildir.

## K18 taşıması (Faz 24, 2026-11-28)

`app/Actions/Auth/RegisterUserAction.php` değiştiği için K18 cırcırı dosyanın tamamını denetledi; 3 satırı aşan yorum blokları kısaltıldı, tam metinleri aşağıda (değiştirilmeden).

### K18-1

Eski satır 13, şunun üstünde: `final class RegisterUserAction`.

> Yeni kullanici olusturur ve ilk API token'ini uretir.
> Gelen veri RegisterRequest'ten gecmistir; burada TEKRAR DOGRULANMAZ.
> Benzersizlik, veritabani UNIQUE kisitiyla korunur — `unique` kurali degil (H6).
> Ayrintili aciklama: docs/rehber/app/Actions/Auth/RegisterUserAction.md
