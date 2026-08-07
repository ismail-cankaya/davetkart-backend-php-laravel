# `tests/Feature/AuthTest.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `tests/Feature/AuthTest.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.10 — **fazın kanıtı**
> **Bağlantılı:** [`HealthTest.md`](HealthTest.md) (test temelleri orada) ·
> [`fazlar/FAZ-0.md`](../../fazlar/FAZ-0.md) §4.4 · [`FAZ-1.md`](../../fazlar/FAZ-1.md) §4.4

---

## 0. Bir dakikalık özet

14 test. Hepsi bugüne kadar `curl` ile **elle** yaptığımız doğrulamaların
kalıcı hâli.

| Grup | Test sayısı | Neyi korur |
|---|---|---|
| Kayıt | 6 | Sözleşme, hash, enumeration |
| Giriş | 3 | Sözleşme, normalizasyon, **ayırt edilemezlik** |
| Me / Çıkış | 4 | Kimlik zorunluluğu, token izolasyonu |
| Hız sınırı | 2 | Limitin varlığı **ve kapsamı** |

Elle test bir kez doğrular. Test dosyası **her `composer check`'te** doğrular.

---

## 1. Bu testler neyi koruyor?

Bir testin değeri, **kırıldığında ne söylediğiyle** ölçülür.

| Test kırılırsa | Anlamı |
|---|---|
| `register_creates_user_and_returns_unwrapped_session` | Frontend `data.user` bulamayacak — giriş akışı ölür |
| `register_does_not_reveal_that_the_email_is_taken` | 🔴 Kayıt formu hesap tarayıcısına döndü |
| `login_is_indistinguishable_...` | 🔴 Enumeration açığı geri geldi |
| `logout_revokes_only_the_current_token` | Kullanıcı bir cihazdan çıkınca hepsinden çıkıyor |
| `credential_endpoints_are_rate_limited` | Brute-force + bellek tüketimi kapısı açıldı |

Üçü 🔴 işaretli: bunlar **güvenlik regresyon testleridir**. Kod bozulursa
uygulama çalışmaya devam eder ama **güvenli olmaktan çıkar** — hiçbir kullanıcı
şikâyet etmez, hiçbir 500 hatası düşmez. Yalnızca bu testler haber verir.

---

## 2. Uygulanan test kuralları

> Test altyapısının genel anlatımı [`HealthTest.md`](HealthTest.md)'de. Burada
> yalnızca bu dosyanın öne çıkardıkları var.

### 2.1 `RefreshDatabase` (T1) — ve T8'in sınırı

```php
use RefreshDatabase;
```

Her test **boş bir veritabanıyla** başlar. `register_treats_email_case_insensitively`
testindeki `assertSame(1, User::query()->count())` ancak bu sayede anlamlı.

Faz 1'de **T8** kuralı konmuştu: *"`RefreshDatabase` yalnızca veritabanına
dokunan testlerde."* `HealthTest` onu kullanmıyordu. Bu dosyanın **her testi**
veritabanına dokunuyor, dolayısıyla sınıf düzeyinde kullanmak doğru.

### 2.2 `#[Test]` özniteliği (T9)

```php
#[Test]
public function register_creates_user_and_returns_unwrapped_session(): void
```

Eski `/** @test */` yorumu PHPUnit 11 ile kaldırıldı. Alternatif `test` ön eki
(`testRegisterCreates...`) okunurluğu düşürür — metot adları burada **cümle**
gibi okunmalı.

### 2.3 Davranış, metin değil (T5)

```php
->assertJsonPath('error.code', ErrorCode::RegistrationFailed->value)
```

Hiçbir testte kullanıcıya gösterilecek **metin** doğrulanmıyor — zaten backend
metin döndürmüyor (K20). Ve `'REGISTRATION_FAILED'` düz string'i yazılmıyor;
enum üzerinden geliyor. Kod adı değişirse test **derleme anında** değil ama
tek yerden kırılır.

### 2.4 🔴 Varlık **ve** yokluk (T6) — bu dosyanın kalbi

Faz 1'in **T6** kuralı: *"Bir davranışın hem varlığı hem yokluğu test edilir.
Yalnızca 'yok' testi, özellik tamamen silinse de yeşil kalır."*

Somut örnek — `fields` anahtarı:

```php
// VARLIK: normal doğrulama hatası fields DÖNDÜRÜR
public function register_reports_field_errors_for_invalid_input(): void
{
    ...->assertJsonPath('error.fields.password.0.rule', 'min');
}

// YOKLUK: kayıt hatası fields DÖNDÜRMEZ
public function register_does_not_reveal_that_the_email_is_taken(): void
{
    ...->assertJsonMissingPath('error.fields');
}
```

**İkincisi tek başına neden yetmez?** Çünkü `fields` üretimini tamamen
silseydik — `ApiExceptionRenderer`'daki `if ($e instanceof ValidationException)`
bloğunu kaldırsaydık — "yokluk" testi **yine geçerdi**. Yeşil bir test, çalışan
bir özellik anlamına gelmez.

