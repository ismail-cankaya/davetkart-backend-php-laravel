# DavetKart Backend — Geliştirme Yol Haritası

> **Oluşturma:** 30 Temmuz 2026
> **Neyi değiştiriyor:** `03-MIMARI-PLAN.md` §8'deki 12 adımlık **yatay** sırayı
> iptal eder, yerine **dikey dilim** sırasını koyar. Mimari kararlar (katman
> modeli, veri modeli, güvenlik stratejisi) aynen geçerlidir.
> **Ayrıca:** K9 kararı revize edildi — üretim veritabanı **PostgreSQL 16**.

---

## 1. Neden sırayı değiştiriyoruz?

Eski plan **yatay** dilimlenmişti: önce *tüm* enum'lar, sonra *tüm* migration'lar,
sonra *tüm* modeller… Bu sırayla ilk çalışan endpoint 6. adımda ortaya çıkıyor.

Sorun teknik değil, **öğrenme** sorunu:

- Yazdığın kod hiçbir şey yapmıyor; tarayıcıda görülecek sonuç yok.
- Katmanların birbirine nasıl bağlandığı görünmüyor — sadece parçalar var.
- `SubscriptionTier` enum'unu yazdık; onu kullanan ilk kod Adım 12'de gelecekti.

**Yeni yaklaşım: Walking Skeleton (Yürüyen İskelet).**

Önce tek bir isteği **uçtan uca** çalıştırırız. Kısa ama *eksiksiz* bir dikey
dilim — her katmandan birer dosya:

```
POST /api/auth/register
   │
   ├─ routes/api.php ............... rota eşlemesi
   ├─ RegisterRequest .............. doğrulama
   ├─ AuthController ............... yönlendirme (3-8 satır)
   ├─ RegisterUserAction ........... iş kuralı
   ├─ User (Model) ................. veri erişimi
   ├─ UserResource ................. JSON dönüşümü
   └─ AuthTest ..................... kanıt
                    ↓
            gerçek HTTP yanıtı
```

Bu iskelet bir kez yürüdükten sonra, kalan her modül **aynı kalıbın tekrarıdır**.
Öğrenme eğrisi bir kez tırmanılır.

> **Yazdığımız config ve enum boşa gitmedi.** İkisi de yerinde duruyor,
> ilgili fazlarda kullanılacak. Yalnızca sıraları erkendi.

---

## 2. Teknoloji Yığını

### 2.1 Çekirdek

| Katman | Teknoloji | Sürüm | Neden |
|---|---|---|---|
| Dil | **PHP** | 8.3+ | Laravel 13'ün minimumu. Typed properties, enum, readonly, `match` |
| Framework | **Laravel** | 13.x | Hızlı geliştirme; frontend zaten Laravel'e göre yapılandırılmış |
| ORM | **Eloquent** | (Laravel içinde) | Active Record. Repository katmanı **yok** (K4) |
| Kimlik doğrulama | **Laravel Sanctum** | 4.x | İptal edilebilir Bearer token. JWT bunu karşılayamaz (K5) |
| Paket yöneticisi | **Composer** | 2.x | PHP'nin npm'i |
| Yerel ortam | **Laravel Herd** | (ücretsiz) | PHP + nginx, sıfır kurulum. Windows |

### 2.2 Veri

| Konu | Geliştirme | Üretim | Neden |
|---|---|---|---|
| Veritabanı | **SQLite** | **PostgreSQL 16** | Yerelde sıfır kurulum; üretimde düşük RAM tabanı, `jsonb`, güçlü kısıt desteği |
| Cache | **file** | **Redis 7** | SQLite'ta `database` cache dosya kilidi için yarışır |
| Kuyruk | **database** | **Redis 7** | `jobs` tablosu yeterli; hacim artarsa Redis |
| Dosya | **local disk** | **S3 uyumlu** | `Storage` soyutlaması sayesinde geçiş `.env` satırı |

> **⚠️ K9 revizyonu.** Önceki kayıt "üretimde MySQL 8" diyordu. Gerekçe TR
> hosting yaygınlığıydı. PostgreSQL'e geçiş sebebi: MySQL 8'in `performance_schema`
> ve varsayılan buffer ayarlarıyla küçük sunucularda yüksek RAM tabanı;
> PostgreSQL'in `jsonb` desteği ve daha güçlü kısıt (CHECK, partial index) yeteneği.
> **Maliyeti sıfır** — henüz tek migration yazılmadı.
>
> Kod tarafında hiçbir şey değişmez: Eloquent ve Schema Builder SQL farklarını
> gizler. Etkilenen tek şey `.env` ve üretim kurulumu.

