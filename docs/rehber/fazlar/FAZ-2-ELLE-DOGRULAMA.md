# Faz 2 — Elle Doğrulama Kılavuzu

> **Ne için:** 2.7'den itibaren yazılan uç noktaların **elle** doğrulanması.
> **Ne zaman:** Otomatik testler yeşil yandıktan sonra, faz kapanışından önce.
> **Neden elle?** Otomatik testler davranışı doğrular; bazı şeyler (süre ölçümü,
> gerçek HTTP başlıkları, tarayıcı davranışı) yalnızca elle görülebilir.
> **Bağlantılı:** [`AuthTest.md`](../tests/Feature/AuthTest.md) ·
> [`LoginUserAction.md`](../app/Actions/Auth/LoginUserAction.md)

---

## 0. Hazırlık

### 0.1 Önce otomatik testler

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
composer lint
composer check
```

**Beklenen:** Pint ✓ · PHPStan ✓ · `errors:export --check` ✓ · **21 test** yeşil
(7 HealthTest + 14 AuthTest).

Bu geçmeden aşağıdakilere geçme — elle doğrulama, otomatik testin yerine geçmez.

### 0.2 Temiz veritabanı ve sunucu

```powershell
php artisan migrate:fresh
php artisan serve
```

🔴 **Bu terminali açık bırak.** Aşağıdakilerin tamamı **ikinci bir terminalde**
çalıştırılır.

### 0.3 Yardımcı fonksiyonlar

İkinci terminale bir kez yapıştır:

```powershell
$base = "http://localhost:8000/api"

# Basliklarla birlikte ham cikti verir
function api {
    param($method, $path, $body, $token)
    $f = "$env:TEMP\_api.json"
    $p = @('-s', '-i', '-X', $method, "$base$path", '-H', 'Accept: application/json')
    if ($body)  { Set-Content -Path $f -Encoding ascii -Value $body
                  $p += @('-H', 'Content-Type: application/json', '--data', "@$f") }
    if ($token) { $p += @('-H', "Authorization: Bearer $token") }
    & curl.exe @p
}

# Yalnizca govde, PowerShell nesnesi olarak (token yakalamak icin)
function apiJson {
    param($method, $path, $body, $token)
    $f = "$env:TEMP\_api.json"
    $p = @('-s', '-X', $method, "$base$path", '-H', 'Accept: application/json')
    if ($body)  { Set-Content -Path $f -Encoding ascii -Value $body
                  $p += @('-H', 'Content-Type: application/json', '--data', "@$f") }
    if ($token) { $p += @('-H', "Authorization: Bearer $token") }
    (& curl.exe @p) | ConvertFrom-Json
}

# Yalnizca durum kodu ve sure
function apiTime {
    param($method, $path, $body)
    $f = "$env:TEMP\_api.json"
    Set-Content -Path $f -Encoding ascii -Value $body
    & curl.exe -s -o NUL -w "durum: %{http_code}  sure: %{time_total}s`n" `
        -X $method "$base$path" -H 'Accept: application/json' `
        -H 'Content-Type: application/json' --data "@$f"
}
```

> ⚠️ **Rate limit uyarısı:** `/auth/register` ve `/auth/login` dakikada **5**
> istekle sınırlı (aynı e-posta + IP için) ve **20** (aynı IP için). Art arda
> deneme yaparken `429` alırsan bir dakika bekle — bu bir hata değil, K36'nın
> çalıştığının kanıtı.

---

## 1. Rota kaydı (2.7 · 2.8d · 2.9)

```powershell
php artisan route:list --path=api
```

**Beklenen — 5 rota:**

```
GET|HEAD   api/ping .............. health.ping ..... HealthController
POST       api/auth/register ..... auth.register ... AuthController@register
POST       api/auth/login ........ auth.login ...... AuthController@login
POST       api/auth/logout ....... auth.logout ..... AuthController@logout
GET|HEAD   api/auth/me ........... auth.me ......... AuthController@me
```

✅ **Kanıtladığı:** Rota dosyası doğru yüklendi, `/api` öneki uygulandı (K10),
adlandırma tutarlı (R2).

---

## 2. Kayıt — mutlu yol (2.7)

```powershell
api POST /auth/register '{"firstName":"Ayse","lastName":"Yildirim","email":"AYSE@Ornek.TEST","password":"gizli1234"}'
```

**Beklenen:**

```
HTTP/1.1 201 Created
Content-Type: application/json

