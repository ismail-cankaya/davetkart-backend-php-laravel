# Klasör ve Dosya Referansı — Mimari + Algoritmik

> **Bu doküman iki soruya cevap verir:**
> 1. **Mimari olarak:** Bu klasör/dosya hangi katmana ait, hangi deseni uygular,
>    hangi ilkeyi korur? (*Neden var?*)
> 2. **Algoritmik olarak:** Bir istek geldiğinde bu dosya adım adım ne yapar,
>    kimden veri alır, kime verir? (*Çalışırken ne oluyor?*)

---

# BÖLÜM 0 — Mevcut Durumun Denetimi

## 0.1 Doğru yaptıklarınız ✅

`davetkart-backend-php-laravel/` içinde oluşturduğunuz şunlar **mimari plana
birebir uygun:**

| Klasör | Değerlendirme |
|---|---|
| `app/Actions/` | ✅ Doğru — uygulama katmanı |
| `app/Enums/` | ✅ Doğru — sihirli string avcısı |
| `app/Policies/` | ✅ Doğru — IDOR koruması |
| `app/Services/` | ✅ Doğru — Ports & Adapters |
| `app/Jobs/` | ✅ Doğru — 15 sn kuralı |
| `app/Events/` | ✅ Doğru — gevşek bağ |
| `app/Exceptions/` | ✅ Doğru — özel istisnalar |
| `app/Models/`, `app/Http/` | ✅ Doğru — Laravel standardı |
| `.gitignore` | ✅ Laravel sürümü, `/vendor` ve `.env` doğru şekilde hariç |
| `CLAUDE.md` | ✅ Çok iyi — mimari kuralları kod standardına dönüştürmüşsünüz |

**Klasör isimlendirmeniz doğru.** Kavramsal olarak mimariyi anlamışsınız.

---

## 0.2 🔴 Temel problem: Laravel kurulu değil

```
❌ artisan            yok
❌ composer.json      yok
❌ vendor/            yok
❌ public/index.php   yok
⚠️  bootstrap/app.php  var ama BOŞ (0 byte)
```

### Neden bu kritik?

Klasör yapısı, Laravel kurulumunun **çıktısıdır — girdisi değil.** El ile klasör
açmak, bir arabanın fotoğrafını çizip motor beklemeye benzer.

PHP'nin bir dosyayı çalıştırabilmesi için `vendor/autoload.php` gerekir. Bu dosya
Composer tarafından üretilir ve **PSR-4 otomatik yükleme haritasını** taşır:

```
"App\Actions\Auth\LoginUserAction"  →  app/Actions/Auth/LoginUserAction.php
```

Bu harita olmadan PHP, sınıf isimlerini dosya yollarına çeviremez. Şu anda
`app/Actions/` klasörüne mükemmel bir sınıf yazsanız bile **hiçbir şey onu
bulamaz.**

### Eksik olan kritik klasörler

| Eksik | Neden hayati |
|---|---|
| 🔴 `public/` | **Web sunucusunun kökü.** Tüm istekler `public/index.php`'den girer. Bu yoksa uygulama erişilemez |
| 🔴 `vendor/` | Laravel'in kendisi + tüm paketler |
| 🔴 `bootstrap/cache/` | Derlenmiş config/route önbelleği — yoksa uygulama açılmaz |
| 🔴 `app/Providers/` | Servis kayıtları, DI bağlamaları |
| 🟠 `app/Listeners/` | Event'lerin karşılığı (planda vardı) |
| 🟠 `app/Http/{Controllers,Requests,Resources,Middleware}/` | `app/Http/` boş |
| 🟠 `database/{migrations,factories,seeders}/` | `database/` boş |
| 🟠 `storage/framework/{cache,sessions,views}`, `storage/logs/` | Laravel yazamazsa 500 hatası |
| 🟠 `resources/`, `tests/{Feature,Unit}/` | — |

---

## 0.3 Düzeltme adımları

`davetkart-backend-php-laravel/` içindeki **boş** klasörleri silip Laravel'i
gerçekten kuralım. `.git`, `.gitignore`, `CLAUDE.md` ve `docs/` korunacak.

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel

# 1) El ile oluşturulmuş BOŞ iskeleti kaldır (içlerinde dosya yok)
Remove-Item app, bootstrap, config, database, routes, storage, tests -Recurse -Force -ErrorAction SilentlyContinue

# 2) Laravel'i geçici klasöre kur
cd ..
composer create-project laravel/laravel _laravel-tmp

# 3) İçeriği taşı (-Force: gizli dosyalar + .gitignore üzerine yazma)
Get-ChildItem -Path _laravel-tmp -Force | Move-Item -Destination davetkart-backend-php-laravel -Force
Remove-Item _laravel-tmp -Recurse -Force

# 4) Kurulumu tamamla
cd davetkart-backend-php-laravel
php artisan install:api      # Sanctum + routes/api.php
php artisan storage:link     # /storage proxy'si için
php artisan serve            # port 8000 — vite proxy oraya bakıyor