### 2.3 Kalite araçları

| Araç | İşi | Ne zaman çalışır |
|---|---|---|
| **Pest** | Test framework (PHPUnit üzerine, okunur sözdizimi) | Her fazda |
| **Laravel Pint** | Kod formatlayıcı (PSR-12) | Commit öncesi |
| **Larastan** (PHPStan) | Statik analiz — çalıştırmadan hata bulur | Commit öncesi |
| **Laravel Tinker** | Etkileşimli PHP kabuğu | Deneme/keşif |
| **Laravel Telescope** | İstek/sorgu/job izleyici (yerel) | Faz 3'ten sonra, opsiyonel |

### 2.4 Kullanılmayacaklar (ve neden)

| Teknoloji | Neden hayır |
|---|---|
| Repository Pattern | Eloquent zaten Active Record; anlamsız aracı üretir (K4) |
| Clean Architecture / DDD | Soyutlama bütçesi. En karmaşık iş kuralı 3 satır (K15) |
| Mikroservis | Tek geliştirici, tek sunucu. Conway Yasası (K2) |
| Laravel Reverb / WebSocket | Ayrı daemon + RAM. Polling + ETag yeterli (K7) |
| Inertia / Blade / Livewire | Frontend bitti ve ayrı çalışıyor. Backend saf API |
| Docker (şimdilik) | Herd yeterli. Üretim kurulumunda değerlendirilir |

---

## 3. Çalışma Ritmi (her dosya için)

```
1. Komut         → php artisan make:*   (klasörü açar, namespace'i yazar)
2. Kod           → kısa yorumlarla
3. Kılavuz       → docs/rehber/<mimari-yol>/<dosya>.md
4. Doğrulama     → tinker veya test
5. DUR           → onay bekle
```

Kılavuz yolu koddaki yolu **birebir** yansıtır:

```
app/Actions/Auth/RegisterUserAction.php
   → docs/rehber/app/Actions/Auth/RegisterUserAction.md
```

---

## 4. Fazlar

Her faz **çalışan bir çıktı** ile biter. "Bitti" ölçütü tarayıcıda veya testte
görülebilir bir şeydir; "dosya yazıldı" değildir.

---

### FAZ 0 — Zemin ve kalite kapıları

**Amaç:** Kod yazmaya başlamadan önce "yanlışı anında söyleyen" araçları kurmak.

| # | İş | Dosya / Komut |
|---|---|---|
| 0.1 | `.env` düzeltmeleri | `APP_NAME=DavetKart`, `APP_LOCALE=tr`, `APP_FAKER_LOCALE=tr_TR`, `CACHE_STORE=file` |
| 0.2 | Türkçe dil dosyaları | `php artisan lang:publish` + `lang/tr/validation.php` |
| 0.3 | Pint kurulumu | `composer require laravel/pint --dev` |
| 0.4 | Larastan kurulumu | `composer require larastan/larastan --dev` + `phpstan.neon` |
| 0.5 | Test ortamı | `phpunit.xml` → SQLite in-memory |
| 0.6 | `AppServiceProvider` sıkılaştırma | `Model::preventLazyLoading()`, `Model::shouldBeStrict()` yerelde |

**Bitti ölçütü:**

```powershell
php artisan test              # yeşil
./vendor/bin/pint --test      # temiz
./vendor/bin/phpstan analyse  # hatasız
```

**Öğrenilecek:** Statik analiz nedir, N+1 sorgusu nasıl exception'a çevrilir,
neden hata üretimde değil laptop'ta yakalanmalı.

---

### FAZ 1 — İlk nefes: çalışan endpoint

**Amaç:** Bir HTTP isteğinin Laravel içinde nereden girip nereden çıktığını
**görmek**. En küçük tam tur.

| # | Dosya | İşi |
|---|---|---|
| 1.1 | `routes/api.php` | `GET /api/ping` → `{"status":"ok"}` |
| 1.2 | `app/Http/Middleware/ForceJsonResponse.php` | API her zaman JSON döner (HTML hata sayfası dönmesin) |
| 1.3 | `bootstrap/app.php` | Middleware kaydı (Laravel 11+ `Kernel.php` yok) |
| 1.4 | `tests/Feature/HealthTest.php` | İlk test |

**Bitti ölçütü:** Tarayıcıda `http://localhost:8000/api/ping` → JSON.
`php artisan test` yeşil.

**Öğrenilecek:** İstek yaşam döngüsü, `public/index.php` → bootstrap → router →
middleware → response. `bootstrap/app.php`'nin Laravel 11+ ile üstlendiği rol.

---

### FAZ 2 — Auth dikey dilimi 🎯