{"user":{"id":"1","firstName":"Ayse","lastName":"Yildirim","email":"ayse@ornek.test"},"token":"1|kJ8x..."}
```

✅ **Kanıtladığı — dört şey birden:**

| Gözlem | Kanıt |
|---|---|
| `201`, `200` değil | Yeni kaynak oluştu (§AuthController.md 3.2) |
| `"id":"1"` tırnak içinde | `(string)` cast'i çalışıyor — frontend `id: string` bekliyor |
| `ayse@ornek.test` küçük | `prepareForValidation` + mutator çalışıyor |
| `password` **yok** | Resource beyaz listesi + `#[Hidden]` |
| `data` zarfı **yok** | K11 — `->resolve()` çalışıyor |

---

## 3. 🔴 Enumeration savunması (2.7)

Aynı e-postayı **farklı harflerle**, farklı isimle gönder:

```powershell
api POST /auth/register '{"firstName":"Baska","lastName":"Kisi","email":"ayse@ORNEK.test","password":"baska1234"}'
```

**Beklenen:**

```
HTTP/1.1 422 Unprocessable Content

{"error":{"code":"REGISTRATION_FAILED","debug":{"message":"Registration failed: email already exists.",...}}}
```

✅ **Kanıtladığı:**

- 🔴 **`fields` anahtarı YOK** — saldırgan hangi alanın çakıştığını öğrenemiyor (H6)
- Büyük harfli yazım da aynı kayda düştü — mutator çalışıyor
- `debug` bloğu yalnızca `APP_DEBUG=true` olduğu için görünüyor (H3)

> Sebep (`email already exists`) **yalnızca** `debug` içinde. Üretimde
> `APP_DEBUG=false` olduğunda o blok **hiç üretilmez**.

---

## 4. Karşılaştırma — normal doğrulama hatası (2.7)

```powershell
api POST /auth/register '{"firstName":"Ali","lastName":"Veli","email":"ali@ornek.test","password":"123"}'
```

**Beklenen:**

```
HTTP/1.1 422 Unprocessable Content

{"error":{"code":"VALIDATION_FAILED","fields":{"password":[{"rule":"min","params":{"min":8}}]},...}}
```

✅ **Kanıtladığı — 3. adımla yan yana koy:**

| | Adım 3 | Adım 4 |
|---|---|---|
| Durum | 422 | 422 |
| Kod | `REGISTRATION_FAILED` | `VALIDATION_FAILED` |
| `fields` | ❌ **yok** | ✅ var |

**Aynı uç nokta, aynı durum kodu, farklı ifşa düzeyi.** Parola hatası
kullanıcının *kendi gönderdiği* veri hakkında — söylenebilir. E-posta çakışması
*sistemin durumu* hakkında — söylenemez.

---

## 5. Frontend'in şu an gönderdiği gövde (2.7)

```powershell
api POST /auth/register '{"fullName":"Ayse Yildirim","email":"yeni@ornek.test","password":"gizli1234"}'
```

**Beklenen:** `422` + `fields` içinde `firstName` ve `lastName` için `required`.

✅ **Kanıtladığı:** Bu bir **hata değil, beklenen sonuç**. Frontend hâlâ
`fullName` gönderiyor; K35 sonrası güncellenmedi. Faz 2'nin bitiş ölçütünün son
parçası.

---

## 6. 🔴 Hız sınırı (2.8a · K36)

Bir dakika bekledikten sonra, aynı gövdeyi **altı kez** gönder:

```powershell
1..6 | ForEach-Object {
  apiTime POST /auth/login '{"email":"ayse@ornek.test","password":"yanlis"}'
}
```

**Beklenen:**

```
durum: 401  sure: 0.2s
durum: 401  sure: 0.2s
durum: 401  sure: 0.2s
durum: 401  sure: 0.2s
durum: 401  sure: 0.2s
durum: 429  sure: 0.01s      ← 6. deneme reddedildi
```

`429` gövdesini de gör:

```powershell
api POST /auth/login '{"email":"ayse@ornek.test","password":"yanlis"}'
```

```json
{"error":{"code":"RATE_LIMITED","params":{"retryAfter":47},...}}
```

✅ **Kanıtladığı:**

- Limit tam **5/dakika** (K36)
- `retryAfter` parametresi zarfa girmiş — Faz 1'de yazılan
  `ErrorCode::allowedParams()` + `ApiExceptionRenderer::params()` makinesi çalışıyor
- `Retry-After` HTTP başlığı da yanıtta

---

## 7. Giriş — mutlu yol (2.8d)

