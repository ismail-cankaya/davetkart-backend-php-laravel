# `routes/api.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `routes/api.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.4 · **Faz 2, dosya 2.7** (auth grubu)
> **Bağlantılı:** [`bootstrap/app.md`](../bootstrap/app.md) ·
> [`ForceJsonResponse.md`](../app/Http/Middleware/ForceJsonResponse.md) ·
> [`AuthController.md`](../app/Http/Controllers/Api/V1/AuthController.md)

---

## 0. Bir dakikalık özet

Bu dosya bir **telefon rehberi**dir: hangi URL'nin hangi kodu çağıracağını söyler.
Başka hiçbir iş yapmaz — mantık, doğrulama, yetki kontrolü buraya yazılmaz.

Faz 1 sonunda içinde tek bir rota var:

```php
Route::get('/ping', fn () => ['status' => 'ok'])->name('health.ping');
```

Faz 9 sonunda 20 rota olacak. Dosyanın **işi** değişmeyecek.

---

## 0.1 Faz 2 eklemesi — auth grubu 🎯

```php
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
});
```

Bu üç satır, Faz 2'de yazılan **yedi dosyayı birbirine bağlayan** halkadır.
`/api/auth/register` adresi bu satırla var olur; öncesinde uygulamada böyle bir
adres yoktu.

### Üretilen rota

| Method | URI | Ad | Controller |
|---|---|---|---|
| POST | `api/auth/register` | `auth.register` | `AuthController@register` |