# 5) Mimari klasörleri ekle
powershell -ExecutionPolicy Bypass -File ..\scaffold-structure.ps1
```

> ⚠️ Adım 3'te Laravel'in `.gitignore`'u sizinkinin üzerine yazacak. Sizinki zaten
> doğru bir Laravel `.gitignore`'u — kayıp yok. `CLAUDE.md` ve `docs/` etkilenmez
> (Laravel'de aynı isimde bir şey yok).

---

# BÖLÜM 1 — Tam Klasör Şeması

Kurulum sonrası oluşacak nihai yapı. `⭐` = mimari planımızın eklemesi,
`🔵` = Laravel standardı.

```
davetkart-backend-php-laravel/
│
├── 📁 app/                          ← UYGULAMA KODU (PSR-4: App\)
│   │
│   ├── ⭐ Actions/                  ═══ UYGULAMA KATMANI: iş kuralları ═══
│   │   ├── Auth/
│   │   │   ├── RegisterUserAction.php      Kullanıcı yarat + token üret
│   │   │   ├── LoginUserAction.php         Kimlik doğrula + token üret
│   │   │   └── RevokeTokenAction.php       Aktif token'ı sil
│   │   ├── Invitation/
│   │   │   ├── CreateInvitationAction.php  Taslak yarat
│   │   │   ├── UpdateInvitationAction.php  Alanları + koleksiyonları güncelle
│   │   │   ├── PublishInvitationAction.php 🔴 Paywall + slug + yayın
│   │   │   ├── SyncTimelineEventsAction.php Nested koleksiyon senkronu
│   │   │   └── ResolvePublicInvitationAction.php Cache'li slug çözümleme
│   │   ├── Rsvp/
│   │   │   ├── SubmitRsvpAction.php        🔴 Deadline + kota + kayıt
│   │   │   └── DeleteRsvpAction.php
│   │   ├── Media/
│   │   │   └── StoreUploadedMediaAction.php Diske yaz + kayıt + Job
│   │   └── Payment/
│   │       ├── StartCheckoutAction.php     Order yarat + gateway'e git
│   │       └── HandlePaymentCallbackAction.php İdempotent webhook işleme
│   │
│   ├── ⭐ Enums/                    ═══ SABİT DEĞER KÜMELERİ ═══
│   │   ├── InvitationStatus.php     draft | saved | published
│   │   ├── RsvpStatus.php           attending|pending|declined + TR çeviri
│   │   ├── SubscriptionTier.php     standart|gold|elit + rank/price/limit
│   │   ├── OrderStatus.php          pending|paid|failed|refunded
│   │   ├── MediaKind.php            gallery|rsvp_photo|rsvp_video
│   │   └── ContactSubject.php       general|support|pricing|partnership|kvkk
│   │
│   ├── 🔵 Http/                     ═══ SUNUM KATMANI ═══
│   │   ├── ⭐ Controllers/Api/V1/   HTTP → Action köprüsü (3-8 satır)
│   │   │   ├── AuthController.php
│   │   │   ├── InvitationController.php
│   │   │   ├── PublicInvitationController.php   auth'suz, cache'li
│   │   │   ├── RsvpController.php
│   │   │   ├── MediaController.php
│   │   │   ├── PaymentController.php
│   │   │   ├── AssistantController.php
│   │   │   └── ContactController.php
│   │   ├── ⭐ Requests/             GİRİŞ KAPISI: doğrulama + yetki
│   │   │   ├── Auth/{Login,Register}Request.php
│   │   │   ├── Invitation/{Store,Update}InvitationRequest.php
│   │   │   ├── Rsvp/StoreRsvpRequest.php
│   │   │   └── ContactRequest.php
│   │   ├── ⭐ Resources/            ÇIKIŞ KAPISI: snake→camel dönüşümü
│   │   │   ├── UserResource.php
│   │   │   ├── InvitationResource.php        {id,status,updatedAt,invitation}
│   │   │   ├── InvitationPayloadResource.php 28 alanlı tasarım nesnesi
│   │   │   ├── TimelineEventResource.php
│   │   │   └── RsvpResource.php
│   │   └── 🔵 Middleware/           Her istekte çalışan ara katmanlar
│   │       ├── SetLocaleFromHeader.php   Accept-Language → app locale
│   │       └── ForceJsonResponse.php     API daima JSON döner
│   │
│   ├── 🔵 Models/                   ═══ VERİ KATMANI (Active Record) ═══
│   │   ├── User.php · Invitation.php · TimelineEvent.php
│   │   └── Media.php · Rsvp.php · Order.php · ContactMessage.php
│   │
│   ├── ⭐ Policies/                 ═══ YETKİLENDİRME ═══
│   │   ├── InvitationPolicy.php     "Bu davetiye bu kullanıcının mı?"
│   │   └── RsvpPolicy.php
│   │
│   ├── ⭐ Services/                 ═══ DIŞ DÜNYA ADAPTÖRLERİ ═══
│   │   ├── Payment/
│   │   │   ├── PaymentGateway.php   interface  ← PORT
│   │   │   ├── FakeGateway.php      ADAPTER (şimdi)
│   │   │   └── IyzicoGateway.php    ADAPTER (sonra)
│   │   ├── Ai/
│   │   │   ├── AiProvider.php       interface  ← PORT
│   │   │   └── GeminiProvider.php   🔴 API anahtarı SADECE burada
│   │   └── Pricing/
│   │       └── TierResolver.php     🔴 Paywall'ın sunucu ikizi (saf mantık)
│   │
│   ├── ⭐ Jobs/                     ═══ KUYRUK (asenkron) ═══
│   │   ├── OptimizeUploadedImage.php
│   │   └── SendRsvpNotification.php
│   │
│   ├── ⭐ Events/                   ═══ "ŞU OLDU" bildirimleri ═══
│   │   ├── InvitationPublished.php
│   │   └── RsvpReceived.php
│   ├── ⭐ Listeners/                ═══ Event'lere tepkiler ═══
│   │   ├── ClearInvitationCache.php
│   │   └── NotifyInvitationOwner.php
│   │
│   ├── ⭐ Exceptions/               ═══ ÖZEL HATA TİPLERİ ═══
│   │   ├── PaywallViolationException.php     → HTTP 402
│   │   └── RsvpQuotaExceededException.php    → HTTP 422
│   │
│   └── 🔵 Providers/                ═══ ÖNYÜKLEME / DI BAĞLAMA ═══
│       └── AppServiceProvider.php   interface → implementation eşlemesi
│
├── 🔵 bootstrap/
│   ├── app.php                      🔑 Laravel 11+ KALBİ: middleware,
│   │                                   exception, rota kaydı tek dosyada
│   ├── providers.php                Yüklenecek provider listesi
│   └── cache/                       Derlenmiş config/route (git'e girmez)
│
├── 🔵 config/                       ═══ AYARLAR (koddan ayrık) ═══
│   ├── ⭐ davetkart.php             Plan fiyatları, kotalar, modül→tier haritası
│   ├── ⭐ payment.php · ai.php      Sağlayıcı seçimi + anahtar referansları
│   ├── app.php · auth.php · cache.php · database.php
│   ├── filesystems.php · logging.php · mail.php · queue.php
│   ├── services.php · session.php · sanctum.php · cors.php
│
├── 🔵 database/
│   ├── migrations/                  ═══ ŞEMA GEÇMİŞİ (sürüm kontrollü) ═══
│   │   ├── 0001_..._create_users_table.php
│   │   ├── 0001_..._create_cache_table.php
│   │   ├── 0001_..._create_jobs_table.php
│   │   ├── ..._create_personal_access_tokens_table.php   (install:api)
│   │   └── ⭐ ..._create_invitations_table.php  vb.
│   ├── factories/                   Sahte veri üreticileri (test için)
│   └── seeders/                     Başlangıç/demo verisi
│
├── 🔵 public/                       🌐 WEB'E AÇIK TEK KLASÖR
│   ├── index.php                    ⚡ TÜM İSTEKLERİN GİRİŞ NOKTASI
│   ├── .htaccess                    URL rewrite (Apache)
│   └── storage → ../storage/app/public   (symlink)
│
├── 🔵 routes/
│   ├── api.php                      ⭐ Tüm API rotalarımız
│   ├── web.php                      SPA fallback
│   └── console.php                  Artisan komutları
│
├── 🔵 storage/
│   ├── app/public/                  Yüklenen dosyalar (web'e açık)
│   ├── app/private/                 Gizli dosyalar
│   ├── framework/{cache,sessions,views}/   Framework geçici verisi
│   └── logs/laravel.log             🔍 Hata ayıklamada İLK bakılacak yer
│
├── 🔵 tests/
│   ├── ⭐ Feature/Api/              Gerçek HTTP + gerçek DB testleri
│   ├── ⭐ Unit/Services/            TierResolver gibi saf mantık
│   └── TestCase.php
│
├── 🔵 vendor/                       Composer paketleri (git'e GİRMEZ)
│
├── 📁 docs/                         Mimari dokümanlarımız
├── 📄 CLAUDE.md                     Kod standartları
├── 🔵 artisan                        CLI giriş noktası
├── 🔵 composer.json                  Bağımlılıklar + PSR-4 haritası
├── 🔵 .env                           🔴 SIRLAR (git'e GİRMEZ)
├── 🔵 .env.example                   Sırsız şablon (git'e girer)
└── 🔵 phpunit.xml                    Test yapılandırması
```

---

# BÖLÜM 2 — Klasör Klasör: Mimari Rol + Algoritmik Rol

## 2.1 `public/index.php` — Her şeyin başladığı yer

**Mimari rolü:** *Front Controller* deseni. Tüm istekler tek bir kapıdan girer;
böylece kimlik doğrulama, loglama, hata yönetimi gibi çapraz kesen işler tek
yerde uygulanabilir. Ayrıca **güvenlik sınırı**: web sunucusunun kökü burasıdır,
`app/` ve `.env` dışarıdan erişilemez.

**Algoritmik rolü:**

```
1. Composer autoloader'ı yükle       (vendor/autoload.php)
2. Uygulama örneğini kur             (bootstrap/app.php)
3. Gelen HTTP isteğini nesneye çevir (Request::capture())
4. İsteği çekirdeğe gönder           ($app->handle($request))
5. Dönen yanıtı tarayıcıya bas       ($response->send())
6. Kapanış işlerini çalıştır         (terminate — log, session yazımı)
```

> **Üretimdeki en kritik ayar:** Web sunucusunun document root'u **`public/`**
> olmalı. Proje köküne ayarlanırsa `site.com/.env` erişilebilir olur ve tüm
> sırlarınız internete açılır.

---

## 2.2 `bootstrap/app.php` — Laravel 11+ kalbi

**Mimari rolü:** *Composition Root*. Uygulamanın tüm parçalarının birbirine
bağlandığı tek nokta. Laravel 11 öncesinde bu iş `Http/Kernel.php`,
`Console/Kernel.php` ve `Exceptions/Handler.php` arasında dağınıktı.

**Algoritmik rolü:**

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',        // 1. rotaları kaydet
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [                // 2. middleware zincirini kur
            SetLocaleFromHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (PaywallViolationException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        });                                        // 3. hata→HTTP eşlemesi
    })->create();
```

Bizim için en önemli kısım **3. adım**: `PaywallViolationException` fırlatıldığında
her yerde `try/catch` yazmak yerine, tek merkezden `402 Payment Required` yanıtına
çevriliyor.

---

## 2.3 `routes/api.php` — Adres defteri

**Mimari rolü:** *Routing table*. URL ile controller arasında **bildirimsel**
eşleme. Buraya iş mantığı yazılmaz — sadece "kim nereye" bilgisi.

**Algoritmik rolü:** Laravel isteği alır, bu tablodaki desenleri **yukarıdan
aşağıya** tarar, ilk eşleşeni çalıştırır. Eşleşme yoksa 404.

```php
// ——— Herkese açık ———
Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login',    [AuthController::class, 'login'])
     ->middleware('throttle:5,1');                      // brute-force koruması
Route::post('contact',       [ContactController::class, 'store']);

Route::prefix('public')->group(function () {
    Route::get('invitations/{slug}', [PublicInvitationController::class, 'show']);
    Route::post('invitations/{slug}/rsvps', [RsvpController::class, 'store'])
         ->middleware('throttle:10,1');                 // spam koruması
});

// ——— Kimlik doğrulaması zorunlu ———
Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::apiResource('invitations', InvitationController::class);
    Route::post('invitations/{invitation}/publish', [InvitationController::class, 'publish']);
    Route::get('invitations/{invitation}/rsvps',    [RsvpController::class, 'index']);
});
```

> **Neden `public` öneki?** Auth'suz rotalar tek grupta toplanınca,
> `auth:sanctum` middleware'ini yanlışlıkla unutma riski **yapısal olarak**
> ortadan kalkar. Varsayılan kapalı, istisna açıkça işaretli — *fail-safe* tasarım.

---

## 2.4 `app/Http/Middleware/` — Ara katmanlar

**Mimari rolü:** *Chain of Responsibility* (Sorumluluk Zinciri) deseni. Her
middleware isteği ya işler ya da bir sonrakine devreder. Çapraz kesen işler
(*cross-cutting concerns*) burada toplanır — controller'lara serpiştirilmez.

**Algoritmik rolü — soğan modeli:**

```
İstek →  [SetLocale] → [Throttle] → [Auth] → CONTROLLER
                                                  ↓
Yanıt ←  [SetLocale] ← [Throttle] ← [Auth] ←──────┘
```

Her katman hem giderken hem dönerken müdahale edebilir.

`SetLocaleFromHeader` örneği:

```
1. Accept-Language başlığını oku            ("tr-TR,tr;q=0.9")
2. Ana dil kodunu ayıkla                     ("tr")
3. Desteklenen 10 dil arasında mı? kontrol
4. Evetse app()->setLocale('tr'), değilse varsayılan
5. İsteği bir sonrakine devret
```

Sonuç: doğrulama hataları kullanıcının dilinde döner.

---

## 2.5 `app/Http/Requests/` — Giriş kapısı

**Mimari rolü:** Doğrulama ve yetkilendirmenin controller'dan **çıkarılması**.
Controller'da `if` bloğu bırakmamanın anahtarı. Aynı zamanda bir **güvenlik
sınırı**: doğrulanmamış veri Action katmanına asla ulaşamaz.

**Algoritmik rolü:**

```
1. authorize()  → false ise ⇒ 403, controller'a HİÇ girilmez
2. rules()      → kurallar çalışır
3. Başarısızsa  ⇒ 422 + {message, errors} otomatik döner
4. Başarılıysa  ⇒ controller çağrılır, $request->validated() TEMİZ veri verir
```

```php
class StoreRsvpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // public endpoint — yetki yok, ama iş kuralı Action'da
    }

    public function rules(): array
    {
        return [
            'guestName'      => ['required', 'string', 'max:120'],
            'guestCount'     => ['required', 'integer', 'min:1', 'max:20'],
            'status'         => ['required', Rule::enum(RsvpStatus::class)],
            'menuPreference' => ['nullable', 'string', 'max:64'],
            'message'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

> **Kritik güvenlik detayı:** Action içinde `$request->all()` değil,
> `$request->validated()` kullanılır. `all()` kullanıcının gönderdiği **her şeyi**
> döner — `"user_id": 1` gibi enjekte edilmiş alanlar dahil. `validated()` sadece
> `rules()` içinde tanımlı alanları döner. Bu, mass assignment saldırısına karşı
> **ikinci savunma hattı**dır (birincisi modeldeki `$fillable`).

---

## 2.6 `app/Http/Controllers/Api/V1/` — Köprü

**Mimari rolü:** Sunum katmanı. **Tek sorumluluğu HTTP'yi iş kuralına
bağlamak.** İş mantığı taşımaz — taşırsa aynı dosya hem HTTP değişince hem
kural değişince açılır (SRP ihlali).

**Algoritmik rolü — daima 4 adım:**

```
1. Yetki sor        → $this->authorize(...)
2. Action çağır     → $action->execute(...)
3. Resource'a sar   → new XResource($sonuç)
4. Döndür
```

```php
class InvitationController extends Controller
{
    public function publish(
        Invitation $invitation,                      // route model binding
        PublishInvitationAction $action              // DI ile enjekte
    ): InvitationResource {
        $this->authorize('update', $invitation);     // 1
        $published = $action->execute($invitation);  // 2
        return new InvitationResource($published);   // 3+4
    }
}
```

> **`Invitation $invitation` nasıl doldu?** *Route Model Binding*: Laravel
> `/api/invitations/42` isteğinde tip ipucuna bakar, `Invitation::find(42)`
> yapar, bulamazsa otomatik 404 döner. **Ama sahibini sormaz** — o yüzden
> 1. satırdaki `authorize` şart.

---

## 2.7 `app/Actions/` ⭐ — Uygulamanın kalbi

**Mimari rolü:** *Application Layer* / *Use Case*. Fowler'ın **Transaction
Script** deseninin modern uygulaması. Her sınıf tam olarak bir kullanım
senaryosunu temsil eder.

**Neden Service değil Action?** "Service" isimli sınıflar zamanla **her şeyi
yapan** dosyalara dönüşür (`InvitationService` → 800 satır, 20 metot). Action
ismin kendisi tekilliği dayatır: `PublishInvitationAction` içine `deleteRsvp`
koyamazsınız.

**Algoritmik rolü — `PublishInvitationAction` örneği:**

```
GİRDİ:  Invitation (yetkisi zaten kontrol edilmiş)
ÇIKTI:  Invitation (yayınlanmış hâli)

ADIMLAR:
  1. requiredTier ← TierResolver::requiredFor(invitation)     🔴 SUNUCUDA hesapla
  2. paidOrder    ← invitation.user.orders
                       .where(status = paid)
                       .where(tier.rank >= requiredTier.rank)
                       .first()
  3. EĞER paidOrder yoksa:
         FIRLAT PaywallViolationException      ⇒ 402
  4. TRANSACTION başlat
       4a. invitation.public_slug  ← Str::ulid()   (yoksa)
       4b. invitation.status       ← Published
       4c. invitation.published_at ← now()
       4d. kaydet
     TRANSACTION bitir
  5. YAYINLA InvitationPublished(invitation) event'i
  6. DÖNDÜR invitation
```

**Adım 4'te transaction neden?** 3 alan güncelleniyor. Ortasında bir hata olursa
davetiye "yayınlandı ama slug'ı yok" durumunda kalır — misafirler erişemez.
Transaction: ya hepsi ya hiçbiri (*atomicity*).

**Adım 5'te event neden?** Cache temizleme ve e-posta gönderme, *yayınlama*
mantığının parçası değil; onun **sonuçları**. Action'a koyarsak yarın "SMS de
gönder" dendiğinde Action'ı değiştirmemiz gerekir. Event ile Action'a hiç
dokunmadan yeni listener eklenir → **Open/Closed Principle**.

---

## 2.8 `app/Models/` — Veri katmanı

**Mimari rolü:** *Active Record*. Nesne hem veriyi hem veritabanı erişimini
taşır. `$fillable` bir **güvenlik beyaz listesi**, `$casts` bir **tip
dönüştürücü**.

**Algoritmik rolü:**

```php
class Invitation extends Model
{
    protected $fillable = [                    // 🔒 sadece bunlar toplu atanabilir
        'title', 'subtitle', 'names', 'venue', 'map_url',
        'show_gallery', 'show_gift', /* ... */
    ];                                          // user_id, status BİLEREK YOK

    protected function casts(): array
    {
        return [
            'status'        => InvitationStatus::class,   // string → Enum
            'event_at'      => 'datetime',                // string → Carbon
            'gift_options'  => 'array',                   // JSON → PHP dizisi
            'show_gallery'  => 'boolean',                 // "1" → true
        ];
    }

    public function timelineEvents(): HasMany { ... }
    public function user(): BelongsTo { ... }

    public function scopePublished(Builder $q): void      // yeniden kullanılabilir filtre
    {
        $q->where('status', InvitationStatus::Published);
    }
}
```

> **`user_id` ve `status` neden `$fillable`'da yok?** Saldırgan
> `{"title":"X", "user_id": 1, "status":"published"}` gönderirse, bu alanlar
> `$fillable`'da olmadığı için **sessizce yok sayılır.** Ödeme yapmadan yayına
> geçemez, başkasının hesabına yazamaz. `$guarded = []` yazmak bu korumayı
> tamamen kaldırır — CLAUDE.md'nizde de yasaklamışsınız, doğru.

---

## 2.9 `app/Enums/` ⭐ — Davranışlı sabitler

**Mimari rolü:** Sihirli string'leri yok eder. PHP 8 backed enum'ları sadece
değer değil **davranış** da taşıyabilir — bu sayede plan bilgisi tek yerde
toplanır.

**Algoritmik rolü:**

```php
enum SubscriptionTier: string
{
    case Standart = 'standart';
    case Gold     = 'gold';
    case Elit     = 'elit';

    public function rank(): int                    // karşılaştırma için
    {
        return match ($this) {
            self::Standart => 0,
            self::Gold     => 1,
            self::Elit     => 2,
        };
    }

    public function rsvpLimit(): ?int              // null = sınırsız
    {
        return $this === self::Standart ? 100 : null;
    }

    public function covers(self $required): bool   // "bu plan şunu kapsıyor mu?"
    {
        return $this->rank() >= $required->rank();
    }
}
```

`RsvpStatus` ise Türkçe/çok dilli arayüz metnini üretir:

```php
enum RsvpStatus: string
{
    case Attending = 'attending';    // DB'de İngilizce
    case Pending   = 'pending';
    case Declined  = 'declined';

    public function label(): string  // Arayüze giderken çevrilir
    {
        return __("rsvp.status.{$this->value}");   // tr → "Katılıyor"
    }
}
```

> Frontend `'Katılıyor'` bekliyor ama DB'ye Türkçe yazamayız — 10 dil var.
> Enum bu çeviriyi **tek yerde** kapsüller.

---

## 2.10 `app/Http/Resources/` ⭐ — Çıkış kapısı

**Mimari rolü:** *Anti-Corruption Layer* / DTO Mapper. İç modeli dış sözleşmeden
ayırır. Eloquent modelini doğrudan `return` etmek bu korumayı yok eder — o zaman
her kolon adı değişikliği API'yi kırar.

**Algoritmik rolü:**

```php
class InvitationPayloadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'title'         => $this->title,
            'mapUrl'        => $this->map_url,                    // ← dönüşüm
            'categoryId'    => $this->category_id,
            'palette'       => $this->palette,
            'showGallery'   => (bool) $this->show_gallery,        // ← tip garantisi
            'showGift'      => (bool) $this->show_gift,
            'giftOptions'   => $this->gift_options ?? [],         // ← null koruması
            'rsvpDeadline'  => $this->rsvp_deadline?->format('Y-m-d'),
            'date'          => $this->event_at?->format('Y-m-d\TH:i'),
            'timelineEvents'=> TimelineEventResource::collection(
                                  $this->whenLoaded('timelineEvents')  // ← N+1 koruması
                              ),
        ];
    }
}
```

**Üç savunma detayı:**

- `(bool)` — MySQL `tinyint(1)` döndürür, JSON'da `1` olur. Frontend `showGallery === true` kontrolü yaparsa kırılır.
- `?? []` — `null` gelirse frontend `.map()` çağırıp çöker. Boş dizi güvenli.
- `whenLoaded()` — ilişki eager-load edilmemişse **atlanır**, sessizce yeni sorgu atmaz. N+1'i yapısal olarak engeller.

---

## 2.11 `app/Policies/` ⭐ — Yetki kapısı

**Mimari rolü:** Yetkilendirme mantığının merkezileştirilmesi. **IDOR** (Insecure
Direct Object Reference) korumasının tek yeri.

**Algoritmik rolü:**

```
Controller: $this->authorize('view', $invitation)
                    ↓
Laravel: InvitationPolicy::view($currentUser, $invitation) çağır
                    ↓
         true  → devam et
         false → AuthorizationException → HTTP 403
```

```php
class InvitationPolicy
{
    public function view(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->user_id;
    }

    public function update(User $user, Invitation $invitation): bool
    {
        return $user->id === $invitation->user_id;
    }
}
```

> **Neden Route Model Binding yetmez?** `/api/invitations/42` isteğinde Laravel
> 42 numaralı kaydı **bulur** — ama kimin olduğunu **sormaz**. Policy koymazsanız
> herkes id'leri deneyerek herkesin davetiyesini okur/siler. OWASP Top 10'un
> birinci sırası.
>
> **Neden 403, 401 değil?** `services/api.ts`'teki interceptor 401 görünce
> oturumu düşürüyor. Yetki hatasında 401 dönersek kullanıcı sistemden atılır.

---

## 2.12 `app/Services/` ⭐ — Ports & Adapters

**Mimari rolü:** *Dependency Inversion* (SOLID'in D'si). Uygulama somut
sağlayıcıya değil, **arayüze** bağımlı olur.

```
   Action (üst seviye)
         ↓ bağımlı
   PaymentGateway (arayüz)  ← PORT
         ↑ uygular
   FakeGateway / IyzicoGateway  ← ADAPTER
```

Bağımlılık oku **yukarı** bakıyor: somut sınıf arayüze bağımlı, arayüz somuta
değil. Sağlayıcı değişince Action'a hiç dokunulmaz.

**Algoritmik rolü — bağlama `AppServiceProvider`'da:**

```php
public function register(): void
{
    $this->app->bind(PaymentGateway::class, function ($app) {
        return match (config('payment.driver')) {   // .env'den okunur
            'iyzico' => new IyzicoGateway(config('payment.iyzico.key'), ...),
            default  => new FakeGateway(),
        };
    });
}
```

Artık `StartCheckoutAction` yapıcısında `PaymentGateway $gateway` yazar; Laravel
doğru adaptörü otomatik enjekte eder. **Sağlayıcı değişimi = `.env`'de tek satır.**

### `TierResolver` — neden ayrı bir sınıf?

İçinde ne veritabanı ne HTTP var. Sadece saf mantık:

```php
final class TierResolver
{
    public function requiredFor(Invitation $invitation): SubscriptionTier
    {
        if ($invitation->show_gallery || $invitation->show_gift) {
            return SubscriptionTier::Elit;
        }
        if ($invitation->show_envelope || $invitation->show_timeline) {
            return SubscriptionTier::Gold;
        }
        return SubscriptionTier::Standart;
    }
}
```

Bu sayede projenin **ticari olarak en kritik kuralı**, veritabanı kurmadan,
mikrosaniyeler içinde çalışan birim testleriyle tam kapsama altına alınabilir.
Soyutlama bütçemizi buraya harcamamızın sebebi bu.

---

## 2.13 `app/Jobs/` ⭐ — Kuyruk

**Mimari rolü:** *Producer/Consumer*. Uzun işleri HTTP döngüsünden çıkarır.
Zorunlu, çünkü `services/api.ts` 15 saniyede isteği iptal ediyor.

**Algoritmik rolü:**

```
HTTP SÜRECİ (hızlı, ~200ms)          KUYRUK İŞÇİSİ (arka plan)
  1. Dosyayı diske yaz
  2. media kaydı oluştur
  3. Job'u kuyruğa at  ──────────────→  4. Görseli yeniden boyutlandır
  4. 201 Created dön                    5. WebP'ye çevir
     (kullanıcı beklemez)               6. Thumbnail üret
                                        7. media kaydını güncelle
```

Çalıştırma: `php artisan queue:work`

> Yerel geliştirmede `QUEUE_CONNECTION=sync` (job anında çalışır, işçi gerekmez),
> üretimde `database` veya `redis`.

---

## 2.14 `app/Events/` + `app/Listeners/` ⭐

**Mimari rolü:** *Observer* deseni + *Open/Closed Principle*. Modüller arası
gevşek bağ.

**Algoritmik rolü:**

```
PublishInvitationAction
      ↓ InvitationPublished::dispatch($invitation)
      │
      ├──→ ClearInvitationCache      Cache::forget("invitation:{$slug}")
      ├──→ NotifyInvitationOwner     Mail kuyruğa
      └──→ (yarın: SendSmsNotification — Action'a HİÇ dokunmadan eklenir)
```

Action sadece **"şu oldu"** der. Kimin dinlediğini bilmez, umursamaz.

---

## 2.15 `config/davetkart.php` ⭐ — İş kurallarının verisi

**Mimari rolü:** *12-Factor App* ilkesi — yapılandırmayı koddan ayır. Fiyat
değişince kod değişmemeli.

```php
return [
    'tiers' => [
        'standart' => ['price' => 249, 'rsvp_limit' => 100],
        'gold'     => ['price' => 399, 'rsvp_limit' => null],
        'elit'     => ['price' => 549, 'rsvp_limit' => null],
    ],
    'module_requirements' => [
        'show_gallery'  => 'elit',
        'show_gift'     => 'elit',
        'show_envelope' => 'gold',
        'show_timeline' => 'gold',
    ],
    'rsvp' => [
        'max_guests_per_entry' => 20,
        'max_photo_mb'         => 5,
        'max_video_mb'         => 25,
    ],
];
```

> **`config()` vs `env()`:** Kod içinde **asla** `env()` çağırmayın — sadece
> `config/` dosyalarında. Sebebi: `php artisan config:cache` çalıştırıldığında
> (üretimde zorunlu performans adımı) `env()` çağrıları **`null` döner** ve
> uygulamanız sessizce bozulur. Bu, Laravel'de en çok baş ağrıtan tuzaklardan
> biridir.

---

## 2.16 `database/migrations/` — Şemanın sürüm kontrolü

**Mimari rolü:** Veritabanı şeması da **koddur** ve git'te yaşar. Takım arkadaşınız
`php artisan migrate` deyince sizinle aynı şemaya sahip olur.

**Algoritmik rolü:**

```
php artisan migrate
  1. migrations tablosunu oku      → hangi dosyalar çalışmış?
  2. Çalışmamışları isimle sırala  (tarih öneki sayesinde kronolojik)
  3. Her biri için up() çalıştır
  4. migrations tablosuna kaydet

php artisan migrate:rollback
  → son batch'in down() metotlarını ters sırada çalıştır
```

```php
Schema::create('invitations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->ulid('public_slug')->unique();
    $table->string('status')->default('draft')->index();
    $table->boolean('show_gallery')->default(false);
    // ...
    $table->timestamps();
    $table->softDeletes();

    $table->index(['user_id', 'status']);      // dashboard sorgusu için bileşik indeks
});
```

> **Bileşik indeks neden `(user_id, status)` sırasında?** MySQL indeksleri
> **soldan sağa** kullanır. Bu sıra hem `WHERE user_id = ?` hem de
> `WHERE user_id = ? AND status = ?` sorgularını hızlandırır. Ters sıra
> `(status, user_id)` ise sadece `status` ile başlayan sorgulara yarar — bizim
> sorgumuz her zaman `user_id` ile başlıyor.

---

## 2.17 `storage/logs/laravel.log` — İlk bakılacak yer

Bir şey çalışmadığında **açacağınız ilk dosya budur.**

```powershell
Get-Content storage/logs/laravel.log -Tail 50 -Wait   # canlı takip
```

---

# BÖLÜM 3 — Bir İsteğin Uçtan Uca Algoritması

**Senaryo:** Misafir LCV gönderiyor →
`POST /api/public/invitations/01HX.../rsvps`

```
┌─ 1. public/index.php ─────────────────────────────────────────┐
│    vendor/autoload.php yükle → bootstrap/app.php ile uygulama │
│    kur → Request nesnesi üret                                 │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 2. MIDDLEWARE ZİNCİRİ ───────────────────────────────────────┐
│    SetLocaleFromHeader → Accept-Language: tr ⇒ locale = tr     │
│    throttle:10,1       → bu IP dakikada 10'u geçti mi?         │
│                          geçtiyse ⇒ 429, DUR                   │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 3. routes/api.php ───────────────────────────────────────────┐
│    Desen eşleşti → RsvpController@store                        │
│    {slug} = "01HX..."                                          │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 4. StoreRsvpRequest (FormRequest) ───────────────────────────┐
│    authorize() → true (public)                                 │
│    rules()     → guestName zorunlu mu? guestCount 1-20 mi?     │
│    ✗ ise ⇒ 422 {message, errors}, controller'a GİRİLMEZ        │
│    ✓ ise ⇒ validated() = TEMİZ veri                            │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 5. RsvpController@store  (3 satır) ──────────────────────────┐
│    $rsvp = $action->execute($slug, $request->validated());     │
│    return new RsvpResource($rsvp);                             │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 6. SubmitRsvpAction  ⭐ İŞ KURALLARI ────────────────────────┐
│    6.1 invitation ← Invitation::where(slug)->published()       │
│                       ->firstOrFail()          ✗ ⇒ 404         │
│    6.2 EĞER !invitation.show_rsvp              ⇒ 403           │
│    6.3 EĞER rsvp_deadline < bugün              ⇒ 422           │
│    6.4 tier  ← invitation sahibinin ödenmiş planı              │
│        limit ← tier.rsvpLimit()                                │
│        EĞER limit != null:                                     │
│            mevcut ← SUM(guest_count) WHERE invitation_id       │
│            EĞER mevcut + yeni > limit                          │
│                ⇒ RsvpQuotaExceededException (422)              │
│    6.5 rsvp yarat:                                             │
│            status  ← RsvpStatus::from(veri)                    │
│            ip_hash ← hash('sha256', ip . APP_KEY)   [KVKK]     │
│            created_at ← now()          [istemci saati DEĞİL]   │
│    6.6 RsvpReceived event yayınla                              │
│    6.7 rsvp döndür                                             │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 7. RsvpResource ─────────────────────────────────────────────┐
│    guest_name → guestName · guest_count → guestCount           │
│    status → RsvpStatus::Attending->label() → "Katılıyor"       │
│    created_at → createdAt (ISO 8601)                           │
│    ⇒ {"data": {...}}                                           │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 8. Middleware geri dönüşü → 201 Created → tarayıcı            │
└───────────────────────────────────────────────────────────────┘
                              ↓
┌─ 9. ARKA PLAN (kullanıcı çoktan yanıt aldı) ──────────────────┐
│    RsvpReceived listener'ları:                                 │
│      · davetiye sahibine e-posta (Job)                         │
│      · LCV listesi cache'ini temizle                           │
└───────────────────────────────────────────────────────────────┘
```

**Bu diyagramın öğrettiği ilke:** Her adımın **tek bir sorumluluğu** var. Kota
kontrolü Action'da (iş kuralı), format kontrolü Request'te (girdi), yetki
Policy'de, dönüşüm Resource'ta. Bir şey bozulduğunda **nereye bakacağınız
bellidir.**

---

# BÖLÜM 4 — "Bu kodu nereye koymalıyım?" Karar Ağacı

```
Yazacağım kod ne yapıyor?
│
├─ Gelen veri doğru formatta mı diye bakıyor
│     → app/Http/Requests/           (FormRequest)
│
├─ Bu kullanıcı bu kaynağa erişebilir mi diye bakıyor
│     → app/Policies/                (Policy)
│
├─ Bir iş kuralı uyguluyor, veri değiştiriyor
│     → app/Actions/<Domain>/        (Action)
│
├─ Veritabanı ilişkisi / scope / cast tanımlıyor
│     → app/Models/                  (Eloquent)
│
├─ Sabit bir değer kümesi tanımlıyor
│     → app/Enums/                   (Backed Enum)
│
├─ Dış bir sisteme (ödeme/AI/depolama) bağlanıyor
│     → app/Services/<Alan>/         (interface + adapter)
│
├─ Uzun sürüyor, kullanıcı beklememeli
│     → app/Jobs/                    (Queueable Job)
│
├─ Bir şey olduğunda tetiklenmeli, ana akışın parçası değil
│     → app/Events/ + app/Listeners/
│
├─ Her istekte çalışmalı (dil, log, rate limit)
│     → app/Http/Middleware/
│
├─ Veriyi frontend'e uygun formata çeviriyor
│     → app/Http/Resources/          (JsonResource)
│
├─ Değişebilecek bir sayı/ayar
│     → config/davetkart.php
│
└─ Sır (anahtar, şifre)
      → .env  (ve config/ üzerinden okunur — kodda env() ASLA)
```

**Controller'a ne yazılır?** Sadece şu dördü: `authorize` → `action->execute`
→ `new Resource` → `return`. Başka bir şey yazıyorsanız, yukarıdaki ağaçtan
doğru yeri bulun.

---

# BÖLÜM 5 — Dosya Adlandırma Kuralları

| Tür | Kural | Örnek |
|---|---|---|
| Action | `<Fiil><Nesne>Action` | `PublishInvitationAction` |
| Controller | `<Nesne>Controller` (tekil) | `InvitationController` |
| FormRequest | `<Fiil><Nesne>Request` | `StoreRsvpRequest` |
| Resource | `<Nesne>Resource` | `InvitationResource` |
| Policy | `<Nesne>Policy` | `InvitationPolicy` |
| Model | Tekil, PascalCase | `Invitation` |
| Tablo | Çoğul, snake_case | `invitations` |
| Enum | Tekil, PascalCase | `SubscriptionTier` |
| Job | `<Fiil><Nesne>` | `OptimizeUploadedImage` |
| Event | Geçmiş zaman | `InvitationPublished` |
| Listener | `<Fiil><Nesne>` | `ClearInvitationCache` |
| Migration | `create_<tablo>_table` | `create_invitations_table` |

> **Neden bu kadar katı?** Tutarlı isimlendirme, `PublishInvitationAction`
> dosyasını aramadan nerede olduğunu bilmenizi sağlar. 6 ay sonra kendi kodunuza
> döndüğünüzde bunun değerini anlarsınız.

---

## Özet

- ✅ Klasör isimlendirmeniz **doğru** — mimariyi kavramışsınız
- 🔴 Laravel kurulmamış: `vendor/`, `public/`, `artisan`, `composer.json` yok
- 🔴 `public/` olmadan uygulama **erişilemez**; `vendor/autoload.php` olmadan
  hiçbir sınıf **bulunamaz**
- ⏭️ Bölüm 0.3'teki komutları çalıştırın, sonra **Adım 2**'ye
  (`config/davetkart.php` + `app/Enums/`) geçelim