**Amaç:** Tüm katmanları bir arada çalışırken görmek. Sonunda **frontend gerçekten
giriş yapabilir**.

| # | Dosya | Katman |
|---|---|---|
| 2.1 | `app/Models/User.php` (düzenleme) | `$fillable`, `casts()`, `HasApiTokens` |
| 2.2 | `database/factories/UserFactory.php` | Test verisi |
| 2.3 | `app/Http/Resources/UserResource.php` | `full_name` → `fullName` dönüşümü |
| 2.4 | `app/Http/Requests/Auth/RegisterRequest.php` | Doğrulama |
| 2.5 | `app/Actions/Auth/RegisterUserAction.php` | İş kuralı |
| 2.6 | `app/Http/Controllers/Api/V1/AuthController.php` | Yönlendirme |
| 2.7 | `routes/api.php` (ekleme) | `/auth/register` |
| 2.8 | `LoginRequest` + `LoginUserAction` + rota | Giriş |
| 2.9 | `logout` + `me` | Token iptali, doğrulama |
| 2.10 | `tests/Feature/AuthTest.php` | 🔴 Zarfsız yanıt sözleşmesi testi |

**🔴 Sözleşme kuralı:** Auth yanıtı **zarfsız** döner — `{user, token}`.
Diğer tüm endpoint'ler `{data: ...}` zarfıyla. `services/auth.ts` doğrudan
`data.user` okuyor.

**Bitti ölçütü:** Frontend'i `npm run dev` ile açıp **gerçek hesapla giriş
yapabilmek**. Token localStorage'a düşüyor, sayfa yenilenince oturum korunuyor.

**Öğrenilecek:** FormRequest ↔ Action ↔ Resource iş bölümü, `validated()` ile
`all()` farkı, Sanctum token üretimi, Argon2id geçişi, 401 ile 403 ayrımı.

---

### FAZ 3 — Invitation dikey dilimi (CRUD)

**Amaç:** Sahiplik, yetkilendirme ve iç içe koleksiyon yönetimi.

| # | Dosya | Not |
|---|---|---|
| 3.1 | `app/Enums/InvitationStatus.php` | ⚠️ Frontend `'published' \| 'saved'` diyor; `draft` uyuşmazlığı burada çözülecek |
| 3.2 | `database/migrations/..._create_invitations_table.php` | ULID slug, indeksler, `show_*` kolonları |
| 3.3 | `..._create_timeline_events_table.php` | FK CASCADE, `sort_order` |
| 3.4 | `app/Models/Invitation.php` | `$fillable`, `casts()`, ilişkiler |
| 3.5 | `app/Models/TimelineEvent.php` | |
| 3.6 | `InvitationFactory` + `DatabaseSeeder` | Deterministik test verisi |
| 3.7 | `app/Policies/InvitationPolicy.php` | 🔴 IDOR savunması |
| 3.8 | `StoreInvitationRequest` / `UpdateInvitationRequest` | camelCase doğrulama |
| 3.9 | `InvitationResource` + `InvitationPayloadResource` + `TimelineEventResource` | 🔴 28 alanlı camelCase eşlemesi |
| 3.10 | `CreateInvitationAction` / `UpdateInvitationAction` / `SyncTimelineEventsAction` | |
| 3.11 | `InvitationController` + rotalar | |
| 3.12 | `tests/Feature/InvitationTest.php` | 🔴 "başkasının davetiyesini okuyamaz" testi |

**Bitti ölçütü:** Frontend dashboard'unda davetiye listesi **gerçek veritabanından**
geliyor; editörde autosave çalışıyor.

**Öğrenilecek:** Migration ve indeks stratejisi, Eloquent ilişkileri, mass
assignment güvenliği, Policy ile IDOR kapatma, iç içe koleksiyon senkronizasyonu,
`whenLoaded()` ile N+1 önleme.

---

### FAZ 4 — Public davetiye (okuma yolu) 🔥

**Amaç:** Sistemin en yüksek trafikli noktası. Cache ve ETag.

| # | Dosya |
|---|---|
| 4.1 | `ResolvePublicInvitationAction` — slug → yayınlanmış davetiye |
| 4.2 | `PublicInvitationController` — auth'suz, cache'li |
| 4.3 | `routes/api.php` → `/api/public/invitations/{slug}` |
| 4.4 | `Events/InvitationPublished` + `Listeners/ClearInvitationCache` |
| 4.5 | ETag middleware veya controller içi `304` |
| 4.6 | `tests/Feature/PublicInvitationTest.php` — 🔴 taslak sızmıyor |

