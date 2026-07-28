# Adım 1 — Kurulum ve Klasör Hiyerarşisi

> **Bu adımın amacı:** Boş bir Laravel projesini, mimari planımızın gerektirdiği
> klasör yapısına dönüştürmek. Kod yazmıyoruz — **iskeleti kuruyoruz.**
>
> Komutları **siz çalıştıracaksınız.** Ben ne yapacağınızı, neden yapacağınızı ve
> her komutun arkasında ne olduğunu anlatacağım. Bir mimarın işi komut yazmak
> değil, hangi komutun neden gerektiğini bilmektir.

---

## 0. Neden komutları ben çalıştırmıyorum?

Composer ve PHP **sizin makinenizde** kurulu. Ben ayrı bir Linux kum havuzunda
çalışıyorum ve orada PHP yok. Bu aslında iyi bir şey: kurulum sürecini kendiniz
yaşayacaksınız — bir backend geliştiricinin ilk günü budur.

---

## 1. Ön koşulları doğrulayın

PowerShell açıp sırayla çalıştırın:

```powershell
php -v          # PHP 8.3 veya üstü olmalı (Laravel 13 minimum 8.3 istiyor)
composer -V     # Composer 2.x
mysql --version # MySQL 8 (veya MariaDB 10.6+)
```

**Hepsi çıktı vermiyorsa:** Windows'ta en pratik yol **Laravel Herd**
(`herd.laravel.com`) — PHP, Composer ve nginx'i tek kurulumda getirir. Alternatif:
XAMPP + ayrı Composer kurulumu.

### Gerekli PHP eklentileri

Laravel'in ihtiyaç duyduğu eklentiler `php.ini` içinde açık olmalı:

```
extension=pdo_mysql      → veritabanı
extension=mbstring       → çok baytlı karakter (Türkçe, Arapça)
extension=openssl        → şifreleme
extension=fileinfo       → 🔴 dosya MIME doğrulaması (LCV yüklemelerinde ŞART)
extension=gd             → görsel işleme
extension=zip · curl · xml
```

Kontrol:

```powershell
php -m | Select-String "pdo_mysql|mbstring|openssl|fileinfo|gd"
```

> **`fileinfo` neden kritik?** Güvenlik planımızda "MIME tipini **içerikten**
> doğrula, uzantıya güvenme" demiştik. `virus.php` dosyasını `photo.jpg` diye
> yeniden adlandırmak 2 saniyelik iş. Laravel'in `mimes:jpeg,png` kuralı dosyanın
> **ilk baytlarını** okur — bunu `fileinfo` eklentisi yapar. Eklenti yoksa
> doğrulama sessizce zayıflar.

---

## 2. Laravel'i kurun

`davetkart-backend` klasörü boş değil (`.git`, `LICENSE`, `README.md`,
`.gitignore` var). Composer, dolu bir klasöre kurulum yapmayı reddeder. Bu yüzden
geçici klasöre kurup içeriği taşıyacağız:

```powershell
cd D:\Projects\davetkart

# 1) Laravel'i geçici bir klasöre kur
composer create-project laravel/laravel _laravel-tmp

# 2) İçeriği (gizli dosyalar dahil) hedef klasöre taşı
Get-ChildItem -Path _laravel-tmp -Force | Move-Item -Destination davetkart-backend -Force

# 3) Geçici klasörü sil
Remove-Item _laravel-tmp -Recurse -Force
```

