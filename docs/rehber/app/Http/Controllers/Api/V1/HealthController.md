# `app/Http/Controllers/Api/V1/HealthController.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Http/Controllers/Api/V1/HealthController.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.4b
> **Bağlantılı:** [`routes/api.md`](../../../../routes/api.md)

---

## 0. Bir dakikalık özet

Projenin **ilk controller'ı**. Tek iş yapar: `{"status":"ok"}` döndürür.

```php
public function __invoke(): JsonResponse
{
    return response()->json(['status' => 'ok']);
}
```

Bu kadar basit bir şey için sınıf açmanın tek sebebi var: `php artisan route:cache`
closure'ları serileştiremez.

---

## 1. Controller nedir, ne değildir?

Controller **trafik polisidir**: gelen isteği doğru yere yönlendirir, dönen sonucu
uygun biçime sokar. Kendisi iş yapmaz.

`CLAUDE.md` §1'e göre controller metotları **3-8 satır** olmalı ve içlerinde `if`
bloğu bulunmamalıdır. Faz 2'den itibaren tipik görünüm:

```php
public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
{
    $user = $action->execute($request->validated());   // iş kuralı Action'da

    return response()->json([
        'user' => UserResource::make($user)->resolve(),
        'token' => $user->createToken('api')->plainTextToken,
    ]);
}
```

| Katman | Sorusu |
|---|---|
| FormRequest | "Bu veri geçerli mi?" |
| **Controller** | **"Kimi çağırayım, sonucu nasıl paketleyeyim?"** |
| Action | "İş kuralı ne?" |
| Resource | "Dışarıya hangi alanlar, hangi adlarla?" |

`HealthController` bu zincirin dejenere hâlidir: doğrulanacak girdi yok, iş kuralı
yok, dönüştürülecek model yok. Yine de **şekli** aynıdır.

---

## 2. `__invoke()` — invokable controller

```php
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse { ... }
}
```

`__invoke()` PHP'nin **sihirli metotlarından** biridir. Bir nesne fonksiyon gibi
çağrıldığında çalışır:

```php
$controller = new HealthController();
$controller();          // __invoke() çalışır
```

Laravel bunu tanır. Rotada metot adı yazılmaz:

```php
Route::get('/ping', HealthController::class);              // ✅ invokable
Route::get('/ping', [HealthController::class, 'index']);   // normal controller
```

### Ne zaman invokable, ne zaman normal?

| Durum | Tercih |
|---|---|
| Controller **tek** eylem yapıyor | Invokable — `__invoke()` |
| Birbiriyle ilişkili birden fazla eylem (CRUD) | Normal — `index`, `store`, `update`… |

`HealthController`'ın ikinci bir eylemi olmayacak. `AuthController` (Faz 2) ise
`register`, `login`, `logout`, `me` taşıyacak — o normal controller olur.

> Invokable controller adlandırmasında **fiil** kullanmak da yaygındır:
> `PublishInvitationController`. Biz `HealthController` dedik çünkü bir eylemi
> değil bir **konuyu** temsil ediyor.

---

## 3. Tasarım kararları

### 3.1 🔴 Neden closure değil sınıf? — `route:cache`

Faz 9'da (`9.2`) üretim optimizasyonu olarak şu komut çalıştırılacak:

```powershell
php artisan route:cache
```

Bu komut tüm rota tanımlarını **serileştirip** diske yazar; Laravel her istekte
`routes/api.php` dosyasını ayrıştırmak yerine hazır diziyi okur. 20 rotalık bir
API'de istek başına birkaç milisaniye kazandırır.

**Closure'lar serileştirilemez.** PHP bir fonksiyonu (ve kapattığı değişken
ortamını) diske yazıp geri okuyamaz. Komut şu hatayı verir:

```
LogicException: Unable to prepare route [api/ping] for serialization. Uses Closure.
```

Sınıf referansı ise sadece bir **metindir** (`"App\Http\Controllers\Api\V1\HealthController"`)
— serileştirilmesi sorunsuzdur.

### 3.2 Neden şimdi, Faz 9'da değil?

İki seçenek vardı ve **şimdi** seçildi:

| | Şimdi ✅ | Faz 9'da |
|---|---|---|
| Maliyet | 12 satır | Aynı 12 satır |
| Risk | Yok | Faz 9'da sebebi aranır, zaman kaybı |
| Kural tutarlılığı | Faz 2+ kuralıyla uyumlu | Tek istisna kalır |