Bir dakika bekle, sonra **farklı harflerle** giriş yap:

```powershell
$s = apiJson POST /auth/login '{"email":"  AYSE@Ornek.TEST  ","password":"gizli1234"}'
$s.user
$t = $s.token
$t
```

**Beklenen:** `user` nesnesi + yeni bir token.

✅ **Kanıtladığı:** `prepareForValidation` boşlukları kırpıp küçülttü;
`LoginUserAction` kaydı buldu. Bu adım olmasaydı kullanıcı **doğru parolasıyla**
giriş yapamazdı.

---

## 8. 🔴 Zamanlama saldırısı savunması (2.8c) — en önemli elle test

**Bu, otomatik testlerin ölçemediği tek şey.** Süre farkı testte kararsızdır
(makine yükü, ilk çağrıdaki sahte hash üretimi), bu yüzden burada ölçülür.

### 8.1 Isınma turu

İlk başarısız giriş, sahte hash'i üretirken bir defalık ek maliyet taşır. Önce
bir kez çalıştır ve sonucu **yok say**:

```powershell
apiTime POST /auth/login '{"email":"hicyok@ornek.test","password":"yanlis"}'
```

### 8.2 Ölçüm

```powershell
Write-Host "--- KAYITLI e-posta, yanlis parola ---"
1..3 | ForEach-Object { apiTime POST /auth/login '{"email":"ayse@ornek.test","password":"yanlis"}' }

Start-Sleep -Seconds 65   # rate limit sifirlansin

Write-Host "--- KAYITSIZ e-posta ---"
1..3 | ForEach-Object { apiTime POST /auth/login '{"email":"hicyok@ornek.test","password":"yanlis"}' }
```

**Beklenen:** İki grubun süreleri **birbirine yakın** (örn. 0.20s / 0.21s).
Durum kodları aynı: `401`.

✅ **Kanıtladığı:** Kullanıcı bulunamasa bile Argon2id çalıştırıldı; saldırgan
kronometreyle enumeration yapamıyor.

### 8.3 🔴 Savunmayı bilerek kır ve farkı gör

Bu adım isteğe bağlı ama **çok öğretici**.

`app/Actions/Auth/LoginUserAction.php` içinde `handle()` metodunun **ilk
satırından sonra** geçici olarak şunu ekle:

```php
$user = User::where('email', $credentials['email'])->first();

if ($user === null) { throw new InvalidCredentialsException; }   // ← GEÇİCİ
```

Sunucuyu yeniden başlat, §8.2'yi tekrarla:

```
--- KAYITLI e-posta ---     durum: 401  sure: 0.21s
--- KAYITSIZ e-posta ---    durum: 401  sure: 0.01s      ← 20 KAT HIZLI
```

Gövdeler birebir aynı, ama **süre her şeyi ele veriyor.** Saldırganın gördüğü
tam olarak budur.

🔴 **Değişikliği geri al** ve `composer check` çalıştır.

---

## 9. `me` — token doğrulama (2.9)

```powershell
api GET /auth/me $null $t
```

**Beklenen:**

```
HTTP/1.1 200 OK

{"data":{"id":"1","firstName":"Ayse","lastName":"Yildirim","email":"ayse@ornek.test"}}
```

✅ **Kanıtladığı:** 🔴 **`data` zarfı VAR.** `login`/`register`'dan farklı —
istisna yalnızca o iki uç için geçerli (`CLAUDE.md` §2). İstisnalar
gerekçesiyle taşınır.

Token'sız da dene:

```powershell
api GET /auth/me
```

```
HTTP/1.1 401 Unauthorized
{"error":{"code":"UNAUTHENTICATED",...}}
```

✅ `auth:sanctum` → `AuthenticationException` → `UNAUTHENTICATED`. Faz 1'de
yazılan eşleme bugün ilk kez gerçekten kullanılıyor.

> Dikkat: `INVALID_CREDENTIALS` değil `UNAUTHENTICATED`. İkisi de 401 ama farklı
> olayları anlatıyor — frontend birinde formda kalmalı, diğerinde oturumu
> düşürmeli.

---

## 10. 🔴 Çıkış — token izolasyonu (2.9)

İki cihazdan giriş yapmış gibi iki token al:

```powershell
$g = '{"email":"ayse@ornek.test","password":"gizli1234"}'
$telefon = (apiJson POST /auth/login $g).token
$laptop  = (apiJson POST /auth/login $g).token
```

Telefondan çık:

```powershell
api POST /auth/logout $null $telefon
```