**Ne oldu:**
- `-Force` (GetChildItem'da) → `.env.example`, `.gitattributes` gibi **gizli
  dosyaları da** listeler. Bu olmadan Laravel'in yarısı taşınmazdı.
- `-Force` (Move-Item'da) → Laravel'in `.gitignore` ve `README.md` dosyaları
  mevcutların üzerine yazılır. **Bunu istiyoruz:** mevcut `.gitignore` NestJS'e
  göre yazılmış (eski karardan kalma), `/node_modules` ve `/dist` yok sayıyor ama
  Laravel'in `/vendor` klasörünü **yok saymıyordu.** Düzeltilmesi şart.
- `docs/` klasörümüz ve bu doküman etkilenmez — Laravel'de aynı isimde bir şey yok.

### Kurulumu doğrulayın

```powershell
cd davetkart-backend
php artisan --version        # "Laravel Framework 13.x.x"
php artisan serve            # http://127.0.0.1:8000
```

Tarayıcıda `http://127.0.0.1:8000` → Laravel karşılama sayfası görünmeli.
**Port 8000 olmak zorunda** — `vite.config.ts` proxy'si oraya bakıyor.

---

## 3. API katmanını açın

Laravel 11'den beri API rotaları **varsayılan olarak gelmiyor** (birçok proje
sadece web tarafını kullanıyor, gereksiz dosya üretmesinler diye). Tek komutla
ekleniyor:

```powershell
php artisan install:api
```

**Bu komut 4 şey yapıyor:**

1. `composer require laravel/sanctum` — token altyapısı
2. `routes/api.php` oluşturur ve `bootstrap/app.php`'ye kaydeder
3. `personal_access_tokens` migration'ını ekler
4. `App\Models\User`'a `HasApiTokens` trait'ini ekler

> **Not:** Bu, mimari planındaki Sanctum kararını uygular. JWT'ye geçmek isterseniz
> maliyeti düşük — Sanctum'u kaldırıp paketi değiştirmek yeterli. Ama `auth.ts`
> sunucu tarafında token iptali beklediği için tavsiyem Sanctum'da kalmak.

Sonrasında:

```powershell
php artisan storage:link
```

`public/storage` → `storage/app/public` sembolik bağı kurar. `vite.config.ts`'teki
`/storage` proxy kuralının çalışması için **zorunlu.**

---

## 4. Veritabanını hazırlayın

```sql
CREATE DATABASE davetkart CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**`utf8mb4` neden?** MySQL'in eski `utf8`'i aslında 3 baytlıktır ve emoji'yi
saklayamaz. Kullanıcılarınız davetiye başlığına 💍 yazacak, misafirler LCV
mesajına 🎉 koyacak. `utf8mb4` gerçek UTF-8'dir (4 bayt). Ayrıca 10 dil desteği
var — Çince ve Arapça karakterler için de gerekli.

`.env` dosyasını düzenleyin:

```env
APP_NAME=DavetKart
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=davetkart
DB_USERNAME=root
DB_PASSWORD=

FRONTEND_URL=http://localhost:3000
```

```powershell
php artisan migrate
```

---

## 5. Mimari klasörleri oluşturun

Laravel'in varsayılan yapısı bizim planımızın **bir alt kümesi**. `Actions/`,
`Enums/`, `Policies/`, `Services/` klasörleri yok — onları biz ekleyeceğiz.

Hazırladığım betiği çalıştırın:

```powershell
cd D:\Projects\davetkart\davetkart-backend
powershell -ExecutionPolicy Bypass -File .\scaffold-structure.ps1
```

> Betik sadece **klasör oluşturur ve `.gitkeep` koyar.** Hiçbir dosyanın üzerine
> yazmaz, hiçbir şeyi silmez. İsterseniz önce açıp okuyun — okumadığınız betiği
> çalıştırmamak iyi bir alışkanlıktır.

**`.gitkeep` nedir?** Git **dosyaları** takip eder, klasörleri değil. Boş bir
klasör commit edilemez. `.gitkeep` (içeriği boş, adı gelenekseldir) klasörün
depoda görünmesini sağlar. İçine ilk gerçek dosya girince silebilirsiniz.

---

## 6. Ortaya çıkan yapı ve her klasörün görevi

```
davetkart-backend/
│
├── app/
│   │
│   ├── Actions/          ⭐ BİZ EKLEDİK — İş kuralları
│   │   ├── Auth/  Invitation/  Rsvp/  Media/  Payment/
│   │
│   ├── Enums/            ⭐ BİZ EKLEDİK — Sabit değer kümeleri
│   │
│   ├── Http/
│   │   ├── Controllers/Api/V1/    ⭐ BİZ EKLEDİK
│   │   ├── Requests/              ⭐ Auth/ Invitation/ Rsvp/
│   │   ├── Resources/             ⭐ BİZ EKLEDİK
│   │   └── Middleware/            (Laravel'de var, boş)
│   │
│   ├── Models/           (Laravel: User.php burada)
│   ├── Policies/         ⭐ BİZ EKLEDİK
│   ├── Services/         ⭐ Payment/ Ai/ Pricing/
│   ├── Jobs/             ⭐ BİZ EKLEDİK
│   ├── Events/           ⭐ BİZ EKLEDİK
│   ├── Listeners/        ⭐ BİZ EKLEDİK
│   ├── Exceptions/       ⭐ BİZ EKLEDİK
│   └── Providers/        (Laravel)
│
├── bootstrap/app.php     🔑 Laravel 11+ kalbi: middleware, exception, rota kaydı
├── config/               Ayar dosyaları (davetkart.php'yi biz ekleyeceğiz)
├── database/
│   ├── migrations/       Şema geçmişi
│   ├── factories/        Sahte veri üreticileri
│   └── seeders/          Başlangıç verisi
├── public/               🌐 Web'e açık TEK klasör (index.php)
├── routes/
│   ├── api.php           Bizim tüm rotalarımız
│   ├── web.php           SPA fallback (ileride)
│   └── console.php
├── storage/
│   ├── app/public/       Yüklenen dosyalar
│   ├── framework/        Cache, session, view
│   └── logs/             laravel.log
├── tests/Feature/ · Unit/
├── vendor/               Composer paketleri (git'e girmez)
└── docs/                 Bizim mimari dokümanlarımız
```

---

### Katman katman: neden bu klasörler?

#### `app/Actions/` — planın en önemli eklemesi

Laravel'in varsayılan yaklaşımı iş kuralını **Controller'a** yazmaktır. Küçük
projelerde sorun değil; ama `PublishInvitationAction` gibi bir işlem şunları
yapıyor: yetki kontrolü → paywall hesabı → sipariş doğrulaması → slug üretimi →
durum değişimi → cache temizleme → event yayını.

Bunu controller'a yazarsanız 80 satırlık bir metot olur ve:
- Test etmek için **HTTP isteği simüle etmek** zorunda kalırsınız
- Aynı işi bir Artisan komutundan çağıramazsınız
- HTTP değişse de iş kuralı değişse de aynı dosyayı açarsınız (**SRP ihlali**)

Action olarak ayırınca controller şuna iner:

```php
public function publish(Invitation $invitation, PublishInvitationAction $action)
{
    $this->authorize('update', $invitation);
    return new InvitationResource($action->execute($invitation));
}
```

Controller'ın tek sorumluluğu kaldı: **HTTP'yi iş kuralına bağlamak.**

> **Alt klasörler neden?** `Actions/` düz kalırsa 6 ay sonra 25 dosya olur.
> Domain'e göre gruplamak (`Auth/`, `Invitation/`…) modüler monolitin sınırlarını
> klasör seviyesinde görünür kılar. Yarın `Payment` modülünü ayırmak isterseniz
> hangi dosyaların taşınacağı bellidir.

#### `app/Enums/` — sihirli string avcısı

```php
// ❌ Sihirli string
if ($invitation->status === 'published') { ... }
// Yazım hatası ('publised') → sessizce false → hata 3 gün sonra bulunur

// ✅ Enum
if ($invitation->status === InvitationStatus::Published) { ... }
// Yazım hatası → IDE anında uyarır, PHP anında hata verir
```

PHP 8.1 backed enum'ları ayrıca **davranış** taşıyabilir — `SubscriptionTier`
enum'una `rank()`, `price()`, `rsvpLimit()` metotlarını koyacağız. Böylece plan
bilgisi tek yerde toplanır (frontend'de bu bilgi `data.ts` ve
`useSubscriptionStore.ts` arasında dağınık).

#### `app/Http/Requests/` — controller'da `if` bırakmamak

FormRequest, isteğin gövdesine controller'a girmeden önce bakar:

```php
class StoreRsvpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'guestName'  => ['required', 'string', 'max:120'],
            'guestCount' => ['required', 'integer', 'min:1', 'max:20'],
            'status'     => ['required', Rule::enum(RsvpStatus::class)],
        ];
    }
}
```

Kural ihlalinde Laravel otomatik `422` + `{message, errors}` döner — frontend'in
`catch` blokları bunu zaten bekliyor. Controller'a hiç girilmez.

> **`Requests/` altında da domain klasörleri var** çünkü `StoreInvitationRequest`
> ile `UpdateInvitationRequest` yan yana dursun, `LoginRequest` başka yerde olsun
> istiyoruz. Bir dosyayı ararken hangi klasöre bakacağınızı düşünmek zorunda
> kalmamalısınız.

#### `app/Http/Resources/` — sözleşmenin bekçisi

Frontend `mapUrl` bekliyor, DB'de `map_url` var. Bu dönüşüm **sadece burada**
yapılacak.

Asıl faydası şu: yarın `map_url` kolonunu `location_url` olarak değiştirirsek,
Resource'ta tek satır düzeltiriz ve **API sözleşmesi hiç değişmez.** İç model ile
dış sözleşme birbirinden bağımsız evrilebilir. Bu **Anti-Corruption Layer**
fikridir — Eloquent modelini doğrudan `return` etmek bu korumayı yok eder.

#### `app/Policies/` — "bu kaynak bu kullanıcının mı?"

Laravel'in route model binding'i `/api/invitations/42` isteğinde 42 numaralı
davetiyeyi **bulur** — ama sahibinin kim olduğunu **sormaz**. Kontrol
koymazsanız herkes herkesin davetiyesini okur/siler. Buna **IDOR** denir ve
OWASP Top 10'un ilk sırasındadır.

```php
class InvitationPolicy
{
    public function view(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->user_id;
    }
}
```

Controller'da tek satır: `$this->authorize('view', $invitation);`
Yetkisizse Laravel otomatik **403** döner — `401` değil, ki frontend kullanıcıyı
sistemden atmasın.

#### `app/Services/` — dış dünyanın izole edildiği yer

Üç alt klasör, üç farklı gerekçe:

| Klasör | İçerik | Desen | Neden soyutluyoruz |
|---|---|---|---|
| `Payment/` | `PaymentGateway` arayüzü + `FakeGateway`, `IyzicoGateway` | **Strategy** | Sağlayıcı değişimi tek config satırı olsun |
| `Ai/` | `AiProvider` arayüzü + `GeminiProvider` | **Adapter** | API anahtarı tek dosyada; sağlayıcı değişse domain etkilenmesin |
| `Pricing/` | `TierResolver` | Saf mantık | 🔴 Paywall'ın sunucu ikizi — HTTP'siz test edilebilir olmalı |

`TierResolver`'ın neden ayrı bir sınıf olduğuna dikkat edin: içinde hiç veritabanı,
hiç HTTP yok. Sadece "şu bayraklar açıksa şu plan gerekir" mantığı. Bu sayede
**mikrosaniyeler içinde çalışan bir birim testi** yazabiliriz ve projenin en
kritik iş kuralı tam kapsama altında olur.

#### `app/Jobs/` — 15 saniye kuralı

`api.ts` 15 saniye sonra isteği iptal ediyor. 5 MB'lık bir fotoğrafı yeniden
boyutlandırmak + WebP'ye çevirmek bu süreyi zorlayabilir. Job'a atarız:

```
İstek → dosyayı kaydet → Job kuyruğa at → hemen 201 dön   (~200ms)
                                     ↓
                          Arka planda: optimize et, thumbnail üret
```

Kullanıcı beklemez, timeout'a takılmayız.

#### `app/Events/` + `app/Listeners/` — modüller arası gevşek bağ

`PublishInvitationAction` içinde şunu **yazmak istemiyoruz**:

```php
Cache::forget("invitation:{$slug}");   // ❌ yayınlama mantığı cache'i bilmemeli
Mail::send(...);                       // ❌ ve e-postayı da
```

Bunun yerine:

```php
InvitationPublished::dispatch($invitation);   // ✅ "şu oldu" der, geçer
```

Cache temizleme ve e-posta ayrı Listener'lar olur. Yarın "yayınlanınca SMS de
gönder" denirse **Action'a hiç dokunmadan** yeni bir listener eklenir. Bu
**Open/Closed Principle**: genişlemeye açık, değişikliğe kapalı.

#### `bootstrap/app.php` — Laravel 11+ ile değişen şey

Eski Laravel'de `app/Http/Kernel.php` middleware'leri, `app/Exceptions/Handler.php`
hataları yönetirdi. Laravel 11'den itibaren ikisi de kaldırıldı; her şey
`bootstrap/app.php` içinde toplandı:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [ SetLocaleFromHeader::class ]);
})
->withExceptions(function (Exceptions $exceptions) {
    // PaywallViolationException → 402
})
```

**İnternette bulacağınız eğitimlerin çoğu eski yapıyı anlatıyor.** `Kernel.php`
arayıp bulamayınca şaşırmayın — kaybolmadı, taşındı.

#### `public/` — web'e açık tek klasör

Web sunucusunun kökü `public/`. `app/`, `config/`, `.env` **dışarıdan
erişilemez.** Bu Laravel'in temel güvenlik tasarımıdır: bir saldırgan
`site.com/.env` isteyemez, çünkü o dosya web kökünün dışında.

> **Production'da en sık yapılan hata:** Sunucunun document root'unu proje
> köküne göstermek. O an `.env` dosyanız (DB şifresi, ödeme anahtarları) internete
> açılır. Root **her zaman** `public/` olmalı.

---

## 7. `.gitignore` kontrolü

Laravel'in kendi `.gitignore`'u geldi, eski NestJS sürümü üzerine yazıldı.
Şu satırların olduğunu doğrulayın:

```gitignore
/vendor          ← 100+ MB paket, asla commit edilmez
/node_modules
/public/storage  ← sembolik bağ, her makinede yeniden kurulur
/storage/*.key
.env             ← 🔴 EN KRİTİK: sırlar buradadır
.env.backup
```

> **`.env` neden asla commit edilmez?** İçinde DB şifreniz, `APP_KEY`'iniz,
> ileride ödeme ve Gemini anahtarlarınız olacak. GitHub'a bir kez gitti mi,
> silseniz bile **git geçmişinde kalır.** Bot'lar public repo'ları tarayıp
> sızmış anahtarları dakikalar içinde bulur. `.env.example` (sırsız, sadece
> anahtar isimleri) commit edilir — takım arkadaşınız onu kopyalayıp doldurur.

---

## 8. Bu adım bittiğinde elinizde ne var?

- ✅ Çalışan bir Laravel 13 kurulumu, `:8000` portunda
- ✅ Sanctum kurulu, `routes/api.php` hazır
- ✅ `utf8mb4` veritabanı, ilk migration'lar atılmış
- ✅ Mimari planın gerektirdiği tüm klasörler, `.gitkeep` ile git'te görünür
- ✅ `storage:link` kurulu — `/storage` proxy'si çalışacak
- ❌ Henüz **tek satır iş kodu yok** — bilinçli olarak

---

## ⏭️ Sıradaki adım (onayınızla)

**Adım 2: `config/davetkart.php` + `app/Enums/`**

İlk gerçek kodumuz. Öğrenecekleriniz:
- Plan fiyatları/kotaları neden `config/`'e taşınıyor (12-Factor)
- PHP 8 backed enum'ları ve enum'a davranış eklemek
- `RsvpStatus`'ün Türkçe arayüz metnine nasıl çevrileceği
- Sihirli string'lerin nasıl sistematik olarak yok edildiği

**Kurulumu tamamlayınca haber verin** — takıldığınız bir yer olursa hata mesajını
paylaşın, birlikte çözelim.