Maliyet aynı olduğunda **erken ödemek** daha ucuzdur: sonraya bırakılan iş, o güne
kadar bağlamını kaybeder. Faz 9'da bu hatayı gören kişi (yani sen, aylar sonra)
önce hatayı anlamaya, sonra hangi rotanın closure olduğunu bulmaya vakit harcar.

Buna **teknik borç** denir ve bu örnekte faizi maliyetinden yüksekti.

### 3.3 `Api\V1\` namespace'i — sürüm burada

```php
namespace App\Http\Controllers\Api\V1;
```

K10: sürüm **URL'de değil namespace'te**. URL `/api/ping`, namespace `Api\V1`.

Yarın uyumsuz bir v2 gerekirse `App\Http\Controllers\Api\V2\` açılır ve
`/api/v2/...` **ek** olarak tanımlanır. Bugünkü `/api/...` v1 olarak yaşamaya
devam eder — frontend'in `baseURL = '/api'` sözleşmesi kırılmaz.

### 3.4 `extends Controller` neden var?

`app/Http/Controllers/Controller.php` şu an boş bir `abstract class`. Miras almak
bugün hiçbir şey kazandırmıyor.

Yine de yazıldı: Faz 3'te `AuthorizesRequests` trait'i (Policy çağırmak için
`$this->authorize(...)`) muhtemelen o taban sınıfa eklenecek. O gün tüm
controller'lar otomatik olarak kazanacak. Tutarlılık, tek bir istisnadan ucuzdur.

### 3.5 Dizi değil `JsonResponse` — neden değişti?

Closure hâlinde dizi döndürmek yeterliydi (Laravel otomatik çevirir). Controller'da
açıkça `response()->json(...)` yazıldı:

```php
return response()->json(['status' => 'ok']);
```

Sebep **tip güvenliği**: dönüş tipi `JsonResponse` olarak beyan edilebiliyor.
`array` beyan etseydik PHPStan bize durum kodunu veya başlığı değiştirmek
istediğimizde yardımcı olamazdı. Faz 2'den itibaren controller'lar zaten
`JsonResponse` döndürecek — biçim baştan tutarlı.

---

## 4. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| Rotada `[HealthController::class, '__invoke']` yazmak | Çalışır ama gereksiz | Sadece `HealthController::class` |
| Invokable controller'a ikinci metot eklemek | `__invoke` dışındakiler erişilemez | Normal controller'a dönüştür |
| Controller'a iş mantığı yazmak | Test edilemez, SRP ihlali | Action katmanı |
| `namespace App\Http\Controllers;` yazmak | K10 ihlali, sürümsüz | `Api\V1\` |
| Yanıta sürüm/ortam bilgisi eklemek | Sürüm taraması hedefi (08 §3.3) | Yalnızca `status` |
| Dönüş tipini `array` beyan etmek | PHPStan yardım edemez | `JsonResponse` |

---

## 5. Kendin dene

```powershell
php artisan route:list --path=api
```

```
GET|HEAD   api/ping   health.ping   App\Http\Controllers\Api\V1\HealthController
```

Closure hâlinde son sütun `Closure` yazıyordu. Şimdi sınıf adı görünüyor — komutun
serileştirebileceği şey tam olarak bu metin.

```powershell
php artisan route:cache
php artisan route:list --path=api    # önbellekten okunuyor
php artisan route:clear              # geliştirmede önbellek KAPALI olmalı
```

🔴 `route:cache` çalıştırdıktan sonra `routes/api.php`'de yaptığın değişiklikler
**görünmez**. Geliştirmede mutlaka `route:clear` ile temizle. Bu, Faz 9'a kadar
kullanılmayacak bir komuttur; burada sadece closure sorununu göstermek için denendi.

**Kasten kır:** `routes/api.php`'deki satırı geçici olarak
`Route::get('/ping', fn () => ['status' => 'ok'])` yap ve `php artisan route:cache`
çalıştır. `LogicException`'ı kendi gözünle gör, sonra geri al.

---

## 6. Sözlük

| Terim | Anlamı |
|---|---|
| **Controller** | İsteği ilgili koda yönlendiren, yanıtı paketleyen katman |
| **Invokable controller** | Tek `__invoke()` metodu olan controller |
| **Sihirli metot** | PHP'nin belirli durumlarda otomatik çağırdığı `__` önekli metot |
| **Serileştirme** | Nesne/veriyi saklanabilir metne çevirme |
| **`route:cache`** | Rotaları önceden derleyen üretim optimizasyonu |
| **Teknik borç** | Sonraya bırakılan ve zamanla maliyeti artan iş |
| **SRP** | Single Responsibility Principle — bir sınıf, bir değişme sebebi |
