# `app/Actions/Auth/RevokeTokenAction.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Actions/Auth/RevokeTokenAction.php`
> (+ `AuthController::logout()`, `AuthController::me()`, `routes/api.php`)
> **Yol haritasındaki yeri:** Faz 2, dosya 2.9 — **Faz 2'nin son kod dilimi**
> **Bağlantılı:** [`LoginUserAction.md`](LoginUserAction.md) ·
> [`AuthController.md`](../../Http/Controllers/Api/V1/AuthController.md) ·
> [`config/sanctum.md`](../../../config/sanctum.md)

---

## 0. Bir dakikalık özet

```php
$token = $user->currentAccessToken();

if ($token instanceof PersonalAccessToken) {
    $token->delete();
}
```

Üç satır — ve her satırın bir gerekçesi var.

Bu dilim üç şey ekliyor:

| Uç nokta | Ne yapar | Yanıt |
|---|---|---|
| `POST /api/auth/logout` | Yalnızca **o anki** token'ı siler | `204 No Content` |
| `GET /api/auth/me` | Token geçerli mi? Kim bu? | `200` + `{data: {...}}` |

---

## 1. Neden "çıkış" sunucuda bir iş gerektiriyor?

JWT kullansaydık gerekmezdi — ve K5 kararının tam sebebi budur.

| | JWT | **Sanctum (bizim seçimimiz)** |
|---|---|---|
| Token nerede doğrulanır | İmzasından, **sunucuya sormadan** | `personal_access_tokens` tablosundan |
| Çıkış nasıl olur | Sadece istemci onu **unutur** | Sunucu kaydı **siler** |
| Çalınan token | Süresi dolana kadar **geçerli kalır** | Anında iptal edilebilir |

JWT'de "çıkış yaptım" demek, "token'ı çöpe attım" demektir. Token birinin eline
geçmişse çöpe atmak onu durdurmaz.

Frontend'in `services/auth.ts` dosyası bunu zaten bekliyor:

```ts
/**
 * Best-effort server-side token revocation (POST /auth/logout).
 */
revokeSession(token: string): void;
```

*"Server-side token revocation"* — sunucu tarafında iptal. Bu satır, K5'in
frontend'den okunan gerekçesiydi.

---

## 2. Kodun her satırı

### 2.1 `currentAccessToken()` nedir?

```php
$token = $user->currentAccessToken();
```

Bu metot `HasApiTokens` trait'inden geliyor ve **isteği taşıyan** token'ı verir:

```php
// vendor/laravel/sanctum/src/HasApiTokens.php
public function currentAccessToken()
{
    return $this->accessToken;
}
```

`$this->accessToken` nereden doldu? Sanctum'un `Guard`'ı, `Authorization: Bearer`
başlığını çözdüğünde onu modele **iliştirir**:

```php
// vendor/laravel/sanctum/src/Guard.php
$tokenable = $accessToken->tokenable->withAccessToken($accessToken);
```

Yani `$user` nesnesi, "hangi token ile geldim" bilgisini taşıyor. Kullanıcının
diğer token'ları bu değişkende **yok**.

### 2.2 🔴 `instanceof PersonalAccessToken` — neden şart?

`currentAccessToken()` üç farklı şey döndürebilir:

| Dönen | Ne zaman | `delete()` var mı |
|---|---|---|
| `PersonalAccessToken` | `Authorization: Bearer ...` ile geldiyse | ✅ Eloquent modeli |
| `TransientToken` | Çerez tabanlı SPA kipinde (`statefulApi()`) | ❌ **YOK** |
| `null` | Guard hiç çalışmadıysa | ❌ |

`TransientToken`'ın tamamı şudur:

```php
class TransientToken implements HasAbilities
{
    public function can($ability) { return true; }
    public function cant($ability) { return false; }
}
```

Veritabanında **kaydı yoktur** — çerezle gelen oturumun "sanki token varmış gibi"
davranmasını sağlayan bir yer tutucudur. `delete()` metodu yoktur; kontrolsüz
çağrı `Error: Call to undefined method` → **500** verir.

Şu an `statefulApi()` kullanmıyoruz, yani pratikte hep `PersonalAccessToken`
gelir. Kontrol yine de duruyor:

> **Genel ilke:** Bir kütüphane metodunun **birden çok tip** döndürebildiğini
> öğrendiysen, kullandığın tipi doğrulamak bir savunma değil, **doğruluk**
> meselesidir. "Bizde hep şu gelir" varsayımı, yapılandırma değiştiği gün
> sessizce yanlışlanır.

