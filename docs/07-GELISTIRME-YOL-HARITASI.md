# DavetKart Backend — Geliştirme Yol Haritası

> **Oluşturma:** 30 Temmuz 2026 · **Terim güncellemesi:** 30 Temmuz 2026
> **Neyi değiştiriyor:** `03-MIMARI-PLAN.md` §8'deki 12 adımlık **katman-katman**
> inşa sırasını iptal eder, yerine **özellik-özellik** inşa sırasını koyar.
> Mimari kararlar (katman modeli, klasör yapısı, veri modeli, güvenlik stratejisi)
> **aynen geçerlidir** — değişen tek şey dosyaların yazılma sırasıdır.
> **Ayrıca:** K9 kararı revize edildi — üretim veritabanı **PostgreSQL 18**.

---

## 0. Terim uyarısı: iki ayrı eksen 🔴

Literatürde "yatay mimari" ve "dikey mimari" terimleri **iki farklı anlamda**
kullanılır. Karıştırılırsa bu dokümanın tamamı yanlış okunur.

### Eksen A — Klasörleme (dosyalar neye göre gruplanır?)

| Katman-bazlı klasörleme | Özellik-bazlı klasörleme |
|---|---|
| `app/Http/Controllers/`, `app/Models/`, `app/Actions/` | `app/Modules/Auth/`, `app/Modules/Payment/` |
| Laravel varsayılanı | `nwidart/laravel-modules` gibi paketlerle |

**Bizim seçimimiz: katman-bazlı klasörleme.** (K2 + K3)
Modül sınırları ayrı klasör ağacıyla değil, **alt klasör disipliniyle** korunur:
`app/Actions/Auth/`, `app/Actions/Invitation/`, `app/Actions/Payment/`…
Tek geliştiricili bir projede `app/Modules/` kurmak aşırı mühendisliktir.

> **"Modüler Monolit" ne demek, ne demek değil?**
> Planımızdaki bu terim bir **dağıtım** kararıdır: *"mikroservis değil, tek
> uygulama olarak deploy edilir."* Klasör yapısıyla ilgisi yoktur.
> `app/Modules/` klasörleri kurmuyoruz.

### Eksen B — İnşa sırası (dosyalar hangi sırayla yazılır?)

| Katman-katman inşa | Özellik-özellik inşa |
|---|---|
| Tüm migration'lar → tüm modeller → tüm controller'lar | Auth'un tüm katmanları → Invitation'ın tüm katmanları |
| İlk çalışan endpoint en sonda | İlk çalışan endpoint 6. dosyada |

**Bizim seçimimiz: özellik-özellik inşa.** (K17 — bu dokümanın konusu)

### İki eksen bağımsızdır

Katman-bazlı klasörleme kullanıp özellik-özellik inşa edebilirsin — bizim
yaptığımız tam olarak budur. Aşağıdaki tablo projedeki durumu özetler:

|  | Seçim | Kayıt |
|---|---|---|
| Klasörleme | Katman-bazlı (+ Action katmanı) | K2, K3 |
| İnşa sırası | Özellik-özellik | K17 |
| Dağıtım | Tek uygulama (Modüler Monolit) | K2 |

> **Not:** Literatürde *"Vertical Slice Architecture"* diye gerçek bir mimari var
> ve o, **Eksen A**'nın sağ sütununu anlatır. Biz onu **yapmıyoruz**. Bu
> dokümandaki "özellik-özellik" ifadesi yalnızca **Eksen B**'yi, yani teslimat
> sırasını kasteder.

---

## 1. Neden inşa sırasını değiştiriyoruz?

Eski plan **katman-katman** dilimlenmişti: önce *tüm* enum'lar, sonra *tüm*
migration'lar, sonra *tüm* modeller… Bu sırayla ilk çalışan endpoint 6. adımda
ortaya çıkıyor.

Sorun teknik değil, **öğrenme** sorunu:

- Yazdığın kod hiçbir şey yapmıyor; tarayıcıda görülecek sonuç yok.
- Katmanların birbirine nasıl bağlandığı görünmüyor — sadece parçalar var.
- `SubscriptionTier` enum'unu yazdık; onu kullanan ilk kod Adım 12'de gelecekti.

**Yeni yaklaşım: Walking Skeleton (Yürüyen İskelet).**

Önce tek bir isteği **uçtan uca** çalıştırırız. Kısa ama *eksiksiz* bir özellik
dilimi — her katmandan birer dosya:

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

### Izgara olarak: aynı 18 dosya, iki farklı sıra

Satırlar katmanlar, sütunlar özellikler. Her iki yolda da **aynı 18 dosya**
yazılır; değişen yalnızca doldurma yönüdür.

**Katman-katman (satır satır):**

|  | Auth | Invitation | RSVP |
|---|:---:|:---:|:---:|
| Migration | 1 | 2 | 3 |
| Model | 4 | 5 | 6 |
| FormRequest | 7 | 8 | 9 |
| Action | 10 | 11 | 12 |
| Resource | 13 | 14 | 15 |
| Controller | 16 | 17 | 18 |

→ 6. dosyada elinde üç migration ve üç model var. **Hiçbir şey çalışmıyor.**
İlk çalışan endpoint 16. dosyada.

**Özellik-özellik (sütun sütun):**

|  | Auth | Invitation | RSVP |
|---|:---:|:---:|:---:|
| Migration | **1** | 7 | 13 |
| Model | **2** | 8 | 14 |
| FormRequest | **3** | 9 | 15 |
| Action | **4** | 10 | 16 |
| Resource | **5** | 11 | 17 |
| Controller | **6** | 12 | 18 |

→ 6. dosyada **çalışan bir kayıt endpoint'i** var. Frontend'den giriş yapılır.

**Neden bu fark önemli?** Sözleşme hatası (yanlış camelCase eşlemesi, yanlış
yanıt zarfı) katman-katman sırada 12 resource'a kopyalanmış olarak keşfedilir;
özellik-özellik sırada ilk özellikte, tek dosyada yakalanır.

> **Katman-katman sıranın tek gerçek üstünlüğü** — 7 tabloyu birlikte tasarlayıp
> ilişki hatalarını erken görmek — bizde **zaten karşılandı**: `03-MIMARI-PLAN.md`
> §3.2 tüm tabloları indeks ve FK'larıyla tasarlamış durumda.
> **Kural: tasarımı bütün yap, inşayı parça parça.**