İkisi birlikte şunu söyler: *"mekanizma çalışıyor **ve** doğru yerde
susuyor."*

Aynı çift `throttle` için de var:

| Test | Ne der |
|---|---|
| `credential_endpoints_are_rate_limited` | Limit **var** |
| `authenticated_endpoints_are_not_throttled_...` | Limit **doğru yerde yok** |

İkincisi olmasa, yanlışlıkla `logout`/`me`'yi de `throttle:auth`'a koysak
kimse fark etmezdi (bkz. [`RevokeTokenAction.md`](../../app/Actions/Auth/RevokeTokenAction.md) §5.1).

---

## 3. Öne çıkan testler

### 3.1 🔴 `login_is_indistinguishable_for_unknown_email_and_wrong_password`

Dosyanın en önemli testi.

```php
$unknownEmail  = $this->postJson(route('auth.login'), ['email' => 'hicyok@...', ...]);
$wrongPassword = $this->postJson(route('auth.login'), ['email' => self::EMAIL, ...]);

// ...

$this->assertSame($unknownEmail->getContent(), $wrongPassword->getContent());
```

Son satır, iki yanıtın **ham gövdesini** karşılaştırıyor. Sadece kodları değil,
**her baytı**.

Neden bu kadar katı? Çünkü sızıntı en beklenmedik yerden gelir: bir gün biri
`debug` bloğuna farklı bir mesaj koyar, ya da bir alan ekler. `assertJsonPath`
yalnızca baktığın yeri kontrol eder; `assertSame` **bakmadığın yerleri de**
kontrol eder.

> `phpunit.xml`'de `APP_DEBUG=false` (T4) olduğu için `debug` bloğu hiç
> üretilmez ve gövdeler gerçekten birebir aynıdır. Testler **üretim kipinde**
> koşuyor olmasa bu test yazılamazdı — Faz 0'da alınan kararın ödemesi.

⚠️ **Bu test zamanlamayı ölçmez.** Süre farkı testte güvenilir ölçülemez
(makine yükü, JIT, ilk çağrıdaki sahte hash üretimi). Zamanlama savunmasının
doğrulaması elle yapılır — bkz.
[`LoginUserAction.md`](../../app/Actions/Auth/LoginUserAction.md) §5.

### 3.2 `logout_revokes_only_the_current_token`

```php
$phone  = $user->createToken('api')->plainTextToken;
$laptop = $user->createToken('api')->plainTextToken;

$this->withToken($phone)->postJson(route('auth.logout'))->assertNoContent();

$this->withToken($phone)->getJson(route('auth.me'))->assertUnauthorized();   // gitti
$this->withToken($laptop)->getJson(route('auth.me'))->assertOk();            // duruyor
```

Değişken adları (`$phone`, `$laptop`) senaryoyu anlatıyor: kullanıcı iki
cihazdan girmiş, birinden çıkıyor.

`withToken()` `Authorization: Bearer <token>` başlığını ekler — gerçek istemcinin
yaptığı şeyin aynısı.

### 3.3 `me_returns_the_wrapped_user` — `actingAs` vs `withToken`

```php
$this->actingAs($user, 'sanctum')->getJson(route('auth.me'))
```

`actingAs()` guard'ı **atlar** ve kullanıcıyı doğrudan yerleştirir. Hızlıdır ama
token doğrulama yolunu test **etmez**.

Bu yüzden iki yöntem bilinçli olarak karışık kullanılıyor:

| Yöntem | Nerede | Neden |
|---|---|---|
| `actingAs($user, 'sanctum')` | Yanıt biçimini test ederken | Token üretmeye gerek yok, hızlı |
| `withToken($plainText)` | Token **iptalini** test ederken | Gerçek token yolu test edilmeli |

`logout_revokes_only_the_current_token` testinde `actingAs` kullansaydık,
`currentAccessToken()` bir `PersonalAccessToken` değil `null` dönerdi ve test
hiçbir şey doğrulamazdı — **yeşil yanan boş bir test**.

### 3.4 `credential_endpoints_are_rate_limited`

```php
foreach (range(1, 5) as $ignored) {
    $this->postJson(route('auth.login'), $payload)->assertUnauthorized();
}

$this->postJson(route('auth.login'), $payload)
    ->assertStatus(429)
    ->assertJsonPath('error.code', ErrorCode::RateLimited->value)
    ->assertJsonStructure(['error' => ['params' => ['retryAfter']]]);
```

Son satır Faz 1'in makinesini doğruluyor: `ErrorCode::RateLimited->allowedParams()`
`['retryAfter']` döndürüyor ve `ApiExceptionRenderer::params()` `Retry-After`
başlığını okuyup zarfa koyuyor. Bir yıl önce yazılan kod, bugün ilk kez test
ediliyor.

> **Testler neden birbirini kilitlemiyor?** `phpunit.xml`'de `CACHE_STORE=array`.
> Rate limiter sayaçları cache'te tutulur; her test yeni bir uygulama örneğiyle
> başladığı için `array` deposu **boş** doğar. `file` sürücüsüyle koşsaydık
> testler birbirinin limitini tüketirdi.