**Bitti ölçütü:** `/invite/{slug}` sayfası gerçek backend'den yükleniyor;
ikinci istek `304 Not Modified` dönüyor.

**Öğrenilecek:** Okuma-ağırlıklı yük, cache invalidation stratejileri, ETag ve
koşullu istek, `/api/public/` fail-safe grubu.

---

### FAZ 5 — RSVP modülü

**Amaç:** Auth'suz yazma yolu — en çok saldırıya açık nokta.

| # | Dosya |
|---|---|
| 5.1 | `app/Enums/RsvpStatus.php` — DB İngilizce, `label()` Türkçe |
| 5.2 | `..._create_rsvps_table.php` — `ip_hash`, `(invitation_id, status)` indeksi |
| 5.3 | `app/Models/Rsvp.php` |
| 5.4 | `StoreRsvpRequest` — honeypot alanı |
| 5.5 | `app/Exceptions/RsvpQuotaExceededException.php` |
| 5.6 | `SubmitRsvpAction` — 🔴 deadline + kota + IP hash |
| 5.7 | `RsvpResource` + `RsvpController` (public submit + owner list) |
| 5.8 | Rate limit kaydı (`bootstrap/app.php`) |
| 5.9 | `Jobs/SendRsvpNotification` |
| 5.10 | `tests/Feature/RsvpTest.php` — 🔴 kota `SUM(guest_count)` ile |

**Bitti ölçütü:** Misafir LCV gönderiyor, sahip panelde 15 sn'de bir güncellenen
listeyi görüyor.

**Öğrenilecek:** Katmanlı savunma, rate limiting, honeypot, KVKK veri
minimizasyonu, özel exception → HTTP kodu eşlemesi, kuyruk.

---

### FAZ 6 — Media modülü

| # | Dosya |
|---|---|
| 6.1 | `app/Enums/MediaKind.php` |
| 6.2 | `..._create_media_table.php` |
| 6.3 | `app/Models/Media.php` |
| 6.4 | `StoreUploadedMediaAction` — MIME içerikten doğrulama, rastgele ad |
| 6.5 | `MediaController` → ⚠️ rota `/api/media/upload` (frontend böyle çağırıyor) |
| 6.6 | `Jobs/OptimizeUploadedImage` |
| 6.7 | `tests/Feature/MediaTest.php` |

**Bitti ölçütü:** Editörden galeri fotoğrafı yükleniyor, önizlemede görünüyor.

**Öğrenilecek:** Dosya güvenliği, disk soyutlaması, 15 saniye kuralı ve kuyruk.

---

### FAZ 7 — Ödeme ve paywall 🔴

**Amaç:** Projenin ticari çekirdeği. Sunucu tarafı yetki doğrulaması.

| # | Dosya |
|---|---|
| 7.1 | `app/Enums/OrderStatus.php` |
| 7.2 | `..._create_orders_table.php` — `provider_ref` **UNIQUE** |
| 7.3 | `app/Models/Order.php` — `tier` cast'i → `SubscriptionTier` |
| 7.4 | `app/Services/Payment/PaymentGateway.php` (interface) |
| 7.5 | `app/Services/Payment/FakeGateway.php` |
| 7.6 | `AppServiceProvider` — arayüz → sürücü bağlama |
| 7.7 | `app/Services/Pricing/TierResolver.php` — 🔴 `getRequiredTier()`'ın sunucu ikizi |
| 7.8 | `app/Exceptions/PaywallViolationException.php` |
| 7.9 | `StartCheckoutAction` + `HandlePaymentCallbackAction` |
| 7.10 | `PublishInvitationAction` — policy → tier → order → yayınla |
| 7.11 | `PaymentController` + webhook rotası |
| 7.12 | `tests/Feature/PaywallTest.php` — 🔴 yetersiz plan reddediliyor, webhook idempotan |

**Bitti ölçütü:** Standart planla galeri açık davetiye yayınlanamıyor (402);
sahte ödeme sonrası yayınlanabiliyor. Aynı webhook iki kez gelince tek order.

**Öğrenilecek:** Strategy Pattern, Dependency Inversion, idempotans, veritabanı
kısıtıyla race condition önleme.

---

### FAZ 8 — AI asistan ve iletişim

| # | Dosya |
|---|---|
| 8.1 | `app/Services/Ai/AiProvider.php` (interface) + `GeminiProvider` + `NullProvider` |
| 8.2 | `AssistantController` — kotalı proxy |
| 8.3 | `app/Enums/ContactSubject.php` |
| 8.4 | `..._create_contact_messages_table.php` + model |
| 8.5 | `ContactRequest` + `ContactController` |
| 8.6 | `SetLocaleFromHeader` middleware — 10 dil desteği |