**Beklenen:** `HTTP/1.1 204 No Content` — **gövde yok**.

Şimdi ikisini de dene:

```powershell
curl.exe -s -o NUL -w "telefon: %{http_code}`n" "$base/auth/me" -H 'Accept: application/json' -H "Authorization: Bearer $telefon"
curl.exe -s -o NUL -w "laptop:  %{http_code}`n" "$base/auth/me" -H 'Accept: application/json' -H "Authorization: Bearer $laptop"
```

**Beklenen:**

```
telefon: 401     ← iptal edildi
laptop:  200     ← duruyor
```

✅ **Kanıtladığı:** `RevokeTokenAction` yalnızca `currentAccessToken()`'ı sildi.
`$user->tokens()->delete()` yazsaydık laptop da düşerdi.

Aynı token'la ikinci çıkış denemesi:

```powershell
api POST /auth/logout $null $telefon
```

`401` döner — ve bu **doğru davranıştır**. Frontend `revokeSession()` hatayı
zaten yutuyor; çıkış işlemi ağa bağımlı olmamalı.

---

## 11. Veritabanı tarafı

```powershell
php artisan tinker
```

```php
// Parola gercekten Argon2id mi?
$u = App\Models\User::firstWhere('email', 'ayse@ornek.test');
str_starts_with($u->password, '$argon2id$');     // true
Hash::check('gizli1234', $u->password);          // true

// Token veritabaninda HAM mi duruyor?
$u->tokens()->first()->token;
// 64 karakterlik sha256 hash — "1|kJ8x..." DEGIL

// Kolonlar ayri mi?
$u->first_name;    // "Ayse"
$u->last_name;     // "Yildirim"

// Kalan token sayisi (10. adimdan sonra)
$u->tokens()->count();
```

✅ **Kanıtladığı:** K32 uygulandı · token'ın ham hâli veritabanında **yok** ·
K35 kolonları ayrı.

`exit` ile çık.

---

## 12. Kontrol listesi

| # | Doğrulama | ✓ |
|---|---|:---:|
| 0 | `composer check` yeşil, 21 test | ⬜ |
| 1 | 5 rota kayıtlı | ⬜ |
| 2 | Kayıt `201`, `id` string, e-posta küçük, `data` zarfı yok | ⬜ |
| 3 | 🔴 Mükerrer kayıt → `REGISTRATION_FAILED`, `fields` **yok** | ⬜ |
| 4 | Kısa parola → `VALIDATION_FAILED`, `fields` **var** | ⬜ |
| 5 | `fullName` gövdesi → `422` (beklenen) | ⬜ |
| 6 | 🔴 6. denemede `429` + `retryAfter` | ⬜ |
| 7 | Büyük harfli e-postayla giriş çalışıyor | ⬜ |
| 8 | 🔴 Kayıtlı/kayıtsız e-posta süreleri **yakın** | ⬜ |
| 9 | `me` → `data` zarfı **var**; token'sız → `UNAUTHENTICATED` | ⬜ |
| 10 | 🔴 Çıkış `204`; telefon `401`, laptop `200` | ⬜ |
| 11 | DB'de Argon2id hash + sha256 token | ⬜ |

---

## 13. Otomatik testlerin kapsamadığı şeyler

Dürüstlük payı — `AuthTest` şunları **doğrulamıyor**:

| Konu | Neden test edilmiyor | Nerede doğrulanır |
|---|---|---|
| **Zamanlama farkı** | Testte kararsız (flaky) ölçüm | §8 — elle |
| `Retry-After` **başlığı** | Yalnızca gövdedeki `params` test ediliyor | §6 |
| Gerçek HTTP başlıkları | Test istemcisi framework içinde | §2, §10 |
| Tarayıcı/frontend entegrasyonu | Backend testinin kapsamı dışı | Faz 2 bitiş ölçütü |

---

## 14. Bağlantılar

| İlgili | Nerede |
|---|---|
| Otomatik testler | [`AuthTest.md`](../tests/Feature/AuthTest.md) |
| Zamanlama savunması | [`LoginUserAction.md`](../app/Actions/Auth/LoginUserAction.md) §1-2 |
| Token izolasyonu | [`RevokeTokenAction.md`](../app/Actions/Auth/RevokeTokenAction.md) §2.3 |
| Hız sınırı (K36) | [`AppServiceProvider.md`](../app/Providers/AppServiceProvider.md) §5.5 |
| Komut referansı | [`kavramlar/komutlar.md`](../kavramlar/komutlar.md) |