### 2.3 Neden `$user->tokens()->delete()` değil?

```php
$user->tokens()->delete();     // ❌ TÜM token'ları siler
```

Bu, kullanıcıyı **her cihazdan** çıkarırdı. Telefondan çıkış yapan biri
bilgisayardaki oturumunu da kaybederdi.

Doğru davranış: her cihaz kendi token'ıyla gelir, kendi token'ını siler.

> "Tüm cihazlardan çıkış yap" ayrı bir özelliktir ve kullanıcı **isteyerek**
> tetiklemelidir (parola değişikliği, çalıntı cihaz). Faz 2'nin kapsamında yok.

---

## 3. `logout()` — controller tarafı

```php
public function logout(Request $request, RevokeTokenAction $action): Response
{
    /** @var User $user  auth:sanctum burada null OLAMAYACAGINI garanti eder. */
    $user = $request->user();

    $action->handle($user);

    return response()->noContent();
}
```

### 3.1 `@var User $user` — bir kaçış kapısı ve gerekçesi

`$request->user()` imzası `Authenticatable|null` döndürür. `RevokeTokenAction`
ise `User` bekliyor. PHPStan haklı olarak itiraz ederdi.

Neden `null` olamaz? Çünkü rota `auth:sanctum` middleware'i altında. Middleware
kimliği doğrulayamazsa `AuthenticationException` fırlatır ve controller **hiç
çağrılmaz**. Yani bu satıra ulaşıldıysa kullanıcı kesin vardır.

Bu bilgi **rota tanımında** yaşıyor, tip sisteminde değil. `@var` o boşluğu
kapatıyor — bkz. [`php-dili.md`](../../../kavramlar/php-dili.md) §13.2.

### 3.2 `204 No Content` neden?

Silme işleminin döndürecek bir şeyi yok. `200` + `{"message": "ok"}` da
yazabilirdik ama:

- K20 gereği **metin döndürmüyoruz**
- `{"success": true}` gibi bir gövde, durum kodunun zaten söylediğini tekrarlar
- Frontend zaten *fire-and-forget* çağırıyor, gövdeye bakmıyor

`204`, HTTP'nin "başarılı, gövde yok" demenin standart yoludur.

### 3.3 🔴 Çıkış **idempotent** olmalı

Aynı token'la iki kez `logout` çağrılırsa ne olur?

```
1. çağrı:  token bulundu, silindi        → 204
2. çağrı:  token yok → auth:sanctum      → 401 UNAUTHENTICATED
```

İkincisinin 401 dönmesi doğrudur ve frontend'i bozmaz: `revokeSession()`
hatayı yutuyor —

```ts
.catch(() => {
    // Token already expired/revoked server-side — nothing to do.
});
```

Frontend zaten kendi durumunu **çağrıdan önce** temizliyor. Bu bilinçli bir
tasarım: çıkış işlemi ağa bağımlı olmamalı. İnternet yoksa da kullanıcı çıkmış
sayılır.

---

## 4. `me()` — ve zarf kuralının sınırı

```php
public function me(Request $request): UserResource
{
    /** @var User $user */
    $user = $request->user();

    return new UserResource($user);
}
```

🔴 Dikkat: burada **`->resolve()` YOK**. Yani yanıt zarflıdır:

```json
{ "data": { "id": "1", "firstName": "Ayse", "lastName": "Yildirim", "email": "..." } }
```

**Neden `login`/`register`'dan farklı?** `CLAUDE.md` §2 istisnayı **ad ad**
sayıyor:

> **Auth (`/auth/login`, `/auth/register`):** `{ data: ... }` zarfı OLMADAN…
> **Diğer Tüm API Yanıtları:** `{ data: ... }` zarfı ile döner.

`/auth/me` bu iki addan biri değil. İstisna genişletilmedi, çünkü istisnanın
sebebi "auth altında olmak" değil — **frontend'in `services/auth.ts` dosyasının
`data.user` okuması**. `me` frontend tarafından şu an hiç çağrılmıyor,
dolayısıyla bağlayıcı bir beklentisi yok.

> **Genel ilke:** İstisnalar **gerekçesiyle birlikte** taşınır. Gerekçe
> geçmiyorsa istisna da geçmez. "Aynı klasördeler" bir gerekçe değildir.