### Özellikler arası yabancı anahtarlar

Özellik-özellik giderken bazen henüz var olmayan bir tabloya FK gerekir.
Somut örnek: `rsvps.photo_media_id` → `media` tablosu (RSVP Faz 5, Media Faz 6).

> 🔴 **BU BÖLÜM GÜNCELLENDİ (29 Ağustos 2026).** Plandaki çözüm uygulanmadı ve
> uygulanmaması doğruydu.

**Plandaki çözüm:** kolon Faz 5'te nullable ve kısıtsız açılır, kısıt Faz 6'da
eklenir.

**Gerçekte yapılan:** kolon Faz 5'te **hiç açılmadı**; kolonlar, FK'leri,
yazanı ve okuyanı **Faz 6'da birlikte** geldi (6.17–6.21).

**Neden:** bir faz boyunca hiçbir kodun yazmadığı, hiçbir testin doğrulamadığı
bir kolon, **doğru olduğu varsayılan** bir kolondur — ders 26'nın şema sürümü ve
Faz 4'te `InvitationPublished`'ın `InvitationChanged`'e dönüşme sebebiyle (K48)
aynı aile. Ayrıca "kolon var ama kısıtı yok" ara durumu hiç oluşmadı.

```php
// ..._add_media_columns_to_rsvps_table.php — 6.17
$table->foreignUlid('photo_media_id')->nullable()->constrained('media')->nullOnDelete();
$table->foreignUlid('video_media_id')->nullable()->constrained('media')->nullOnDelete();
```

**Genel kural:** özellikler arası bir FK, **hedef tablo ve yazan kod hazır
olduğunda** eklenir. Erken açılan kolon evrim değil borçtur.

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

| Konu | Geliştirme | Test | Üretim | Neden |
|---|---|---|---|---|
| Veritabanı | **PostgreSQL 18** | **PostgreSQL 18** (`davetkart_test`) | **PostgreSQL 18** | 🔴 Dev/prod parity — 12-Factor X |
| Cache | **file** | **array** | **Redis 7** | Yerelde ek servis gereksiz |
| Kuyruk | **database** | **sync** | **Redis 7** | `jobs` tablosu yeterli; hacim artarsa Redis |
| Dosya | **local disk** | **fake** | **S3 uyumlu** | `Storage` soyutlaması sayesinde geçiş `.env` satırı |