`prefix('auth')` URI'ye, `name('auth.')` rota adına önek ekler. Sonuç:
`/api` (bootstrap'tan) + `/auth` (buradan) + `/register`.

### 🔴 `group(function () { ... })` — R1'i ihlal ediyor mu?

**Hayır.** Bu ayrım kafa karıştırıcıdır, netleştirelim.

**R1:** *"Rota dosyasına closure yazılmaz, controller referansı yazılır."*
Gerekçesi `route:cache`'in closure'ları serileştirememesiydi (Faz 9).

Ama iki farklı closure türü var:

| Closure | Ne zaman çalışır | `route:cache` | Örnek |
|---|---|---|---|
| **Rota eylemi** | Her istekte | 🔴 **Kırar** | `Route::get('/x', fn () => ...)` |
| **Grup tanımı** | Yalnızca **kayıt anında** | ✅ Sorunsuz | `Route::prefix(...)->group(fn () => ...)` |

Grup closure'ı, rotalar kaydedilirken **bir kez** çalışır ve içindeki
`Route::post(...)` satırlarını tanımlar. İşi bittiğinde atılır — `Route`
nesnesinde saklanmaz, dolayısıyla serileştirilecek bir şey kalmaz.

Serileştirilen şey **rota eylemidir** (`[AuthController::class, 'register']`)
ve o bir dizi, closure değil.

> **Genel ders:** Bir kuralı uygularken **lafzını değil gerekçesini** takip et.
> R1'in gerekçesi "closure yasak" değil, *"`route:cache` kırılmasın"*dı. Gerekçe
> sağlanıyorsa lafzın ihlali görünürdedir, gerçek değildir.

### Grup neden tek rota için kuruldu?

2.8 ve 2.9'da üç rota daha gelecek ve ikisi `auth:sanctum` middleware'i
isteyecek:

```php
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AuthController::class, 'me'])->name('me');
    });
});
```

Şekli şimdi kurmak, sonraki iki adımı **satır eklemeye** indirger.

### ⚠️ Bilinen boşluk — rate limiting yok

Laravel 11+ ile `api` middleware grubu **varsayılan olarak throttle içermez**;
`bootstrap/app.php`'de `$middleware->throttleApi()` çağrılmadıkça hız sınırı
yoktur. Bizde çağrılmıyor.

Bugün için kabul edilebilir, ama `POST /auth/login` eklendiğinde bu bir
**brute-force açığı** hâline gelir: saldırgan dakikada binlerce parola
deneyebilir. Yol haritası rate limit kaydını Faz 5'e koyuyor — 2.8'de bu takvimi
yeniden değerlendireceğiz.

---

## 1. `/api` öneki nereden geliyor?

Dosyada `/ping` yazıyor ama endpoint `/api/ping`. Önek bu dosyada değil,
`bootstrap/app.php`'de tanımlı:

```php
->withRouting(
    api: __DIR__.'/../routes/api.php',   // ← bu dosyayı 'api' grubuna yükler
)
```

Laravel `api` grubuna yüklenen her rotaya otomatik olarak `/api` önekini ekler.

🔴 **K10'un mümkün olmasının sebebi budur.** Sözleşme gereği rotalar `/api/...`
olmalı, `/api/v1/...` **olmamalı** — frontend'in `baseURL`'i `'/api'`. Sürüm
bilgisi controller namespace'inde taşınır:

```
URL:       /api/auth/login                      ← düz, sürümsüz
Namespace: App\Http\Controllers\Api\V1\AuthController   ← sürüm burada
```

Yarın v2 gerekirse `Api\V2\` namespace'i açılır ve `/api/v2/...` **ek** olarak
tanımlanır; `/api/...` v1 olarak yaşamaya devam eder. Bugünkü frontend kırılmaz.

---

## 2. Kod okuması

### 2.1 `Route::get(...)` — cephe (facade) nedir?

```php
use Illuminate\Support\Facades\Route;
```

`Route` bir **facade**'dır: arkasındaki gerçek nesneye statik görünümlü bir kısayol
sağlar. `Route::get()` yazdığında Laravel bunu perde arkasında
`app('router')->get()` çağrısına çevirir.

Neden böyle? Okunabilirlik. Alternatifi her rota satırında konteynerden router
çekmek olurdu. Facade'ler Laravel'in imza özelliklerinden biridir.

### 2.2 Dizi döndürmek neden yeterli?

```php
fn () => ['status' => 'ok']
```

`response()->json([...])` yazılmadı. Laravel bir rotadan dizi döndüğünü görürse
onu **otomatik olarak** `JsonResponse`'a çevirir, `Content-Type: application/json`
başlığını ekler ve `200` durum kodu verir.

Aynı otomatik dönüşüm `Arrayable` ve `JsonSerializable` arayüzlerini uygulayan
nesneler için de çalışır — Faz 2'den itibaren `UserResource` döndürebilmemizin
sebebi budur.

### 2.3 `->name(...)` neden var?

```php
->name('health.ping');
```

Rotaya bir **isim** verir. Faydası testlerde ve kod içinde görülür:

```php
$this->getJson(route('health.ping'));   // ✅ URL değişse de test çalışır
$this->getJson('/api/ping');            // ❌ URL değişince test kırılır
```

İsimlendirme kuralı `nokta.ile.gruplama`: `health.ping`, `auth.login`,
`invitations.store`. Modül adı önce gelir — bir modülün tüm rotalarını aramak
kolaylaşır.

### 2.4 `fn () =>` — ok fonksiyonu

PHP 7.4 ile geldi. `function () { return ...; }`'ın kısa hâlidir ve **tek ifade**
içerir; `return` yazılmaz.

```php
fn () => ['status' => 'ok']
// eşdeğeri:
function () { return ['status' => 'ok']; }
```

---

## 3. Tasarım kararları

### 3.1 🔴 Varsayılan `/user` rotası neden silindi?

Laravel iskeleti şu rotayla gelir:

```php
// ❌ Silindi
Route::get('/user', fn (Request $request) => $request->user())
    ->middleware('auth:sanctum');
```

İki sorunu vardı:

**1. Ham modeli döndürüyor.** `$request->user()` bir `User` **modelidir**. JSON'a
çevrildiğinde `$hidden` dizisinde olmayan **tüm** kolonlar dışarı çıkar. Bugün
zararsız görünür; Faz 7'de `users` tablosuna bir `stripe_customer_id` veya
`admin_notes` kolonu eklendiğinde o da sessizce sızar.

Sözleşme gereği alan adları **camelCase** olmalı ve dönüşüm **yalnızca Resource
katmanında** yapılmalı. Bu rota `full_name` döndürürdü — snake_case, yani
sözleşme ihlali.

**2. Belgesiz bir endpoint.** Endpoint tablosunda (`03` §4.3) `/api/user` yok;
`GET /api/auth/me` var. İkisi aynı işi yapıyor ama biri planda, biri kazara orada.

> **Genel kural:** Framework iskeletinden gelen örnek kodlar **silinir**. "Zararsız,
> dursun" diyerek bırakılan her satır bir gün bir sürprizin kaynağı olur.

Karşılığı Faz 2'de `AuthController::me()` olarak, `UserResource` ile birlikte
yazılacak.

### 3.2 `/ping` yanıtı neden `{data: ...}` zarfında değil?

K11 der ki: auth endpoint'leri zarfsız, **diğerleri** `{data: ...}` zarfıyla.
`/ping` ise düz `{"status":"ok"}` dönüyor. Çelişki mi?

Hayır. Zarf kuralı **kaynak temsilleri** (resource representations) içindir —
bir davetiye, bir kullanıcı, bir LCV listesi. `/ping` bir kaynak döndürmez; bir
**canlılık sondası**dır (liveness probe). Frontend onu hiç çağırmaz.

`{data: {status: 'ok'}}` yazmak, kuralı anlamadan uygulamak olurdu.

### 3.3 🔴 `/ping` yanıtına sürüm bilgisi neden eklenmedi?

Sağlık endpoint'lerine sürüm eklemek yaygın bir alışkanlıktır:

```php
// ❌ Yapmadığımız
fn () => ['status' => 'ok', 'version' => app()->version(), 'php' => PHP_VERSION]
```

`08` §3.3 bunu açıkça yasaklar: *"Sürüm bilgisi (PHP, Laravel) → hiçbir yere."*

Sebep: bilinen bir güvenlik açığı yayınlandığında saldırganlar **savunmasız sürümü
çalıştıran sunucuları taramak** için tam olarak bu bilgiyi kullanır. Kimliği
doğrulanmamış bir endpoint'ten sürüm yayınlamak, bu taramaya gönüllü olmaktır.

Sürüm bilgisine ihtiyaç duyulursa: kimlik doğrulamalı bir yönetim endpoint'i veya
sunucu tarafı log'lar.

### 3.4 `/up` ile `/api/ping` farkı

`bootstrap/app.php` içinde `health: '/up'` tanımlı — Laravel'in hazır sağlık
kontrolü. Neden ikisi birden?

| | `/up` | `/api/ping` |
|---|---|---|
| Kim yazdı | Laravel | Biz |
| Neyi doğrular | **Framework** ayakta mı | **Bizim API sözleşmemiz** çalışıyor mu |
| Neyden geçer | Web grubu | `api` grubu → `ForceJsonResponse` |
| Yanıt | HTML | JSON |

`/api/ping` çalışıyorsa yalnızca PHP ayakta değil; middleware zinciri, rota grubu
ve JSON dönüşümü de doğrulanmış olur. Yük dengeleyici sondası olarak da bu
kullanılır.

---

## 4. ⚠️ Bilinen borç: closure ve `route:cache`

```php
Route::get('/ping', fn () => ['status' => 'ok'])   // ← closure
```

Faz 9'da (`9.2`) üretim optimizasyonu olarak `php artisan route:cache` çalıştırılacak.
Bu komut rotaları serileştirip diske yazar — ve **closure'lar serileştirilemez**:

```
LogicException: Unable to prepare route [api/ping] for serialization. Uses Closure.
```

Yani bu satır Faz 9'da komutu **kırar**.

### Çözüm seçenekleri

| Seçenek | Maliyet | Ne zaman |
|---|---|---|
| **A** — `HealthController` (invokable) yaz | +1 dosya (~12 satır) | Şimdi |
| **B** — Faz 9'da dönüştür | 0 | Faz 9 |

> **Neden şimdi karar veriliyor?** Faz 9'da bu satırın varlığı unutulacak; komut
> hata verdiğinde sebebi aramak zaman kaybı olur. Karar ne olursa olsun **kayda
> geçmesi** gerekir — bu bölüm o kayıttır.

**Genel kural (Faz 2'den itibaren):** Rota dosyalarına closure yazılmaz, controller
referansı yazılır. `/ping` bu kuralın bilinçli ve tek istisnasıdır.

---

## 5. Rota dosyasına ne yazılır, ne yazılmaz?

| ✅ Yazılır | ❌ Yazılmaz |
|---|---|
| URL → controller eşlemesi | İş mantığı |
| Middleware ataması | Veritabanı sorgusu |
| Rota grupları ve önekler | `if` blokları |
| Rota isimleri | Doğrulama |
| Model binding tanımları | Yanıt biçimlendirme |

Rota dosyası **okunduğunda API'nin yüzeyi anlaşılmalıdır**. 40 satırlık bir closure
oraya konursa dosya haritalık işlevini kaybeder.

---

## 6. Faz 1 sonrası nasıl büyüyecek?

```php
// Faz 2
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Faz 3
    Route::apiResource('invitations', InvitationController::class);
});