`me` ne işe yarar? Frontend `localStorage`'daki token'la açıldığında *"bu token
hâlâ geçerli mi?"* diye sorar. `200` → oturum devam; `401` → token iptal
edilmiş, giriş ekranına dön.

---

## 5. Rota yapısı — iki ayrı tehdit modeli

```php
Route::prefix('auth')->name('auth.')->group(function (): void {
    // Kimlik BİLGİSİ kabul eden uçlar: brute-force hedefi
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/register', ...)->name('register');
        Route::post('/login', ...)->name('login');
    });

    // Geçerli token gerektiren uçlar: tehdit modeli farklı
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', ...)->name('logout');
        Route::get('/me', ...)->name('me');
    });
});
```

### 5.1 🔴 `throttle:auth` neden `logout`/`me`'yi kapsamıyor?

İlk yazımda dört rota da tek bir `throttle:auth` grubundaydı. **Bu bir hataydı**
ve limiter'ın anahtarına bakınca ortaya çıkıyor:

```php
$identity = is_string($email) ? mb_strtolower(trim($email)) : 'anonim';
Limit::perMinute(5)->by($identity.'|'.$request->ip());
```

`logout` ve `me` istekleri gövdesinde `email` **taşımaz**. Dolayısıyla anahtar
herkes için `'anonim|IP'` olurdu — **aynı IP'deki tüm kullanıcılar tek bir 5/dakika
kovasını paylaşırdı.** Ofis ağındaki on kişi birbirinin oturumunu kilitlerdi.

Ayrı gruplamak bunu çözüyor ve zaten doğru olanı yapıyor: **iki uç ailesinin
tehdit modeli farklı.**

| Uç ailesi | Tehdit | Savunma |
|---|---|---|
| `register`, `login` | Parola tahmini, hesap tarama | Hız sınırı (K36) |
| `logout`, `me` | *(geçerli token gerekiyor)* | `auth:sanctum` |

Geçerli token'ı olan biri zaten kimliği doğrulanmış bir kullanıcıdır; onu
kısıtlamak brute-force savunması değil, genel API kotasıdır.

> ⚠️ **Bilinen boşluk:** `logout`/`me` şu an tamamen sınırsız. Genel API hız
> sınırı (`throttleApi()`) Faz 5'in konusu — orada bu uçlar da kapsanacak.

### 5.2 `auth:sanctum` ne yapar?

`auth` middleware'i, adı verilen **guard**'ı kullanarak kimlik doğrular.
`sanctum` guard'ı `Authorization: Bearer <token>` başlığını okur, token'ın
sha256 hash'ini `personal_access_tokens` tablosunda arar, bulursa
`$request->user()`'ı doldurur.

Başarısızsa `AuthenticationException` fırlatır → `ApiExceptionRenderer` →
**401 `UNAUTHENTICATED`**. Faz 1'de yazılan bu eşleme, bugün ilk kez gerçekten
kullanılıyor.

### 5.3 M4 kuralı hatırlatması

Faz 1'in **M4** kuralı: *"Kaynağa bağlı yetki kararı middleware'de verilmez."*

`auth:sanctum` bir **kimlik** kontrolüdür ("sen kimsin?"), **yetki** kontrolü
değil ("bunu yapabilir misin?"). Kural ihlal edilmiyor. Faz 3'te
`InvitationPolicy` gerçek yetki kontrolünü yapacak.

---

## 6. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `$user->tokens()->delete()` | Kullanıcı **tüm cihazlardan** çıkar | Yalnızca `currentAccessToken()` |
| `instanceof` kontrolünü atlamak | `TransientToken` gelirse 500 | Tip kontrolü |
| `me()`'de `->resolve()` kullanmak | Zarf kaybolur, kural ihlali | Zarflı bırak |
| `logout`'u `throttle:auth`'a koymak | Aynı IP'deki kullanıcılar birbirini kilitler | Ayrı grup |
| `logout`'u `auth:sanctum` dışında bırakmak | Kimin token'ı silinecek belirsiz | Middleware şart |
| `@var` yazmadan `$request->user()` | PHPStan `Authenticatable\|null` hatası | Docblock + gerekçe |
| `200` + `{"success":true}` döndürmek | Gövde durum kodunu tekrarlar | `204` |
| İkinci `logout`'un 401 dönmesini hata saymak | Doğru davranış | Frontend zaten yutuyor |

---

## 7. Kendin dene