> **⚠️ K9 revizyonu — iki aşamalı.**
>
> **Önceki kayıt:** "Geliştirmede SQLite, üretimde MySQL 8."
> **Yeni karar:** Üç ortamda da **PostgreSQL 18**.
>
> **Neden MySQL değil?** MySQL 8'in `performance_schema` ve varsayılan buffer
> ayarları küçük sunucularda yüksek RAM tabanı üretir. PostgreSQL ayrıca `jsonb`
> ve daha güçlü kısıt desteği (CHECK, partial index) sunar.
>
> **Neden geliştirmede de SQLite değil?** SQLite'ın oradaki gerekçesi "Herd
> ücretsiz sürümünde MySQL yok" idi — yani *SQLite daha iyi olduğu için* değil,
> *kurulum zahmetli olduğu için*. PostgreSQL'e geçince bu gerekçe düştü.
> Asıl mesele **dev/prod parity** (12-Factor X): farklı veritabanı, hataların
> laptop'ta değil üretimde ortaya çıkması demektir.
>
> | Konu | SQLite | PostgreSQL | Bizi etkiler mi |
> |---|---|---|---|
> | `ENUM` kolon tipi | Yok, `varchar`'a düşer | Var | 🔴 6 enum |
> | `jsonb` | Yok, düz metin | İndekslenebilir | 🔴 `gift_options` |
> | `CHECK` kısıtı | Kısıtlı | Tam | `guest_count > 0` |
> | Eşzamanlı yazma | Dosya kilidi, tek yazıcı | Satır kilidi | 🔴 LCV seli |
> | Kısmi indeks | Yok | Var | `WHERE status='published'` |
> | Yabancı anahtar | Varsayılan **kapalı** | Açık | Yetim kayıt riski |
>
> **Feragat edilen tek şey test hızı.** SQLite `:memory:` ile testler 3-5 kat
> hızlı koşardı. Ama yanlış veritabanında koşan hızlı test yanlış güven verir —
> `test_rsvp_quota` SQLite'ta geçip PostgreSQL'de farklı davranabilir.
>
> **Maliyeti sıfır** — henüz tek migration yazılmadı.
> Kod tarafında hiçbir şey değişmez: Eloquent ve Schema Builder SQL farklarını
> gizler.
>
> **Ortadan kalkan taviz:** Daha önce "SQLite'ta ENUM yok, o yüzden `string`
> kolon + PHP enum cast kullanacağız" demiştik. Bu bir uyum tavizeydi; artık
> gerçek `ENUM` veya `CHECK` kısıtı kullanabiliriz. (Karar Faz 3'te verilecek.)

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
| 0.1 | PHP sürücü kontrolü | `php -m \| Select-String "pgsql"` → `pdo_pgsql` görünmeli |
| 0.2 | PostgreSQL 18 kurulumu | Resmî Windows installer (postgresql.org) |
| 0.3 | İki veritabanı oluştur | `davetkart` ve `davetkart_test` |
| 0.4 | `.env` düzeltmeleri | `DB_CONNECTION=pgsql`, `APP_NAME=DavetKart`, `APP_LOCALE=tr`, `APP_FAKER_LOCALE=tr_TR`, `CACHE_STORE=file` |
| 0.5 | Bağlantı doğrulama | `php artisan migrate` → users/cache/jobs/sanctum tabloları oluşmalı |
| 0.6 | SQLite'ı kaldır | `database/database.sqlite` silinir |
| 0.7 | Türkçe dil dosyaları | `php artisan lang:publish` + `lang/tr/validation.php` |
| 0.8 | Pint kurulumu | `composer require laravel/pint --dev` |
| 0.9 | Larastan kurulumu | `composer require larastan/larastan --dev` + `phpstan.neon` |
| 0.10 | Test ortamı | `phpunit.xml` → `DB_DATABASE=davetkart_test`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` |
| 0.11 | `AppServiceProvider` sıkılaştırma | `Model::preventLazyLoading()`, `Model::shouldBeStrict()` yerelde |

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

### FAZ 2 — Auth özellik dilimi ✅ TAMAMLANDI

> **Bitiş:** 6 Ağustos 2026 · Özet ve kurulan **21 kural**:
> [`rehber/fazlar/FAZ-2.md`](rehber/fazlar/FAZ-2.md)
> Elle doğrulama: [`rehber/fazlar/FAZ-2-ELLE-DOGRULAMA.md`](rehber/fazlar/FAZ-2-ELLE-DOGRULAMA.md)

**Amaç:** Tüm katmanları bir arada çalışırken görmek. Sonunda **frontend gerçekten
giriş yapabiliyor.**

**Plan 10 dosyaydı, gerçekleşen 17 oldu.** Genişleme üç zorunluluktan geldi:
K35 (şema değişikliği), H10/H11'in gerektirdiği iki exception sınıfı, K36 (rate
limit). Kapsam kayması değil — plan yazılırken görülmemiş bağımlılıklar.

| # | Dosya | Katman | Durum |
|---|---|---|:---:|
| **2.0** | `..._create_users_table.php` | 🆕 **K35** — `first_name` + `last_name`, `VARCHAR(60)` | ✅ |
| 2.1 | `app/Models/User.php` | `#[Fillable]`, `hashed` cast, `HasApiTokens`, e-posta mutator | ✅ |
| — | `.env` · `.env.example` · `phpunit.xml` | 🆕 **K32'nin uygulanması** — Argon2id devrede | ✅ |
| 2.2 | `database/factories/UserFactory.php` | Test verisi, hash memoization, `PASSWORD` sabiti | ✅ |
| 2.3 | `app/Http/Resources/UserResource.php` | ⚠️ **K35 ile revize** — `firstName`/`lastName` **ayrı** döner | ✅ |
| 2.4 | `Requests/Auth/RegisterRequest.php` | 🔴 `unique` **bilerek yok** (A1) | ✅ |
| **2.5a** | `Exceptions/RegistrationFailedException.php` | 🆕 H10/H11 zorunluluğu | ✅ |
| 2.5b | `Actions/Auth/RegisterUserAction.php` | Transaction + UNIQUE kısıtı yakalama (E2, E4) | ✅ |
| 2.6 | `Controllers/Api/V1/AuthController.php` | 3 satırlık metotlar + `session()` yardımcısı | ✅ |
| 2.7 | `routes/api.php` | 🎯 İlk gerçek uç nokta | ✅ |
| **2.8a** | `AppServiceProvider::configureRateLimiting()` | 🆕 **K36** — Faz 5'ten öne çekildi | ✅ |
| 2.8b | `Requests/Auth/LoginRequest.php` | 🔴 `Password::min` **bilerek yok** (D3) | ✅ |
| **2.8c1** | `Exceptions/InvalidCredentialsException.php` | 🆕 **Parametresiz kurucu** — ayrım imkânsız | ✅ |
| 2.8c2 | `Actions/Auth/LoginUserAction.php` | 🔴 **Zamanlama savunması** + rehash | ✅ |
| 2.8d | `AuthController::login()` + rota | Giriş | ✅ |
| 2.9 | `RevokeTokenAction` + `logout` + `me` | Token izolasyonu (A6) | ✅ |
| 2.10 | `tests/Feature/AuthTest.php` + `tests/TestCase.php` | 15 test · 🆕 `forgetAuthState()` (T13) | ✅ |
| — | `phpstan.neon` | **K22** — level 5 → **6** | ✅ |

**🔴 Sözleşme kuralı:** Auth yanıtı **zarfsız** döner — `{user, token}`.
Diğer tüm endpoint'ler `{data: ...}` zarfıyla. ⚠️ `/auth/me` **zarflıdır**:
istisna `login` ve `register` için ad ad tanımlıdır (C2).

**Çalışan uç noktalar:**

| Method | Path | Auth | Yanıt |
|---|---|:---:|---|
| POST | `/api/auth/register` | — | `201` · zarfsız `{user, token}` |
| POST | `/api/auth/login` | — | `200` · zarfsız `{user, token}` |
| POST | `/api/auth/logout` | ✅ | `204` · gövde yok |
| GET | `/api/auth/me` | ✅ | `200` · **zarflı** `{data: {...}}` |

**Bitti ölçütü — karşılandı ✅:** Frontend `npm run dev` ile açılıp gerçek
hesapla giriş yapılabiliyor. Token `localStorage`'a düşüyor, sayfa yenilenince
oturum korunuyor.

**Frontend'de yapılanlar (K35 sözleşme değişikliği):** `types.ts` ·
🆕 `utils/user.ts` (`fullName()` yardımcısı) · `RegisterPage` iki input ·
`Header`/`Dashboard`/`LoginPage` · `services/api.ts`'te 🔴 **401 ayrımı**
(`INVALID_CREDENTIALS` oturumu düşürmez). Ayrıntı:
`claude/Notlar/03-FRONTEND-YAPILACAKLAR.md` **Bölüm II**.

**Öğrenilen:** FormRequest ↔ Action ↔ Resource iş bölümü · `validated()` ile
`all()` farkı · Sanctum token üretimi ve iptali · Argon2id geçişi · 401 ile 403
ayrımı · **kullanıcı sayımı (enumeration)** ve **zamanlama saldırısı** savunmaları.

---

### FAZ 3 — Invitation özellik dilimi (CRUD) ✅ TAMAMLANDI

> **Bitiş:** 19 Ağustos 2026 · Özet, **kronoloji** ve kurulan **15 kural**:
> [`rehber/fazlar/FAZ-3.md`](rehber/fazlar/FAZ-3.md)
> Elle doğrulama: [`rehber/fazlar/FAZ-3-ELLE-DOGRULAMA.md`](rehber/fazlar/FAZ-3-ELLE-DOGRULAMA.md)

**Amaç:** Sahiplik, yetkilendirme ve iç içe koleksiyon yönetimi.

**Plan 12 backend dosyasıydı; gerçekleşen 12 backend + 8 frontend oldu.**
Genişleme iki karardan geldi: **K37** (REST koleksiyonu — frontend'in "hesap
başına tek davetiye" varsayımı geçersiz kılındı) ve **K44** (kimliği backend
üretir). İkisi de sözleşme değişikliği olduğu için frontend uyarlaması bu fazın
parçası oldu.

| # | Dosya | Not | Durum |
|---|---|---|:---:|
| 3.1 | `app/Enums/InvitationStatus.php` | ⚠️ **K38** — `draft` atıldı, iki değer kaldı: `saved \| published` | ✅ |
| 3.2 | `..._create_invitations_table.php` | ⚠️ **K40** — ULID **birincil anahtar**, ayrı slug **yok**. **K39** CHECK kısıtı · **K41** `phone_background` kolonu **yok** · içerik alanları **tamamen nullable** | ✅ |
| 3.3 | `..._create_timeline_events_table.php` | `foreignUlid` (üst PK ULID) · `sort_order` · CASCADE | ✅ |
| 3.4 | `app/Models/Invitation.php` | ⚠️ `$fillable` değil **`#[Fillable]`** özniteliği (Laravel 13) · `immutable_*` cast (K23) · `'user_id' => 'integer'` (P4) | ✅ |
| 3.5 | `app/Models/TimelineEvent.php` | + `User::invitations()` ilişkisi | ✅ |
| **3.6** | `InvitationFactory` + **`TimelineEventFactory`** + `DatabaseSeeder` | 🆕 İkinci fabrika · 🔴 seeder Faz 2'den beri **bozuktu** (`name` kolonu yok), yeniden yazıldı | ✅ |
| 3.7 | `app/Policies/InvitationPolicy.php` | 🔴 IDOR savunması — reddi **404** (H7, Faz 1'de kurulmuştu) | ✅ |
| **3.8** | `Requests/Invitation/{InvitationRequest, Store…, Update…}.php` | 🆕 **Üç** dosya: ortak soyut taban + iki ince alt sınıf (C3). 21 alanlık açık eşleme | ✅ |
| 3.9 | `Resources/{InvitationResource, InvitationPayloadResource, TimelineEventResource}.php` | ⚠️ **Sapma:** `whenLoaded()` **kullanılmadı** — gerekçe aşağıda | ✅ |
| 3.10 | `Actions/Invitation/{Create…, Update…, SyncTimelineEvents…}.php` | Transaction (E4) + senkronizasyon (N1-N4) | ✅ |
| 3.11 | `InvitationController` + `routes/api.php` | ⚠️ **Sapma:** `authorizeResource` **çalışmıyor** — gerekçe aşağıda. Rota ULID desenine kısıtlandı | ✅ |
| 3.12 | `tests/Feature/InvitationTest.php` | **18 test**, 5'i sahiplik. 🔴 T13 olmadan ikisi **boş yeşil** yanardı | ✅ |
| — | `tests/TestCase.php` · `LoginUserAction` · `RegisterRequest` · `AuthTest` | 🆕 Faz 2'de bulunan **4 kusurun** düzeltilmesi (aşağıda) | ✅ |

**Çalışan uç noktalar:**

| Method | Path | Auth | Yanıt |
|---|---|:---:|---|
| GET | `/api/invitations` | ✅ | `200` · `{data: [...]}` — yalnızca kendi kayıtları |
| POST | `/api/invitations` | ✅ | `201` · `{data: {...}}` |
| GET | `/api/invitations/{id}` | ✅ | `200` · başkasınınkinde **404** |
| PUT | `/api/invitations/{id}` | ✅ | `200` · program senkronize edilir |
| DELETE | `/api/invitations/{id}` | ✅ | `204` · soft delete |

⚠️ **`POST /api/invitations/{id}/publish` AÇILMADI.** Plan "rota burada açılsın,
iş kuralı Faz 7'de" diyordu; açılmadı çünkü çağıracak bir iş kuralı yok ve boş
bir uç nokta sözleşmede yalan bir söz olurdu (B4). **Faz 7'ye taşındı.**

**🔴 İki plan sapması — gerekçeleriyle:**

| Plandaki | Yapılan | Neden |
|---|---|---|
| `whenLoaded()` ile N+1 önleme (3.9) | Doğrudan `$this->timelineEvents` + controller'da `with()` | `whenLoaded` ilişki yüklü değilse anahtarı **düşürür**; frontend eksik alanı varsayılanla doldurur ve kullanıcı **hiç yazmadığı bir programı** görür. Doğrudan erişimde `preventLazyLoading` yerelde exception fırlatır — sessiz yanlış veri yerine gürültülü hata |
| `authorizeResource` (3.11) | Her metotta `Gate::authorize()` | Laravel 11+ taban controller'ı boş; `authorizeResource` `$this->middleware()` çağırıyor ve o metot **yok** |

**🔴 Faz 2'de bulunan ve bu fazda düzeltilen kusurlar:** Faz 3'ün ilk
`composer check` çalıştırması Faz 2'nin **yeşil kapanmadığını** ortaya çıkardı.

| Kusur | Ne zamandan beri | Düzeltme |
|---|---|---|
| `Password::min(8)` framework sınıf adını sözleşmeye sızdırıyordu | 4 Ağu | `'min:8'` (**D6**) |
| Guard önbelleği token testini **boş yeşil** yakıyordu | 7 Ağu | `forgetAuthState()` (**T13**) |
| `LoginUserAction`'da gereksiz `?->` + kılavuzda **yanlış açıklama** | 4 Ağu | Düzeltildi (**B4**) |
| `DatabaseSeeder` var olmayan `name` kolonuna yazıyordu | Faz 0'dan beri | Yeniden yazıldı + idempotans |

**Bitti ölçütü — karşılandı ✅:** Dashboard'da davetiye listesi gerçek
veritabanından geliyor; editörde autosave çalışıyor.

**Frontend'de yapılanlar (K37 + K44 sözleşme değişikliği) — 8 dosya:**
`types.ts` (`id: string \| null` + `localKey`) · `services/invitations.ts` (REST
istemcisi) · `services/persistence.ts` (kimlik taşıyan arayüz) ·
🔴 `stores/useInvitationStore.ts` (`recordId`, kaydetme kuyruğu,
`adoptServerIds`) · `hooks/useDashboardData.ts` (gerçek dizi + iyimser silme) ·
`pages/DashboardPage.tsx` (kart kaydın tamamını taşır, silme düğmesi) ·
`components/create/TimelineEditor.tsx` · `data.ts`.
Kılavuzları: `davetkart-frontent/docs/rehber/src/`.

**Öğrenilen:** Migration ve indeks stratejisi · Eloquent ilişkileri · mass
assignment güvenliği · **Policy ile IDOR kapatma** · iç içe koleksiyon
senkronizasyonu · N+1 önleme · **sahipliğin bir `if` değil sorgunun kapsamı
olduğu** · **çalıştırılmayan kodun doğru varsayıldığı**.

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

### FAZ 5 — RSVP modülü ⚠️ KOD TAMAMLANDI · DOĞRULAMA BEKLİYOR

**Amaç:** Auth'suz yazma yolu — en çok saldırıya açık nokta.

> **Faz kaydı:** `docs/rehber/fazlar/FAZ-5.md`
> **Kapanış ölçütü:** `docs/rehber/fazlar/FAZ-5-ELLE-DOGRULAMA.md` (16 adım)
>
> 🔴 17 geliştirme adımının tamamı yazıldı ve commit'lendi, ama `composer check`
> **hiç koşmadı** (gerekçe: `FAZ-5.md` §0). Faz ancak elle doğrulama betiği
> yeşil bittiğinde kapanır — kural **B7**.

Plan 10 dosyaydı, **17 adım** oldu:

| # | Dosya | Durum |
|---|---|---|
| 5.1 | `app/Enums/RsvpStatus.php` — ⚠️ `label()` **yazılmadı** (K21 · K49) | ✅ |
| 5.2 | `..._create_rsvps_table.php` — ULID PK (K52), **iki** CHECK, `ip_hash` | ✅ |
| 5.3 | `app/Models/Rsvp.php` + `Invitation::rsvps()` | ✅ |
| 5.4 | `Requests/Rsvp/StoreRsvpRequest.php` — honeypot | ✅ |
| 5.5 | 🆕 `Exceptions/HasErrorCode.php` + `RsvpDeadlinePassed`/`RsvpQuotaExceeded` | ✅ |
| 5.6 | 🆕 `Contracts/RsvpQuotaResolver.php` + `Services/Rsvp/TierRsvpQuotaResolver.php` (K51) | ✅ |
| 5.7 | `Actions/Rsvp/SubmitRsvpAction.php` — 🔴 5 katmanlı savunma | ✅ |
| 5.8 | `Resources/RsvpResource.php` | ✅ |
| 5.9 | `Policies/RsvpPolicy.php` | ✅ |
| 5.10 | `PublicRsvpController` + `RsvpController` (plan tek controller diyordu) | ✅ |
| 5.11 | Rotalar + `throttle:rsvp` (2 kova) + `throttleApi()` | ✅ |
| 5.12 | `database/factories/RsvpFactory.php` + seeder | ✅ |
| 5.13 | `tests/Feature/RsvpTest.php` — **29 test** + mutasyon tablosu | ✅ |
| 5.14 | PHPStan level 6 → **8** (K22) — ayrı commit, geri alınabilir | ✅ |
| 5.15 | `FAZ-5.md` | ✅ |
| 5.16 | `FAZ-5-ELLE-DOGRULAMA.md` | ✅ |
| 5.17 | `docs/07` · `docs/09` · `fazlar/README.md` güncellemesi | ✅ |
| — | 🔴 `Jobs/SendRsvpNotification` | ❌ **YAZILMADI (K53)** |

**Bitti ölçütü:** Misafir LCV gönderiyor, sahip panelde 15 sn'de bir güncellenen
listeyi görüyor. → ⬜ Frontend uyarlaması bekliyor (`claude/Notlar/04`).

**Öğrenilecek:** Katmanlı savunma, rate limiting, honeypot, KVKK veri
minimizasyonu, özel exception → HTTP kodu eşlemesi, kuyruk (⚠️ kuyruk K53
nedeniyle bu fazda öğrenilmedi).

**Kalite kapısı:** PHPStan level 6 → **8** ✅ (doğrulanmadı)

---

### FAZ 6 — Media modülü

> 🔴 **DURUM (29 Ağustos 2026): 24/24 adım yazıldı ve commit'lendi.**
> 6.1–6.14 `composer check` ile doğrulandı; 6.15–6.24 **doğrulanmadı**.
> Kapanış ölçütü: `docs/rehber/fazlar/FAZ-6-ELLE-DOGRULAMA.md` (18 adım).
> Tam liste ve gerekçeler: `docs/09` §FAZ 6 · `docs/rehber/fazlar/FAZ-6.md`.

Plan **8 adımdı, 24 oldu.** Eksik olan iki şey vardı: misafirin LCV
foto/videosu (yoksa `MediaKind`'ın iki türü ölü kalırdı) ve medyanın LCV'ye
bağlanması (yoksa `rsvps` kolonlarının yazanı olmazdı).

| # | Dosya | Durum |
|---|---|---|
| 6.1–6.8 | `MediaKind` · `media` tablosu · `Media` · `MediaFactory` · `MEDIA_QUOTA_EXCEEDED` + exception · `MediaRequest` ailesi · `OptimizeUploadedImage` · `StoreUploadedMediaAction` | ✅ |
| 6.9 | Eksik kılavuz + 🔴 kalite kapısı düzeltmeleri (PHPStan 8, flaky test) | ✅ |
| 6.10–6.11 | `MediaResource` · `MediaController` | ✅ |
| 6.12–6.13 | `ResolveOpenRsvpInvitationAction` 🆕 · `SubmitRsvpAction` refactor | ✅ |
| 6.14–6.16 | `StoreGuestMediaAction` 🆕 · `PublicMediaController` 🆕 · rotalar + `throttle:media` | ✅ |
| 6.17–6.21 | `rsvps` medya kolonları · `Rsvp` ilişkileri · `StoreRsvpRequest` · 🔴 sahiplik doğrulaması · `RsvpResource` | ✅ |
| 6.22 | `tests/Feature/MediaTest.php` — 28 test + mutasyon tablosu | ✅ |
| 6.23–6.24 | `FAZ-6.md` · `FAZ-6-ELLE-DOGRULAMA.md` | ✅ |

⚠️ **Rota planı değişti:** `/api/media/upload` **geçersiz**. Uçlar iç içe
kaynak oldu (`/api/invitations/{id}/media` ve
`/api/public/invitations/{id}/media`), çünkü düz bir uçta aidiyet gövdeden
gelirdi (**N1**). Frontend uyarlanacak — liste: `FAZ-6.md` §8.

**Bitti ölçütü:** Editörden galeri fotoğrafı yükleniyor, önizlemede görünüyor
**ve** misafir LCV formuna fotoğraf ekleyip gönderebiliyor.

**Öğrenilecek:** Dosya güvenliği, disk soyutlaması, 15 saniye kuralı, kuyruk,
ve bir kuralı ikinci bir uç istediğinde **kopyalamak yerine çıkarmak**.

---

### FAZ 7 — Ödeme ve paywall 🔴 ⚠️ KOD TAMAMLANDI · DOĞRULAMA BEKLİYOR

> 🔴 **DURUM (3 Eylül 2026): 25/25 adım yazıldı ve commit'lendi — ama
> `composer check` HİÇ KOŞMADI** (ortamda PHP/Composer yoktu).
> Kapanış ölçütü: `docs/rehber/fazlar/FAZ-7-ELLE-DOGRULAMA.md` (20 adım).
> ⚠️ **Adım 0 = Faz 6'nın kapanış listesi** (`FAZ-6.md` §11, özellikle
> `php artisan storage:link`).
> Tam kayıt: `docs/rehber/fazlar/FAZ-7.md`.

**Amaç:** Projenin ticari çekirdeği. Sunucu tarafı yetki doğrulaması.

Plan **12 adımdı, 25 oldu.** Büyümenin üç sebebi: dört ayrı exception (H11
arayüzü sayesinde renderer'a hiç dokunulmadı), K42'nin arayüzü (`docs/09`
onu bir dosya olarak saymamıştı) ve üç fazdır ertelenen `invitations.timezone`
(**K63**).

| # | Dosya | Durum |
|---|---|---|
| 7.1–7.4 | `OrderStatus` · `orders` tablosu · `Order` · `OrderFactory` | ✅ |
| 7.5 | 4 exception (`Paywall` · `AlreadyPublished` · `InvalidWebhookSignature` · `PaymentProvider`) + `ErrorCode` beyaz listesi | ✅ |
| 7.6–7.7 | `PaymentGateway` + 2 DTO · `FakeGateway` + sürücü bağlama | ✅ |
| 7.8 | `TierResolver` — 🔴 `getRequiredTier()`'ın sunucu ikizi | ✅ |
| 7.9 | `PublishEntitlementResolver` 🆕 + `OrderEntitlementResolver` — 🔴 **K42** | ✅ |
| 7.10–7.11 | `StartCheckoutAction` + `CheckoutResult` · `HandlePaymentCallbackAction` | ✅ |
| 7.12 | 🔴 `PublishInvitationAction` — Faz 3'ten beri boş iskeletti | ✅ |
| 7.13–7.15 | `StoreCheckoutRequest` · `OrderResource` · 2 controller · rotalar | ✅ |
| 7.16 | `SubscriptionRsvpQuotaResolver` 🆕 — 🔴 `TierRsvpQuotaResolver` **silindi** | ✅ |
| 7.17 | 🔴 `invitations.timezone` (**K63**) + 6 dosya + son tarih artık davetiyenin diliminde | ✅ |
| 7.18 | (düzeltme) `Rule::enum` → `'in:'` — **D6** ihlali önlendi | ✅ |
| 7.19 | `tests/Feature/PaywallTest.php` — **33 test** + 33 satırlık mutasyon tablosu | ✅ |
| 7.20–7.26 | Kılavuzlar · `CLAUDE.md` (Faz 6'nın B4 borcu) · `FAZ-7.md` · elle doğrulama | ✅ |

⚠️ **Rota planı değişti (üç sapma, üçü de daha eski bir kararın uygulaması):**

| `docs/09` ne diyordu | Ne yapıldı | Neden |
|---|---|---|
| `POST /api/payments/checkout` (tek uç) | **İki uç**: `/invitations/{id}/checkout` (tekil) + `/payments/checkout` (paket) | **N1** — aidiyet URL'nin yapısında (K64) |
| `POST /api/payments/webhook` | `/api/public/payments/webhook` | **K12** fail-safe grubu (K65) |
| `public_slug` üret | Üretilmiyor | **K40** — `invitations.id` zaten ULID (K66) |

**Çalışan uçlar (4 yeni, toplam 19):**

| Method | Path | Auth |
|---|---|:---:|
| POST | `/api/invitations/{id}/publish` | ✅ |
| POST | `/api/invitations/{id}/checkout` | ✅ |
| POST | `/api/payments/checkout` | ✅ |
| POST | `/api/public/payments/webhook` | — |

**Bitti ölçütü:** Standart planla galeri açık davetiye yayınlanamıyor (402);
sahte ödeme sonrası yayınlanabiliyor. Aynı webhook iki kez gelince tek order.

**Öğrenilecek:** Strategy Pattern, Dependency Inversion, idempotans, veritabanı
kısıtıyla race condition önleme, HMAC imza doğrulaması, para aritmetiği.

🔴 **Açık ticari karar:** paket alımın **kaç yayın** açtığı sınırlanmadı
(`FAZ-7.md` §9). Bugünkü hâliyle tek bir 399 ₺'lik paket sınırsız yayın açıyor.

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
| 9.1 | Üretim PostgreSQL kurulumu, yedekleme ve bağlantı havuzu (PgBouncer) |
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
| **2** ✅ | **Auth (özellik dilimi)** | **Giriş / kayıt** ✅ | 10 planlandı → **17 oldu** |
| **3** ✅ | **Invitation CRUD** | **Dashboard + editör autosave** ✅ | 12 + 8 FE |
| **4** ✅ | **Public davetiye** | **`/invite/{id}` sayfası** ✅ | 6 planlandı → **8 + 2 FE** |
| **5** ⚠️ | **RSVP** — 17/17 adım ✅; `composer check` **Faz 6'da koştu ve yeşil bitti**, elle doğrulama hâlâ açık | LCV gönderimi + canlı panel ⬜ | 10 planlandı → **16** |
| **6** ⚠️ | **Media** — 24/24 adım ✅, **6.15+ doğrulanmadı** | Galeri + LCV medyası ⬜ (frontend borcu) | 8 planlandı → **24** |
| 6 | Media | Galeri yüklemesi | 7 |
| **7** ⚠️ | **Ödeme + paywall** — 25/25 adım ✅, `composer check` **hiç koşmadı** | Yayınlama akışı ⬜ (frontend borcu) | 12 planlandı → **25** |
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
| `provider_ref` **UNIQUE** | Webhook idempotansı — 🔴 ama **yalnızca ikinci satırı** engeller (M8) |
| Durum geçişi + satır kilidi | Bir satırın iki kez ilerlemesini engeller (M8) |
| Fiyat **asla** gövdeden okunmaz | `{"price":1}` biçimsel olarak geçerlidir (M6) |
| Para **kuruşta tam sayı** | Kayan noktada toplamlar kuruş kaçırır (M5) |
| İmza **ham gövde** + `hash_equals()` | Yeniden serileştirme imzayı bozar (W1) · zamanlama saldırısı (W2) |
| Webhook ucu **her zaman 2xx** | 404 sonsuz retry + bilgi sızıntısı (W3) |
| Kod içinde `env()` **çağrılmaz** | `config:cache` sonrası `null` |

---

## 7. Şu an neredeyiz?

> **Son güncelleme:** 6 Ağustos 2026

| Durum | İş |
|---|---|
| ✅ | Laravel 13 kurulumu, Sanctum tablosu |
| ✅ | `config/davetkart.php`, `payment.php`, `ai.php` + kılavuzları |
| ✅ | Laravel varsayılan config'lerinin 11 kılavuzu |
| ✅ | `app/Enums/SubscriptionTier.php` + kılavuzu (Faz 7'de kullanılacak) |
| ✅ | **FAZ 0 TAMAMLANDI** — özet: `docs/rehber/fazlar/FAZ-0.md` |
| ✅ | PostgreSQL 18 · `davetkart` + `davetkart_test` |
| ✅ | `.env` / `.env.example` · `pint.json` · `phpstan.neon` · `phpunit.xml` |
| ✅ | `AppServiceProvider` sıkılaştırma (katı model kipi, CarbonImmutable) |
| ✅ | `composer lint` / `analyse` / `check` scriptleri |
| ✅ | **K20 hata sözleşmesi** tasarlandı → `docs/08-HATA-SOZLESMESI.md` |
| ✅ | **K21 backend tek dil** (`APP_LOCALE=en`) |
| ✅ | **FAZ 1 TAMAMLANDI** — özet: `docs/rehber/fazlar/FAZ-1.md` |
| ✅ | `ErrorCode` (19 kod) · `ForceJsonResponse` · `ApiExceptionRenderer` |
| ✅ | `bootstrap/app.php` kablolama · `GET /api/ping` · `HealthController` |
| ✅ | `HealthTest` (7 test, 3'ü sızıntı) · `php artisan errors:export` |
| ✅ | **K25–K31** kararları · **H10–H13 · R1–R5 · M1–M4 · T6–T9 · G1–G3** kuralları |
| ✅ | **FAZ 2 TAMAMLANDI** — özet: `docs/rehber/fazlar/FAZ-2.md` |
| ✅ | `users` şeması **K35**: `first_name` + `last_name` · `User` modeli · `UserFactory` · `UserResource` |
| ✅ | **K32 fiilen uygulandı** — `.env`'de `HASH_DRIVER` eksikti, hâlâ bcrypt kullanılıyordu |
| ✅ | `Register`/`LoginRequest` · `Register`/`Login`/`RevokeTokenAction` · `AuthController` (4 uç) |
| ✅ | 🔴 **Enumeration savunması** (`unique`/`exists` yok) · 🔴 **zamanlama savunması** (sahte hash) |
| ✅ | **K36 hız sınırı** — Faz 5'ten öne çekildi (Argon2id bellek tüketimi saldırısı) |
| ✅ | `AuthTest` 15 test · `TestCase::forgetAuthState()` (**T13**) · toplam **22 test** |
| ✅ | **K22** — PHPStan level 5 → **6** |
| ✅ | **K35–K36** kararları · **A1–A7 · D1–D5 · E1–E5 · C1–C3 · T10–T13 · B4** kuralları |
| ✅ | Frontend K35 uyarlaması + 401 ayrımı — `Notlar/03` **Bölüm II** |
| ✅ | **FAZ 3 TAMAMLANDI** — özet: `docs/rehber/fazlar/FAZ-3.md` |
| ✅ | `InvitationStatus` · iki migration · `Invitation`/`TimelineEvent` modelleri · fabrikalar |
| ✅ | 🔴 `InvitationPolicy` (IDOR) · Request ailesi (21 alan eşlemesi) · Resource ailesi |
| ✅ | `Create`/`Update`/`SyncTimelineEventsAction` · `InvitationController` (5 uç) · 18 test |
| ✅ | **K37–K44** kararları · **P1–P4 · N1–N4 · D6 · E6 · C4–C5 · T13–T14 · B5** kuralları |
| ✅ | **FAZ 4 TAMAMLANDI** — özet: `docs/rehber/fazlar/FAZ-4.md` |
| ✅ | `ResolvePublicInvitationAction` · public Resource ailesi · `/api/public/` grubu (K12) |
| ✅ | `SetEtag` middleware (304) · `InvitationChanged` + `ClearInvitationCache` · 25 test |
| ✅ | **K45–K48** kararları · **O1–O6 · R6 · E7 · C6 · T15 · B6** kuralları |
| ✅ | 🔴 Faz 3'te bulunan üç kusur düzeltildi (ULID regex'i, `status` yazılmıyordu, Larastan cast'leri okumuyordu) |
| ⚠️ | **FAZ 5 — kod yazıldı, DOĞRULANMADI** — özet: `docs/rehber/fazlar/FAZ-5.md` |
| ⚠️ | `composer check` hiç koşmadı; kapanış ölçütü `FAZ-5-ELLE-DOGRULAMA.md` (16 adım) |
| ✅ | `RsvpStatus` · `rsvps` migration (2 CHECK) · `Rsvp` modeli · `StoreRsvpRequest` (honeypot) |
| ✅ | `HasErrorCode` arayüzü + 2 exception · `RsvpQuotaResolver` dikiş yeri (K51) |
| ✅ | 🔴 `SubmitRsvpAction` — 5 katmanlı savunma · `RsvpResource` · `RsvpPolicy` · 2 controller |
| ✅ | `throttle:rsvp` (2 kova) + `throttleApi()` — **FAZ-4 §9.2 borcu kapandı** |
| ✅ | `RsvpTest` 29 test + 18 satırlık mutasyon tablosu · **K22**: PHPStan 6 → **8** |
| ✅ | **K49–K53** kararları · **L1–L4 · E8–E9 · C7 · P5 · T16 · B7** kuralları |
| 🔴 | **Frontend uyarlaması BEKLİYOR** — 7 dosya, honeypot alanı dâhil (`FAZ-5.md` §8) |
| ⬜ | **SIRADAKİ: `FAZ-6-ELLE-DOGRULAMA.md`'yi koştur, sonra Faz 7 — Ödeme ve paywall** |

### ⚠️ Faz 2'nin kapsamı da büyüdü (K35 · K36 · H10-H11)

Plan 10 dosyaydı, 17 oldu. Üç kaynak:

| Kaynak | Eklenen |
|---|---|
| **K35** — ad/soyad ayrımı | `2.0` migration + `UserResource` revizyonu + frontend uyarlaması |
| **H10/H11** — Action hata yanıtı üretmez | `RegistrationFailedException` · `InvalidCredentialsException` |
| **K36** — hız sınırı | `AppServiceProvider::configureRateLimiting()` + rota grubu ayrımı |

Ayrıca **K32 planda "uygulandı" görünüyordu ama uygulanmamıştı**: `config/hashing.php`
yayınlanmış, `.env`'e `HASH_DRIVER` hiç yazılmamıştı. Faz 2'ye kadar **bcrypt**
kullanılıyordu.

> **Ders (B4):** Karar kaydında ✅ görünen bir madde, kodda doğrulanmadıysa
> yalnızca bir niyettir.

### 🔴 Faz 1'de bulunan hata

`html_request_to_api_still_receives_json` testi **yazıldığı günden beri hiç
geçmemişti** — ve `FAZ-1.md`'ye "doğrulandı" diye yazılmıştı. Sebep:
`Router::findRoute()` eşleşme bulamazsa exception fırlatır ve **grup middleware'i
hiç çalışmaz**, dolayısıyla `ForceJsonResponse` devreye girmez.

Düzeltme Faz 2'de yapıldı: `bootstrap/app.php`'de render koşuluna
`$request->is('api/*') ||` eklendi. Ayrıntı: [`rehber/fazlar/FAZ-1.md`](rehber/fazlar/FAZ-1.md) §7.1.

### ⚠️ Faz 1'in kapsamı büyüdü (K20)

Hata sözleşmesi kararı Faz 1'e üç dosya ekledi:

| # | Dosya | İşi |
|---|---|---|
| 1.1 | `app/Enums/ErrorCode.php` | Hata kodu kataloğu + HTTP durum eşlemesi |
| 1.2 | `app/Http/Middleware/ForceJsonResponse.php` | API her zaman JSON döner |
| 1.3 | `bootstrap/app.php` | Middleware kaydı + **exception handler** (hata zarfı) |
| 1.4 | `routes/api.php` | `GET /api/ping` |
| 1.5 | `tests/Feature/HealthTest.php` | İlk test + sızıntı testi |
| 1.6 | `app/Console/Commands/ExportErrorCodes.php` | `php artisan errors:export` |

**Yeni bitiş ölçütü:** `/api/ping` JSON döner **ve** bilinmeyen rota HTML değil
`{ "error": { "code": "RESOURCE_NOT_FOUND" } }` döner.

### ❌ Faz 8'den çıkarılan

`SetLocaleFromHeader` middleware'i **iptal edildi** (K21). Backend tek dil
konuşur; `Accept-Language` okunmaz.

---

## 8. Değişen kararlar kaydı

| # | Eski | Yeni | Gerekçe |
|---|---|---|---|
| **K9'** | Üretimde MySQL 8 | **PostgreSQL 18** | Düşük RAM tabanı, `jsonb`, güçlü kısıt desteği. Migration yazılmadığı için maliyet sıfır |
| **K17** | Katman-katman inşa (12 adım) | **Özellik-özellik inşa (9 faz)** | Öğrenme hedefi: katmanların birlikte çalıştığını erken görmek. ⚠️ Yalnızca **inşa sırası** değişti; klasörleme katman-bazlı kalıyor (bkz. §0) |
| **K19** | Geliştirmede SQLite | **Geliştirmede de PostgreSQL 18** | Dev/prod parity (12-Factor X). SQLite'ın gerekçesi "kurulum zahmeti"ydi, teknik üstünlük değil. ENUM/jsonb/CHECK tavizleri ortadan kalktı |
| **K18** | — | **Pest + Pint + Larastan** | Hata üretimde değil laptop'ta yakalanmalı |
| **K35** | `users.full_name` (tek kolon) | **`first_name` + `last_name`** — API'de de **ayrı döner** | Birleştirmek kolay, birleşmiş veriyi ayırmak imkânsız ("Ayşe Nur Kaya" bölünemez). Fatura soyadı tek başına ister (Faz 7). Birleştirme bir **sunum kararıdır** → frontend'e ait. Faz 2'de alındı ve uygulandı |
| **K36** | Rate limit Faz 5'te | **Auth uçlarında Faz 2'de** | Brute-force **ve** K32'nin doğurduğu bellek tüketimi saldırısı: Argon2id her isteği 64 MB'lık kaynak talebine çevirdi. İki limit: 5/dk (e-posta+IP) · 20/dk (IP) |