**Bitti ölçütü:** Asistan sohbeti gerçek yanıt veriyor; iletişim formu kaydediyor;
`Accept-Language: de` gönderince doğrulama hataları Almanca dönüyor.

---

### FAZ 9 — Üretim hazırlığı

| # | İş |
|---|---|
| 9.1 | SQLite → PostgreSQL geçişi, migration'ların gerçek DB'de doğrulanması |
| 9.2 | `APP_DEBUG=false`, `config:cache`, `route:cache`, `view:cache` |
| 9.3 | Redis'e geçiş (cache + queue), `queue:work` süpervizörü |
| 9.4 | Gerçek ödeme sağlayıcısı (`IyzicoGateway`) + imza doğrulaması |
| 9.5 | S3 uyumlu depolama, `storage:link` |
| 9.6 | HTTPS, CORS, güvenlik başlıkları |
| 9.7 | Yedekleme ve log rotasyonu |

---

## 5. Faz özeti

| Faz | Konu | Frontend'de ne çalışır | Tahmini dosya |
|---|---|---|---|
| 0 | Zemin + kalite kapıları | — | 5 |
| 1 | İlk endpoint | — | 4 |
| **2** | **Auth (dikey dilim)** | **Giriş / kayıt** | 10 |
| 3 | Invitation CRUD | Dashboard + editör autosave | 12 |
| 4 | Public davetiye | `/invite/{slug}` sayfası | 6 |
| 5 | RSVP | LCV gönderimi + canlı panel | 10 |
| 6 | Media | Galeri yüklemesi | 7 |
| 7 | Ödeme + paywall | Yayınlama akışı | 12 |
| 8 | AI + iletişim + i18n | Asistan, iletişim formu | 6 |
| 9 | Üretim | — | — |

---

## 6. Her fazda uyulacak sabit kurallar

### Sözleşme (ihlal edilirse frontend kırılır)

| Kural | Sebep |
|---|---|
| Rotalar `/api/...`, **`/api/v1/...` değil** | `baseURL = '/api'`; versiyon namespace'te |
| Auth yanıtı **zarfsız** `{user, token}` | `services/auth.ts` doğrudan `data.user` okur |
| Alan adları **camelCase**, dönüşüm **sadece Resource'ta** | `fullName`, `mapUrl`, `showGallery` |
| Yetki hatası **403**, kimlik hatası **401** | 401 frontend'de oturumu düşürür |
| Backend **8000** portunda | `vite.config.ts` proxy'si |
| Uzun işler kuyruğa | `api.ts` timeout 15 sn |
| `id` alanları **string** | ULID |

### Güvenlik

| Kural | Sebep |
|---|---|
| Paywall **sunucuda** yeniden hesaplanır | `getRequiredTier()` DevTools'tan aşılır |
| LCV kotası **`SUM(guest_count)`** | `COUNT(*)` 4 katı misafir sızdırır |
| Modellerde `$guarded = []` **yasak** | Sadece `$fillable` |
| Action'da `validated()`, `all()` değil | Enjekte alan savunması |
| Sırlar `.env` → `config/` → Service | Frontend'e asla |
| IP'ler hash'lenir | KVKK |
| `provider_ref` **UNIQUE** | Webhook idempotansı |
| Kod içinde `env()` **çağrılmaz** | `config:cache` sonrası `null` |

---

## 7. Şu an neredeyiz?

| Durum | İş |
|---|---|
| ✅ | Laravel 13 kurulumu, SQLite, Sanctum tablosu |
| ✅ | `config/davetkart.php` + kılavuzu |
| ✅ | `config/payment.php`, `config/ai.php` + kılavuzları |
| ✅ | Laravel varsayılan config'lerinin 11 kılavuzu |
| ✅ | `app/Enums/SubscriptionTier.php` + kılavuzu (Faz 7'de kullanılacak) |
| ⬜ | **SIRADAKİ: Faz 0.1 — `.env` düzeltmeleri** |

---

## 8. Değişen kararlar kaydı

| # | Eski | Yeni | Gerekçe |
|---|---|---|---|
| **K9'** | Üretimde MySQL 8 | **PostgreSQL 16** | Düşük RAM tabanı, `jsonb`, güçlü kısıt desteği. Migration yazılmadığı için maliyet sıfır |
| **K17** | Yatay inşa (12 adım) | **Dikey dilim (9 faz)** | Öğrenme hedefi: katmanların birlikte çalıştığını erken görmek |
| **K18** | — | **Pest + Pint + Larastan** | Hata üretimde değil laptop'ta yakalanmalı |
