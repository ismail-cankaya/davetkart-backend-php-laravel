# DavetKart Backend — Proje Giriş, Geliştirme Süreçleri ve İş Akışları Rehberi

> **Doküman no:** 11 · **Oluşturma:** 13 Eylül 2026
> **Kapsam:** Faz 0 → Faz 9 arası tüm geliştirme sürecinin toplu kaydı
> **Kimin için:** Projeye **ilk kez** katılan bir geliştirici (insan veya AI asistanı)
> **Okuma süresi:** ~45 dakika · Sonrasında kod tabanında güvenle çalışabilirsin
>
> **Bu doküman ne yapar:** `docs/03`, `docs/07`, `docs/08`, `docs/09`, `claude/FAZ-*-DEVIR.md`
> ve gerçek kod tabanını tek bir giriş kapısında birleştirir.
> **Bu doküman ne yapmaz:** Yeni karar üretmez. Bir çelişki görürsen sıralama şudur:
> **kod > `claude/PHP-LARAVEL-SETUP-EK-FAZ-*.md` > `docs/08` > `docs/07` > `docs/09` > `docs/03`.**

---

## İçindekiler

| # | Bölüm | Ne bulacaksın |
|---|---|---|
| **1** | [Proje Özeti ve Giriş Süreçleri](#1-proje-özeti-ve-giriş-süreçleri-onboarding-guide) | Vizyon, tech stack, sıfırdan kurulum |
| **2** | [Mimari ve Mevcut Kod Tabanı Durumu](#2-mimari-ve-mevcut-kod-tabanı-durumu) | Katmanlar, hata sözleşmesi, şema |
| **3** | [Temel İş Akışları ve Senaryolar](#3-temel-i̇ş-akışları-ve-senaryolar) | Request → Response, gerçek kod akışları |
| **4** | [Geliştirme Tarihçesi: Faz 0 → Faz 9](#4-geliştirme-süreci-faz-0dan-faz-9a-tarihçe) | Her fazın hedefi, çıktısı, sapmaları |
| **5** | [Gelecek Vizyonu ve Sonraki Adımlar](#5-gelecek-vizyonu-ve-sonraki-adımlar) | Açık borçlar ve öncelik sırası |
| **Ek A** | [Uç nokta haritası](#ek-a--tam-uç-nokta-haritası-21-uç) | 21 endpoint tek tabloda |
| **Ek B** | [Kaynak doküman haritası](#ek-b--kaynak-doküman-haritası) | Hangi soru hangi dosyada |
| **Ek C** | [Terim sözlüğü](#ek-c--terim-sözlüğü) | Projeye özgü kısaltmalar |

---

# 1. Proje Özeti ve Giriş Süreçleri (Onboarding Guide)

## 1.1 DavetKart nedir?

**DavetKart**, dijital davetiye üreten bir **SaaS** ürünüdür. Otuz saniyelik özeti:

> Kullanıcı bir davetiye tasarlar → bir plan **satın alır** → davetiyeyi **yayınlar** →
> linkini paylaşır. Misafirler linkten davetiyeyi görür, **LCV** (RSVP) yanıtı bırakır
> ve isterse fotoğraf/video ekler.

Kullanım senaryoları: düğün, kına, nişan, sünnet, doğum günü, mezuniyet, baby shower, parti.

### Ana vizyon — üç cümlede

1. **Ticari çekirdek paywall'dır.** Davetiye tasarlamak ücretsizdir; **yayınlamak** ücretlidir.
   Hangi modülleri açtığın hangi planı gerektirdiğini belirler ve bu hesap **her zaman
   sunucuda** yapılır (`TierResolver`).
2. **Misafir tarafı auth'suzdur ve en çok saldırıya açık yüzeydir.** Davetiyeyi okuma, LCV
   gönderme, medya yükleme — üçü de kimliği bilinmeyen kullanıcıya açıktır. Bu yüzden
   `/api/public/` öneki bir kolaylık değil, bir **fail-safe** tasarım kararıdır.
3. **Backend metin üretmez, kod üretir.** Kullanıcıya gösterilecek hiçbir cümle backend'den
   çıkmaz; API sadece makine-okunur bir `ErrorCode` döner, çeviri frontend'in işidir (K20/K21).

### Bu projenin ikinci amacı

Bu depo yalnızca bir ürün değil, bir **öğrenme kaydıdır.** Geliştirici (İsmail, bilgisayar
mühendisliği 3. sınıf) için hedef "kod üretmek" değil **mimari vizyon kazanmaktır**. Bu
yüzden kod tabanının her kritik satırında *neden* açıklanır, her fazda kurallar kaydedilir
ve `docs/rehber/` altında koddaki yolu **birebir yansıtan** bir eğitim kılavuzu ağacı durur:

```
app/Actions/Auth/RegisterUserAction.php
   → docs/rehber/app/Actions/Auth/RegisterUserAction.md
```

Bugün itibarıyla kayıt altına alınmış: **86 karar (K1–K86)**, **140 kural**, **64 ders**.

---

## 1.2 Ürün akışı — kullanıcı yolculuğu

```
┌── SAHİP (auth'lu) ────────────────────────────────────────────────┐
│  kayıt/giriş → davetiye oluştur → autosave ile düzenle            │
│       → modülleri aç (galeri, hediye, program, LCV…)              │
│       → plan satın al (tekil VEYA paket)                          │
│       → YAYINLA  ← 🔴 paywall kapısı burada                       │
│       → linki paylaş → LCV panelini 15 sn'de bir izle             │
└───────────────────────────────────────────────────────────────────┘
                              │  https://.../invite/{ULID}
                              ▼
┌── MİSAFİR (auth'suz) ─────────────────────────────────────────────┐
│  davetiyeyi oku (cache + ETag) → LCV gönder → foto/video ekle     │
└───────────────────────────────────────────────────────────────────┘
```

---

## 1.3 Teknoloji yığını (Tech Stack)

### Çekirdek

| Katman | Teknoloji | Sürüm | Neden bu seçildi |
|---|---|---|---|
| Dil | **PHP** | `^8.3` | Laravel 13'ün minimumu. Typed properties, backed enum, `readonly`, `match` |
| Framework | **Laravel** | `^13.8` | Hız önceliği; frontend zaten buna göre yapılandırılmış |
| ORM | **Eloquent** | (Laravel içinde) | Active Record. 🔴 Repository katmanı **yok** (K4) |
| Kimlik doğrulama | **Laravel Sanctum** | `^4.0` | İptal edilebilir Bearer token. JWT bunu karşılayamaz (K5) |
| Veritabanı | **PostgreSQL** | **18** | Dev/test/prod aynı motor — 12-Factor X (K9'/K19) |
| Konsol | **Laravel Tinker** | `^3.0` | Etkileşimli keşif |
| Paket yöneticisi | **Composer** | 2.x | — |
| Yerel ortam | **Laravel Herd** (Windows) | ücretsiz | PHP + nginx, sıfır kurulum |

### Ortam matrisi

| Konu | Geliştirme | Test | Üretim (hedef) |
|---|---|---|---|
| Veritabanı | PostgreSQL 18 (`davetkart`) | PostgreSQL 18 (`davetkart_test`) | PostgreSQL 18 |
| Cache | `file` | `array` | `file` → VPS'te `redis` (K80) |
| Kuyruk | `database` | `sync` | `database` → VPS'te `redis` + supervisor |
| Dosya | `public` diski | `local` / `Storage::fake()` | `s3` (K55 borcu) |
| Hash | Argon2id (64 MB) | Argon2id (1 MB — hız için) | Argon2id (ölçülecek) |
| `APP_DEBUG` | `true` | `false` | `false` |

> 🔴 **Neden testte de PostgreSQL?** SQLite `:memory:` testleri 3-5 kat hızlandırırdı ama
> `ENUM`, `jsonb`, `CHECK`, kısmi indeks ve satır kilidi davranışları farklıdır. Yanlış
> veritabanında koşan hızlı test **yanlış güven** verir.

### Kalite araçları — `composer check` zinciri

| Araç | İşi | Komut |
|---|---|---|
| **Laravel Pint** | PSR-12 biçimlendirici | `composer lint` / `vendor/bin/pint --test` |
| **Larastan (PHPStan)** | Statik analiz — **level 8** | `composer analyse` |
| **`errors:export --check`** | `ErrorCode` enum'u ↔ `contracts/error-codes.json` senkronu | `php artisan errors:export --check` |
| **PHPUnit** | 238 test (232 Feature + 6 Unit) | `php artisan test` |

```bash
composer check   # pint --test → phpstan → errors:export --check → phpunit
```

> 🔴 **`composer check` fail-fast çalışır.** PHPStan kırılırsa testler **hiç koşmaz**.
> "Yeşil gördüm" demek için çıktının **son satırına** bakmak zorundasın (kural 13).

---

## 1.4 Sıfırdan kurulum — adım adım

### Adım 0 — Ön koşullar

```powershell
php -v                 # 8.3+
composer -V            # 2.x
psql --version         # PostgreSQL 18
```

Gerekli PHP eklentileri (`php.ini`):

```
extension=pdo_pgsql     → veritabanı  🔴 pdo_mysql DEĞİL
extension=mbstring      → çok baytlı karakter (Türkçe)
extension=openssl       → şifreleme
extension=fileinfo      → 🔴 MIME doğrulaması — medya yüklemede ŞART
extension=gd            → görsel işleme (OptimizeUploadedImage)
extension=zip · curl · xml
```

```powershell
php -m | Select-String "pdo_pgsql|mbstring|openssl|fileinfo|gd"
```

> 🔴 **`fileinfo` neden kritik?** F1 kuralı "dosya tipi **içerikten** doğrulanır" der.
> `virus.php` dosyasını `photo.jpg` diye yeniden adlandırmak 2 saniyelik iştir; Laravel'in
> `mimetypes:` kuralı dosyanın ilk baytlarını okur ve bunu `fileinfo` yapar. Eklenti yoksa
> doğrulama **sessizce zayıflar**.

> ⚠️ **`docs/04-KURULUM-VE-KLASOR-YAPISI.md` §1 ve §4 GEÇERSİZDİR** — orada MySQL yazıyor.
> K9'/K19 ile üç ortamda da PostgreSQL 18 kullanılıyor. Aşağıdaki adımlar günceldir.

### Adım 1 — İki veritabanı oluştur

```sql
CREATE DATABASE davetkart;
CREATE DATABASE davetkart_test;
```

> **İkincisi opsiyonel değildir (kural V2).** `phpunit.xml` `DB_DATABASE=davetkart_test`
> diyor ve `RefreshDatabase` her koşuda tabloları siler. Tek veritabanı kullanılırsa
> ilk `php artisan test` geliştirme verini **siler**.

### Adım 2 — Bağımlılıklar ve `.env`

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

`.env` içinde doldurulması gerekenler:

| Değişken | Değer | Not |
|---|---|---|
| `DB_PASSWORD` | PostgreSQL parolan | `.env.example`'da bilerek boş |
| `DB_HOST` | `127.0.0.1` | 🔴 `localhost` **değil**: Windows'ta önce IPv6 (`::1`) denenir ve gecikir |
| `APP_URL` | `http://localhost:8000` | Medya URL'leri buradan türetilir |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | Vite sunucusu; sonda `/` **yok** |
| `PAYMENT_PROVIDER` | `fake` | 🔴 Bilinmeyen değer sessiz varsayılana düşmez, 503 verir (K70) |
| `AI_PROVIDER` | `null` | `NullProvider` ağa hiç çıkmaz — yapılandırma eksiği para harcamasın |
| `DAVETKART_MEDIA_DISK` | `public` | Üretimde `s3` (K55) |

### Adım 3 — Şema, tohum verisi ve depolama

```powershell
php artisan migrate
php artisan db:seed              # deterministik demo verisi, idempotent
php artisan storage:link         # 🔴 Faz 6'dan beri açık borç — atlanırsa medya URL'leri 404
```

### Adım 4 — Doğrula

```powershell
composer check                   # dördü de yeşil olmalı
php artisan serve                # http://localhost:8000
curl http://localhost:8000/api/ping
# → {"status":"ok"}
curl http://localhost:8000/api/olmayan-bir-yol
# → {"error":{"code":"RESOURCE_NOT_FOUND"}}   ← HTML DEĞİL
```

İkinci çıktı **kurulumun asıl kanıtıdır**: hata zarfı (K20) devrede demektir.

### Adım 5 — Frontend'i bağla (opsiyonel)

Frontend ayrı bir depodadır (`davetkart-frontent`, React 19 + TypeScript + Vite + Zustand)
ve Vite proxy'si backend'i **8000** portunda bekler.

> 🔴 **Frontend bugün backend'in ALTI faz gerisindedir.** Ödeme, yayınlama, LCV, medya,
> iletişim ve asistan uçlarının hepsi frontend'de ya mock ya da yanlış yola bağlı.
> Ayrıntı: [§5.2](#52-🔴-en-büyük-risk-frontend-yakalama-fazı).

---

## 1.5 Günlük çalışma ritmi

Bu proje bir **Pair Programming** seansı olarak yürütülür. Her dosya için ritim:

```
1. Komut         → php artisan make:*        (klasörü açar, namespace'i yazar)
2. Kod           → kısa yorumlarla
3. Kılavuz       → docs/rehber/<mimari-yol>/<dosya>.md   (K18)
4. Doğrulama     → composer check
5. DUR           → onay bekle
```

### Bağlayıcı çalışma kuralları

| # | Kural |
|---|---|
| 1 | **Tek dosya:** bir cevapta birden fazla dosya yazılmaz |
| 2 | **Gerekçe anlat:** hangi desen, hangi güvenlik/performans kazancı |
| 3 | **Onay bekle:** dosya yazıldıktan sonra DUR |
| 4 | Komutları İsmail çalıştırır (Windows + Herd) |
| 5 | Plandan sapılacaksa **önce tartışılır** |
| 6 | SOLID · Clean Code · PHPStan level 8 |
| 7 | Anlatım **Türkçe**, teknik terimler İngilizce jargonda |
| 8 | Kodda **kısa yorum**, detay `docs/rehber/<kod-yolu>.md` içinde (K18) |
| 10 | Her adım **yeşil** bitmeli; var olmayan sınıfa referans verilmez |
| 11 | Tahmin yürütme, kaynağa bak — `vendor/` okunabilir |
| 12 | Faz sonunda `FAZ-N.md` + `FAZ-N-ELLE-DOGRULAMA.md` yazılır, `docs/07`/`docs/09`/`claude/` güncellenir |
| 13 | "Yeşil gördüm" için zincirin **tamamı** koşmalı |
| 14 | **Beklediğin yanıtı almak, beklediğin sebeple aldığın anlamına gelmez** → mutasyon tablosu (T16) |

### Komut sözlüğü

| Komut | Ne yapar |
|---|---|
| `composer check` | Kalite kapısının tamamı |
| `composer lint` / `analyse` / `test` | Tek tek |
| `php artisan errors:export` | `ErrorCode` → `contracts/error-codes.json` (frontend çeviri senkronu) |
| `php artisan orders:expire [--dry-run]` | Ödeme penceresi dolmuş `pending` siparişleri `failed` yapar |
| `php artisan media:prune-orphans [--dry-run]` | LCV'ye bağlanmamış misafir yüklemelerini siler |
| `php artisan schedule:run` | Zamanlayıcı — sunucuda dakikada bir cron'dan çağrılır |


---

# 2. Mimari ve Mevcut Kod Tabanı Durumu

## 2.1 Mimari stil: Modüler Monolit + Action-Based Architecture

İlk gereksinim dokümanı "Microservices backend" diyordu. Bu **olduğu gibi uygulanmadı** ve
gerekçesi kaydedildi (K2):

> Mikroservisin çözdüğü problemler bağımsız ölçekleme, bağımsız deploy ve ekipler arası
> bağımsızlıktır. Buradaki durum: **tek geliştirici, tek sunucu, hız önceliği.** Kazanç
> sıfır; kayıp: dağıtık transaction, ağ gecikmesi, 5 kat deploy karmaşıklığı, dağıtık
> loglama. Conway Yasası.

Doğru okuma: *modülleri ileride ayrılabilecek şekilde sınırla, ama tek uygulama olarak
deploy et.* Buna **Modüler Monolit** denir.

🔴 **Önemli terim uyarısı:** "Modüler Monolit" burada bir **dağıtım** kararıdır, bir
klasörleme kararı değil. `app/Modules/` diye bir ağaç **yoktur**. Modül sınırları
**alt klasör disipliniyle** korunur: `app/Actions/Auth/`, `app/Actions/Invitation/`,
`app/Actions/Payment/`…

| Eksen | Seçim | Kayıt |
|---|---|---|
| **Klasörleme** (dosyalar neye göre gruplanır) | Katman-bazlı + Action katmanı | K2, K3 |
| **İnşa sırası** (dosyalar hangi sırayla yazılır) | Özellik-özellik (9 faz) | K17 |
| **Dağıtım** | Tek uygulama | K2 |

### Yedi bounded context

| Modül | Tablo | Uç | Faz |
|---|---|---|---|
| Auth | `users` | 4 | 2 |
| Invitation | `invitations`, `timeline_events` | 7 | 3 · 4 · 7 · 9 |
| RSVP | `rsvps` | 3 | 5 |
| Media | `media` | 2 | 6 |
| Payment | `orders` | 3 | 7 · 9 |
| Assistant | `assistant_usages` | 1 | 8 |
| Contact | `contact_messages` | 1 | 8 |

---

## 2.2 Katman modeli — bir isteğin yolculuğu

```
HTTP İsteği
   │
   ├─[ bootstrap/app.php ]  ForceJsonResponse (prepend) → throttleApi → SecurityHeaders (append)
   │
   ├─[ routes/api.php ]     rota eşlemesi — mantık YOK, whereUlid kısıtı
   │
   ├─[ Middleware ]         auth:sanctum · throttle:<kova> · SetEtag
   │
   ├─[ FormRequest ]        doğrulama + camelCase→snake_case eşlemesi
   │
   ├─[ Controller ]         3-8 satır: Gate::authorize → Action çağır → Resource döndür
   │
   ├─[ Action ]             ⭐ İŞ KURALI BURADA (tek sınıf, tek iş)
   │      ├─[ Contracts/ ]  uygulamanın kendi soyutlamaları
   │      └─[ Services/ ]   dış dünya adaptörleri (Payment, Ai, Pricing)
   │
   ├─[ Model/Eloquent ]     #[Fillable] beyaz listesi · cast · ilişki · scope · $dispatchesEvents
   │
   ├─[ Resource ]           snake_case → camelCase, beyaz liste
   │
   └─ JSON Yanıtı        ── hata olursa → ApiExceptionRenderer → { error: {...} }
```

### Katman sorumlulukları — kesin sınırlar

| Katman | Yapar | 🔴 ASLA yapmaz |
|---|---|---|
| `routes/api.php` | Eşleme, middleware, `whereUlid` kısıtı | İş kuralı, closure eylemi (K30) |
| `app/Http/Requests/` | Doğrulama, yetkilendirme kabuğu, camelCase→snake_case | Veritabanı yazma; **üst kaynak aidiyeti** sorma (L5) |
| `app/Http/Controllers/Api/V1/` | `Gate::authorize` → Action → Resource | İş kuralı taşıyan `if` |
| `app/Actions/` | Tek eylem, iş kuralı, DB + yan görevler | HTTP yanıtı döndürmek (H10), yeniden doğrulama |
| `app/Models/` | `#[Fillable]`, cast, ilişki, **sorgu kapsamı** | `$guarded = []` |
| `app/Http/Resources/` | snake→camel dönüşümü — **tek yer** | Sihirli otomatik dönüştürücü |
| `app/Policies/` | Sahiplik / IDOR — cevabı `bool` | Bilgi taşıyan red (P6 → paywall Policy'de değil) |
| `app/Contracts/` | Uygulamanın **kendi** soyutlamaları | Dış servis arayüzü |
| `app/Services/<Alan>/` | Dış servis arayüzü + uygulaması; **sırlar burada** | Kendi veritabanımıza soru sormak |
| `app/Enums/` | Sihirli string yasağı + **kural taşıyıcı** | Gösterim metni (K21) |
| `app/Jobs/` | 15 saniyeyi aşabilecek işler | Senkron çağrılmak |
| `app/Console/Commands/` | Bakım komutları (Faz 9) | `--dry-run`suz olmak (K84) |

> 🔴 **`Contracts/` ile `Services/` ayrımı** — "arayüz nerede duruyor" değil, **"kimin
> sorusunu soyutluyor"**: kendi veritabanımıza bakan bir soru (`RsvpQuotaResolver`,
> `PublishEntitlementResolver`) → `Contracts/`. Dış bir servise giden çağrı
> (`PaymentGateway`, `AiProvider`) → `Services/<Alan>/`.

### Controller'da `if` kuralı — Faz 6'da gevşetildi

`if` kategorik olarak yasak değildir. Yasak olan **iş kuralının** controller'a taşınmasıdır.
Ayırt edici soru:

> *"Bu `if` silinirse bozulan şey bir **iş kuralı** mı, yoksa yalnızca **hangi kodun
> çağrıldığı** mı?"*

Faz 7 bu esnekliği **kullanmadı**: `PaymentController` iki kolu iki ayrı metoda ayırdı
(`forInvitation` / `forAccount`), çünkü ayrım rota seviyesinde zaten yapılmıştı.

---

## 2.3 Klasör yapısı — gerçekleşen hâli

```
davetkart-backend-php-laravel/
├── app/
│   ├── Actions/                       ⭐ İş kuralları — 21 sınıf
│   │   ├── Assistant/AskAssistantAction.php
│   │   ├── Auth/{Register,Login,RevokeToken}…Action.php
│   │   ├── Contact/SubmitContactAction.php
│   │   ├── Invitation/{Create,Update,Publish,Delete,SyncTimelineEvents,ResolvePublic}…
│   │   ├── Media/{StoreUploadedMedia,StoreGuestMedia}Action.php
│   │   ├── Payment/{StartCheckout,HandlePaymentCallback,ClaimReleasedOrder}Action.php
│   │   │            + CheckoutResult.php  (DTO)
│   │   └── Rsvp/{SubmitRsvp,ResolveOpenRsvpInvitation}Action.php
│   │
│   ├── Console/Commands/              🆕 Faz 9 — bakım
│   │   ├── ExpireStaleOrders.php · PruneOrphanMedia.php · ExportErrorCodes.php
│   │
│   ├── Contracts/                     Kendi soyutlamalarımız
│   │   ├── PublishEntitlementResolver.php   (K42)
│   │   └── RsvpQuotaResolver.php            (K51)
│   │
│   ├── Enums/                         8 enum — sihirli string yok
│   │   ├── ErrorCode.php  ← 🔴 hata sözleşmesinin tek doğruluk kaynağı (21 kod)
│   │   ├── InvitationStatus · RsvpStatus · MediaKind · ContactSubject
│   │   └── SubscriptionTier · OrderStatus · OrderScope
│   │
│   ├── Events/InvitationChanged.php   Listeners/ClearInvitationCache.php
│   │
│   ├── Exceptions/                    11 dosya
│   │   ├── ApiExceptionRenderer.php   ← 🔴 tek hata çıkışı
│   │   ├── HasErrorCode.php           ← arayüz: exception kendi kodunu söyler
│   │   └── (9 alan exception'ı)
│   │
│   ├── Http/
│   │   ├── Controllers/Api/V1/        12 controller — versiyon namespace'te
│   │   ├── Middleware/{ForceJsonResponse, SetEtag, SecurityHeaders}.php
│   │   ├── Requests/                  Alan alan klasörlenmiş + Concerns/HasHoneypot
│   │   └── Resources/                 9 Resource — camelCase dönüşümünün TEK yeri
│   │
│   ├── Jobs/OptimizeUploadedImage.php
│   ├── Models/                        8 model
│   ├── Policies/{Invitation,Rsvp}Policy.php
│   ├── Providers/AppServiceProvider.php   ← bağlamalar + 6 rate limiter
│   ├── Services/{Ai,Payment,Pricing,Rsvp}/
│   └── Support/IpHasher.php           ← katmansızlık işareti, yeni bir katman değil
│
├── bootstrap/app.php                  Laravel 11+: middleware + exception kaydı (Kernel.php YOK)
├── config/                            davetkart.php · payment.php · ai.php · cors.php + Laravel'inkiler
├── contracts/error-codes.json         `errors:export` çıktısı → frontend çeviri senkronu
├── database/{migrations,factories,seeders}/
├── docs/                              01…11 + rehber/ (dosya-bazlı eğitim kılavuzları)
├── claude/                            Faz devir dosyaları ve yamalar
├── routes/{api.php, console.php}      🔴 web.php Faz 9'da SİLİNDİ
└── tests/{Feature,Unit}/              238 test
```

### 🔴 Neden `Repositories/` yok? (K4)

Eloquent **zaten Active Record**'dur; modelin kendisi veri erişim katmanıdır. Üstüne bir
Repository koymak çoğu projede `findById($id) { return Model::find($id); }` gibi anlamsız
aracılar üretir. Gerçek soyutlama ihtiyacı **dış sistemler** içindir (ödeme, AI, depolama)
ve onlar `Services/` altında arayüzle soyutlanmıştır. Veritabanı için soyutlama, ORM'i
değiştirmeyi planlamıyorsan **YAGNI** ihlalidir.

> Bu bir tercih, dogma değil. Karmaşık sorgular çoğalırsa `Models/Scopes/` veya query
> object'lere geçilir — ama peşinen katman eklenmez (K15: soyutlama bütçesi).

---

## 2.4 API sözleşmesi

### Versiyonlama: URL'de yok, namespace'te var (K10)

Frontend `baseURL = '/api'` kullanıyor. `/api/v1/...` yazılsaydı frontend **anında kırılırdı**.

- **URL:** `/api/auth/login` (düz)
- **Namespace:** `App\Http\Controllers\Api\V1\AuthController`

Kod organizasyonu bugünden v2'ye hazır; URL sözleşmesi bozulmuyor.

### Yanıt zarfı — ikili kural (K11 / C2 / C8)

| Uç grubu | Zarf | Sebep |
|---|:---:|---|
| `POST /auth/login`, `POST /auth/register` | ❌ **YOK** — düz `{user, token}` | `services/auth.ts` doğrudan `data.user` okuyor |
| **Diğer her şey** (`/auth/me` dâhil) | ✅ `{data: ...}` | Laravel varsayılanı |

```php
// Auth — zarfsız. ::make değil ->resolve() ile zarf soyulur.
return response()->json([
    'user'  => (new UserResource($user))->resolve(),
    'token' => $token,
], $status);
```

> 🔴 **C8: zarf istisnası büyütülmez.** İstisna **ad ad** tanımlıdır: yalnızca `login` ve
> `register`. `/auth/me` zarflıdır. Asistan ucu "tek satır kısalırdı" diye zarfsız
> yapılmadı — bir istisnayı kolay olduğu için büyütmek, sözleşmeyi kuralsız bırakmanın
> ilk adımıdır.

### Alan adları — camelCase, dönüşüm tek yerde

DB `snake_case` ↔ API `camelCase`. Dönüşüm **yalnızca Resource katmanında** ve **açıkça**
yazılır; ara katmanda otomatik dönüştürücü yoktur.

```php
'mapUrl'         => $this->map_url,
'showGallery'    => (bool) $this->show_gallery,
'rsvpDeadline'   => $this->rsvp_deadline?->format('Y-m-d'),
```

Ters yön: FormRequest camelCase doğrular, `…Attributes()` metodu snake_case'e eşler,
Action saf veri alır.

### `/api/public/` öneki — fail-safe tasarım (K12)

Auth gerektirmeyen **her** rota tek grupta toplanır. Gerekçe kolaylık değil güvenliktir:
`auth:sanctum` unutulursa bir davetiye herkese açılır. Önek, "açık olmayı" bir
**unutmanın sonucu** olmaktan çıkarıp **açıkça işaretlenmiş bir istisna** yapar.

> ⚠️ Buraya bir rota eklemek, onu internete açmaktır. Önce şu soru cevaplanır:
> *"Bu veriyi kimliği bilinmeyen biri görebilir mi?"*

Bu grupta bugün **5 uç** var: 1 okuma + 4 yazma yolu (LCV, misafir medyası, ödeme
webhook'u, iletişim formu). Dördünün de tehdit modeli farklıdır — [§3.6–3.8](#36-i̇ş-akışı--lcv-rsvp-gönderimi-sistemin-en-savunmasız-noktası)'e bak.


---

## 2.5 🔴 Hata yönetimi standardı

> Tam sözleşme: [`docs/08-HATA-SOZLESMESI.md`](08-HATA-SOZLESMESI.md). Bu bölüm onun
> **uygulanmış hâlini** anlatır.

### Temel karar (K20): backend olayı bildirir, frontend anlatır

Backend API yanıtlarında **kullanıcıya gösterilecek metin döndürmez.** Yerine makine
tarafından okunabilir bir **hata kodu** döner.

```
Backend:   "email alanı unique kuralını ihlal etti"     → kod
Frontend:  t('validation.unique', { field: t('fields.email') })
             tr → "E-posta adresi zaten kullanılıyor"
             de → "E-Mail-Adresse wird bereits verwendet"
```

Metin backend'den gelseydi üç şey birden bozulurdu: **dil** (backend 10 dil taşımak
zorunda kalırdı — tekrar), **esneklik** (frontend metni bağlama göre değiştiremez) ve
**test** (metin değişince test kırılır, oysa davranış aynıdır).

İkinci gerekçe **bilgi ifşasını azaltmaktır**: kod sabit bir tanımlayıcıdır, iç durumu
anlatmaz.

### Zarf tasarımı

**Üretim (`APP_DEBUG=false`):**

```json
{ "error": { "code": "VALIDATION_FAILED",
             "fields": { "guestCount": [{ "rule": "max", "params": { "max": 10 } }] } } }
```

**İş kuralı hatası:**

```json
{ "error": { "code": "PAYWALL_TIER_INSUFFICIENT", "params": { "requiredTier": "elit" } } }
```

**Yerel (`APP_DEBUG=true`)** — üstüne `debug` bloğu eklenir:

```json
"debug": { "message": "...", "exception": "Illuminate\\Validation\\ValidationException",
           "file": "app/Http/Requests/Rsvp/StoreRsvpRequest.php", "line": 42 }
```

> 🔴 **H3:** `debug` bloğu ortam bayrağına bağlı **üretilir** — üretimde kod hiç çalışmaz.
> "Unutulup açık kalması" mümkün değildir. Güvenlik *disipline* değil **yapıya** bağlanır.

### Uygulama: üç parça

**1. `app/Enums/ErrorCode.php` — tek doğruluk kaynağı (21 kod)**

Enum üç şeyi birden taşır:

```php
public function status(): int          // her kodun TEK ve değişmez HTTP karşılığı
public function allowedParams(): array // 🔴 dışarı verilebilecek parametre BEYAZ LİSTESİ
public function filterParams(array $p): array  // beyaz listeyi ZORLAR — kodda, belgede değil
public function isRetryable(): bool    // 429 / 502 / 503
```

Kodda `'RSVP_QUOTA_EXCEEDED'` düz metni **asla** yazılmaz; `ErrorCode::RsvpQuotaExceeded`
yazılır. Yazım hatası çalışma anında değil **anında** yakalanır.

**2. `app/Exceptions/HasErrorCode.php` — arayüz**

```php
interface HasErrorCode { public function errorCode(): ErrorCode;
                         public function errorParams(): array; }
```

🔴 Faz 5'te doğdu ve **H11'i tip sistemine bağladı.** Öncesinde her yeni exception
renderer'a bir `match` kolu istiyordu; unutulursa **500** dönüyordu — yani bir istemci
hatası sunucu hatası gibi görünüyordu. Bugün 9 alan exception'ı bu arayüzü uygular ve
renderer'a **hiç dokunulmaz**.

**3. `app/Exceptions/ApiExceptionRenderer.php` — tek çıkış noktası**

`bootstrap/app.php` içinde kablolanır:

```php
$exceptions->render(
    fn (Throwable $e, Request $request) => $request->is('api/*') || $request->expectsJson()
        ? app(ApiExceptionRenderer::class)->render($e)
        : null,
);
```

> 🔴 **İki koşul, iki farklı durum.** `expectsJson()` → rota **eşleşti**, `ForceJsonResponse`
> `Accept`'i ezdi. `is('api/*')` → rota **eşleşmedi**; Router middleware çalışmadan
> `NotFoundHttpException` fırlatır ve grup üyeliği diye bir şey olmaz. Bu satır Faz 1'de
> **eksikti** ve `html_request_to_api_still_receives_json` testi yazıldığı günden beri
> hiç geçmemişti — Faz 2'de bulunup düzeltildi.

Eşleme sırası (`resolveCode`):

```
ValidationException        → VALIDATION_FAILED (+ fields)
HasErrorCode               → 🔴 exception KENDİ kodunu söyler
AuthenticationException    → UNAUTHENTICATED
ThrottleRequestsException  → RATE_LIMITED (+ retryAfter)
PostTooLargeException      → FILE_TOO_LARGE
ModelNotFound | Authorization → RESOURCE_NOT_FOUND   ← 🔴 H7
HttpExceptionInterface     → durum kodundan geri eşleme
default                    → SERVER_ERROR
```

### HTTP durum kodu ↔ hata kodu

| Durum | Anlam | Kodlar |
|:---:|---|---|
| **400** | İstek biçimsel olarak bozuk | `MALFORMED_REQUEST` |
| **401** | Kimlik yok / geçersiz | `UNAUTHENTICATED` · `INVALID_CREDENTIALS` · `TOKEN_EXPIRED` |
| **402** | Ödeme gerekli | `PAYWALL_TIER_INSUFFICIENT` · `PAYMENT_REQUIRED` |
| **403** | Kimlik var, işlem yasak | `INVITATION_LOCKED` · `RSVP_DEADLINE_PASSED` · `RSVP_QUOTA_EXCEEDED` · `MEDIA_QUOTA_EXCEEDED` |
| **404** | Kaynak yok **veya senin değil** | `RESOURCE_NOT_FOUND` |
| **409** | Durum çakışması | `INVITATION_ALREADY_PUBLISHED` · `SLUG_TAKEN` |
| **413** | Dosya çok büyük | `FILE_TOO_LARGE` |
| **422** | Doğrulama başarısız | `VALIDATION_FAILED` · `REGISTRATION_FAILED` |
| **429** | Hız sınırı **ve** zamana bağlı kota | `RATE_LIMITED` · `ASSISTANT_QUOTA_EXCEEDED` |
| **500** | **Bizim** kodumuz | `SERVER_ERROR` |
| **502** | **Yukarı akış** cevap verdi ama hatalı | `PAYMENT_PROVIDER_ERROR` |
| **503** | **Bu servis** geçici olarak veremiyor | `PROVIDER_UNAVAILABLE` |

### 🔴 İhlal edilemez üç kural

**1. 401 ile 403 ayrımı.** Frontend `api.ts` interceptor'ı 401'de **oturumu düşürür**.
Yanlış kod kullanıcıyı sistemden atar. (Frontend ayrıca `INVALID_CREDENTIALS`'ı ayırıp
oturumu düşürmüyor — yanlış parola girmek oturum düşürmemeli.)

**2. Sahiplik yoksa 404, 403 değil (H7).** 403 *"bu kaynak var ama senin değil"* der ve
kaynağın **varlığını doğrular**; saldırgan ULID uzayını tarayıp haritalayabilir. 404 hiçbir
ayrım vermez. `AuthorizationException` bu yüzden `RESOURCE_NOT_FOUND`'a eşlenir.

> ⚠️ Bu, `CLAUDE.md`'nin *"yetki hatası 403"* kuralının **istisnasıdır**. 403 yalnızca
> **sahiplik doğrulanmış ama işlem yasak** durumlarında kullanılır (son tarih geçti, kota doldu).

**3. Kullanıcı sayımı (enumeration) savunması.**

| Uç | ❌ Yasak | ✅ Zorunlu |
|---|---|---|
| `POST /auth/login` | "Parola hatalı" / "Kullanıcı yok" ayrımı | Her iki durumda `INVALID_CREDENTIALS`, `fields` **yok** |
| `POST /auth/register` | `fields: {email:[{rule:"unique"}]}` | `REGISTRATION_FAILED`, `fields` **yok** |

Bu yüzden `RegisterRequest`'te `unique` kuralı, `LoginRequest`'te `exists` kuralı
**bilerek yoktur** (A1). Benzersizlik veritabanı UNIQUE kısıtıyla korunur, ihlali
`UniqueConstraintViolationException` olarak yakalanır.

### `params` beyaz listesi (H9) — kodda zorlanır

```php
self::PaywallTierInsufficient, self::PaymentRequired => ['requiredTier'],
self::RsvpQuotaExceeded  => ['remaining', 'limit'],
self::MediaQuotaExceeded => ['limit'],            // 🔴 'remaining' YOK
self::AssistantQuotaExceeded => ['retryAfter', 'limit'],
default => [],                                     // varsayılan: hiçbiri
```

> `MediaQuotaExceeded`'da `remaining` neden yok? Kalan sayı kaç dosyanın yüklendiğini ele
> verir ve **misafirin** yükleme ucu da aynı kodu döndürüyor. Bir alan sızıntıysa,
> "sahibe göstermek için" bırakılmaz.

### Asla yanıta girmeyenler

| Sızıntı | Nereye gider |
|---|---|
| Yığın izi, dosya yolu, satır no | Yalnızca `debug` bloğu (yerel) |
| SQL sorgusu / veritabanı hata metni | Yalnızca log |
| Sağlayıcı ham hataları (Iyzico, Gemini) | Log; dışarı `PAYMENT_PROVIDER_ERROR` (502) veya `PROVIDER_UNAVAILABLE` (503) |
| Sürüm bilgisi (PHP, Laravel) | Hiçbir yere |

### Katalog senkronizasyonu

```bash
php artisan errors:export          # ErrorCode → contracts/error-codes.json
php artisan errors:export --check  # kalite kapısında: enum ile dosya ayrıştıysa KIRILIR
```

Frontend bu JSON'dan çeviri anahtarlarını türetir. Tek yönlü üretim iki depoyu birbirine
**bağlamaz** — dosya kopyalanır, bağımlılık oluşmaz.

> 🔴 **Bir hata kodu adı yayınlandıktan sonra sözleşmedir.** Yeniden adlandırmak, bir API
> alanını yeniden adlandırmakla aynı kırıcılıktadır. `SLUG_TAKEN` bugün hiç kullanılmıyor
> (K66) ama **silinmiyor**.

### Neden RFC 9457 (Problem Details) kullanılmıyor?

`title` ve `detail` alanları **insan tarafından okunabilir metin** zorunlu kılar — K20'nin
tam olarak yasakladığı şey. Standarda uymak için İngilizce cümle üretip frontend'in onu
görmezden gelmesini beklemek ölü kod olurdu. Bu zarf RFC 9457'nin **makine-okunur
çekirdeğini** (`code`, `status`) alır, metin kısmını atar.


---

## 2.6 Veritabanı şeması ve temel ilişkiler

### İlişki haritası

```
                        ┌──────────────┐
                        │    users     │  bigint PK
                        │ first_name   │  🔴 K35: ad ve soyad AYRI kolon
                        │ last_name    │
                        │ email UNIQUE │
                        │ password     │  Argon2id
                        └──────┬───────┘
                               │ 1
              ┌────────────────┼────────────────────┐
              │ n              │ n                  │ n
   ┌──────────▼─────────┐  ┌───▼────────┐   ┌───────▼──────────┐
   │    invitations     │  │   orders   │   │ assistant_usages │
   │  ULID PK  (=link)  │  │  ULID PK   │   │  UNIQUE(user,gün)│
   │  status CHECK      │◄─┤ invitation │   │  message_count   │
   │  6 × show_* bool   │n │ _id NULL   │   └──────────────────┘
   │  timezone          │  │ scope 🆕   │
   │  softDeletes       │  │ tier·status│
   └───┬──────┬──────┬──┘  │ amount_    │
       │1     │1     │1    │  minor int │      ┌──────────────────┐
       │n     │n     │n    │ provider_  │      │ contact_messages │
┌──────▼───┐ ┌▼─────┐ ┌────▼──┐ ref UNIQ│      │  ip_hash         │
│ timeline │ │rsvps │ │ media │─────────┘      │  handled_at      │
│ _events  │ │ULID  │ │ ULID  │                └──────────────────┘
│ bigint   │ │ip_   │ │ disk  │
│sort_order│ │ hash │ │ kind  │◄── rsvps.photo_media_id  (nullOnDelete)
└──────────┘ └──┬───┘ └───────┘◄── rsvps.video_media_id  (nullOnDelete)
                └────────────────────┘
```

Ek olarak Laravel/Sanctum'un kendi tabloları: `personal_access_tokens`, `cache`,
`cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

### Tablo tablo — kritik kararlar

| Tablo | PK | Kritik alanlar / kısıtlar | Neden |
|---|---|---|---|
| `users` | `bigint` | `first_name` + `last_name` (60), `email` UNIQUE | **K35** — birleştirmek kolay, birleşmiş veriyi ayırmak imkânsız ("Ayşe Nur Kaya" bölünemez). API'de de **ayrı** döner; `fullName` üretilmez |
| `invitations` | **ULID** | `status` VARCHAR+CHECK · 6 × `show_*` **ayrı boolean** · içerik alanları **nullable** · `timezone` · `jsonb gift_options` · softDeletes · `INDEX(user_id,status)` | **K40** — id hem dahili kimlik hem **paylaşılan linkin kendisi**; ayrı `public_slug` yok. **K6** — paywall SQL ile doğrulanabilsin diye JSON değil kolon |
| `timeline_events` | `bigint` | `foreignUlid invitation_id` CASCADE · `sort_order` · `INDEX(invitation_id,sort_order)` | Kimliği hiçbir URL'de geçmediği için bigint kaldı |
| `rsvps` | **ULID** | `guest_count` CHECK `>= 1` · `status` CHECK · `ip_hash CHAR(64)` · `photo/video_media_id` **nullOnDelete** · `INDEX(invitation_id,status)` | **K52** — kimlik `DELETE /api/rsvps/{id}` URL'sinde geçiyor. **K60** — misafirin yazdığı metin, eklediği fotoğraftan bağımsız bir veridir |
| `media` | **ULID** | `disk` **kolonda** · `kind` · `mime` · `size` · `path` | **K54/F4** — config *"şu an nereye yazıyoruz"*, kolon *"o dosya nereye yazılmıştı"* der. S3 göçü eski satırları **kırmaz** |
| `orders` | **ULID** | `amount_minor` **integer** CHECK `>0` · `currency CHAR(3)` · `provider` · `provider_ref` **UNIQUE** · `scope` 🆕 · 4 CHECK | **M5** para kuruşta tam sayı · **M7** para birimi ve sağlayıcı satırda · **E11** `status IN (paid,refunded) ⟺ paid_at IS NOT NULL` |
| `assistant_usages` | `bigint` | `UNIQUE(user_id, usage_date)` · `message_count` | **K73/Q2** — bir **para kontrolü cache'te durmaz** |
| `contact_messages` | `bigint` | `subject` CHECK · `ip_hash` · `handled_at` | Yazan var, **okuyan yok** (açık borç) |

### 🔴 Dört tasarım detayının gerekçesi

**1. Neden ULID birincil anahtar?**
Artan integer kullanılsaydı misafir `/invite/1`, `/invite/2` diye gezip başkalarının
davetiyelerini okurdu (*enumeration attack*). ULID tahmin edilemez ama **zaman sıralıdır**,
dolayısıyla UUIDv4'ün indekste yol açtığı sayfa parçalanmasını yaşatmaz. Kural şu:
**kimliği bir URL'de geçen her tablo ULID alır** — `invitations` (K40), `rsvps` (K52),
`media` (K56), `orders`. `timeline_events` bigint kaldı çünkü kimliği hiçbir URL'de geçmez.

**2. Neden `ip_hash`, ham IP değil?**
Ham IP KVKK/GDPR kapsamında **kişisel veridir**. Spam tespiti için gereken tek şey "aynı
kişi mi" sorusunun cevabıdır; `hash_hmac(ip, app_key)` buna yeter (K77 ile düz hash'ten
HMAC'e geçildi — düz hash uzunluk-uzatma saldırısına açıktır). Veri minimizasyonu bir
güvenlik ilkesidir. 🔴 **L4:** hash sahibe bile gösterilmez.

**3. Neden `provider_ref` UNIQUE — ve neden tek başına yetmiyor?**
Ödeme sağlayıcıları webhook'u **birden fazla kez** gönderir (ağ hatası, retry). UNIQUE
kısıt aynı ödemenin iki satır üretmesini veritabanı seviyesinde imkânsız kılar.

> 🔴 **M8 / B6 — bir savunmanın neyi kapatmadığını yazmak:**
>
> | Katman | Neyi imkânsız kılar | Neyi kılmaz |
> |---|---|---|
> | `provider_ref` UNIQUE | Aynı ödeme için **ikinci satır** | Var olan satırın iki kez **güncellenmesi** |
> | `OrderStatus::canTransitionTo()` + `lockForUpdate()` | Bir satırın iki kez **ilerlemesi** | — |
>
> İkisi **farklı yarışları** kapatır. Yalnızca birine güvenmek yetmez.

**4. 🔴 Neden `orders.scope` kolonu var? (K81 — Faz 9'un en büyük bulgusu)**

Faz 7'de yayın hakkı şöyle soruluyordu:

```php
$query->whereNull('invitation_id')          // "paket alımı"
    ->orWhere('invitation_id', $invitation->getKey());
```

Yazıldığı gün doğruydu: `NULL` olmanın **tek yolu** paket satın almaktı. Ama
`orders.invitation_id` **`nullOnDelete`**'tir. Kalıcı silme geldiği gün ikinci bir yol doğar:

```
249 ₺ Standart (tek davetiye için)  →  orders(invitation_id = X, paid)
Davetiye X kalıcı silinir           →  nullOnDelete → invitation_id = NULL
Aynı satır artık "paket" görünür    →  hesabın TÜM davetiyeleri bedava yayınlanır
```

Kimse hata yapmamıştı — şema tutarlı, FK doğru, sorgu doğru. Yanlış olan tek şey
**`NULL`'un iki gerçeği birden anlatmasıydı.** Çözüm: kapsam **satırda saklanır**,
okumada türetilmez.

| `scope` | `invitation_id` | Anlamı | Yayın hakkı |
|---|---|---|---|
| `account` | `NULL` | Paket alımı | Sahibinin **her** davetiyesine |
| `invitation` | dolu | Tekil, bağlı | **Yalnızca** o davetiyeye |
| 🔴 `invitation` | `NULL` | **Serbest bırakılmış tekil** | **Hiçbirine** — bağlanana kadar |
| `account` | dolu | Anlamsız | ❌ CHECK kısıtı reddeder |

> **Ders 60 / kural E12:** *Bir kolonun anlamı, ona yazan **tüm yolların** toplamıdır.*
> Kritik bir ayrım bir alanın **yokluğuna** değil **varlığına** yazılır.

### Şema evrimi deseni: genişlet → taşı → daralt

`orders.scope` üç ayrı migration'da eklendi:

1. **Genişlet** — nullable kolon + 2 CHECK + mevcut satırları geri doldurma
2. **Taşı** — `Order`, `OrderFactory`, `StartCheckoutAction`: yazıcılar
3. **Daralt** — `SET NOT NULL`

> 🔴 Üçü aynı deploy'a sıkıştırılırsa desen bir **tören** olur; koruduğu pencere hiç açılmaz.
> Ve **CHECK kısıtı `NULL`'u reddetmez** (`NULL IN (...)` → `NULL`; CHECK yalnızca `FALSE`
> olduğunda reddeder). Zorunluluğu kuran tek şey `NOT NULL`'dır.

### CHECK kısıtları neden elle yazılmıyor?

```php
$allowed = "'".implode("', '", InvitationStatus::values())."'";
DB::statement("ALTER TABLE invitations
               ADD CONSTRAINT invitations_status_check CHECK (status IN ({$allowed}))");
```

Değerler **enum'dan beslenir** (K39). Elle yazılsaydı enum değiştiğinde kısıt sessizce
eskirdi. Kaynak derleme zamanı sabiti olduğu için string birleştirme burada güvenlidir —
kullanıcı girdisi değildir.

### 🔴 Kısıt neden yalnızca `status`'te? (E6)

`palette`, `category_id`, `preset_id` ve `menu_preference` de kapalı kümelerdir ama CHECK
almadılar. Ölçüt **sahipliktir**: `status` backend'in malıdır ve bir güvenlik sınırıdır
(public sorgu ona bakar). Diğerleri **frontend kataloğunun anahtarlarıdır** — kısıtlansaydı
tasarımcının eklediği her yeni tema bir backend deploy'u gerektirirdi.

### PostgreSQL'e özgü iki tuzak

1. **`UNSIGNED` yoktur.** `unsignedInteger` düz `integer`'a düşer ve `-100` kabul eder.
   Bu yüzden `amount_minor > 0` ve `guest_count >= 1` **CHECK kısıtı** olarak yazıldı.
2. **`date` ≠ `timestamp`.** `rsvp_deadline` bir `date`'tir; `isPast()` onu son gün boyunca
   "geçmiş" gösterir ve kullanıcıları **bir gün erken** kapıda bırakır (E8 / ders 43).
   Karşılaştırma tarih dizesi üzerinden yapılır.

---

## 2.7 Güvenlik omurgası — tek tabloda

| Kural | Nerede uygulanıyor | Sebep |
|---|---|---|
| Paywall **sunucuda** yeniden hesaplanır | `TierResolver::requiredFor()` | `getRequiredTier()` DevTools'tan aşılır |
| İstemciden gelen `tier` **hiçbir adımda** kullanılmaz | `PublishInvitationAction` | Frontend'in `activeTier`'ı yalnızca arayüz kararı |
| Fiyat **asla** gövdeden okunmaz | `StartCheckoutAction` → `config` | **M6** — `{"price":1}` biçimsel olarak geçerlidir; savunma doğrulama değil **mimaridir** |
| Modellerde `$guarded = []` **yasak** | `#[Fillable]` beyaz listesi | `{"user_id":1}` gönderip başkasının hesabına yazma |
| `Order`'ın `#[Fillable]`'ı **boş** | Her alan açıkça atanır | `{"status":"paid"}` ödemeyi atlardı |
| Action'da `all()` değil **`validated()`** | Tüm Action'lar | Enjekte alan savunması |
| IDOR → `Policy` + **sorgu kapsamı** | `InvitationPolicy` + `$user->invitations()` | Gate'i unutmak tüm kayıtları açardı (**P3**) |
| Sahiplik yoksa **404** | `ApiExceptionRenderer` | **H7** — 403 kaynağın varlığını doğrular |
| Enumeration savunması | `unique`/`exists` kuralı **yok** | Form bir hesap tarayıcısına dönüşür |
| Zamanlama savunması | `LoginUserAction` → sahte hash | Kullanıcı yoksa yanıt ~250 ms hızlı dönerdi |
| Argon2id | `HASH_DRIVER=argon2id`, 64 MB | GPU/ASIC paralel deneme direnci |
| IP'ler **hash'lenir** | `App\Support\IpHasher` (HMAC) | KVKK veri minimizasyonu |
| MIME **içerikten** doğrulanır | `MediaRequest` → `mimetypes:` | **F1** — uzantı kullanıcı girdisidir |
| Dosya adını **sunucu üretir** | `hashName()` | **F2** — path traversal yapısal olarak imkânsız |
| İmza **ham gövde** + `hash_equals()` | `FakeGateway::parseNotification` | **W1** yeniden serileştirme imzayı bozar · **W2** zamanlama saldırısı |
| Webhook ucu **her zaman 2xx** | `PublicPaymentWebhookController` | **W3** — 404 sonsuz retry + bilgi sızıntısı |
| Sırlar `.env` → `config/` → Service | `GeminiProvider`, ödeme sürücüleri | Vite yalnızca `VITE_` önekini paketler — sızma yolu **mimari olarak yok** |
| Kod içinde **`env()` çağrılmaz** | Y1 — `app/` ve `routes/` içinde 0 çağrı | `config:cache` sonrası `null` döner |
| 6 sertleştirme başlığı | `SecurityHeaders` middleware, **global** yığın | **K85** — nginx.conf sunucuda yaşar ve taşımada geride kalır |
| CORS daraltıldı + `exposed_headers: ETag` | `config/cors.php` | **K86** — bu satır olmadan polling optimizasyonu **sessizce** ölür |

### Rate limiting — 6 kova, 6 farklı tehdit

| Kova | Sınır | Anahtar | Neden bu değer |
|---|---|---|---|
| `auth` | 5/dk + 20/dk | e-posta+IP · IP | Brute-force **ve** spraying. Ayrıca Argon2id her denemeyi 64 MB'lık talebe çevirir → bellek tüketimi saldırısı (K36) |
| `rsvp` | 10/dk (IP) + 60/sa (davetiye) | iki kova | Tek kaynaktan seri gönderim **ve** botnet'ten tek davetiyeye yağmur |
| `media` | 5/dk + 40/sa | iki kova | LCV'den **dar**: honeypot katmanı yok, istek başına maliyet on kat |
| `assistant` | 6/dk | **kullanıcı** | Q1 — maliyet kontrolü kimliğe yazılabilmeli; IP hem çok geniş (CGNAT) hem çok dar |
| `contact` | 3/dk + 10/sa | IP | LCV'den dar: meşru kullanıcı bu formu günde bir kez doldurur |
| `api` (global) | 60/dk | IP | Faz 4'ün açık borcu: rastgele ULID yağdıran biri her istekte bir sorgu açtırıyordu |

> 🔴 **L3: hız sınırı ile kota birbirinin yerine geçmez.** Biri *sıklığa*, diğeri *hacme* bakar.

---

## 2.8 Performans omurgası

| Teknik | Nerede | Kazanç |
|---|---|---|
| **Cache** (6 saat TTL) | `PublicInvitationController` | Veritabanına **hiç gitme** |
| **ETag → 304** | `SetEtag` middleware | Gövdeyi **hiç gönderme** |
| **Event ile invalidation** | `InvitationChanged` → `ClearInvitationCache` | Tazelik TTL'den değil olaydan gelir (**O3**: TTL bir üst sınırdır, garanti değil) |
| **Eager loading** | `with(['timelineEvents'])`, `with(['photoMedia','videoMedia'])` | N+1 → 2 sorgu |
| **`preventLazyLoading`** | `Model::shouldBeStrict(! production)` | N+1 laptop'ta **exception**, üretimde sessiz |
| **Kuyruk** | `OptimizeUploadedImage` | 15 saniye kuralı |
| **Kompozit indeksler** | `(user_id,status)`, `(invitation_id,status)`, `(invitation_id,sort_order)` | Dashboard, kota ve sıralama sorguları |
| **Satır kilidi** | `lockForUpdate()` — 5 yerde | Check-then-act yarışları (E9) |

### İki katmanlı okuma optimizasyonu

```
Misafir isteği
   ├─ 1. katman: Cache::remember(6 saat)   → veritabanına hiç gitme
   └─ 2. katman: ETag / 304 Not Modified   → gövdeyi hiç gönderme
```

🔴 **Cache Action'ın İÇİNDE değil (K45).** Action saf bir okuma olarak kaldı; cache
controller'da ve **Resource çıktısı olan dizi** üzerinde çalışıyor. Sebep: cache'te
serileşmiş bir Eloquent modeli tutmak, şema değişince **bayat bir nesne canlandırır**;
dizi ise neyse odur. Ayrıca ETag aynı diziden hesaplanabiliyor.


---

# 3. Temel İş Akışları ve Senaryolar

> Bu bölüm **teorik değildir.** Her akış mevcut kod tabanındaki gerçek çağrı zincirini
> izler; satır referansları gerçek dosyalardır.

## 3.1 İş akışı — Kimlik: kayıt, giriş, çıkış

### 3.1.1 `POST /api/auth/register`

```
İstek: { firstName, lastName, email, password }
   │
1. routes/api.php
   └─ Route::prefix('auth')->middleware('throttle:auth')
        🔴 İKİ kova birden: 5/dk (e-posta+IP) ve 20/dk (IP)
        Anahtar DOĞRULAMADAN ÖNCE hesaplanır → `email` dizi de gelebilir,
        is_string() kontrolü bu yüzden var
   │
2. RegisterRequest::prepareForValidation()
   ├─ firstName/lastName/email → trim()
   └─ email → mb_strtolower()
        🔴 Buradaki veri HENÜZ DOĞRULANMAMIŞTIR. `email[]=x` gönderilirse input
        bir dizidir ve mb_strtolower(dizi) TypeError fırlatır → yalnızca
        string olanlara dokunulur, gerisi `string` kuralına bırakılır
   │
3. RegisterRequest::rules()
   ├─ firstName · lastName : required|string|max:60
   ├─ email                : required|string|email:rfc|max:255
   └─ password             : required|string|min:8|max:255
        🔴 `unique:users,email` BİLEREK YOK  (A1 — enumeration savunması)
        🔴 `Password::min(8)` DEĞİL `'min:8'`  (D6 — kural NESNESİ kullanılırsa
           Laravel onu SINIF ADIYLA raporlar ve framework içi API hata zarfına sızar)
   │
4. RegisterRequest::userAttributes()
   └─ camelCase → snake_case eşlemesi: firstName → first_name …
        HTTP alan adlarını bilmek bu katmanın işi; Action saf veri alır
   │
5. AuthController::register()   ← 3 satır, hiçbir if
   │
6. RegisterUserAction::handle()
   └─ DB::transaction:
        ├─ User::create($attributes)   ← `hashed` cast parolayı Argon2id'ler
        └─ $user->createToken('api')->plainTextToken
      catch (UniqueConstraintViolationException)
        └─ throw RegistrationFailedException::emailTaken()
             🔴 H6: hangi alanın çakıştığı İSTEMCİYE söylenmez
   │
7. AuthController::session()
   └─ response()->json([
        'user'  => (new UserResource($user))->resolve(),   ← ::make DEĞİL, zarf soyulur
        'token' => $token,
      ], 201)
```

**Yanıt (201, zarfsız):**

```json
{ "user": { "id": 1, "firstName": "Ayşe", "lastName": "Kaya", "email": "ayse@ornek.test" },
  "token": "3|xxxxxxxxxxxxxxxxxxxx" }
```

> 🔴 `UserResource` **`fullName` üretmez.** Birleştirme bir **sunum kararıdır** ve frontend'e
> aittir: "Merhaba Ayşe", "Sayın Kaya", "KAYA, Ayşe" — üçü de aynı veriden kurulur, ama
> `fullName` gönderilirse hiçbiri kurulamaz (K35).

**Hata senaryoları:**

| Durum | Yanıt | Kod |
|---|---|---|
| Eksik/biçimsiz alan | `422` | `VALIDATION_FAILED` + `fields` |
| E-posta zaten kayıtlı | `422` | `REGISTRATION_FAILED` — 🔴 `fields` **yok** |
| Dakikada 5'ten fazla deneme | `429` | `RATE_LIMITED` + `retryAfter` |

---

### 3.1.2 `POST /api/auth/login` — 🔴 iki savunmalı akış

```
İstek: { email, password }
   │
1. throttle:auth  → 5/dk (e-posta+IP) · 20/dk (IP)
   │
2. LoginRequest
   ├─ prepareForValidation(): email → mb_strtolower(trim())
   │     Bu olmadan "Ayse@Ornek.TEST" ile giriş kaydı BULAMAZ ve kullanıcı
   │     doğru parolayla bile giremez
   ├─ rules: email → email:rfc  ·  password → required|string|max:255
   │     🔴 `exists:users,email` YOK  → enumeration
   │     🔴 Parola KARMAŞIKLIK kuralı YOK  → girişin işi kimlik doğrulamak,
   │        kimlik TOPLAMAK değil (D3)
   └─ credentials(): { email, password }
   │
3. LoginUserAction::handle()
   │
   ├─ $user = User::where('email', …)->first();
   │
   ├─ 🔴 ZAMANLAMA SALDIRISI SAVUNMASI
   │    $hash = $user->password ?? self::dummyHash();
   │    $passwordMatches = Hash::check($credentials['password'], $hash);
   │
   │    Kullanıcı yoksa SAHTE bir hash'e karşı doğrulama yapılır — iş yükü
   │    her iki yolda da AYNI. Aksi hâlde yanıt ~200 ms hızlı döner ve
   │    saldırgan bunu ÖLÇEREK e-postanın kayıtlı olduğunu anlar.
   │    Sahte hash GEÇERLİ ayarlarla üretilir ki süresi gerçeklerle eşitlensin
   │    ve süreç başına BİR KEZ hesaplanır (memoization).
   │    ⚠️ Hash::check kontrolden ÖNCE çalışır; sırası değiştirilemez.
   │
   ├─ if ($user === null || ! $passwordMatches)
   │      throw new InvalidCredentialsException;   ← 🔴 PARAMETRESİZ kurucu:
   │                                                  ayrım YAPMAK İMKÂNSIZ
   │
   ├─ rehashIfNeeded(): Hash::needsRehash() ise parola sessizce yükseltilir
   │      Ham parola yalnızca şu anda elimizde; fırsat bu istekte kullanılır
   │
   └─ return ['user' => $user, 'token' => $user->createToken('api')->plainTextToken]
   │
4. AuthController::session() → 200, zarfsız { user, token }
```

**Hata senaryoları — 🔴 ikisi ayırt edilemez:**

```php
// Kayıtlı e-posta + yanlış parola
{ "error": { "code": "INVALID_CREDENTIALS" } }     // 401

// Hiç kayıtlı olmayan e-posta
{ "error": { "code": "INVALID_CREDENTIALS" } }     // 401  ← BİREBİR AYNI
```

`fields` **yok**, mesaj **yok**, süre **aynı**.

> 🔴 Frontend `api.ts` interceptor'ı 401'de oturumu düşürür — ama `INVALID_CREDENTIALS`
> için **düşürmez**. Yanlış parola girmek oturumu sonlandırmamalı. Bu ayrım Faz 2'de
> frontend'e uygulandı.

---

### 3.1.3 `POST /api/auth/logout` ve `GET /api/auth/me`

```php
// logout → 204, gövde yok
$action->handle($user);   // RevokeTokenAction: YALNIZCA isteği taşıyan token silinir
```

> 🔴 **Token izolasyonu (A6):** kullanıcının telefonundaki oturum, laptop'tan çıkış
> yapınca düşmemeli. `AuthTest::logout_revokes_only_the_current_token` bunu doğruluyor.

```php
// me → 200, 🔴 ZARFLI { data: {...} }
return new UserResource($user);
```

> `/auth/me` zarflıdır. İstisna **ad ad** tanımlıdır ve o listede yalnızca `login` ve
> `register` var (C2/C8).

> ⚠️ **Test tuzağı (T13):** `actingAs()` guard'ı atlar ve guard önbelleği token testlerini
> **boş yeşil** yakar. Bu yüzden `withToken()` + `TestCase::forgetAuthState()` kullanılır.
> Faz 2'de yazılan iki test, bu yardımcı olmadan savunma tamamen silinse bile geçiyordu.


---

## 3.2 İş akışı — Davetiye ekleme ve yönetme (CRUD)

### 3.2.1 `POST /api/invitations` — oluşturma

```
İstek: { invitation: { categoryId, presetId, palette, title, …, timelineEvents: [...] } }
   │
1. routes/api.php
   └─ Route::middleware('auth:sanctum')->apiResource('invitations', …)->whereUlid('invitation')
        🔴 R6: ULID kısıtı ELLE YAZILMAZ. Faz 3'te elle yazılan büyük-harf regex
        hiçbir isteği eşleştirmedi (HasUlids strtolower uyguluyor) — show/update/destroy
        ÜÇ AY BOYUNCA HİÇ ÇALIŞMADI ve 3 IDOR testi boş yeşil yandı.
        whereUlid() ayrıca biçimsiz kimliği veritabanına HİÇ ULAŞTIRMAZ (O6)
   │
2. StoreInvitationRequest  (soyut InvitationRequest tabanının ince alt sınıfı — C3)
   ├─ 21 alanın AÇIK camelCase eşlemesi
   ├─ 🔴 status / userId / publishedAt kuralda YOK → gönderilse bile düşer
   └─ invitationAttributes() + timelineEvents()
   │
3. InvitationController::store()
   ├─ Gate::authorize('create', Invitation::class)
   │     🔴 `authorizeResource` KULLANILAMIYOR: Laravel 11+ taban controller'ı boş,
   │        o metot `$this->middleware()` çağırıyor ve öyle bir metot YOK.
   │        Bu yüzden her metotta açık Gate::authorize.
   └─ $action->handle($user, …)
   │
4. CreateInvitationAction::handle()
   └─ DB::transaction:                          ← E4: iki tabloya yazılıyor
        ├─ $user->invitations()->make($attributes)
        │     🔴 SAHİPLİK İLİŞKİDEN GELİR. user_id hiçbir zaman istemci
        │        verisinden okunmaz — bir `if` değil, sorgunun KAPSAMI
        │
        ├─ $invitation->status = InvitationStatus::default();
        │     🔴 E7: sunucunun sahip olduğu alanın değerini SUNUCU KODU söyler.
        │        `status` #[Fillable] listesinde YOK → doğrudan atama toplu atama
        │        korumasını AŞMAZ, ona hiç uğramaz.
        │        create() kullanılsaydı değeri yalnızca veritabanı varsayılanı
        │        bilirdi ve bellekteki model null kalırdı → Faz 4'te bulunan 500 hatası
        │
        ├─ $invitation->save()
        ├─ SyncTimelineEventsAction::handle()   (varsa)
        └─ return $invitation->load('timelineEvents')
   │
5. InvitationResource → 201
```

**Yanıt:**

```json
{ "data": { "id": "01k3n8…q7", "status": "saved", "updatedAt": "2026-09-13T10:22:31+00:00",
            "invitation": { "title": "…", "mapUrl": "…", "showGallery": false, … } } }
```

> Ayrım istek gövdesiyle **simetrik**: `{ invitation: {...} }` gönderilir,
> `{ id, status, updatedAt, invitation: {...} }` döner. Sunucu üstverisi dışarıda,
> kullanıcının tasarımı `invitation` altında.

### 3.2.2 `PUT /api/invitations/{id}` — autosave

Frontend editörü debounce'lu autosave yapar; bu yüzden **içerik alanlarının hepsi
nullable'dır**: kullanıcı başlığı silip yenisini yazmak için duraklarsa o boş hâl sunucuya
gider. Eksiksizlik kuralı **yayın anında** aranır, kayıt anında değil.

```
UpdateInvitationAction::handle()
  └─ DB::transaction:
       ├─ $invitation->fill($attributes)->save();
       ├─ $timelineChanged = $timelineEvents !== null
       │                     && $this->syncTimelineEvents->handle(...);
       ├─ 🔴 if ($timelineChanged && ! $invitation->wasChanged()) $invitation->touch();
       │     Yalnızca program değiştiyse kaydın kendisi "kirli" olmaz ve updated_at
       │     bayat kalırdı — frontend onu "son kaydetme" diye gösteriyor
       └─ return $invitation->load('timelineEvents');
```

**`timelineEvents` üç ayrı anlam taşır (N1–N4):**

| Gelen | Anlam |
|---|---|
| Anahtar **yok** / `null` | Programa **dokunma** |
| `[]` | Programın **tamamını sil** |
| `[{...}, {...}]` | Listeyle **senkronize et** (ekle / güncelle / sil) |

```php
// SyncTimelineEventsAction — aidiyet YAPISAL olarak garanti
$existing = $invitation->timelineEvents()->get()->keyBy('id')->all();
//                      ▲ İLİŞKİ üzerinden okunur → eşleşme kümesinde
//                        BAŞKA davetiyenin satırı BULUNAMAZ (K44)

// Gelen id 'tl-1' gibi istemci uydurması ya da bayat ise → null → YENİ satır.
// Hata değildir: kimliği backend üretir, id: null = yeni satır (K44)

$deleted = $invitation->timelineEvents()->whereNotIn('id', $keptIds)->delete();
// Boş $keptIds → "1 = 1" → hepsi silinir; istenen davranış budur
```

> `sort_order` istemciden **gelmez**, dizideki **konumdan** türetilir.

### 3.2.3 IDOR savunması — iki katman

```php
// 1. Katman: Policy karar verir
public function view(User $user, Invitation $invitation): bool {
    return $user->id === $invitation->user_id;
}
// Reddi ApiExceptionRenderer'da 404'e çevrilir (H7) — her policy metodunda değil,
// TEK yerde. AuthorizationException → RESOURCE_NOT_FOUND

// 2. Katman: sorgu KAPSAMI zorlar
$invitations = $user->invitations()->with('timelineEvents')->latest('updated_at')->get();
//              ▲ Gate unutulsa bile başkasının kaydı listeye giremez (P3)
```

> 🔴 **Sahiplik bir `if` değil, sorgunun kapsamıdır.** Faz 3'ün en önemli dersi.

**Yetenek adları ayrı tutulur:**

| Uç | Yetenek | Neden ayrı |
|---|---|---|
| `PUT /invitations/{id}` | `update` | — |
| `POST /invitations/{id}/media` | `update` | Bir davetiyeye dosya eklemek onu **değiştirmektir** |
| `POST /invitations/{id}/publish` | **`publish`** | Sahiplik aynı olsa da **niyet** ayrı. Yarın "yayınlanmış davetiye düzenlenemez" (`INVITATION_LOCKED`) kuralı gelirse `update` ile `publish` aynı yeteneği paylaşıyor olsaydı ikisi **birlikte kilitlenirdi** |
| `POST /invitations/{id}/checkout` | `publish` | Bir davetiye için plan almak, yalnızca yayınlayabileceğin davetiye için anlamlıdır |

### 3.2.4 🔴 `whenLoaded()` neden kullanılmadı? (plandan bilinçli sapma)

Plan `whenLoaded()` ile N+1 önlemeyi öngörüyordu. Kullanılmadı:

> `whenLoaded` ilişki yüklü değilse anahtarı **düşürür**. Frontend eksik alanı
> varsayılanla doldurur ve kullanıcı **hiç yazmadığı bir programı** görür. Doğrudan
> erişimde ise `preventLazyLoading` yerelde exception fırlatır — **sessiz yanlış veri
> yerine gürültülü hata.**

Bunun bedeli: her çağıranın `with()`/`load()` yazma zorunluluğu. `InvitationController`,
`RsvpController` ve `publish` metodu bunu açıkça yapar.


---

## 3.3 🔴 İş akışı — Yayınlama (publish): projenin paywall kapısı

Bu, sistemin **ticari çekirdeğidir**. `PublishInvitationAction` Faz 3'ten Faz 7'ye kadar
**boş bir iskelet** olarak durdu — bilerek. K47: *"yayın ucu şimdi yazılırsa paywall'sız
bir bedava yol açılır."* İskelet, kapıyı kilitleyecek anahtarlar var olduktan sonra dolduruldu.

> **Ders: bir rotayı erken açmak, onu korumasız açmaktır.**

### `POST /api/invitations/{id}/publish`

```
1. routes/api.php → auth:sanctum + whereUlid
     POST, PUT DEĞİL: yayın bir DURUM GEÇİŞİDİR, bir alan güncellemesi değil.
     PUT idempotan olmalı; ikinci yayın isteği bilerek 409 döner.
   │
2. InvitationController::publish()
   └─ Gate::authorize('publish', $invitation)     → değilse 404 (H7)
   │
3. PublishInvitationAction::handle()  → DB::transaction:
   │
   ├─ 1. SATIRI KİLİTLE ve YENİDEN OKU
   │      Invitation::query()->whereKey(...)->lockForUpdate()->firstOrFail();
   │
   │      🔴 Elimizdeki nesne rota bağlamasından geldi ve o okumadan bu ana kadar
   │      başka bir istek davetiyeyi yayınlamış olabilirdi. Kilitsiz çalışılsaydı iki
   │      eş zamanlı istek ikisi de "yayında değil" görür ve ikisi de yayınlardı —
   │      409 hiç fırlamaz, published_at iki kez yazılırdı (check-then-act, E9)
   │
   ├─ 2. DURUM ÇAKIŞMASI
   │      if ($fresh->status === Published) throw new InvitationAlreadyPublishedException;
   │      → 409 INVITATION_ALREADY_PUBLISHED
   │
   │      🔴 K68: sessizce başarılı dönmek kullanıcıya iki kez yayınladığını
   │      (ve belki iki kez ödediğini) düşündürürdü. Yayın ücretli ve yan etkili.
   │      Karşılaştır: webhook'ta idempotans İSTENİYOR — orada tekrar eden taraf bir
   │      MAKİNE ve niyeti teyit; burada bir İNSAN ve niyeti yeni bir şey yapmak (ders 51)
   │
   ├─ 3. GEREKEN PLAN — SUNUCUDA hesaplanır
   │      $required = TierResolver::requiredFor($fresh);
   │
   │      foreach (config('davetkart.module_tiers') as $column => $tier)
   │          if ($invitation->getAttribute($column) === true && $tier->rank() > $required->rank())
   │              $required = $tier;
   │
   │      show_gallery | show_gift      → elit    (549 ₺)
   │      show_envelope | show_timeline → gold    (399 ₺)
   │      show_timer | show_rsvp        → standart(249 ₺)
   │
   │      🔴 K6'nın Faz 3'te ödenen bedeli (show_* ayrı boolean kolonlar) burada
   │      karşılığını buluyor: hesap SQL ile de yapılabilir.
   │      🔴 Katı kip sayesinde config'e var olmayan bir kolon adı yazılırsa burası
   │      GÜRÜLTÜLÜ patlar (MissingAttributeException). Sessizce false sayılsaydı,
   │      yazım hatası olan bir modül paywall'dan MUAF olurdu.
   │
   ├─ 4. SAHİP OLUNAN PLAN — K42: iki kaynak, TEK arayüz
   │      $owned = PublishEntitlementResolver::highestTierFor($fresh);
   │
   │      OrderEntitlementResolver'ın sorgusu üç koşul taşır:
   │        1. grantingPublishRight()          → yalnızca 'paid' siparişler
   │        2. where('user_id', $inv->user_id) → 🔴 BU KULLANICININ mı
   │        3. (scope='account'  OR  invitation_id = :id)
   │
   │      🔴 İç içe closure ZORUNLU. Parantezsiz yazılsaydı SQL şöyle olurdu:
   │          ... AND user_id = ? AND scope = ? OR invitation_id = ?
   │      ve OR'un önceliği yüzünden SON kol tek başına eşleşirdi — yani BAŞKA
   │      birinin ödenmiş siparişi bu davetiyeyi açardı. Operatör önceliği burada
   │      bir GÜVENLİK meselesidir.
   │
   │      🔴 whereNull('invitation_id') DEĞİL, `scope` süzgeci (K81):
   │          scope='invitation' + invitation_id=NULL  (serbest bırakılmış)
   │            eski sorgu → EŞLEŞİR  (hesap geneline bedava yayın!)
   │            yeni sorgu → eşleşmez (doğru: önce bağlanmalı)
   │
   ├─ 4b. SERBEST HAKKI BAĞLA (Faz 9)
   │      if ($owned === null || ! $owned->covers($required))
   │          if (ClaimReleasedOrderAction::handle($fresh, $required))
   │              $owned = $entitlements->highestTierFor($fresh);   ← yeniden SOR
   │
   │      🔴 Yalnızca eldeki hak YETMİYORSA denenir. Ters sırada (önce bağla, sonra
   │      sor) paketi olan bir kullanıcı her yayında serbest bir tekil siparişini de
   │      harcardı. Koşullu çalışması bir OPTİMİZASYON DEĞİL, bir İŞ KURALIDIR.
   │      Claim'in döndürdüğü planı VARSAYMIYORUZ — tek doğruluk kaynağı resolver (C3)
   │
   ├─ 5. İKİ AYRI RED, İKİ AYRI KOD
   │      $owned === null            → 402 PAYMENT_REQUIRED          + requiredTier
   │      ! $owned->covers($required)→ 402 PAYWALL_TIER_INSUFFICIENT + requiredTier
   │
   │      Kullanıcının önündeki eylem farklı: "önce bir plan al" ile "planını
   │      yükselt" aynı ekran değil (docs/08 §4 — durum kodu kaba, `code` ince ayrım)
   │
   └─ 6. YAYIN
          $fresh->status = Published;  $fresh->published_at = now();  $fresh->save();
             │
             └─ save() → 'updated' → InvitationChanged → ClearInvitationCache (K48)
                🔴 Olay MODELDEN yapısal olarak fırlar ($dispatchesEvents).
                Bu Action'ın cache'i temizlemeyi HATIRLAMASI gerekmiyor
```

**Yanıt:** `200` + tam `InvitationResource`. Frontend'in editörü aynı Resource'u okuyup
durumu `published` gösterebilsin — ayrı bir "yayınlandı" zarfı ikinci bir sözleşme olurdu.

> 🔴 `load('timelineEvents')` controller'da **zorunlu**: Action kilitli bir yeniden okuma
> yapıyor ve o örnek ilişkileri taşımıyor. Katı kip yerelde `LazyLoadingViolation` fırlatır;
> üretimde ise sessiz bir N+1 olurdu.

### 🔴 Paywall neden Policy'ye konmadı? (P6 / ders 53)

Doğal görünen tasarım `InvitationPolicy::publish()` içine plan kontrolünü koymaktı.
Yapılmadı:

> Policy'nin cevabı bir **`bool`**'dur ve reddi H7 gereği **404**'e çevrilir — kullanıcı
> "davetiyem kayboldu" derdi. Paywall reddi ise **402** olmalı ve `requiredTier`
> **taşımalıdır**. Bir `bool` bu bilgiyi taşıyamaz.

Kural iki katmana doğru yerlerinden bölündü:

```
Policy  → "bu kayıt senin mi?"    → yoksa 404, kaynak gizlenir
Action  → "planın yetiyor mu?"    → yoksa 402, ne alması gerektiği söylenir
```

Ayırt edici soru: ***"Bu red bir bilgi taşımak zorunda mı?"***

---

## 3.4 İş akışı — Ödeme: checkout ve webhook

### 3.4.1 Checkout — iki uç, tek Action (K42/K64)

```
POST /api/invitations/{id}/checkout   → PaymentController::forInvitation()  (TEKİL)
POST /api/payments/checkout           → PaymentController::forAccount()     (PAKET)
```

> 🔴 Neden iki uç? `docs/09` tek uç öngörüyordu, gövdede `invitationId` taşıyacaktı.
> **N1:** aidiyet gövdeden gelseydi **istemcinin sözüne** kalırdı. İç içe kaynakta aidiyet
> URL'nin **yapısında** durur ve `whereUlid()` biçimsiz kimliği veritabanına hiç ulaştırmaz.
> Faz 6 aynı kararı medya uçlarında zaten vermişti.

```
1. StoreCheckoutRequest → tier: required|string|in:standart,gold,elit
      🔴 Rule::enum(SubscriptionTier::class) KULLANILMADI: $validator->failed()
      anahtarı SINIF ADI olurdu ve hata zarfına 'illuminate\validation\rules\enum'
      diye sızardı. Faz 3'ün D6 kuralı tam olarak bunu yasaklamıştı — aynı tuzak,
      yeni kılıkta, bu kez KOD YAZILMADAN ÖNCE yakalandı (ders 54)
   │
2. Gate::authorize('publish', $invitation)     (yalnızca tekil kolda)
   │
3. StartCheckoutAction::handle($user, $invitation|null, $tier)
   │
   ├─ 3. KATMAN — YETERLİLİK  (yalnızca tekil kolda)
   │      $required = $tiers->requiredFor($invitation);
   │      if (! $tier->covers($required)) throw PaywallViolationException::insufficientTier(…);
   │        → 402. Kullanıcının daha UCUZ bir plan seçip galerili davetiye
   │          yayınlamasını burada engelliyoruz. Yayın anında TEKRAR bakılacak,
   │          çünkü modül ödeme SONRASINDA da eklenebilir
   │        Paket alımda kontrol edilecek bir davetiye yok — yeterlilik yayında sınanır
   │
   ├─ createPendingOrder()  🔴 HER ALAN AÇIKÇA ATANIR — Order'ın #[Fillable]'ı BOŞ
   │      $order->user_id       = $user->id;
   │      $order->invitation_id = $invitation?->getKey();
   │      $order->scope         = $invitation === null ? Account : Invitation;
   │           🔴 KAPSAM SATIN ALMA ANINDA YAZILIR, okuma anında türetilmez (K81).
   │           Buradaki türetme meşrudur: çağrı YOLU hangi kapsamın alındığını
   │           kesin biliyor ve sonuç kolona YAZILIYOR. Sınırda bir kez türet, sakla
   │      $order->status        = OrderStatus::default();      // pending
   │      $order->amount_minor  = $tier->price() * 100;
   │           🔴 4. KATMAN. Fiyat SUNUCUDAN okunur. Gövdeden gelseydi
   │           {"tier":"elit","price":1} Elit planı 1 kuruşa satardı — ve hiçbir
   │           doğrulama kuralı bunu yakalamaz, çünkü değer BİÇİMSEL olarak geçerlidir (M6)
   │      $order->currency      = config('davetkart.currency');   // satırda saklanır (M7)
   │      $order->provider      = $this->gateway->name();          // sürücü kendi adını söyler
   │      $order->expires_at    = now()->addMinutes(30);
   │      $order->save();
   │
   ├─ 🔴 L7 — DIŞ SERVİS TRANSACTION'A DAHİL DEĞİLDİR
   │      try { $session = $gateway->startCheckout($order); }
   │      catch (Throwable $e) {
   │          $order->status = Failed; $order->save();   ← TELAFİ (compensating transaction)
   │          Log::error(…);                             ← H8: ham hata YALNIZCA log'a
   │          throw PaymentProviderException::rejected($e);   → 502
   │      }
   │
   │      Satır ÖNCE yazılır, oturum SONRA açılır. Ters sırada sağlayıcı oturumu
   │      açıldıktan sonra veritabanı yazımı patlarsa ÖDENMİŞ AMA KAYDI OLMAYAN bir
   │      ödeme kalırdı. Sipariş silinmiyor, 'failed' işaretleniyor: silmek denemenin
   │      izini de silerdi ve "neden ödeyemiyorum" sorusu cevapsız kalırdı
   │
   └─ $order->provider_ref = $session->providerRef;   ← idempotansın veritabanı yarısı
      $order->expires_at   = $session->expiresAt ?? …;
      $order->save();
   │
4. OrderResource → 201  { data: { orderId, tier, status: "pending", redirectUrl } }
      🔴 provider_ref yanıta GİRMEZ (beyaz liste)
```

> ⚠️ **Ödeme anında tamamlanmaz.** `status` `pending` döner; `paid`'e geçişi **webhook**
> yapar (K67: ödeme yayınlamaz, yayın ayrı bir kullanıcı eylemidir). Frontend bugün
> `status: 'paid'` sabiti bekliyor — uyarlanacak.

### 3.4.2 Webhook — `POST /api/public/payments/webhook`

Bu, sistemin **üçüncü auth'suz yazma yoludur** ve tehdit modeli öncekilerden farklıdır:
yazan anonim bir **misafir** değil, bilinen bir **makine**.

| Faz | Uç | Savunma katmanları |
|---|---|---|
| 5 | LCV | honeypot + hız sınırı + kota + iş kuralı |
| 6 | Misafir medyası | hız sınırı + kota + MIME |
| **7** | **Webhook** | 🔴 **yalnızca imza** |

Honeypot yok (görünmez alan diye bir şey yok), kota yok (meşru bildirim sayısı önceden
bilinemez). Bu bir eksiklik değildir: **imza, gönderenin kim olduğunu kriptografik olarak
kanıtlar** — diğerlerinde böyle bir kanıt hiç yoktu.

```
1. $payload = $request->getContent();      🔴 HAM GÖVDE
      $request->all() KULLANILAMAZ: imza ayrıştırılmış diziden değil BAYT
      DİZİSİNDEN hesaplanır. Laravel JSON'u yeniden serileştirdiğinde anahtar
      sırası veya boşluklar değişir ve imza "bazen" tutmaz (W1)
   │
2. $signature = $request->header(config('payment.webhook.signature_header'), '');
   │
3. $gateway->parseNotification($payload, $signature)
      → hash_equals() ile karşılaştırma  (W2: === ilk farklı baytta durur → zamanlama)
      → geçersizse InvalidWebhookSignatureException → 🔴 404
           K69: 401 frontend interceptor'ını tetikler + saldırgana sinyal verir;
           403 ucun VARLIĞINI doğrular; 400 bozuk gövde ile sahte imzayı AYIRT ETTİRİR.
           404 hiçbir ayrım vermez (L2/L6)
   │
4. HandlePaymentCallbackAction::handle($notification)  → DB::transaction:
   │
   ├─ 🔴 KİLİT SORGUNUN KENDİSİNDE
   │      Order::where('provider_ref', …)->lockForUpdate()->first();
   │      Önce okuyup sonra kilitlemek arada boşluk bırakırdı (E9). Eş zamanlı
   │      ikinci webhook bu satırda BEKLER; kilidi aldığında satırı GÜNCELLENMİŞ
   │      hâliyle okur ve geçiş kontrolüne takılır
   │
   ├─ $order === null → Log::warning + return null
   │      Bilinmeyen referans: bizim başlatmadığımız bir ödeme ya da başka bir
   │      ortamın (staging) bildirimi. Sessizce yutulur
   │
   ├─ 🔴 İDEMPOTANS — DURUM MAKİNESİ
   │      if (! $order->status->canTransitionTo($notification->status)) return $order;
   │
   │      paid → paid geçişi OrderStatus'te YASAK. Yan etki (paid_at damgası,
   │      ilerideki e-posta ve muhasebe kaydı) ikinci kez uygulanmaz.
   │      `if (status === 'paid') return;` yazılmadı çünkü o kural burada, çalışma
   │      yerinde durur ve ikinci bir çağıranda (iade ucu, admin paneli) yeniden
   │      yazılması gerekirdi (C3)
   │
   ├─ $order->status = $notification->status;
   ├─ if ($notification->status->hasBeenPaid() && $order->paid_at === null)
   │      $order->paid_at = now();
   │      orders_paid_at_check kısıtı: parası alınmış sipariş damga TAŞIMAK ZORUNDA.
   │      Damga BİR KEZ yazılır — iade bildirimi ödemenin gerçekleştiği anı DEĞİŞTİRMEZ
   │
   └─ $order->save();
   │
5. 🔴 HER ZAMAN 204 — sipariş bulunsa da bulunmasa da (W3)
      404 dönmek sağlayıcıyı SONSUZA KADAR retry ettirir ve kuyruğunu doldurur.
      Ayrıca yanıt farkından "bu referans bizde var / yok" bilgisi sızardı
```

> 🔴 **CSRF muafiyeti yapılandırılmadı, YAPISALDIR:** Laravel 11+ iskeletinde
> `VerifyCsrfToken` yalnızca `web` grubunda; `api` grubu onu hiç taşımaz. Burada
> unutulabilecek bir ayar yok — K12'nin fail-safe fikrinin CSRF eksenindeki karşılığı.
> (Faz 9'da `routes/web.php` tamamen silindiği için `web` grubu artık hiç koşmuyor.)

### 3.4.3 Strategy Pattern — `PaymentGateway`

```php
interface PaymentGateway {
    public function name(): string;
    public function startCheckout(Order $order): CheckoutSession;
    public function parseNotification(string $payload, string $signature): PaymentNotification;
}
```

Bugün tek uygulama: `FakeGateway` — **gerçek HMAC, sahte para**. `IyzicoGateway` sandbox
anahtarları gelince tek `match` kolu + tek bağlama satırı olacak.

```php
// AppServiceProvider::resolvePaymentGateway() — 🔴 ÇÖZÜM anında çalışır, kayıt anında değil
return match ($driver) {
    'fake' => $app->make(FakeGateway::class),
    default => throw PaymentProviderException::unavailable($default),   // 503
};
```

> 🔴 **Sessiz varsayılan YOK (K70).** `default => fake` olsaydı üretimde `IYZICO_API_KEY`
> eksik olduğu gün **her ödeme bedava** olurdu — bir yapılandırma hatasının sessizce
> bedava yayına dönüşmesi. Ayrıca closure çözüm anında çalıştığı için hatalı config
> yalnızca **ödeme uçlarını** kırar; sağlık sondası, davetiye okuma ve LCV çalışmaya
> devam eder (blast radius).


---

## 3.5 İş akışı — Public davetiye okuma (en yüksek trafik)

Davetiye linki WhatsApp grubuna düşer, 500 kişi 2 dakikada açar — ama veri **neredeyse
hiç değişmez.** Kitap gibi bir okuma yükü.

```
GET /api/public/invitations/{ULID}
   │
1. routes → prefix('public') + SetEtag (grup middleware'i) + whereUlid('id')
   │
2. PublicInvitationController::show(Request $request, string $id, …)
   │     🔴 Rota parametresi MODEL DEĞİL STRING: route-model binding middleware'den
   │     önce çalışıp HER İSTEKTE bir SELECT açardı ve cache'i anlamsızlaştırırdı
   │
   └─ Cache::remember(
          Invitation::publicCacheKey($id),        ← anahtarı TEK yer üretir (C3)
          6 saat,
          fn () => PublicInvitationResource::make($resolve->handle($id))->resolve($request)
      )                                            ▲ cache'te DÜZ DİZİ durur (K45)
   │
3. ResolvePublicInvitationAction::handle($id)
   └─ yalnızca status = 'published' ve silinmemiş kayıt döner
        Görünürlük bir `if` değil, sorgunun KAPSAMI. Yayınlanmamış, soft-delete
        edilmiş ve hiç var olmayan davetiye AYIRT EDİLEMEZ biçimde 404 döner
   │
4. PublicInvitationResource → 🔴 C6: KAPALI MODÜLÜN VERİSİ GÖNDERİLMEZ
   │
5. return response()->json(['data' => $payload]);     ← K11 zarfı korunur
   │
6. SetEtag middleware (çıkışta)
   ├─ ETag = hash('xxh128', gövde)     ← kriptografik değil, EŞİTLİK parmak izi
   └─ $response->isNotModified($request)  → eşleşirse 304, gövde boşaltılır
```

### 🔴 C6 — "ekranda görünmemek ile gönderilmemek farklı şeylerdir"

`show_gift = false` iken `iban`, `bankName`, `accountHolder`, `giftOptions` gövdeye **hiç
girmez** — boş string olarak değil, **anahtar olarak da** yok.

> Kullanıcı hediye modülünü açıp IBAN'ını girip sonra kapatabilir. Modül kapalıysa ekranda
> hiçbir şey görünmez — ama veri gövdedeyse **DevTools açan misafir onu okur.**

Aynı kural her kapalı modüle uygulanır. Ayrıca `PublicTimelineEventResource` **planda
yoktu** ve eklendi: artan bigint kimlik, K40'ın kapattığı sayım sızıntısını geri getirirdi.

### Cache invalidation — olay tabanlı

```php
// app/Models/Invitation.php
protected $dispatchesEvents = [
    'updated'  => InvitationChanged::class,
    'deleted'  => InvitationChanged::class,
    'restored' => InvitationChanged::class,
];   // 🔴 'created' BİLEREK yok — yeni kaydın cache girdisi olamaz
```

> 🔴 Olay **modelden yapısal olarak** fırlar. Action'lardan elle fırlatmak yerine modele
> gömüldü ki **yeni bir yazma yolu eklendiğinde kimsenin hatırlaması gerekmesin** (K48).
> `PublishInvitationAction` cache'i temizlemeyi bilmiyor bile.

**O3 — TTL bir tazelik garantisi değil, üst sınırdır.** Tazeliği olay sağlar; TTL yalnızca
olayın kaçırıldığı durumlar (ham SQL, bayat `event:cache`) için emniyet kemeridir.

**T15 — cache zinciri uçtan uca test edilemez.** `RefreshDatabase` rollback ediyor ve
`ShouldHandleEventsAfterCommit` testte hiç koşmuyor. Bu yüzden zincir **üç halkaya**
bölündü (olay fırlıyor mu / listener bağlı mı / listener anahtarı siliyor mu) ve boşluk
`FAZ-4-ELLE-DOGRULAMA.md` adım 12 ile kapatıldı.

> 🔴 **`exposed_headers: ['ETag']` olmadan bu optimizasyon SESSİZCE ÖLÜR** (K86).
> ETag CORS güvenli listesinde **değildir**; bu satır yoksa çapraz kaynakta JS onu
> okuyamaz, `If-None-Match` gönderilemez ve K7/K46'nın tüm çalışması boşa gider.

---

## 3.6 İş akışı — LCV (RSVP) gönderimi: sistemin en savunmasız noktası

`POST /api/public/invitations/{id}/rsvps` — auth'suz **yazma** yolu. Katmanlı savunma
(defense in depth) burada öğrenilir.

```
0. KATMAN — HIZ SINIRI   (rota katmanı, Action'a hiç gelmez)
     throttle:rsvp → 10/dk (IP kovası) + 60/sa (davetiye kovası)
     İki kova iki farklı saldırıyı kapatır: tek kaynaktan seri gönderim ve
     botnet'ten tek davetiyeye yağmur
   │
   StoreRsvpRequest → biçim doğrulaması + honeypot alanı okuma
   │
   PublicRsvpController::store()   ← hiçbir if
     🔴 Rota parametresi MODEL DEĞİL STRING: route-model binding çalışsaydı
     YAYINLANMAMIŞ bir davetiye de çözülürdü ve görünürlük kararı Action'ın
     dışına kaçardı
   │
1. KATMAN — HONEYPOT   (en başta, çünkü EN UCUZU ve en çok işleyeni)
     if ($honeypotTripped) return $this->silentlyDiscard($attributes);
       → Bot ne bir sorgu açtırır ne bir satır yazar; yine de 201 alır
       → Dönen nesne gerçek bir ULID ve zaman damgası taşır, yani gerçek bir
         kayıttan AYIRT EDİLEMEZ (HasUlids kimliği DB'ye gitmeden üretebiliyor)
       🔴 L2: bota "yakalandın" demek, savunmanın bir kez kullanılıp ölmesidir
   │
2. KATMAN — HEDEF AÇIK MI?   ResolveOpenRsvpInvitationAction
     ├─ 1. Yayında mı        → ResolvePublicInvitationAction (sorgu kapsamı) → 404
     ├─ 2. show_rsvp açık mı → değilse 404 (kapalı modülün VARLIĞI da bilgidir)
     └─ 3. Son tarih geçti mi→ 403 RSVP_DEADLINE_PASSED
              🔴 403 DEĞİL 404: davetiyenin varlığı zaten herkese açık,
              gizlenecek bir şey yok — H7'nin gerekçesi burada geçerli değil
   │
3. KATMAN — MEDYA AİDİYETİ   resolveGuestMedia()
     $invitation->media()->whereKey($mediaId)->where('kind', $kind)->exists()
       ├─ 1. Medya BU davetiyeye ait mi
       └─ 2. Beklenen TÜRDE mi
     🔴 İkincisi olmasaydı misafir kendi rsvp_video kimliğini photoMediaId olarak
     gönderebilir ya da (davetiyeye ait olduğu için) SAHİBİN GALERİ fotoğrafını
     kendi yanıtına iliştirebilirdi
     🔴 Geçersiz kimlik EXCEPTION FIRLATMAZ, sessizce null olur (K59/L6): 403 dönmek
     "bu kimlik gerçekti ama senin değil" ile "hiç yok" farkını öğretirdi ve media
     tablosunu ULID uzayından taranabilir yapardı
   │
4. KATMAN — KOTA   (kontrol ve yazma AYNI transaction'da)
     DB::transaction:
       ├─ $limit = RsvpQuotaResolver::limitFor($invitation);
       │     null (sınırsız) ise SORGU BİLE AÇILMAZ
       ├─ Invitation::whereKey(...)->lockForUpdate()->first();
       │     🔴 Üst kaydı kilitle. PostgreSQL'in READ COMMITTED seviyesinde
       │     SELECT'ler birbirini beklemez; eş zamanlı iki gönderim aynı SUM'ı
       │     okuyup ikisi de "yer var" derdi (E9 / check-then-act)
       ├─ $used = $invitation->rsvps()
       │            ->whereIn('status', RsvpStatus::quotaConsumingValues())
       │            ->sum('guest_count');
       │     🔴 COUNT(*) DEĞİL SUM(guest_count) (E10): 100 kayıt × 4 kişi = 400
       │     misafir kotayı aşmadan geçerdi. Frontend'in LiveRsvpPanel'i de aynı
       │     metriği kullanıyor — iki taraf aynı şeyi saymak ZORUNDA
       │     🔴 K50: 'declined' saymaz. Kota bir KAPASİTE sınırıdır; gelmeyeceğini
       │     bildiren misafir masada yer kaplamaz. Kural enum'da, sorguda değil
       └─ if ($used + $incoming > $limit) throw new RsvpQuotaExceededException;  → 403
   │
5. KATMAN — KVKK
     $rsvp->ip_hash = IpHasher::hash($ip);    ← hash_hmac, ham IP saklanmaz
     🔴 ip_hash #[Fillable] listesinde YOK: toplu atamayla değil sunucu kodu atar
   │
201 + RsvpResource   (honeypot yolunda da 201 — ayırt edilebilir olsaydı savunma ölürdü)
```

> 🔴 **Sıra tesadüfi değil (L1): savunma katmanları en ucuzdan pahalıya sıralanır.**
> Bot trafiği ezici çoğunluktaysa onları tek sorgu açtırmadan elemek, sonraki katmanların
> yükünü de azaltır.

### Kotanın kaynağı — bir dikiş yerinin (seam) değeri

Faz 5'te `RsvpQuotaResolver` **arayüzü** yazıldı ve arkasına geçici bir uygulama kondu;
gerçek kaynak (`orders` tablosu) Faz 7'de doğacaktı.

```php
// AppServiceProvider::register()
$this->app->bind(RsvpQuotaResolver::class, SubscriptionRsvpQuotaResolver::class);
```

> 🔴 **Ders 52:** Faz 7'de gerçek kaynağa geçiş **tek satır** oldu. `SubmitRsvpAction`,
> 29 testi ve hata sözleşmesi **hiç değişmedi**. Bir arayüzün değerini yazdığın gün değil,
> **kaldırdığın uygulamanın maliyetiyle** ölçersin.

### Sahibin paneli — `GET /api/invitations/{id}/rsvps`

15 saniyede bir çağrılır; sistemin **en sık istenen auth'lu ucudur**. Bu yüzden rotasına
`SetEtag` takılıdır (K46/C3: Faz 4'te ETag'i ayrı bir middleware yapmanın gerekçesi tam
olarak buydu).

```php
Gate::authorize('view', $invitation);          // 1. katman: karar
return RsvpResource::collection(
    $invitation->rsvps()->with(['photoMedia','videoMedia'])->latest()->get()
);                //  ▲ 2. katman: sorgu kapsamı  ·  with() N+1'i kapatır
```

> ⚠️ `throttle:rsvp` bu rotaya **konmaz** — o kova YAZMA içindir (dakikada 10). Okuma
> polling'i 15 saniyede bir gelir ve o kovada boğulurdu.

---

## 3.7 İş akışı — Medya yükleme (iki uç, iki tehdit modeli)

```
POST /api/invitations/{id}/media          → sahip, auth'lu, yalnızca 'gallery'
POST /api/public/invitations/{id}/media   → misafir, auth'suz, yalnızca rsvp_photo|rsvp_video
```

| Kural | Nerede | Seri |
|---|---|---|
| MIME **içerikten** doğrulanır (`mimetypes:`) | `MediaRequest` | **F1** |
| Dosya adı **rastgele** üretilir (`hashName()`) | `store()` | **F2** |
| Diske yazma **transaction dışıdır**, elle telafi edilir | `StoreUploadedMediaAction` | **F3** |
| Depolama konumu **satırda** saklanır | `media.disk` | **F4** |
| Sözleşme URL taşır, şema **kimlik** tutar | `MediaResource` | **F5** |
| Optimizasyon **kuyruğa** gider | `OptimizeUploadedImage` | 15 sn kuralı |

```php
// F3 — DB::transaction() diski geri almaz
try   { $path = $file->store(...); DB::transaction(fn () => $media->save()); }
catch { Storage::disk($disk)->delete($path); throw; }   // compensating transaction
```

Misafirin ucu (`StoreGuestMediaAction`) aynı üç açıklık koşulunu
`ResolveOpenRsvpInvitationAction` üzerinden sorar — kural **kopyalanmadı, çıkarıldı** (C3).

> 🔴 Kopyalansaydı şu delik açılırdı: son tarih kontrolü yalnızca LCV'de kalır, süresi
> dolmuş bir davetiyeye misafir **sınırsız süre** boyunca dosya yüklerdi (kota başına
> ~2.4 GB).

**Yanıt:** `{ "data": { "id": "01k3n8…q7", "url": "http://…/storage/media/gallery/aB3x…jpg" } }`
— plan yalnızca `{url}` diyordu; `id` eklendi (süperset). Kimlik olmadan misafir yüklediği
dosyayı LCV'ye bağlayamaz (K58: **bir URL doğrulanamaz**).

> ⚠️ **Bugünkü açık borç (K55):** `disk = public` ve `storage:link` dosyaları web kökü
> altına koyuyor. *"Yüklenenler çalıştırılabilir dizinde durmaz"* kuralı bugün **MIME beyaz
> listesiyle** karşılanıyor — yani **kurala bağlı, yapısal değil.** S3 göçü bunu yapısal
> olarak kapatacak ve `media.disk` kolonda saklandığı için (F4) eski satırları kırmayacak.

---

## 3.8 İş akışı — AI asistan: para harcayan tek uç

`POST /api/assistant/chat` — 🔴 **auth'lu** (K72).

```
0. auth:sanctum          → kimliksiz istek buraya HİÇ gelmez
1. throttle:assistant    → 6/dk, anahtar KULLANICI (cache'te bir sayaç, mikrosaniye)
2. AskAssistantRequest   → max_prompt_chars (tek strlen, mikrosaniye)
3. 🔴 GÜNLÜK KOTA        → AskAssistantAction (iki SQL deyimi, milisaniye)
4. Sağlayıcı çağrısı     → AiProvider (ağ + PARA, saniyeler)
```

> Her katman **kendinden sonrakinin maliyetini haklı çıkaracak kadar ucuz** olmalı.

```php
public function handle(User $user, string $prompt): string {
    $this->chargeDailyQuota($user);      // 🔴 ÖNCE kota düşülür
    return $this->provider->reply($prompt);
}
```

> 🔴 **Q3 / ders 57 — "cevap alamadım" ile "para harcanmadı" aynı şey değildir.**
> Tersi daha adil görünürdü (çağrı başarısızsa hak yanmasın) ama yanlış tarafa hata
> yapardı: bir zaman aşımında Gemini isteği **zaten faturalamış** olabilir. Fail-safe yön:
> şüpheli durumda kullanıcı bir mesaj kaybeder, **sistem para kaybetmez.**

```php
// 🔴 CHECK-THEN-ACT YOK — kontrol ve yazma TEK SQL deyiminde
AssistantUsage::insertOrIgnore([...]);           // UNIQUE(user_id, usage_date) ihlali sessiz
$charged = AssistantUsage::where('user_id', …)
    ->where('usage_date', $today)
    ->where('message_count', '<', $limit)        // ← kota doluysa hiçbir satıra uymaz
    ->increment('message_count');                // ← etkilenen satır sayısı 0 → red

if ($charged === 0) throw new AssistantQuotaExceededException($retryAfter, $limit);  // 429
```

**Kota neden veritabanında, `RateLimiter` kovasında değil? (K73 / Q2)**
Kova cache'tedir ve `cache:clear` / deploy / Redis restart **bütün kotaları sıfırlar**.
Bir **hız sınırı** için kabul edilebilir, bir **fatura kontrolü** için değil. Bu bedel Faz
9'da karşılığını verdi: Redis'e geçiş kotayı sıfırlamayacak.

**Neden 429, 403 değil? (K74)** Ayırt edici soru: *bekleyerek aşılabilir mi?* LCV
kotasında **hayır** (kapasite sınırı → 403); günlük bütçede **evet** (gece yarısı yenilenir
→ 429 + `Retry-After`). `RATE_LIMITED` yeniden kullanılmadı: "hızlısın, 30 sn bekle" ile
"bugünlük hakkın bitti" frontend'de aynı metni gösteremez.

**Sağlayıcı hatası:** tamamı `PROVIDER_UNAVAILABLE` (503) olur; ham hata yalnızca log'a
gider (H8). `NullProvider` (Null Object Pattern) testte ve API anahtarı olmayan ortamda
ağa **hiç çıkmaz**.

> 🔴 **X3:** API anahtarı **başlıkta** gider, URL'de değil — URL'ler kopyalanır (log, proxy,
> APM, `Referer`). **X1:** sistem talimatı çağıranın parametresi olamaz.

---

## 3.9 İş akışı — Silme, hak serbest bırakma ve yeniden bağlama (Faz 9)

`DELETE /api/invitations/{id}` Faz 9'a kadar controller'da **tek satırdı**
(`$invitation->delete()`). Silmenin bir **iş kuralı** doğması onu bir Action'a taşıdı.

> Bir Action, sardığı kadar değil **taşıdığı kural kadar** değerlidir (K15).

```php
// DeleteInvitationAction — K82: 3 günlük serbest bırakma penceresi
DB::transaction(function () use ($invitation) {
    $fresh = Invitation::whereKey(…)->lockForUpdate()->firstOrFail();
    //  🔴 Kilit olmasaydı eş zamanlı bir "yayınla" isteği ile bu silme birbirini
    //  görmezdi: yayın published_at'i yazarken silme onu NULL diye okuyup hakkı
    //  serbest bırakabilirdi

    if ($this->releaseWindowIsOpen($fresh)) {
        $fresh->orders()->releasable()->update(['invitation_id' => null]);
        //  🔴 scope DEĞİŞMİYOR, yalnızca bağ kopuyor. 'invitation' kaldığı için bu
        //  satır OrderEntitlementResolver'ın paket koluna DÜŞMEZ
        //  🔴 Sipariş satırı HİÇ SİLİNMEZ — muhasebe kaydı bir tıkla yok olamaz
    }

    $fresh->delete();   // soft delete → 'deleted' → InvitationChanged → cache temizlenir
});
```

**Pencere kuralı:**

| Durum | Sonuç |
|---|---|
| `published_at IS NULL` | Hak hiç harcanmadı → **SERBEST** |
| `published_at + 3 gün` gelecekte | Pencere açık → **SERBEST** |
| `published_at + 3 gün` geçmiş | Pencere kapalı → hak **YANAR** |

> 🔴 Bu bir **süre** hesabıdır, bir **takvim** hesabı değil — ve fark önemli. K71'de LCV
> son tarihi davetiyenin saat dilimine taşınmıştı çünkü "15 Ağustos" bir yerin takvim
> günüdür. Burada "yayından 3 gün sonrası" bir **andan itibaren geçen süredir**; saat
> dilimi hiç girmez ve girmemeli.

```php
// ClaimReleasedOrderAction — K83: "en yüksek" değil "yeten en düşük"
Order::grantingPublishRight()->releasable()->whereNull('invitation_id')
     ->where('user_id', $invitation->user_id)
     ->lockForUpdate()->get();
//    🔴 lockForUpdate ZORUNLU: aynı kullanıcı iki davetiyeyi eş zamanlı yayınlarsa
//    kilitsiz iki istek AYNI serbest siparişi görür ve ikisi de bağlar — bir ödeme
//    İKİ yayın açar. M8'in ikinci görünümü
```

> 🔴 **Ders 62 — tüketimde refleks terstir.** Resolver "en yüksek"i döndürür (**okuma**:
> ne kadar hakkım var?); tüketim "yeten en düşük"ü harcar. Elde hem Standart hem Elit
> serbest sipariş varsa ve davetiye Standart gerektiriyorsa, Elit'i harcamak kullanıcının
> 300 lirasını yakardı. **Aynı veriye bakan iki kod farklı yönde optimize edilir.**

### Bakım komutları — projenin ilk kendiliğinden çalışan kodu

| Komut | Cadence | Ne yapar |
|---|---|---|
| `orders:expire` | saatlik | `expires_at` geçmiş `pending` siparişleri `failed` yapar. `expires_at` üç fazdır yazılıyordu, **ilk kez okunuyor** |
| `media:prune-orphans` | günlük `03:15` | LCV'ye bağlanmamış, `orphan_grace_hours` (24 sa) geçmiş misafir yüklemeleri |
| `sanctum:prune-expired --hours=720` | günlük | Laravel bu komutu Faz 2'den beri sağlıyordu ve sekiz fazdır **çağrılmadı** |

Üçü de `withoutOverlapping()` + `onOneServer()` taşır ve `--dry-run` destekler (K84: bir
bakım komutunun ilk gerçek koşusu üretimde ve gözlemsiz olmamalı).

> 🔴 **Temizlikte fail-safe yön silmemektir.** `PruneOrphanMedia` ham tablo sorgusu
> kullanır (soft-delete'li LCV'ler de sayılsın) ve **önce dosyayı, sonra satırı** siler —
> ters sıra kalıcı disk sızıntısı üretir.
>
> ⚠️ `routes/console.php` **tek başına hiçbir şey yapmaz**: sunucuda dakikada bir
> `php artisan schedule:run` çağrılmalı. Bu dosya "ne zaman" der; "çalıştır" diyen şey
> işletim sistemidir.


---

## 3.10 Test senaryoları — `tests/` klasörü neyi kapsıyor?

**Toplam 238 test:** 232 Feature + 6 Unit. Ağırlık bilinçli olarak Feature testlerindedir:
gerçek HTTP isteği atıp gerçek PostgreSQL'e yazan testler, birim testlerinden **daha fazla
gerçek hata yakalar.**

| Dosya | Test | Kapsadığı iş süreci |
|---|:---:|---|
| `Feature/HealthTest.php` | 7 | Sözleşme sağlığı + **sızıntı** |
| `Feature/AuthTest.php` | 15 | Kayıt · giriş · çıkış · token izolasyonu |
| `Feature/InvitationTest.php` | 19 | CRUD · **IDOR** · program senkronizasyonu |
| `Feature/PublicInvitationTest.php` | 25 | Public okuma · cache · ETag · olay zinciri |
| `Feature/RsvpTest.php` | 29 | LCV · 5 katmanlı savunma · kota |
| `Feature/MediaTest.php` | 28 | İki yükleme ucu · MIME · kota · LCV'ye bağlama |
| `Feature/PaywallTest.php` | **51** | Tier hesabı · yayın · checkout · webhook · Faz 9 hak akışı |
| `Feature/AssistantTest.php` | 21 | Kota · sağlayıcı hatası · sır yönetimi |
| `Feature/ContactTest.php` | 14 | İletişim formu · honeypot · KVKK |
| `Feature/HardeningTest.php` | 8 | CORS · güvenlik başlıkları (Faz 9) |
| `Feature/MaintenanceTest.php` | 15 | Bakım komutları · zamanlayıcı kaydı (Faz 9) |
| `Unit/OrderScopeTest.php` | **6** | `OrderScope` enum'unun iki yüklemi — ilk birim testi |

### Ne tür senaryolar yazılıyor? — dört kategori

**1. Mutlu yol (happy path)** — sözleşmenin gerçekten tutup tutmadığı

```
register_creates_user_and_returns_unwrapped_session
store_creates_an_invitation_for_the_authenticated_user
a_paid_order_publishes_the_invitation
guest_can_submit_an_rsvp_to_a_published_invitation
response_matches_the_frontend_contract
```

**2. Yetkisiz erişim / IDOR** — projenin en yoğun test edilen alanı

```
owner_cannot_read_another_users_invitation
owner_cannot_update_another_users_invitation
owner_cannot_delete_another_users_invitation
🔴 missing_and_forbidden_invitations_are_indistinguishable   ← H7'nin kanıtı
another_users_package_does_not_grant_publish_rights          ← ödeme katmanında IDOR
another_users_released_order_is_never_claimed
owner_cannot_publish_someone_elses_invitation
owner_cannot_upload_to_someone_elses_invitation
another_user_cannot_list_rsvps · another_user_cannot_delete_an_rsvp
a_timeline_event_of_another_invitation_cannot_be_overwritten
```

**3. Doğrulama ve iş kuralı hataları**

```
register_reports_field_errors_for_invalid_input
guest_count_is_capped_by_configuration · guest_count_must_be_at_least_one
status_must_be_a_known_value · the_subject_must_be_a_known_value
the_database_refuses_an_unknown_subject          ← CHECK kısıtının kendisi test ediliyor
rsvp_is_rejected_after_the_deadline · rsvp_is_accepted_on_the_deadline_day
publishing_twice_returns_conflict                ← 409
checkout_rejects_an_unknown_tier
an_invalid_timeline_time_is_reported_with_its_index
```

**4. 🔴 Sızıntı ve savunma testleri** — bu projeye özgü ağırlık

```
# Enumeration ve zamanlama
register_does_not_reveal_that_the_email_is_taken
login_is_indistinguishable_for_unknown_email_and_wrong_password
unpublished_and_missing_invitations_are_indistinguishable
closed_module_and_missing_invitation_are_indistinguishable
wrong_http_method_does_not_reveal_route_existence

# Hata zarfı
debug_block_is_absent_in_production_mode  /  debug_block_is_present_in_local_mode
unknown_route_returns_error_envelope
html_request_to_api_still_receives_json

# Veri sızıntısı
register_response_never_exposes_the_password
gift_details_are_absent_when_the_module_is_off      ← C6
timeline_events_do_not_expose_their_ids
server_metadata_is_not_exposed
ip_hash_is_never_exposed                            ← L4
the_raw_ip_is_never_stored · the_ip_hash_is_a_keyed_hmac
quota_rejection_does_not_leak_counters
the_guest_never_learns_the_quota / the_owner_learns_the_gallery_limit
the_checkout_response_never_exposes_the_provider_ref
the_rsvp_response_never_exposes_media_ids
the_raw_provider_error_never_reaches_the_response
the_api_key_travels_in_a_header_and_never_in_the_url
an_unknown_origin_is_never_echoed_back

# Bot ve kötüye kullanım
honeypot_submission_looks_successful / honeypot_submission_is_not_persisted
honeypot_response_has_the_same_shape_as_a_real_one
an_empty_honeypot_field_is_not_a_trap
credential_endpoints_are_rate_limited · rsvp_submissions_are_rate_limited
assistant_requests_are_rate_limited · contact_submissions_are_rate_limited
a_malformed_id_never_reaches_the_database           ← whereUlid'in kanıtı

# Para ve idempotans
the_order_amount_comes_from_the_server_side_price   ← M6
the_webhook_rejects_an_invalid_signature
the_same_webhook_twice_does_not_move_paid_at        ← M8
a_paid_order_cannot_be_moved_back_to_failed
a_refund_keeps_the_paid_at_stamp
an_unknown_provider_ref_is_accepted_silently        ← W3
the_webhook_does_not_publish_the_invitation         ← K67
the_quota_is_charged_even_when_the_provider_fails   ← Q3

# Medya aidiyeti
media_from_another_invitation_is_silently_dropped
a_gallery_photo_cannot_be_attached_to_an_rsvp
a_video_id_cannot_be_used_as_a_photo
an_unknown_media_id_is_silently_dropped
the_upload_is_validated_by_mime_not_extension       ← F1
the_stored_filename_is_random                       ← F2
```

### 🔴 T16 — mutasyon tablosu: faz kapanış ölçütü

Faz 5'ten itibaren her büyük test dosyası bir **mutasyon tablosu** ile birlikte yazılır.
Tabloda her savunma satırı için şu yazar: *"bu satırı silersem/tersine çevirirsem hangi
test kırmızı yanar?"*

Gerekçe bir paranoya değil, yaşanmış bir hatadır:

> Faz 3'te üç IDOR testi **boş yeşil** yanıyordu. Rota ULID kısıtı elle yazılmıştı ve
> yalnızca büyük harfle eşleşiyordu; `HasUlids` ise `strtolower()` uyguluyor. `show`,
> `update` ve `destroy` uçları **hiç çalışmadı** ve Policy hiç koşmadı. Testler yine de
> geçiyordu, çünkü 404 bekliyorlardı ve 404 alıyorlardı — **yanlış sebeple.**
>
> **Kural 14: beklediğin yanıtı almak, beklediğin sebeple aldığın anlamına gelmez.**

Benzer boş yeşiller ve düzeltmeleri:

| Boş yeşil | Sebep | Düzeltme |
|---|---|---|
| 3 IDOR testi (Faz 3) | Elle yazılan büyük-harf ULID regex'i | `whereUlid()` (**R6**) |
| Token izolasyon testleri (Faz 2) | `actingAs()` guard'ı atlıyor, guard önbelleği | `withToken()` + `forgetAuthState()` (**T13**) |
| `html_request_to_api_still_receives_json` (Faz 1) | Rota eşleşmeyince grup middleware'i hiç koşmuyor | `bootstrap/app.php`'de `$request->is('api/*') \|\|` |
| Honeypot testi | Savunma 201 döndürüyor, yanıt hiçbir şey kanıtlamıyor | Yanıtı değil **etkiyi** doğrula: `assertDatabaseCount` (**T14**) |
| `touching_an_invitation_dispatches_the_change_event` | Aynı saniyede `touch()` → `isDirty()` false → olay hiç fırlamıyor | Zamanı bir **girdi** olarak ele al (ders 49) |
| Tüm `composer check` (8 faz) | `tests/Unit` dizini hiçbir commit'te yoktu — **git boş dizin saklamaz** | `tests/Unit/.gitkeep` → sonra gerçek birim testi (**B10**) |

### Test altyapısı — bilinmesi gereken üç şey

**1. `tests/TestCase.php::forgetAuthState()`** — Laravel'in guard önbelleğini temizler.
`withToken()` ile yapılan testlerde bu çağrılmazsa önceki isteğin kullanıcısı sızar.

**2. `RefreshDatabase` rollback eder.** `ShouldHandleEventsAfterCommit` uygulayan listener
testte **hiç koşmaz**. Bu yüzden cache invalidation zinciri üç ayrı teste bölündü ve son
halka elle doğrulama betiğine yazıldı (**T15**).

**3. `Storage::fake()` gerçek diski hiç görmez.** `storage:link` testlerde görünmez —
bu yüzden Faz 6'dan beri açık bir borç olarak durabildi.

### 🔴 Bitti ölçütü: hiçbir testte METİN doğrulanmaz

```php
// ✅ Davranışa bakar — metin değişse de geçer
$response->assertStatus(422)
    ->assertJsonPath('error.code', 'VALIDATION_FAILED')
    ->assertJsonPath('error.fields.guestCount.0.rule', 'max');

// ✅ Sızıntı testi — üretim kipinde debug bloğu YOK
config(['app.debug' => false]);
$response->assertJsonMissingPath('error.debug');
```

Metin frontend'in işidir; backend testi yalnızca **kod, durum ve alan adı** bilir.


---

# 4. Geliştirme Süreci: Faz 0'dan Faz 9'a Tarihçe

## 4.0 Neden 9 faz, neden bu sırayla?

İlk plan (`docs/03` §8) **12 adımlık katman-katman** bir inşa öngörüyordu: önce *tüm*
enum'lar, sonra *tüm* migration'lar, sonra *tüm* modeller… Bu sırayla ilk çalışan endpoint
**16. dosyada** ortaya çıkıyordu.

Sorun teknik değil, **öğrenme** sorunuydu: yazılan kod hiçbir şey yapmıyor, katmanların
birbirine nasıl bağlandığı görünmüyor.

**K17 ile inşa sırası değişti: özellik-özellik (Walking Skeleton).**

|  | Auth | Invitation | RSVP |
|---|:---:|:---:|:---:|
| Migration | **1** | 7 | 13 |
| Model | **2** | 8 | 14 |
| FormRequest | **3** | 9 | 15 |
| Action | **4** | 10 | 16 |
| Resource | **5** | 11 | 17 |
| Controller | **6** | 12 | 18 |

→ 6. dosyada **çalışan bir kayıt endpoint'i** var. Aynı 18 dosya, farklı doldurma yönü.

> **Neden bu fark önemli?** Bir sözleşme hatası (yanlış camelCase eşlemesi, yanlış yanıt
> zarfı) katman-katman sırada **12 Resource'a kopyalanmış olarak** keşfedilir;
> özellik-özellik sırada ilk özellikte, **tek dosyada** yakalanır.
>
> **Kural: tasarımı bütün yap, inşayı parça parça.** Veri modelinin tamamı `docs/03` §3.2'de
> baştan tasarlandı; yalnızca yazılma sırası dilimlendi.

### Bağımlılık akışı

```
Faz 1 (hata zarfı) ─── sonraki 8 fazın hepsi buna yaslanır
   └→ Faz 2 (Auth) ─── kimlik olmadan sahiplik yok
        └→ Faz 3 (Invitation) ─── ana varlık, veri modelinin kalbi
             ├→ Faz 4 (public okuma)
             ├→ Faz 5 (RSVP) ──────┐
             │                     │ rsvps.photo_media_id FK'si
             ├→ Faz 6 (Media) ←────┘ Faz 6'da geri bağlandı
             └→ Faz 7 (Paywall) ─── Faz 3'ün show_* kolonlarına bağımlı
                  └→ Faz 8 → Faz 9
```

> 🔴 **Kritik gözlem:** Faz 7 projenin ticari çekirdeğidir ama **en sonda** gelir ve
> **Faz 3'ün veri modeli kararlarına bağımlıdır.** `show_*` alanları Faz 3'te ayrı boolean
> kolon olarak açılmasaydı, Faz 7'de paywall'ı SQL ile doğrulamak imkânsızlaşırdı.

### Faz istatistikleri — plan vs. gerçek

| Faz | Konu | Plan | Gerçek | Kod | `composer check` | Elle doğrulama |
|:---:|---|:---:|:---:|:---:|:---:|:---:|
| **0** | Zemin + kalite kapıları | 5 | **9** | ✅ | ✅ | — |
| **1** | İlk uç + hata zarfı | 4 | **8** | ✅ | ✅ | — |
| **2** | Auth (walking skeleton) | 10 | **17** | ✅ | ✅ | ✅ |
| **3** | Invitation CRUD | 12 | **12 + 8 FE** | ✅ | ✅ | ✅ |
| **4** | Public davetiye + cache | 6 | **8 + 2 FE** | ✅ | ✅ | ✅ |
| **5** | RSVP | 10 | **17** | ✅ | ✅ (Faz 6'da) | ⬜ 16 adım |
| **6** | Media | 8 | **24** | ✅ | ✅ | ⬜ 18 adım |
| **7** | Ödeme + paywall | 12 | **25** | ✅ | ✅ | ⬜ 20 adım |
| **8** | AI asistan + iletişim | 6 | **21** | ✅ | ✅ | ⬜ |
| **9** | Üretim hazırlığı | 7 | **14** | ✅ | ✅ (238 test) | ⬜ 22 adım |

> **Her fazda plan büyüdü** ve bu kapsam kayması değildi: plan yazılırken **görülmemiş
> bağımlılıklar**dı. Faz 6'nın 8 → 24 büyümesi, planın yalnızca sahibin galerisini hesaba
> katmış olmasından; Faz 7'nin 12 → 25 büyümesi dört ayrı exception ve üç fazdır ertelenen
> `timezone` kolonundan geldi.

---

## FAZ 0 — Zemin ve kalite kapıları ✅

> **Bitiş:** 31 Temmuz 2026 · Kayıt: [`rehber/fazlar/FAZ-0.md`](rehber/fazlar/FAZ-0.md)

**Ana hedef:** Kod yazmaya başlamadan önce *"yanlışı anında söyleyen"* araçları kurmak.
Bu fazda **hiçbir iş kodu yazılmadı.**

**Neden önce zemin?** Bir hatanın maliyeti keşfedildiği ana göre katlanarak artar:

```
Yazarken bulunursa  → saniyeler
Testte bulunursa    → dakikalar
İncelemede          → saatler
Üretimde            → günler + itibar
```

Faz 0'ın tamamı bu okun **sol tarafına** yatırımdır.

**Uygulanan adımlar:**

| # | İş | Sonuç |
|---|---|---|
| 0.1–0.3 | `pdo_pgsql` kontrolü · PostgreSQL 18 · iki veritabanı | ✅ |
| 0.4–0.6 | `.env` düzenlemesi · bağlantı doğrulama · SQLite'ın kaldırılması | ✅ |
| 0.8–0.9 | Pint (`pint.json`) · Larastan (`phpstan.neon`, level 5) | ✅ |
| 0.10 | `phpunit.xml` → `davetkart_test`, `array` cache, `sync` kuyruk | ✅ |
| 0.11 | `AppServiceProvider` sıkılaştırma | ✅ |
| — | **Hata sözleşmesi** (plan dışı) | ✅ `docs/08` |

**Kritik kararlar:**

- **K9' + K19 — üç ortamda da PostgreSQL 18.** Önceki karar "geliştirmede SQLite, üretimde
  MySQL 8" idi. SQLite'ın gerekçesi *"Herd ücretsiz sürümünde MySQL yok"* — yani teknik
  üstünlük değil **kurulum zahmeti**. Asıl mesele **dev/prod parity** (12-Factor X): farklı
  veritabanı, hataların laptop'ta değil **üretimde** ortaya çıkması demektir. Feragat edilen
  tek şey test hızıydı; ama yanlış veritabanında koşan hızlı test **yanlış güven** verir.
  Maliyet sıfırdı — henüz tek migration yazılmamıştı.
- **K18 — Pint + Larastan + PHPUnit.** (Plan Pest diyordu; K24 ile PHPUnit'te kalındı.)
- **K20 — hata sözleşmesi** tasarlandı (bkz. [§2.5](#25-🔴-hata-yönetimi-standardı)).
- **K21 — backend tek dil konuşur** (`APP_LOCALE=en`). `lang/tr/validation.php` silindi;
  Faz 8'in `SetLocaleFromHeader` middleware'i **iptal edildi**.
- **K32 — Argon2id.** ⚠️ Bu karar "uygulandı" görünüyordu ama **uygulanmamıştı**:
  `config/hashing.php` yayınlanmış, `.env`'e `HASH_DRIVER` hiç yazılmamıştı. Faz 2'ye
  kadar **bcrypt** kullanıldı.
  > **Ders (B4):** Karar kaydında ✅ görünen bir madde, kodda doğrulanmadıysa yalnızca bir
  > **niyettir**.

**`AppServiceProvider` sıkılaştırma — üç ayar:**

```php
Model::shouldBeStrict(! $this->app->isProduction());   // lazy loading + sessiz atılan alan
Date::use(CarbonImmutable::class);                      // K23: ->addDay() orijinali bozmaz
DB::prohibitDestructiveCommands($this->app->isProduction());  // migrate:fresh yasağı
```

**Kurulan kural sayısı:** 31.

---

## FAZ 1 — İlk nefes: çalışan uç + hata zarfı ✅

> **Kayıt:** [`rehber/fazlar/FAZ-1.md`](rehber/fazlar/FAZ-1.md)

**Ana hedef:** Bir HTTP isteğinin Laravel içinde nereden girip nereden çıktığını **görmek**
ve K20 hata sözleşmesini **tek merkeze** kurmak.

**Neden hata zarfı bu kadar erken?** Merkezi bir yerde kurulmazsa her controller kendi hata
biçimini üretir. Faz 1'de yazılan exception handler sonraki 8 fazda **hiç tekrar edilmez** —
Faz 5'in `RsvpQuotaExceededException`'ı da, Faz 7'nin `PaywallViolationException`'ı da aynı
kapıdan geçer.

| # | Dosya | İşi |
|---|---|---|
| 1.1 | `app/Enums/ErrorCode.php` | Kod kataloğu + `status()` + `allowedParams()` beyaz listesi |
| 1.2 | `app/Http/Middleware/ForceJsonResponse.php` | API her zaman JSON döner |
| 1.3 | `bootstrap/app.php` | Middleware kaydı + exception handler |
| 1.4 | `routes/api.php` | `GET /api/ping` |
| 1.5 | `tests/Feature/HealthTest.php` | 7 test, 3'ü sızıntı |
| 1.6 | `app/Console/Commands/ExportErrorCodes.php` | `php artisan errors:export` |
| — | `ApiExceptionRenderer` · `HealthController` | **plan dışı** (K26, K30) |

**Bitti ölçütü — genişletildi:** `/api/ping` JSON döner **ve** bilinmeyen rota HTML değil
`{"error":{"code":"RESOURCE_NOT_FOUND"}}` döner.

**Kritik kararlar:**

- **K26 — `ApiExceptionRenderer` ayrı sınıf.** `bootstrap/app.php` içindeki closure büyüyordu.
- **K30 — rota dosyasında closure yok.** Gerekçesi *"`route:cache` closure'ları
  serileştiremez"* idi. ⚠️ Faz 9'da bu gerekçe **geçersizleşti** (Laravel 13 closure'ları
  `SerializableClosure` ile serileştiriyor) — ama karar hâlâ doğru, gerekçesi artık
  "ölü kod" (ders 26).
- **`ErrorCode` tam katalog** (18 kod) yazıldı, minimal 5 kod değil: *"kod adı
  yayınlandıktan sonra sözleşmedir"* — adları tek oturumda tutarlı düşünmek, parça parça
  eklerken isim tutarsızlığı üretmekten iyidir.

**🔴 Faz 1'de bulunan hata (Faz 2'de düzeltildi):**
`html_request_to_api_still_receives_json` testi **yazıldığı günden beri hiç geçmemişti** —
ve `FAZ-1.md`'ye "doğrulandı" diye yazılmıştı. Sebep: `Router::findRoute()` eşleşme
bulamazsa exception fırlatır ve **grup middleware'i hiç çalışmaz**. Düzeltme:
`bootstrap/app.php`'de render koşuluna `$request->is('api/*') ||` eklendi.

**Kurulan kural sayısı:** 19 (H10–H13 · R1–R5 · M1–M4 · T6–T9 · G1–G3).

---

## FAZ 2 — Auth özellik dilimi (walking skeleton) ✅

> **Bitiş:** 6 Ağustos 2026 · Kayıt: [`rehber/fazlar/FAZ-2.md`](rehber/fazlar/FAZ-2.md)

**Ana hedef:** Tüm katmanları **bir arada** çalışırken görmek. Kalan 7 fazın tamamı bu
kalıbın tekrarıdır — öğrenme eğrisi bir kez tırmanılır.

**Plan 10 dosyaydı, gerçekleşen 17 oldu.** Genişleme üç zorunluluktan geldi: K35 (şema
değişikliği), H10/H11'in gerektirdiği iki exception sınıfı, K36 (rate limit).

| # | Dosya | Not |
|---|---|---|
| **2.0** | `create_users_table` | 🆕 **K35** — `first_name` + `last_name`, `VARCHAR(60)` |
| 2.1–2.3 | `User` · `UserFactory` · `UserResource` | `hashed` cast · `HasApiTokens` · hash memoization |
| 2.4 | `RegisterRequest` | 🔴 `unique` **bilerek yok** (A1) |
| **2.5a** | `RegistrationFailedException` | 🆕 H10/H11 zorunluluğu |
| 2.5b | `RegisterUserAction` | Transaction + UNIQUE kısıtı yakalama |
| 2.6–2.7 | `AuthController` · rotalar | 🎯 İlk gerçek uç nokta |
| **2.8a** | `configureRateLimiting()` | 🆕 **K36** — Faz 5'ten öne çekildi |
| 2.8b–c | `LoginRequest` · `InvalidCredentialsException` · `LoginUserAction` | 🔴 **Zamanlama savunması** + rehash |
| 2.9 | `RevokeTokenAction` + `logout` + `me` | Token izolasyonu (A6) |
| 2.10 | `AuthTest` (15 test) + `TestCase::forgetAuthState()` | 🆕 **T13** |
| — | `phpstan.neon` | **K22** — level 5 → **6** |

**Kritik kararlar:**

- **🔴 K35 — `first_name` + `last_name` ayrı kolon, API'de de ayrı döner.**
  Ayrı kolonun kazandırdığı: fatura ve resmî belgelerde soyadı tek başına gerekir (Faz 7),
  soyada göre sıralama mümkün olur, hitap kurulabilir. **Tek kolondan bunların hiçbiri geri
  kazanılamaz.** Bölme kritik olarak **yazma anında** yapılır: kullanıcıdan tek string alıp
  boşluktan bölmek yasaktır — "Ayşe Nur Kaya" için ad "Ayşe" mi "Ayşe Nur" mü bilinemez.
  > **Genel kural: birleştirmek kolay, birleşmiş veriyi ayırmak imkânsızdır. Şüphede
  > kaldığında ayrık gönder.**
- **K36 — hız sınırı Faz 5'ten öne çekildi.** İki sebep: brute-force **ve** K32'nin
  doğurduğu **bellek tüketimi saldırısı** — Argon2id her isteği 64 MB'lık bir kaynak
  talebine çevirdi.

**Bu fazın iki kritik güvenlik işi:** enumeration savunması ve zamanlama savunması
(ayrıntı: [§3.1.2](#312-post-apiauthlogin--🔴-iki-savunmalı-akış)).

**Frontend'de yapılanlar (K35 sözleşme değişikliği):** `types.ts` · yeni `utils/user.ts`
(`fullName()` yardımcısı) · `RegisterPage` iki input · `Header`/`Dashboard`/`LoginPage` ·
`services/api.ts`'te 🔴 **401 ayrımı** (`INVALID_CREDENTIALS` oturumu düşürmez).

**Kurulan kural sayısı:** 20 (A1–A7 · D1–D5 · E1–E5 · C1–C3 · T10–T13 · B4).


---

## FAZ 3 — Invitation CRUD ✅

> **Bitiş:** 19 Ağustos 2026 · Kayıt: [`rehber/fazlar/FAZ-3.md`](rehber/fazlar/FAZ-3.md)

**Ana hedef:** Sahiplik, yetkilendirme (IDOR) ve iç içe koleksiyon yönetimi. Projenin en
büyük fazı ve **veri modelinin kalbi.**

**Plan 12 backend dosyasıydı; gerçekleşen 12 backend + 8 frontend oldu.** Genişleme iki
karardan geldi: **K37** (REST koleksiyonu — frontend'in "hesap başına tek davetiye"
varsayımı geçersiz kılındı) ve **K44** (kimliği backend üretir). İkisi de sözleşme
değişikliği olduğu için frontend uyarlaması bu fazın parçası oldu.

| # | Dosya | Not |
|---|---|---|
| 3.1 | `InvitationStatus` | ⚠️ **K38** — `draft` atıldı: `saved \| published` |
| 3.2 | `create_invitations_table` | **K40** ULID PK, slug yok · **K39** CHECK · **K41** `phone_background` yok · içerik alanları **nullable** |
| 3.3 | `create_timeline_events_table` | `foreignUlid` · `sort_order` · CASCADE |
| 3.4–3.5 | `Invitation` · `TimelineEvent` modelleri | `#[Fillable]` özniteliği (Laravel 13) · `immutable_*` cast · `user_id` int cast |
| **3.6** | `InvitationFactory` + `TimelineEventFactory` + `DatabaseSeeder` | 🔴 Seeder Faz 2'den beri **bozuktu** (`name` kolonu yok), yeniden yazıldı |
| 3.7 | `InvitationPolicy` | 🔴 IDOR savunması — reddi **404** |
| **3.8** | `Requests/Invitation/` — **3 dosya** | Soyut taban + iki ince alt sınıf (C3), 21 alanlık açık eşleme |
| 3.9 | 3 Resource | ⚠️ **Sapma:** `whenLoaded()` kullanılmadı |
| 3.10 | 3 Action | Transaction + senkronizasyon (N1–N4) |
| 3.11 | `InvitationController` + rotalar | ⚠️ **Sapma:** `authorizeResource` çalışmıyor |
| 3.12 | `InvitationTest` | **19 test**, 5'i sahiplik |

**Kritik kararlar:**

| # | Karar |
|---|---|
| **K37** | `/api/invitations` tam REST koleksiyonu (upsert değil) |
| **K38** | `draft` durumu atıldı — onu doğuran bir olay yok |
| **K39** | Migration'da gerçek `ENUM` değil **`VARCHAR + CHECK`**; değerler enum'dan beslenir |
| **K40** | **ULID birincil anahtar**; ayrı `public_slug` **yok** — frontend zaten `/invite/{record.id}` kullanıyordu |
| **K41** | `phone_background` türetilir (`preset_id`'den), saklanmaz |
| **K42** | Yayın hakkı iki kaynaktan, **tek arayüzden** sorulur → Faz 7'de `PublishEntitlementResolver` |
| **K43** | Plan kotası **yayınlananı** sayar → ⚠️ Faz 7'de **uygulanmadı**, hâlâ açık |
| **K44** | Kimliği backend üretir; `id: null` = yeni satır |

**🔴 İki plan sapması — gerekçeleriyle:**

| Plandaki | Yapılan | Neden |
|---|---|---|
| `whenLoaded()` ile N+1 önleme | Doğrudan `$this->timelineEvents` + controller'da `with()` | `whenLoaded` ilişki yüklü değilse anahtarı **düşürür**; frontend eksik alanı varsayılanla doldurur ve kullanıcı **hiç yazmadığı bir programı** görür. Doğrudan erişimde `preventLazyLoading` yerelde exception fırlatır — **sessiz yanlış veri yerine gürültülü hata** |
| `authorizeResource` | Her metotta `Gate::authorize()` | Laravel 11+ taban controller'ı boş; `authorizeResource` `$this->middleware()` çağırıyor ve o metot **yok** |

**🔴 `POST /api/invitations/{id}/publish` Faz 3'te AÇILMADI.** Plan *"rota burada açılsın,
iş kuralı Faz 7'de"* diyordu. Açılmadı, gerekçe **K47** olarak kaydedildi:

> *"Şimdi yazılırsa paywall'sız bir bedava yayın yolu açılır."* Boş bir uç nokta sözleşmede
> **yalan bir sözdür** (B4). **Ders: bir rotayı erken açmak, onu korumasız açmaktır.**

**🔴 Faz 2'de bulunan ve bu fazda düzeltilen 4 kusur:** Faz 3'ün ilk `composer check`
çalıştırması Faz 2'nin **yeşil kapanmadığını** ortaya çıkardı — `Password::min(8)` sınıf adı
sızıntısı (D6), guard önbelleği (T13), gereksiz `?->`, bozuk seeder.

**Kurulan kural sayısı:** 15 (P1–P4 · N1–N4 · D6 · E6 · C4–C5 · T13–T14 · B5).

**Öğrenilen:** Migration ve indeks stratejisi · Eloquent ilişkileri · mass assignment
güvenliği · **Policy ile IDOR kapatma** · iç içe koleksiyon senkronizasyonu · N+1 önleme ·
**sahipliğin bir `if` değil sorgunun kapsamı olduğu** · **çalıştırılmayan kodun doğru
varsayıldığı**.

---

## FAZ 4 — Public davetiye (okuma yolu) ✅

> **Bitiş:** 27 Ağustos 2026 · Kayıt: [`rehber/fazlar/FAZ-4.md`](rehber/fazlar/FAZ-4.md)

**Ana hedef:** Sistemin en yüksek trafikli noktası. Cache ve ETag.
**Planlanan 6 dosya → 8 backend + 2 frontend oldu.**

| # | Dosya | Durum |
|---|---|---|
| 4.1 | `ResolvePublicInvitationAction` — yalnızca yayınlanmış | ✅ |
| 4.2a | `PublicTimelineEventResource` | ✅ **planda yoktu** — misafire `id` gitmemeli (C5) |
| 4.2b | `PublicInvitationResource` | ✅ 🔴 **C6**: kapalı modülün verisi hiç gönderilmez |
| 4.3 | `PublicInvitationController` — auth'suz, cache'li | ✅ |
| 4.4 | `/api/public/invitations/{id}` (K12) | ✅ |
| 4.5 | `SetEtag` middleware → 304 | ✅ |
| 4.6 | `InvitationChanged` + `ClearInvitationCache` | ✅ ⚠️ **ad değişti** |
| 4.7 | `PublicInvitationTest` | ✅ **25 test** |
| 4.8 | Frontend: `publicInvitation.ts` + `InvitePage.tsx` | ✅ |

**🔴 Plandan sapmalar (tartışılarak yapıldı, geri alınmamalı):**

| Planda | Yapılan | Neden |
|---|---|---|
| Cache Action içinde | Cache **controller'da**, Resource çıktısı **dizi** üzerinde (**K45**) | Action saf ve cache'siz test edilebilir kalır; cache'te serileşmiş Eloquent modeli şema değişince **bayat nesne canlandırır**; ETag aynı diziden hesaplanır |
| `InvitationPublished` olayı | **`InvitationChanged`** (**K48**) | Yayın akışı Faz 7'de — `InvitationPublished`'ı bugün fırlatan kod yok, üç faz boyunca **ölü kod** olurdu. Olay modelden **yapısal** fırlıyor (`$dispatchesEvents`) |
| Tek public Resource | + `PublicTimelineEventResource` | Artan bigint kimlik, K40'ın kapattığı **sayım sızıntısını** geri getirirdi |
| "ETag middleware **veya** controller içi 304" | **Middleware** (**K46**) | Faz 5'in polling ucu aynı katmanı yeniden kullanacak (C3) |
| Cache testleri uçtan uca | Zincir **üç halkaya** bölündü (**T15**) | `RefreshDatabase` rollback ediyor, `ShouldHandleEventsAfterCommit` testte hiç koşmuyor |

**Faz 4'ün ortaya çıkardığı Faz 3 kusurları:**

| Kusur | Etkisi | Düzeltme |
|---|---|---|
| Rota ULID kısıtı elle yazılmış, yalnızca büyük harf | `show`/`update`/`destroy` **hiç çalışmadı**; 3 IDOR testi **boş yeşil** | `whereUlid()` (**R6**) |
| `CreateInvitationAction` `status` yazmıyordu | `POST /api/invitations` → **500** | `make()` + açık atama (**E7**) |
| Larastan `casts()` metodunu hiç okumuyordu | 3 PHPStan hatası gizliydi | `parseModelCastsMethod: true` |

**Faz 5'e devredilen borçlar:** genel API hız sınırı yok · `event_at` saat dilimi
(→ K63, Faz 7'de kapandı) · cache invalidation uçtan uca test edilemiyor.

**Kurulan kural sayısı:** 11 (O1–O6 · R6 · E7 · C6 · T15 · B6).

---

## FAZ 5 — RSVP modülü ⚠️ elle doğrulama açık

> **Kayıt:** [`rehber/fazlar/FAZ-5.md`](rehber/fazlar/FAZ-5.md) · Kapanış:
> [`FAZ-5-ELLE-DOGRULAMA.md`](rehber/fazlar/FAZ-5-ELLE-DOGRULAMA.md) (16 adım)

**Ana hedef:** **Auth'suz yazma yolu** — sistemin en çok saldırıya açık noktası. Katmanlı
savunma (defense in depth) burada öğrenildi.

**Plan 10 dosyaydı, 17 adım oldu.**

| # | Dosya | Not |
|---|---|---|
| 5.1 | `RsvpStatus` | ⚠️ `label()` **yazılmadı** (K21 · K49) |
| 5.2 | `create_rsvps_table` | ULID PK (K52), **iki** CHECK, `ip_hash` |
| 5.3–5.4 | `Rsvp` modeli · `StoreRsvpRequest` (honeypot) | ✅ |
| 5.5 | 🆕 `HasErrorCode` arayüzü + `RsvpDeadlinePassed`/`RsvpQuotaExceeded` | H11'i **tip sistemine** bağladı |
| 5.6 | 🆕 `Contracts/RsvpQuotaResolver` + geçici uygulama | **K51** dikiş yeri |
| 5.7 | `SubmitRsvpAction` | 🔴 5 katmanlı savunma |
| 5.8–5.10 | `RsvpResource` · `RsvpPolicy` · iki controller | Plan tek controller diyordu |
| 5.11 | Rotalar + `throttle:rsvp` (2 kova) + `throttleApi()` | **FAZ-4 §9.2 borcu kapandı** |
| 5.12–5.13 | `RsvpFactory` + seeder · `RsvpTest` **29 test** + mutasyon tablosu | ✅ |
| 5.14 | PHPStan level 6 → **8** (K22) | Ayrı commit, geri alınabilir |
| — | 🔴 `Jobs/SendRsvpNotification` | ❌ **YAZILMADI (K53)** |

**Kritik kararlar:**

| # | Karar |
|---|---|
| **K49** | `RsvpStatus` = `attending \| pending \| declined`; `label()` **yok** — gösterim metni veri değeri olamaz (K21) |
| **K50** | Kota `attending + pending` sayar, `declined` saymaz. Kural `RsvpStatus::consumesQuota()` içinde **tek yerde** |
| **K51** | Kota limiti `RsvpQuotaResolver` **arayüzü** arkasından okunur — gerçek kaynak Faz 7'de doğacak |
| **K52** | `rsvps.id` = **ULID** — kimlik `DELETE /api/rsvps/{id}` URL'sinde geçiyor |
| **K53** | `Jobs/SendRsvpNotification` **yazılmadı**: bildirimin gideceği kanal hiçbir fazda tasarlanmamıştı; bugün yazılsa `handle()` yer tutucu olurdu |

**Yeni kural serisi L — auth'suz yazma yolu:**

| # | Kural |
|---|---|
| **L1** | Savunma katmanları **en ucuzdan pahalıya** sıralanır |
| **L2** | Bot tespiti **sessizdir**; reddin kendisi bilgi sızıntısıdır |
| **L3** | **Hız sınırı ile kota birbirinin yerine geçmez** — biri *sıklığa*, diğeri *hacme* bakar |
| **L4** | Kişisel veri hash'lenerek saklanır ve **türevi de yayılmaz** |

**Öne çıkan dersler:**

- **Ders 42 — bir kuralı uygulamak, gerekçesini kontrol etmeden kopyalamak değildir.**
  Faz 5'te üç kez oldu: K38 (`draft` atılmıştı → `pending` **kaldı**), H7 (*sahiplik yoksa
  404* → son tarih reddi **403**), C4 (iki Resource ayrılmıştı → LCV'de **tek Resource
  yeterli**). Kural değişmedi, **girdi** değişti.
- **Ders 43 — tarih ile zaman damgası farklı tiplerdir** (E8).
- **Ders 44 — sessizlik bir savunma olabilir, ve o zaman testin yükü artar.** Honeypot 201
  döndüğü için yanıt hiçbir şey kanıtlamaz.
- **Ders 45 — bir değerin yokluğunu, o değerin uzayındaki bir sayıyla temsil etme.** Kota
  için `0`, `-1` ve `PHP_INT_MAX` reddedildi, `null` seçildi.
- **Ders 47 — doğrulanmamış bir faz kapatılamaz; ama bunu bilerek yazmak, bilmeden
  yazmaktan iyidir.** Faz 1, 3 ve 4'te "yeşil" yazıldı ve değildi; Faz 5'te "doğrulanmadı"
  yazıldı ve öyle (**B7**).

**Kurulan kural sayısı:** 10. **Kalite kapısı:** PHPStan 6 → **8**.


---

## FAZ 6 — Media modülü ⚠️ elle doğrulama açık

> **Kayıt:** [`rehber/fazlar/FAZ-6.md`](rehber/fazlar/FAZ-6.md) · Kapanış: 18 adım

**Ana hedef:** Dosya kabul etmenin güvenlik yükü ve 15 saniye kuralı.

**🔴 Plan 8 adımdı, 24 oldu — neden?** Orijinal plan **yalnızca sahibin galerisini** hesaba
katıyordu. İki şey eksikti:

1. **Misafirin LCV foto/videosu.** `MediaKind` üç tür tanımlıyordu ama ikisinin ucu yoktu.
   Yazılmasaydı `StorePublicMediaRequest`, `MediaQuotaExceededException::forGuest()` ve
   `guestUploadableValues()` **ölü kod** olarak kalırdı (ders 26).
2. **LCV'ye bağlama.** `rsvps` medya kolonlarının bir **yazanı** ve bir **okuyanı** olmadan
   açılması, Faz 5'te o kolonları açmama gerekçemizin aynısına düşmek olurdu.

| # | Grup | İçerik |
|---|---|---|
| 6.1–6.8 | Temel | `MediaKind` · `media` tablosu · `Media` · `MediaFactory` · `MEDIA_QUOTA_EXCEEDED` · `MediaRequest` ailesi · `OptimizeUploadedImage` · `StoreUploadedMediaAction` |
| 6.9 | Düzeltme | 🔴 Kalite kapısı: PHPStan 8 ilk kez gerçekten koştu ve **gerçek bir 500 buldu**; flaky test düzeltildi |
| 6.10–6.11 | Sahip ucu | `MediaResource` · `MediaController` |
| 6.12–6.13 | Çıkarma | 🆕 `ResolveOpenRsvpInvitationAction` · `SubmitRsvpAction` refactor (üç kontrol devredildi) |
| 6.14–6.16 | Misafir ucu | 🆕 `StoreGuestMediaAction` · `PublicMediaController` · `throttle:media` |
| 6.17–6.21 | LCV'ye bağlama | `rsvps` medya kolonları + FK · ilişkiler · `StoreRsvpRequest` · 🔴 **sahiplik doğrulaması** · `RsvpResource` |
| 6.22–6.24 | Kanıt | `MediaTest` **28 test** + mutasyon tablosu · faz kayıtları |

**⚠️ Rota planı değişti:** `/api/media/upload` **geçersiz**. Uçlar iç içe kaynak oldu:

```
POST /api/invitations/{id}/media          (sahip)
POST /api/public/invitations/{id}/media   (misafir)
```

> `docs/09` *"frontend kazanır, `POST /media/upload`"* diyordu; o not misafir yüklemesini
> hesaba katmadan yazılmıştı. **N1:** düz bir uçta davetiye kimliği gövdeden gelirdi — yani
> **istemcinin sözüne** kalırdı.

**Kritik kararlar (K54–K63):**

| # | Karar | Gerekçe |
|---|---|---|
| **K54** | `media.disk` **kolonda** saklanır | Config *"şu an nereye yazıyoruz"*, kolon *"o dosya nereye yazılmıştı"*. S3 göçü eski satırları **kırmaz** |
| **K55** | Depolama **yerel `public` diski** kalır; S3 ertelendi | Kapsam kararı. ⚠️ Dosyalar bugün web kökü altında — **Faz 9 borcu** |
| **K56** | `media.id` = ULID | Kimlik hem URL'de hem LCV gövdesinde geçiyor |
| **K57** | Medya **polimorfik değil** | `morphTo` yabancı anahtar kısıtı kurdurmaz (E2'ye aykırı); `kind` zaten türü taşıyor |
| **K58** | LCV medyası **kimlikle** iliştirilir, URL ile değil | **Bir URL doğrulanamaz** |
| **K59** | Geçersiz medya kimliği **sessizce düşürülür** | 403 dönmek kimliğin **gerçek** olduğunu doğrular ve `media` tablosunu taranabilir yapar |
| **K60** | `rsvps` medya FK'leri **`nullOnDelete`** | Misafirin yazdığı metin, eklediği fotoğraftan **bağımsız** bir veridir |
| **K61** | Misafirin medya ucu **ayrı throttle kovası** | Honeypot yok + istek başına maliyet on kat |
| **K62/K63** | `SendRsvpNotification` → Faz 8 · `invitations.timezone` → Faz 7 | ⚠️ Üçüncü erteleme |

**Yeni kural serisi F — dosya kabul etme:** F1 (MIME içerikten) · F2 (adı sunucu üretir) ·
F3 (dosya sistemi transaction'a dâhil değil) · F4 (konum satırda) · F5 (sözleşme URL, şema kimlik).

**Öne çıkan dersler:**

- **Ders 48 — kodda verilen bir gerekçe, kaynakta karşılığı yoksa yalandır; ve yanlış bir
  gerekçe, eksik bir gerekçeden tehlikelidir.** `StoreUploadedMediaAction`'ın yorumu
  *"`store()` geçici dosyayı taşır"* diyordu. `vendor/` okundu:
  `FilesystemAdapter::putFileAs()` dosyayı **taşımıyor**, stream olarak **kopyalıyor**.
  Sıra hâlâ doğruydu ama **sebebi başkaydı.**
- **Ders 49 — örtük bir zaman bağımlılığı, flaky bir testi "geçen test" gibi gösterir.**
  Zincir: `Grammar::getDateFormat()` mikrosaniye taşımıyor → aynı saniyede `touch()`
  `isDirty()` false görüyor → olay **hiç fırlamıyor** → `save()` yine `true` dönüyor.
- **L5 / A8 / T17:** İstemciden gelen bir **kimliğin aidiyeti** doğrulama katmanında değil
  **Action'da** sorulur (FormRequest üst kaynağı henüz çözmemiştir). Bir sınıfın
  **değişmezi** doğrulama katmanına bırakılmaz — doğrulama HTTP'ye aittir ve **atlanabilir**
  (konsol, kuyruk, yeni uç).
- **C3'ün en güzel örneği:** bir kural ikinci bir uç istediğinde **kopyalanmaz, çıkarılır.**

**Kurulan kural sayısı:** 11.

---

## FAZ 7 — Ödeme ve paywall ⚠️ elle doğrulama açık

> **Kayıt:** [`rehber/fazlar/FAZ-7.md`](rehber/fazlar/FAZ-7.md) · Kapanış: 20 adım

**Ana hedef:** Projenin **ticari çekirdeği.** Sunucu tarafı yetki doğrulaması. Faz 0'da
yazılan `SubscriptionTier` enum'u nihayet burada kullanıldı — `covers()`, `rank()`,
`price()` ve `rsvpLimit()` metotlarının **ilk gerçek çağıranları** bu fazda doğdu.

**Plan 12 adımdı, 25 oldu.** Üç sebep: dört ayrı exception (H11 arayüzü sayesinde renderer'a
hiç dokunulmadı), K42'nin arayüzü, ve üç fazdır ertelenen `invitations.timezone` (K63).

| # | Dosya | Not |
|---|---|---|
| 7.1–7.4 | `OrderStatus` (**durum makinesi**) · `orders` tablosu · `Order` · `OrderFactory` | 🔴 `provider_ref` UNIQUE + 4 CHECK |
| 7.5 | 4 exception + `ErrorCode` beyaz listesi | Hepsi `HasErrorCode` — renderer'a **hiç dokunulmadı** |
| 7.6–7.7 | `PaymentGateway` + 2 DTO · `FakeGateway` + sürücü bağlama | Strategy Pattern (K8) · 🔴 **gerçek HMAC, sahte para** |
| 7.8 | `TierResolver` | 🔴 `getRequiredTier()`'ın **sunucu ikizi** |
| 7.9 | `PublishEntitlementResolver` + `OrderEntitlementResolver` | 🔴 **K42** — iki kaynak, tek arayüz |
| 7.10–7.11 | `StartCheckoutAction` + `CheckoutResult` · `HandlePaymentCallbackAction` | Sunucu fiyatı + L7 telafisi · **idempotans** |
| 7.12 | 🔴 `PublishInvitationAction` | **Faz 3'ten beri boş iskeletti** |
| 7.13–7.15 | `StoreCheckoutRequest` · `OrderResource` · 2 controller · rotalar | 4 yeni uç |
| 7.16 | `SubscriptionRsvpQuotaResolver` | 🔴 K51 dikiş yeri kapandı; geçici uygulama **silindi** |
| 7.17 | `invitations.timezone` (**K63/K71**) + 6 dosya | Son tarih artık davetiyenin diliminde |
| 7.18 | (düzeltme) `Rule::enum` → `'in:'` | **D6** ihlali önlendi |
| 7.19 | `PaywallTest` | **51 test** + 33 satırlık mutasyon tablosu |

**⚠️ Üç rota sapması — üçü de daha eski ve daha güçlü bir kararın uygulaması:**

| `docs/09` ne diyordu | Ne yapıldı | Neden |
|---|---|---|
| Tek `POST /api/payments/checkout` | **İki uç**, kimlik **URL'de** | **N1/K64** — aidiyet gövdeden gelseydi istemcinin sözüne kalırdı |
| `POST /api/payments/webhook` | `/api/public/payments/webhook` | **K12/K65** fail-safe grubu |
| Akışta *"`public_slug` üret"* | Üretilmiyor | **K40/K66** — `invitations.id` zaten ULID ve paylaşılan linkin kendisi |

**Kritik kararlar (K64–K71):**

| # | Karar |
|---|---|
| **K67** | **Ödeme yayınlamaz**; yayın ayrı bir kullanıcı eylemidir. Webhook bir **makine** bildirimi, yayın bir **insan** kararıdır |
| **K68** | "Zaten yayında" → **409**, sessiz başarı değil. Yayın ücretli ve yan etkili |
| **K69** | İmza hatası → **404** (401/403/400 değil). 401 frontend interceptor'ını tetikler; 403 ucun **varlığını** doğrular; 400 bozuk gövde ile sahte imzayı **ayırt ettirir** |
| **K70** | Sürücü seçimi **çözüm anında**, sessiz varsayılan **yok**. `default => fake` olsaydı eksik `IYZICO_API_KEY` her ödemeyi **bedava** yapardı |
| **K71** | `invitations.timezone` = **duvar saati + IANA kimliği**, `timestamptz` değil. Sorun depolama değil **niyet**: "19:00" düğünün olduğu yerin saatidir. Yaz saati kuralı değişirse `timestamptz` saati **kaydırır** (iCal `TZID` modeli) |

**Yeni kural serileri:**

- **M5–M8 (para):** para kuruşta tam sayı · fiyat asla gövdeden okunmaz · para birimi ve
  sağlayıcı satırda · idempotans **iki katmandır**.
- **W1–W3 (webhook):** imza **ham gövde** üzerinden · `hash_equals()` ile · uç **her zaman 2xx**.
- **L7 · P6 · E11:** dış servis transaction'a dâhil değildir · Policy'nin cevabı `bool`dur,
  bilgi taşıması gereken red Policy'ye konmaz · çok kolonlu bir değişmez CHECK'e yazılır.

**Öne çıkan dersler:**

- **Ders 50 — bir alanın "doğrulanabilir" olması, kabul edilebilir olduğu anlamına gelmez.**
  `{"price": 1}` `integer|min:1`'i geçer. Fiyat için doğru savunma bir **kural** değil, alanı
  **hiç kabul etmemektir.** Doğrulama katmanı **biçimi** doğrular; değeri kimin ürettiği bir
  **mimari karardır.**
- **Ders 51 — aynı teknik soru, farklı çağıranda farklı cevap ister.** İdempotans webhook'ta
  **isteniyor**, yayında **istenmiyor**.
- **Ders 52 — bir arayüzün değerini, yazdığın gün değil kaldırdığın uygulamanın maliyetiyle
  ölçersin.** `RsvpQuotaResolver` Faz 5'te "gereksiz dolaylılık" gibi görünüyordu; Faz 7'de
  geçiş **tek satır** oldu.
- **Ders 54 — framework'ün "modern" yolu, projenin sözleşmesini bozabilir.**
  `Rule::enum()` daha temiz görünüyordu ama sınıf adını hata zarfına sızdırırdı.

**🔴 Açık ticari karar:** paket alımın **kaç yayın** açtığı sınırlanmadı. Bugünkü hâliyle
tek bir 399 ₺'lik paket **sınırsız** davetiye yayınlatır.

**Kurulan kural sayısı:** 10.

---

## FAZ 8 — AI asistan ve iletişim ⚠️ elle doğrulama açık

> **Kayıt:** [`rehber/fazlar/FAZ-8.md`](rehber/fazlar/FAZ-8.md) · 21 adım

**Ana hedef:** Ports & Adapters ile ücretli bir dış servisi vekâleten çağırmak ve
**maliyet kontrolü** kurmak.

| # | Dosya |
|---|---|
| 8.1–8.2 | `ASSISTANT_QUOTA_EXCEEDED` (429) · `AiProviderException` (503) · `AssistantQuotaExceededException` |
| 8.3–8.5 | `AiProvider` arayüzü · `NullProvider` · `GeminiProvider` — **K8'in üçüncü uygulaması** |
| 8.6 | `AppServiceProvider` — sürücü seçimi (K70) + `throttle:assistant` · `throttle:contact` |
| 8.7 | `app/Support/IpHasher` · `HasHoneypot` trait | **C3** — iki refleks birleşti (K77) |
| 8.8 | `assistant_usages` tablosu + model + factory | 🔴 Kota **veritabanında** (K73) |
| 8.9–8.11 | `AskAssistantRequest` · `AskAssistantAction` · `AssistantController` |
| 8.12–8.15 | `ContactSubject` · `contact_messages` · `ContactRequest` · `SubmitContactAction` · `PublicContactController` |
| 8.16–8.17 | `AssistantTest` (21) · `ContactTest` (14) — mutasyon tabloları |
| ~~8.6~~ | ~~`SetLocaleFromHeader` — 10 dil~~ | 🔴 **İPTAL (K21)** |

**Kritik kararlar (K72–K79):**

| # | Karar | Gerekçe |
|---|---|---|
| **K72** | Asistan ucu **auth'lu** | Her çağrı paradır; maliyet kontrolü harcamanın bir **kimliğe** yazılabilmesini gerektirir. IP iki yönde birden başarısız: CGNAT'te çok geniş, saldırgan için çok dar |
| **K73** | Kota **veritabanında** (`assistant_usages`); `throttle:assistant` **ayrıca** durur | Cache kovası `cache:clear`/deploy/Redis restart ile sıfırlanır. Hız sınırı için kabul edilebilir, **fatura** için değil |
| **K74** | Kota aşımı **429** + yeni kod | Ayırt edici soru: *bekleyerek aşılabilir mi?* LCV'de hayır (403), günlük bütçede **evet** |
| **K75** | AI sağlayıcı hatalarının tamamı → **503** | K27'nin 502/503 ayrımı **tekrarlanmadı**: ayrım izleme alarmı içindi; burada kullanıcının önündeki eylem her iki hâlde aynı |
| **K76** | İletişim ucu **`/api/public/contact`** | **K12** fail-safe: ilk istisna ikincisinin gerekçesi olur |
| **K77** | `ip_hash` → **`hash_hmac`**; ortak yer `app/Support/IpHasher` | Düz hash uzunluk-uzatma saldırısına açık. `app/Support/` yeni bir katman değil, **katmansızlık işareti** |
| **K78** | `ai.request.timeout_seconds` **10 → 6**; retry **yalnızca bağlantı hatasında** | 🔴 Eski değerler 15 sn kuralını çiğniyordu: 10 + 0.2 + 10 = **20.2 sn**. Cevap veren bir sağlayıcıyı tekrar çağırmak **parayı ikiye katlar** |
| **K79** | `Jobs/SendRsvpNotification` **yazılmadı** | Bir bildirim e-postası, backend'in ilk kez **insan tarafından okunacak metin** üretmesidir — K20/K21'in dışında kalan ilk şey. Kanal + dil + politika + şablon **dört ayrı karar** |

**Yeni kural serileri:**

- **Q (kota ve maliyet):** Q1 maliyet kontrolü kimliğe yazılabilmeli · Q2 **bir para
  kontrolü cache'te durmaz** · Q3 bedel çağrıdan **önce** yazılır (fail-closed) ·
  Q4 **yenilenen** sınır 429, **kapasite** sınırı 403.
- **X (dış model çağrısı):** X1 sistem talimatı çağıranın parametresi olamaz · X2 **200
  yanıt, kullanılabilir yanıt demek değildir** · X3 bir sır URL'e konmaz.
- **L8 · C8 · B9:** Bir savunma katmanı, cevapladığı bir soru yoksa **kopyalanmaz,
  çıkarılır** · zarf istisnası büyütülmez · bir savunmanın **karşı tarafta karşılığı yoksa
  savunma kurulmamıştır**.

**Öne çıkan dersler:**

- **Ders 57 — "cevap alamadım" ile "para harcanmadı" aynı şey değildir.**
- **Ders 58 — bir test saatin kaçında koştuğuna göre yeşil ya da kırmızı yanıyorsa, yanlış
  olan testtir.** K71 son tarihi davetiyenin saat dilimine taşımıştı; testler hâlâ UTC ile
  kuruyordu. Günün 21 saati yeşil, 3 saati kırmızı — ve ilk koşu tam o pencereye denk geldi.
- **Ders 59 — bir katmanı kopyalamak savunmayı güçlendirmez.** Anlamsız bir katman bakımda
  *"bu neden burada?"* diye silinir ve **gerekli olanı da beraberinde götürür.**

**Kurulan kural sayısı:** 10.


---

## FAZ 9 — Üretim hazırlığı ✅ kod · ⬜ elle doğrulama

> **Tarih:** 11 Eylül 2026 · Kayıt: [`rehber/fazlar/FAZ-9.md`](rehber/fazlar/FAZ-9.md)
> Kapanış: [`FAZ-9-ELLE-DOGRULAMA.md`](rehber/fazlar/FAZ-9-ELLE-DOGRULAMA.md) (22 adım)

**Ana hedef:** Faz 9 bir **özellik** fazı değil, bir **ortam** fazıdır: kodun kendisini
değil, kodun **çalıştığı yeri** değiştirir. Bunun iki sonucu var:

**Birincisi:** bu fazda kırılacak şeylerin çoğu `composer check`'in görüş alanının dışında.
Testler `array` cache, `sync` kuyruk ve `local` disk ile koşar; Redis, S3, HTTPS,
`config:cache` ve cron oralarda yoktur. Bu yüzden her altyapı adımı elle doğrulama
betiğine somut bir adım üretti.

**İkincisi ve daha önemlisi:** bu faz, önceki sekiz fazın **bilerek ödediği bedellerin
gerçekten işe yarayıp yaramadığını** gördü:

| Faz | Ödenen bedel | Faz 9'da olan |
|---|---|---|
| 6 · **F4** | `media.disk` kolonda saklanıyor | ✅ S3 göçü eski satırları kırmıyor; `PruneOrphanMedia` her satırı **kendi diskinden** siliyor |
| 8 · **Q2** | Kota veritabanında, cache'te değil | ✅ Redis'e geçiş kotayı sıfırlamayacak |
| 0 · **Y1** | Kodda `env()` yok | ✅ `config:cache` güvenli — `app/` ve `routes/` içinde **sıfır** çağrı |
| 7 · **K8** | `PaymentGateway` arayüzü | ⬜ Henüz tahsil edilmedi — `IyzicoGateway` anahtarlar gelince |
| 1 · **K30** | Rota dosyasında closure yok | ⚠️ **Gerekçesi geçersizleşti** — Laravel 13 closure'ları serileştiriyor. Karar hâlâ doğru, gerekçesi artık "ölü kod" |

### 🔴 Fazın en büyük bulgusu

Faz 9'un asıl işi planlanan liste değildi. 9.7'ye hazırlanırken `orders.invitation_id`'nin
**iki gerçeği birden sakladığı** ortaya çıktı — ayrıntı
[§2.6](#26-veritabanı-şeması-ve-temel-ilişkiler) "4. Neden `orders.scope` var?" bölümünde.

> 🔴 Bugün sömürülebilir **değildi**, çünkü kod tabanında `forceDelete()` çağıran tek satır
> yok. Ama 9.7 tam olarak kalıcı silmenin yazılacağı adımdı: **plan düz uygulansaydı delik
> bu fazda açılacaktı.**

### Yazılan 14 adım

| # | İş | Ne getirdi |
|---|---|---|
| 9.1 | `routes/web.php` **silindi** + `bootstrap/app.php` | Ölü `welcome` rotası; `web` middleware grubu (session, CSRF) artık **hiç koşmuyor** |
| 9.2 | `OrderScope` enum'u + `tests/Unit` düzeltildi | **B10** — sürümlenmemiş test süiti |
| 9.3 | `orders.scope` migration — nullable + 2 CHECK + geri doldurma | **genişlet** |
| 9.4 | `Order` · `OrderFactory` · `StartCheckoutAction` | **taşı** — yazıcılar |
| 9.5 | `SET NOT NULL` migration | **daralt** |
| 9.6 | `OrderEntitlementResolver` | 🔴 Deliği kapatan sorgu |
| 9.7 | `DeleteInvitationAction` + 3 günlük serbest bırakma penceresi | **K82** |
| 9.8 | `ClaimReleasedOrderAction` + yayında yeniden bağlama | **K83** |
| 9.9 | `orders:expire` | `expires_at` üç fazdır yazılıyordu, **ilk kez okunuyor** |
| 9.10 | `media:prune-orphans` | LCV'ye bağlanmamış misafir yüklemeleri |
| 9.11 | `routes/console.php` zamanlayıcı + `sanctum:prune-expired` | Projenin ilk **kendiliğinden çalışan** kodu |
| 9.12 | `config/cors.php` (🔴 `exposed_headers: ETag`) + `SecurityHeaders` | **K86 · K85** |
| 9.13 | `.env.example` + `docs/10-URETIM-ENV-SABLONU.md` | **B10**'un ikinci uygulaması |
| 9.14 | `FAZ-9.md` + elle doğrulama + `docs/07`/`docs/09` | — |

### Kritik kararlar (K80–K86)

| # | Karar | Gerekçe |
|---|---|---|
| **K80** | Altyapı **en düşük ortak paydaya** yazılır; Redis, S3 ve süpervizör birer **yükseltmedir** | Barındırma kararı *"VPS ama paylaşımlı hostinge göre"*. Kuyruk cron'dan `--stop-when-empty` ile koşabilmeli, cache `file` ile çalışabilmeli. Q2 sayesinde bunun bir para kontrolüne maliyeti **yok** |
| **K81** | `orders.scope` — kapsam **satırda** saklanır, okumada türetilmez | `invitation_id IS NULL` iki gerçeği birden anlatıyordu (**N4/E12**) |
| **K82** | Yayınlanmış davetiye **silinebilir**; ödenen hak **3 gün** içinde serbest kalır | Ticari karar. 🔴 Sipariş satırı **hiç silinmez** — muhasebe kaydı bir tıkla yok olamaz |
| **K83** | Serbest hak, yayın anında **yeten en düşük** siparişten harcanır | Resolver "en yüksek"i döndürür (**okuma**); tüketim tersini ister |
| **K84** | Bakım komutları `--dry-run` taşır ve zamanlayıcıdan **önce elle** koşulur | Bir bakım komutunun ilk gerçek koşusu üretimde ve gözlemsiz olmamalı |
| **K85** | Güvenlik başlıkları **middleware'de**, nginx'te değil | **B10:** `nginx.conf` sunucuda yaşar ve taşımada sessizce geride kalır. Middleware sürümlenir, test edilir ve paylaşımlı hostingde de çalışır |
| **K86** | `config/cors.php` **yayınlandı ve daraltıldı**; `exposed_headers: ['ETag']` | Laravel varsayılanı `allowed_origins => ['*']` **ve** boş `exposed_headers`. İkincisi K7/K46'nın polling optimizasyonunu **sessizce** öldürüyordu |

### Bilinmesi gereken beş ince nokta

1. **Genişlet/daralt deseni.** Üçü aynı deploy'a sıkıştırılırsa desen bir **tören** olur;
   koruduğu pencere hiç açılmaz.
2. **CHECK, `NULL`'u reddetmez.** `NULL IN (...)` → `NULL`; CHECK yalnızca `FALSE` olduğunda
   reddeder. Zorunluluğu kuran tek şey **`NOT NULL`**'dır.
3. **Tüketimde refleks terstir.** Resolver "en yüksek"i döndürür; `ClaimReleasedOrderAction`
   "yeten en düşük"ü harcar.
4. **Temizlikte fail-safe yön silmemektir.** `PruneOrphanMedia` ham tablo sorgusu kullanır
   ve **önce dosyayı, sonra satırı** siler — ters sıra kalıcı disk sızıntısı üretir.
5. **`exposed_headers: ['ETag']`.** Bu satır olmadan çapraz kaynakta JS ETag'i okuyamaz ve
   polling optimizasyonu **sessizce** ölür.

### Kurulan kurallar ve dersler

**B10 · K80 · E12** (3 kural, toplam **140**).

- **Ders 60 — bir kolonun anlamı, ona yazan TÜM yolların toplamıdır.** `invitation_id IS
  NULL` yazıldığı gün tek bir şey demekti ve o gün doğruydu. `nullOnDelete` ikinci bir yol
  açtığında anlam, **kimse dosyaya dokunmadan** değişti. *Bir sorgunun doğruluğu, yazıldığı
  andaki dünyanın doğruluğudur; o dünyayı değiştiren şey başka bir dosyada durabilir.*
- **Ders 61 — sekiz fazdır yeşil yanan bir kapı, doğru sebeple yanıyor olmayabilir.**
  `composer check`, `phpunit.xml`'in istediği `tests/Unit` dizinine bağımlıydı ve o dizin
  **hiçbir commit'te yoktu** — git boş dizin saklamaz. Temiz bir klonda, CI'da ya da üretim
  sunucusunda kapı sekiz faz boyunca **kırmızı** olurdu. Aynı hata `.env.example`'da ikinci
  kez tekrarladı.
- **Ders 62 — bir tüketim adımı, tüketmeden önce "gerekli mi" diye sorar.**
- **Ders 63 — bir savunmayı "zaten başka bir savunma tutuyor" diye açmak, katmanlı
  savunmanın tersidir.** CORS'ta `['*']` bırakmanın gerekçesi hazırdı: *"token Authorization
  başlığında gidiyor, CSRF geçerli değil."* Doğru ama yetersiz — `*`, herhangi bir sitedeki
  JavaScript'in `/api/public/` uçlarını sürmesine izin verir.
- **Ders 64 — tek origin varsa CORS başlığı koşulsuz gider** (`CorsService.php:209`);
  test varsayımı yanlıştı.

### Faz 9'un eklediği kalıcı yapılar

| Yapı | Ne işe yarar |
|---|---|
| `orders.scope` (`OrderScope`) | Siparişin **ne satın aldığı** — türetilmiyor |
| `DeleteInvitationAction` | Silme + 3 günlük hak serbest bırakma penceresi |
| `ClaimReleasedOrderAction` | Serbest hakkı yeni davetiyeye bağlama (yeten en düşük) |
| `routes/console.php` zamanlayıcı | Projenin ilk **kendiliğinden çalışan** kodu |
| `config/cors.php` | 🔴 `exposed_headers: ['ETag']` — Faz 4/5 buna bağlı |
| `SecurityHeaders` middleware | 6 tarayıcı sertleştirme başlığı, **global** yığında |
| `docs/10-URETIM-ENV-SABLONU.md` | Üretim `.env` şablonu, her satırın gerekçesiyle |

---

## 4.10 Bugünkü teknik durum

| | |
|---|---|
| **Dal** | `faz-9` |
| **Uç nokta** | **21** (+ `GET /api/ping` sağlık sondası, `/up`) |
| **Test** | **238** — 232 Feature + 6 Unit (`tests/Unit/OrderScopeTest`) |
| **PHPStan** | level **8** · 164 dosya · **0 hata** |
| **Tablo** | 8 iş tablosu + Sanctum/Laravel tabloları |
| **Kural** | **140** · **Karar** 86 · **Ders** 64 |
| **Hata kodu** | **21** (`contracts/error-codes.json` ile senkron) |
| **Kalite kapısı** | `pint` · `phpstan` · `errors:export --check` · `phpunit` → `composer check` ✅ **YEŞİL** |
| **Artisan komutu** | `errors:export` · `orders:expire` · `media:prune-orphans` |

### Doğrulama durumu

| Faz | Kod | `composer check` | Elle doğrulama |
|:---:|:---:|:---:|:---:|
| 0–4 | ✅ | ✅ | ✅ |
| 5 | ✅ (17 adım) | ✅ | ⬜ 16 adım |
| 6 | ✅ (24 adım) | ✅ | ⬜ 18 adım |
| 7 | ✅ (25 adım) | ✅ | ⬜ 20 adım |
| 8 | ✅ (21 adım) | ✅ | ⬜ |
| 9 | ✅ (14 adım) | ✅ (238 test) | ⬜ 22 adım |


---

# 5. Gelecek Vizyonu ve Sonraki Adımlar

## 5.1 Öncelik sırası — özet

| Sıra | İş | Neden bu sırada |
|:---:|---|---|
| **1** | 🔴 **Frontend yakalama fazı** | Backend altı faz önde; çalışan ama **kimsenin konuşamadığı** bir üretim backend'i var |
| **2** | Faz 5–9 elle doğrulama betiklerini koştur | `composer check` yeşil ama **hiçbir faz kapanış ölçütü işaretlenmedi** |
| **3** | Paket alımın yayın kotası (K43) | Bugün tek bir 399 ₺'lik paket **sınırsız** yayın açıyor |
| **4** | `IyzicoGateway` | Sandbox anahtarları gelince — arayüz (K8) hazır bekliyor |
| **5** | Dört EK dosyasını master'a işle | K49–K86 yalnızca yama dosyalarında |
| **6** | Redis · S3 · süpervizör | Barındırma netleşince (K80: **yükseltme**, varsayım değil) |

---

## 5.2 🔴 En büyük risk: frontend yakalama fazı

Backend **altı faz** (4/5/6/7/8/9) öndedir ve bu, Faz 9'un ortaya çıkardığı en büyük tek
risktir. Bugün elimizde çalışan ama **kimsenin konuşamadığı** bir üretim backend'i var.

| Frontend'de | Gerçek durum |
|---|---|
| `services/payments.ts` | **Tamamen mock** — 1.8 sn bekleyip `status: 'paid'` döner |
| `useSubscriptionStore.activeTier` | **Oturum içi mock** — 🔴 en kritik madde: gerçek ödeme açıldığı gün kullanıcı ödediğini sanıp **402** alır |
| Yayınlama ucu | **YOK** — `POST /invitations/{id}/publish` hiç çağrılmıyor |
| `services/rsvps.ts` | **Yanlış uçlar** — `/rsvps` yazıyor, backend `/invitations/{id}/rsvps` |
| `services/media.ts` | **Yanlış uç ve yanlış yanıt** — `/media/upload` + `{url}` bekliyor |
| `services/contact.ts` | **Yanlış yol** — `/contact`, backend `/public/contact` (K76) |
| `ContactPage` honeypot | **YOK** — tuzak hiç kurulmamış (**B9**) |
| `AssistantWidget` | **Giriş duvarı yok** — uç auth'lu (K72) |
| `useAssistantChat` | **Mock** |
| ETag / polling | **YOK** — Faz 4 ve 5'in tüm optimizasyonu kullanılmıyor |
| `errors.json` | **YOK** — 🔴 K20'nin frontend yarısı hiç yazılmadı |
| `invitation.timezone` | `types.ts`'te **alan yok** — geri sayım bu dilimde hesaplanmalı (K63/K71) |

> **B9:** *Bir savunmanın karşı tarafta karşılığı yoksa savunma kurulmamıştır.* Honeypot
> alanı backend'de okunuyor ama `ContactPage` onu render etmiyor — tuzak kurulmamış demektir.

Faz 9'un iki maddesi de doğrudan frontend'e dokunuyor:

1. `services/api.ts` → üretimde Vite proxy yok; gerçek origin `CORS_ALLOWED_ORIGINS`'e
   yazılmalı.
2. 🔴 ETag artık açığa çıkıyor (`exposed_headers`); polling'in `If-None-Match` gönderdiği
   **frontend tarafında doğrulanmalı**.

**Tam plan:** `davetkart-frontent/docs/FRONTEND-YAKALAMA-PLANI.md`

---

## 5.3 Doğrulama borcu

`composer check` **yeşil** (238 test) ama **hiçbir fazın kapanış ölçütü işaretlenmedi.**

| Betik | Adım | Neden hâlâ gerekli |
|---|:---:|---|
| `FAZ-5-ELLE-DOGRULAMA.md` | 16 | LCV akışı tarayıcıda hiç denenmedi |
| `FAZ-6-ELLE-DOGRULAMA.md` | 18 | 🔴 **`php artisan storage:link` HİÇ ÇALIŞTIRILMADI** — `Storage::fake()` gerçek diski hiç görmez |
| `FAZ-7-ELLE-DOGRULAMA.md` | 20 | Ödeme akışı uçtan uca denenmedi |
| `FAZ-8-ELLE-DOGRULAMA.md` | — | Asistan ve iletişim |
| `FAZ-9-ELLE-DOGRULAMA.md` | 22 | 🔴 Faz 9'un kırılacak şeylerinin çoğu `composer check`'in **görüş alanı dışında**: Redis, S3, HTTPS, `config:cache`, cron |

> **B7:** *Faz özetindeki durum alanı, gerçekten koşan bir komuta dayanır.* Faz 1, 3 ve 4'te
> "yeşil" yazıldı ve değildi. Bu betikler işaretlenene kadar Faz 5–9 **kapanmadı** sayılır.

---

## 5.4 🔴 Cevap bekleyen açık ticari kararlar

| # | Konu | Bugünkü durum | Önerilen çözüm |
|:---:|---|---|---|
| **1** | **Paket alım kaç yayın açar?** | 🔴 **Sınırsız** — tek bir 399 ₺'lik paket hesabın tüm davetiyelerini yayınlatır | `orders.publish_quota` (int) + `PublishInvitationAction`'da sayaç. **K43 ancak o zaman tam uygulanmış olur.** `scope` kolonu artık yerini hazırlıyor |
| **2** | **Bildirim kanalı** (K79) | Yazılmadı | Kanal + dil (`users`'ta dil kolonu yok) + politika + şablon — **dört ayrı karar**. Backend'in ilk kez insan tarafından okunacak metin üretmesi olacak |
| **3** | İade akışı var olan yayını geri çekmiyor | `refunded` durumu var, etkisi yok | İade akışı doğduğunda |
| **4** | Silme sonucu kullanıcıya söylenmiyor | `DELETE` → 204; hak serbest mi yandı mı bilinmiyor | Bir *"siparişlerim"* ucu |
| **5** | `contact_messages` okuma ucu yok | Yazan var, **okuyan yok** | Admin paneli |

---

## 5.5 Teknik borçlar

### Ertelenen altyapı (K80: birer **yükseltme**, varsayım değil)

| Konu | Ne zaman | Hazırlık durumu |
|---|---|---|
| 🔴 `IyzicoGateway` + imza + replay penceresi | Sandbox anahtarı gelince | ✅ `PaymentGateway` arayüzü (K8) hazır: **tek `match` kolu + tek bağlama satırı** |
| Redis (cache + queue) + `queue:work` süpervizörü | Barındırma netleşince | ⚠️ Redis'e geçince `throttle` sınıfı `ThrottleRequestsWithRedis`'e döner — kovalar aynı, davranış **birebir aynı değil**; `throttle:assistant` ve `throttle:contact` yeniden sınanmalı |
| S3 uyumlu disk (**K55**) | Aynı | ✅ `media.disk` kolonda (F4) — göç eski satırları **kırmaz**. Ayrıca *"yüklenenler çalıştırılabilir dizinde durmaz"* kuralını **yapısal** hâle getirir |
| Argon2id ölçümü | Üretim donanımı bilinince | Hedef **~250 ms/hash**; paylaşımlı hostingde `memory_limit` zorlayabilir |
| Log rotasyonu · yedekleme | Sunucu kurulumunda | `docs/10` `LOG_STACK=daily` + `LOG_LEVEL=warning` diyor |

### Küçük borçlar

| Konu | Not |
|---|---|
| 🆕 **Zamanlanmış iş koşmazsa kimse bilmez** | İzleme kararı gerekiyor: e-posta (K79 açık) / ping servisi / kendi kendini izleyen tablo |
| `SubscriptionTier::label()` dokuz fazdır çağrılmıyor | Ders 26 gereği **silinmeli** |
| Asistan kotasının gün sınırı **UTC** | İstanbul'da 03:00'te yenileniyor. Doğru çözüm `users.timezone`; bugün eklemek **okunmayan bir alan** üretir |
| `rsvps.id` ULID (K52) | Faz 5'ten beri bekliyor — frontend uyarlaması |
| Ters yön yetim medya (diskte var, satırı yok) | S3 göçünden sonra bir kez elle sayım |
| 🔴 Dört EK dosyası master'a işlenmedi | `-EK-FAZ-5/6/8/9` → `claude/PHP-LARAVEL-SETUP.md`. Bir çelişkide **EK dosyaları kazanır** |
| `docs/04` §1 ve §4 geçersiz | MySQL diyor; K9'/K19 ile PostgreSQL 18. `docs/03` §8 de geçersiz (12 adım → 9 faz) |

---

## 5.6 Bir sonraki geliştirici için üç uyarı

**1. `composer check` yeşil, ama proje "bitmiş" değil.** Kalite kapısı testlerin geçtiğini
söyler; **kullanıcının bunu kullanabildiğini** söylemez. Bugün frontend altı faz geride ve
elle doğrulama betikleri hiç koşulmadı.

**2. Kurallar birikimlidir ve gerekçeleriyle birlikte taşınır.** 140 kural, 86 karar ve 64
ders `docs/rehber/fazlar/` ile `claude/PHP-LARAVEL-SETUP*.md` altında duruyor. Bir kuralı
uygulamadan önce **gerekçesini kontrol et** — ders 42: *kural değişmez, girdi değişir.*

**3. Bu projede en sık düşülen tuzaklar (hepsi burada yaşandı):**

| Tuzak | Nerede yaşandı |
|---|---|
| Elle yazılan rota kısıtı sessizce yanlış olabilir | Faz 3 ULID regex'i → 3 IDOR testi boş yeşil |
| `create()` sonrası DB varsayılanı bellekte yok | `CreateInvitationAction` → 500 |
| Bir aracın kurulu olması, işini yaptığı anlamına gelmez | Larastan `casts()`'i hiç okumuyordu |
| `actingAs()` guard'ı atlar | `withToken()` + `forgetAuthState()` (T13) |
| Doğrulama kuralı **nesnesi** sınıf adı sızdırır | `Password::min(8)` (D6) · `Rule::enum()` (Faz 7) |
| `composer check` fail-fast | PHPStan kırılırsa testler **hiç koşmaz** |
| Soft delete ilişkiyi `null` yapar | `RsvpPolicy` → `TypeError` → 500 (Faz 6) |
| `Storage::fake()` gerçek diski hiç görmez | `storage:link` testlerde görünmez |
| Bir fiyat alanı "doğrulanabilir"dir ama kabul edilemez | `{"price":1}` (M6) |
| UNIQUE kısıt `UPDATE`'i engellemez | Webhook idempotansı (M8) |
| SQL'de `AND`, `OR`'dan önce bağlar | Yetki sorgusunda parantezsiz `OR` (Faz 7) |
| Bir tarihin saat dilimi yoktur | `setTimezone()` tarihi bir gün kaydırır (K71) |
| **Git boş dizin saklamaz** | `tests/Unit` yoktu; kapı sekiz fazdır yalnızca bir makinede yeşildi (B10) |
| **CHECK kısıtı `NULL`'u geçirir** | Üç değerli mantık; zorunluluk `NOT NULL`'dan gelir |

---

# Ek A — Tam uç nokta haritası (21 uç)

| # | Method | Path | Auth | Throttle | Yanıt | Faz |
|:---:|---|---|:---:|---|---|:---:|
| — | GET | `/api/ping` | — | `api` | `{status:"ok"}` | 1 |
| 1 | POST | `/api/auth/register` | — | `auth` | `201` · **zarfsız** `{user, token}` | 2 |
| 2 | POST | `/api/auth/login` | — | `auth` | `200` · **zarfsız** `{user, token}` | 2 |
| 3 | POST | `/api/auth/logout` | ✅ | `api` | `204` | 2 |
| 4 | GET | `/api/auth/me` | ✅ | `api` | `200` · **zarflı** | 2 |
| 5 | GET | `/api/invitations` | ✅ | `api` | `200` · yalnızca kendi kayıtları | 3 |
| 6 | POST | `/api/invitations` | ✅ | `api` | `201` | 3 |
| 7 | GET | `/api/invitations/{id}` | ✅ | `api` | `200` · başkasınınkinde **404** | 3 |
| 8 | PUT/PATCH | `/api/invitations/{id}` | ✅ | `api` | `200` · program senkronize edilir | 3 |
| 9 | DELETE | `/api/invitations/{id}` | ✅ | `api` | `204` · soft delete + hak serbest bırakma | 3 · 9 |
| 10 | POST | `/api/invitations/{id}/publish` | ✅ | `api` | `200` / **402** / **409** — 🔴 paywall kapısı | 7 |
| 11 | POST | `/api/invitations/{id}/checkout` | ✅ | `api` | `201` — **tekil** alım | 7 |
| 12 | POST | `/api/invitations/{id}/media` | ✅ | `api` | `201` — sahibin galerisi | 6 |
| 13 | GET | `/api/invitations/{id}/rsvps` | ✅ | `api` + **ETag** | `200` / **304** — polling | 5 |
| 14 | DELETE | `/api/rsvps/{id}` | ✅ | `api` | `204` — sahip moderasyonu | 5 |
| 15 | POST | `/api/payments/checkout` | ✅ | `api` | `201` — **paket** alım | 7 |
| 16 | POST | `/api/assistant/chat` | ✅ | **`assistant`** | `200` / **429** / **503** | 8 |
| 17 | GET | `/api/public/invitations/{id}` | — | `api` + **ETag** | `200` / **304** — 🔥 cache'li | 4 |
| 18 | POST | `/api/public/invitations/{id}/rsvps` | — | **`rsvp`** | `201` — 1. auth'suz yazma yolu | 5 |
| 19 | POST | `/api/public/invitations/{id}/media` | — | **`media`** | `201` — 2. auth'suz yazma yolu | 6 |
| 20 | POST | `/api/public/payments/webhook` | — | `api` | `204` **her zaman** — 3. yol, 🔒 imza | 7 |
| 21 | POST | `/api/public/contact` | — | **`contact`** | `204` — 4. yol, honeypot'lu | 8 |

---

# Ek B — Kaynak doküman haritası

## Okuma sırası (yeni gelen için)

```
1. docs/11-PROJE-TARIHCESI-VE-IS-AKISLARI.md   ← bu dosya
2. CLAUDE.md                                    ← bağlayıcı kod standartları
3. docs/08-HATA-SOZLESMESI.md                   ← API hata sözleşmesi (K20)
4. claude/FAZ-9-DEVIR.md                        ← en güncel durum
5. claude/PHP-LARAVEL-SETUP.md                  ← ana karar/ders kaydı
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-5/6/8/9.md ← 🔴 master'a işlenmeyi bekliyor
6. docs/rehber/fazlar/FAZ-0.md … FAZ-9.md       ← faz kayıtları
7. docs/10-URETIM-ENV-SABLONU.md                ← üretim .env
```

## Hangi soru hangi dosyada?

| Soru | Dosya |
|---|---|
| Bu dosya neden böyle yazıldı? | `docs/rehber/<kod-yolu>.md` — koddaki yolu birebir yansıtır |
| Bu fazda ne hedeflendi, hangi kurallar doğdu? | `docs/rehber/fazlar/FAZ-N.md` |
| Bu faz nasıl kapatılır? | `docs/rehber/fazlar/FAZ-N-ELLE-DOGRULAMA.md` |
| Bağlayıcı kod standartları | `CLAUDE.md` |
| API hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
| Mimari kararların gerekçesi | `docs/03-MIMARI-PLAN.md` (⚠️ §8 geçersiz) |
| Kurulum ve klasör görevleri | `docs/04-KURULUM-VE-KLASOR-YAPISI.md` (⚠️ §1, §4 geçersiz) |
| Dosya dosya referans | `docs/05-KLASOR-VE-DOSYA-REFERANSI.md` |
| PHP/Laravel/Herd nasıl çalışır | `docs/06-PHP-LARAVEL-HERD-NASIL-CALISIR.md` |
| Faz sırası ve tech stack | `docs/07-GELISTIRME-YOL-HARITASI.md` |
| Tüm fazların detay planı | `docs/09-TUM-FAZLAR-PLANI.md` |
| Üretim `.env` | `docs/10-URETIM-ENV-SABLONU.md` |
| Frontend borçları | `davetkart-frontent/docs/FRONTEND-YAKALAMA-PLANI.md` |

## ⚠️ Geçersiz kılınmış satırlar

| Nerede | Eski | Doğrusu |
|---|---|---|
| `docs/03` §0 · `docs/04` §1, §4 | MySQL 8 | **PostgreSQL 18** (K9'/K19) |
| `docs/03` §3.2 | `public_slug` kolonu | **Yok** — `id` zaten ULID (K40) |
| `docs/03` §4.5 | `{message, errors}` hata formatı | **`{error:{code, fields?, params?}}`** (K20) |
| `docs/03` §5.1 | Sahiplik reddi → 403 | **404** (H7) |
| `docs/03` §7 | Testte SQLite in-memory | **PostgreSQL `davetkart_test`** (K19) |
| `docs/03` §8 | 12 adımlık katman-katman inşa | **9 faz, özellik-özellik** (K17) |
| `docs/07` §2.3 | Pest test framework'ü | **PHPUnit** (K24) |
| `docs/07` Faz 8 | `SetLocaleFromHeader` middleware | **İptal** (K21) |
| `docs/09` Faz 6 | `POST /api/media/upload` | **İç içe kaynak** uçları (N1) |
| `docs/09` Faz 7 | Tek `/api/payments/checkout` · `/api/payments/webhook` | **İki uç** (K64) · `/api/public/...` (K65) |

---

# Ek C — Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **LCV** | *Lütfen Cevap Veriniz* — RSVP'nin Türkçesi. Kod ve veritabanında `rsvp`, dokümanlarda LCV |
| **Faz** | 0'dan 9'a numaralı geliştirme dilimleri; her biri **çalışan bir çıktı** ile biter |
| **K** | Karar kaydı (K1–K86) |
| **Ders** | Yaşanmış hatalardan çıkarılan genelleme (1–64) |
| **Kural serileri** | A (auth) · B (belge/süreç) · C (sözleşme) · D (doğrulama) · E (veri bütünlüğü) · F (dosya) · G (genel) · H (hata) · L (auth'suz yol) · M (middleware/para) · N (iç içe kaynak) · O (önbellek) · P (policy) · Q (kota/maliyet) · R (rota) · T (test) · V (ortam) · W (webhook) · X (dış model) · Y (yapılandırma) |
| **Elle doğrulama** | Bir fazın **kapanış ölçütü**: tarayıcı/terminalde adım adım koşulan betik |
| **Boş yeşil** | Yanlış sebeple geçen test — savunma silinse bile yeşil kalır |
| **Mutasyon tablosu** | *"Bu satırı silersem hangi test kırmızı yanar?"* eşlemesi (T16) |
| **Dikiş yeri (seam)** | Gerçek uygulaması henüz olmayan bir soruyu soyutlayan arayüz (K51) |
| **Walking Skeleton** | Uçtan uca çalışan en küçük tam özellik dilimi |
| **Tekil / paket alım** | Tek davetiye için ödeme (`scope=invitation`) / hesap geneli plan (`scope=account`) |
| **Serbest bırakılmış sipariş** | `scope=invitation` + `invitation_id=NULL` — hiçbir davetiyeye hak vermez, bağlanmayı bekler |
| **Fail-safe** | Bir unutmanın sonucunun **kapalı** taraf olduğu tasarım (`/api/public/` öneki) |
| **Blast radius** | Bir yapılandırma hatasının kırdığı yüzeyin genişliği |

---

> **Son söz.** Bu proje bir mimari tercihler koleksiyonu değil, **gerekçeler koleksiyonudur.**
> Bir satırı değiştirmeden önce onu doğuran kararı bul; bulamıyorsan, değiştirmeden önce
> yaz. Bugüne kadar bu projede yakalanan hataların çoğu kodun yanlış olmasından değil,
> **gerekçesinin sessizce eskimesinden** doğdu.