### 3.5 `register_response_never_exposes_the_password`

```php
$this->assertStringNotContainsString(self::PASSWORD, $content);
$this->assertStringNotContainsString('password', $content);
```

İkinci satır daha katı: yalnızca **değerin** değil, **anahtarın** da olmadığını
doğruluyor. `"password": null` gibi bir sızıntı da yakalanır.

Bu bir **sızıntı testidir** (Faz 1'in terimi): bir bilginin yanıta *girmediğini*
doğrular. Normal testler "şu var mı?" der, sızıntı testleri "şu **yok** mu?" der.

---

## 4. Yardımcı metot — `registerPayload()`

```php
private function registerPayload(array $overrides = []): array
{
    return array_merge([
        'firstName' => 'Ayse', 'lastName' => 'Yildirim',
        'email' => self::EMAIL, 'password' => self::PASSWORD,
    ], $overrides);
}
```

Altı test aynı gövdeyi gönderiyor, bazıları tek bir alanı değiştiriyor:

```php
$this->registerPayload(['password' => '123'])
$this->registerPayload(['email' => 'AYSE@Ornek.TEST'])
```

**Kazancı:** yarın `RegisterRequest`'e zorunlu bir alan eklersek **tek yer**
güncellenir. Aksi hâlde altı test birden kırılır ve her biri elle düzeltilir.

Ayrıca testin **niyeti** okunur hâle gelir: çağrı yerinde yalnızca *değişen*
alan görünüyor, geri kalan gürültü kayboluyor.

> `UserFactory` ile aynı fikir — test verisi tanımı tek yerde. Fark: factory
> **model** üretir, bu metot **HTTP gövdesi** üretir.

---

## 5. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| Token iptalini `actingAs` ile test etmek | `currentAccessToken()` null — test boş yeşil | `withToken()` |
| Yalnızca "yokluk" testi yazmak | Özellik silinse de yeşil kalır | T6: çifti de yaz |
| `assertJsonPath` ile yetinmek (enumeration) | Bakmadığın alandan sızar | `assertSame` ile tam gövde |
| Sabit e-posta kullanıp `RefreshDatabase`'i atlamak | İkinci test `UNIQUE` ihlali | `RefreshDatabase` |
| `CACHE_STORE=file` ile koşmak | Testler birbirinin limitini tüketir | `array` (phpunit.xml) |
| Metin doğrulamak | Backend metin döndürmüyor (K20) | Kod / durum / alan adı |
| Düz string hata kodu yazmak | Yazım hatası sessiz kalır | `ErrorCode::X->value` |
| Zamanlama farkını testte ölçmek | Kararsız (flaky) test | Elle ölç, testte davranışı doğrula |

---

## 6. Çalıştırma

```powershell
php artisan test --filter=AuthTest
php artisan test --filter=login_is_indistinguishable   # tek test
composer check                                          # tam zincir
```

Beklenen: **14 test, hepsi yeşil.**

Bir testin gerçekten bir şey koruduğunu görmek için **bilerek kır**:

1. `RegisterRequest`'e `'unique:users,email'` ekle →
   `register_does_not_reveal_that_the_email_is_taken` kırılır.
2. `LoginUserAction`'ın başına `if ($user === null) throw ...` ekle →
   testler **yine geçer** (zamanlama testte ölçülmüyor) ama elle ölçtüğünde
   fark görünür. §3.1'deki uyarının somut anlamı budur.
3. `logout`'u `$user->tokens()->delete()` yap →
   `logout_revokes_only_the_current_token` kırılır.

Her seferinde değişikliği **geri al**.

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Feature test** | Uygulamayı HTTP üzerinden uçtan uca sınayan test |
| **Regresyon testi** | Daha önce düzeltilmiş bir hatanın geri gelmesini engelleyen test |
| **Sızıntı testi** | Bir bilginin yanıta **girmediğini** doğrulayan test |
| **Flaky test** | Kod değişmediği hâlde bazen geçen bazen kalan test |
| **Fixture** | Testin ihtiyaç duyduğu hazır veri |
| **`actingAs`** | Guard'ı atlayıp kullanıcıyı doğrudan yerleştirme |
| **Ayırt edilemezlik** | İki farklı iç durumun dışarıdan aynı görünmesi |

---

## 8. Bağlantılar

| İlgili | Nerede |
|---|---|
| Test temelleri | [`HealthTest.md`](HealthTest.md) |
| Test kuralları | [`FAZ-0.md`](../../fazlar/FAZ-0.md) §4.4 · [`FAZ-1.md`](../../fazlar/FAZ-1.md) §4.4 |
| Zamanlama savunması | [`LoginUserAction.md`](../../app/Actions/Auth/LoginUserAction.md) |
| Token izolasyonu | [`RevokeTokenAction.md`](../../app/Actions/Auth/RevokeTokenAction.md) |
| Hız sınırı | [`AppServiceProvider.md`](../../app/Providers/AppServiceProvider.md) §5.5 |
| Test ortamı | [`phpunit.md`](../../phpunit.md) |