// Faz 4 — K12: auth'suz rotalar açıkça işaretli
Route::prefix('public')->group(function () {
    Route::get('/invitations/{slug}', [PublicInvitationController::class, 'show']);
});
```

🔴 **`auth:sanctum` grubunun varsayılan olması bilinçli.** Yeni bir rota yazan kişi
onu grup içine koyarsa korumalı olur; dışına koymak **açık bir eylem** gerektirir.
`/api/public/` öneki bu açıklığı görünür kılar — K12'nin fail-safe tasarımı.

---

## 7. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `/api/v1/ping` yazmak | Frontend `baseURL='/api'` — anında kırılır | `/ping` |
| Dosyada `/api` öneki tekrarlamak | `/api/api/ping` | Önek `withRouting()`'de |
| Closure içine iş mantığı yazmak | Test edilemez, `route:cache` kırılır | Controller |
| `/ping`'e sürüm bilgisi eklemek | Sürüm taraması için hedef olur | Eklenmez |
| Ham model döndürmek | Yeni kolonlar sessizce sızar | Resource katmanı |
| Rotayı `auth:sanctum` grubunun dışına koymak | Korumasız endpoint | Grup içine, istisna `/public/` |
| Rota ismi vermemek | Testler URL'e bağlanır | `->name(...)` |

---

## 8. Kendin dene

```powershell
php artisan serve
```

```powershell
curl.exe http://localhost:8000/api/ping
# {"status":"ok"}