```powershell
php artisan migrate:fresh
php artisan serve
```

İkinci terminalde:

```powershell
# 1) Kayıt ol ve token'ı yakala
Set-Content -Path "$env:TEMP\k.json" -Encoding ascii -Value '{"firstName":"Ayse","lastName":"Yildirim","email":"ayse@ornek.test","password":"gizli1234"}'
$r = curl.exe -s -X POST http://localhost:8000/api/auth/register -H "Content-Type: application/json" -H "Accept: application/json" --data "@$env:TEMP\k.json" | ConvertFrom-Json
$t = $r.token
$t
```

**2. `me` çalışıyor mu?** — zarfa dikkat:

```powershell
curl.exe -i http://localhost:8000/api/auth/me -H "Accept: application/json" -H "Authorization: Bearer $t"
# 200  {"data":{"id":"1","firstName":"Ayse",...}}      ← data ZARFI VAR
```

**3. Token'sız `me`:**

```powershell
curl.exe -i http://localhost:8000/api/auth/me -H "Accept: application/json"
# 401  {"error":{"code":"UNAUTHENTICATED"}}
```

**4. Çıkış:**

```powershell
curl.exe -i -X POST http://localhost:8000/api/auth/logout -H "Accept: application/json" -H "Authorization: Bearer $t"
# 204  (gövde yok)
```

**5. 🔴 Token gerçekten iptal oldu mu?**

```powershell
curl.exe -i http://localhost:8000/api/auth/me -H "Accept: application/json" -H "Authorization: Bearer $t"
# 401  ← aynı token artık geçersiz
```

**6. Diğer cihaz etkilendi mi?** İki kez giriş yapıp birinden çıkarak dene:

```powershell
Set-Content -Path "$env:TEMP\g.json" -Encoding ascii -Value '{"email":"ayse@ornek.test","password":"gizli1234"}'
$a = (curl.exe -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" -H "Accept: application/json" --data "@$env:TEMP\g.json" | ConvertFrom-Json).token
$b = (curl.exe -s -X POST http://localhost:8000/api/auth/login -H "Content-Type: application/json" -H "Accept: application/json" --data "@$env:TEMP\g.json" | ConvertFrom-Json).token

curl.exe -s -o NUL -w "A cikis: %{http_code}`n" -X POST http://localhost:8000/api/auth/logout -H "Accept: application/json" -H "Authorization: Bearer $a"
curl.exe -s -o NUL -w "A me:    %{http_code}`n" http://localhost:8000/api/auth/me -H "Accept: application/json" -H "Authorization: Bearer $a"
curl.exe -s -o NUL -w "B me:    %{http_code}`n" http://localhost:8000/api/auth/me -H "Accept: application/json" -H "Authorization: Bearer $b"
```

Beklenen: `204`, `401`, **`200`** — B'nin oturumu ayakta. §2.3'ün kanıtı.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Token iptali (revocation)** | Sunucunun bir token'ı geçersiz kılması |
| **Bearer token** | `Authorization: Bearer <token>` ile taşınan kimlik |
| **Guard** | Laravel'de kimlik doğrulama stratejisi |
| **Kimlik doğrulama** | "Sen kimsin?" — authentication |
| **Yetkilendirme** | "Bunu yapabilir misin?" — authorization |
| **Idempotent** | Tekrarı ek etki üretmeyen işlem |
| **`204 No Content`** | Başarılı, döndürülecek gövde yok |
| **Fire-and-forget** | Sonucu beklenmeyen çağrı |
| **Tehdit modeli** | Bir uç noktanın hangi saldırıya açık olduğunun analizi |

---

## 9. Bağlantılar

| İlgili | Nerede |
|---|---|
| Token üretimi | [`LoginUserAction.md`](LoginUserAction.md) · [`RegisterUserAction.md`](RegisterUserAction.md) |
| Controller tarafı | [`AuthController.md`](../../Http/Controllers/Api/V1/AuthController.md) |
| Rate limit tanımı (K36) | [`AppServiceProvider.md`](../../Providers/AppServiceProvider.md) §5.5 |
| Sanctum ayarları | [`config/sanctum.md`](../../../config/sanctum.md) |
| Zarf politikası | [`CLAUDE.md`](../../../../../CLAUDE.md) §2 |
| Sıradaki dosya | `tests/Feature/AuthTest.php` (2.10) — **Faz 2'nin kanıtı** |