curl.exe -H "Accept: text/html" http://localhost:8000/api/olmayan
# {"error":{"code":"RESOURCE_NOT_FOUND",...}}     ← HTML değil

curl.exe -X POST http://localhost:8000/api/ping
# {"error":{"code":"RESOURCE_NOT_FOUND"}}          ← yanlış metot da 404 (H7)
```

Kayıtlı rotaları listele:

```powershell
php artisan route:list --path=api
```

Çıktıda `GET|HEAD  api/ping  health.ping` görünmeli. `HEAD` otomatik gelir —
HTTP'de her `GET` gövdesiz karşılığını da tanımlar.

**Kasten kır:** `->name('health.ping')` kısmını sil ve `php artisan route:list`
çalıştır. İsim sütununun boşaldığını göreceksin; 1.5'te yazacağımız test
`route('health.ping')` çağırdığı için o test de patlar. Sonra geri ekle.

---

## 9. Sözlük

| Terim | Anlamı |
|---|---|
| **Rota (route)** | URL + HTTP metodu → çalışacak kod eşlemesi |
| **Facade** | Konteynerdeki nesneye statik görünümlü kısayol (`Route::`) |
| **Ok fonksiyonu** | `fn () => ifade` — tek ifadelik anonim fonksiyon |
| **Rota grubu** | Ortak önek/middleware paylaşan rota kümesi |
| **Liveness probe** | Servisin ayakta olduğunu doğrulayan basit endpoint |
| **`route:cache`** | Rotaları serileştirip önbelleğe alan üretim optimizasyonu |
| **Invokable controller** | Tek `__invoke()` metodu olan controller |
| **Fail-safe** | Unutulduğunda güvenli tarafa düşen tasarım |
