# DavetKart Backend — Tüm Fazların Planı

> **Oluşturma:** 31 Temmuz 2026
> **Kaynak:** `07-GELISTIRME-YOL-HARITASI.md` (faz sırası) + `03-MIMARI-PLAN.md` §3-4
> (veri modeli, endpoint tablosu) + `08-HATA-SOZLESMESI.md` (K20).
> **Bu doküman ne yapar:** Dağınık üç dokümandaki faz bilgisini tek yerde toplar ve
> eskimiş yerleri (Pest, MySQL, `03` §4.5 hata formatı) K19-K24 kararlarına göre
> düzeltilmiş halde sunar.
> **Bu doküman ne yapmaz:** Yeni karar üretmez. Çelişki görürsen `07` ve `08`
> kazanır; buradaki satır hatalıdır, düzeltilmelidir.

---

# BÖLÜM I — ÖZET

## 1. Toplam hedef

Backend bittiğinde ortaya çıkan şey:

**7 tablo · 20 endpoint · 7 bounded context · 9 faz**

| Modül (bounded context) | Tablo | Endpoint | Hangi fazda |
|---|---|---|---|
| Auth | `users` | 4 | 2 |
| Invitation | `invitations`, `timeline_events` | 6 | 3 · 4 |
| RSVP | `rsvps` | 3 | 5 |
| Media | `media` | 2 | 6 |
| Payment | `orders` | 2 | 7 |
| Assistant | — | 1 | 8 |
| Contact | `contact_messages` | 1 | 8 |

> `personal_access_tokens` (Sanctum) ve Laravel'in `sessions`/`cache`/`jobs`
> tabloları bu sayıya dahil değildir — kurulumla birlikte geldi.

## 2. Faz tablosu

| Faz | Konu | Frontend'de ne çalışır | ~Dosya | Durum |
|---|---|---|:---:|:---:|
| **0** | Zemin: PostgreSQL + kalite araçları + hata sözleşmesi | — | 6 | ✅ |
| **1** | İlk endpoint + `ForceJsonResponse` + **hata zarfı (K20)** | — | 8 | ✅ |
| **2** | **Auth özellik dilimi** 🎯 walking skeleton | **Giriş / kayıt** ✅ | 10 → **17** | ✅ |
| **3** | **Invitation CRUD + Policy + Resource ailesi** | **Dashboard + editör autosave** ✅ | 12 → **12 + 8 FE** | ✅ |
| **4** | Public davetiye + cache + ETag 🔥 | `/invite/{slug}` sayfası | 6 | ⬜ **SIRADAKİ** |
| **5** | RSVP (public submit + owner list) | LCV gönderimi + canlı panel | 10 | ⬜ |
| **6** | Media + Job | Galeri yüklemesi | 7 | ⬜ |
| **7** | `TierResolver` + Payment + publish 🔴 | Yayınlama + paywall | 12 | ⬜ |
| **8** | AI proxy + Contact | Asistan, iletişim formu | 6 | ⬜ |
| **9** | Üretim hazırlığı | — | — | ⬜ |

## 3. Bağımlılık akışı

```
Faz 1 (hata zarfı) ─── sonraki 8 fazın hepsi buna yaslanır
   │
   └→ Faz 2 (Auth) ─── kimlik olmadan sahiplik yok
        │
        └→ Faz 3 (Invitation) ─── ana varlık, veri modelinin kalbi
             │
             ├→ Faz 4 (public okuma)
             │
             ├→ Faz 5 (RSVP) ──────┐
             │                     │ rsvps.photo_media_id FK'si
             ├→ Faz 6 (Media) ←────┘ Faz 6'da geri bağlanır
             │
             └→ Faz 7 (Paywall) ─── Faz 3'ün show_* kolonlarına bağımlı
                  │
                  └→ Faz 8 → Faz 9
```

🔴 **Kritik gözlem:** Faz 7 projenin ticari çekirdeğidir ama en sonda gelir ve
**Faz 3'ün veri modeli kararlarına bağımlıdır**. `show_*` alanları Faz 3'te ayrı
boolean kolon olarak açılmazsa, Faz 7'de paywall'ı SQL ile doğrulamak imkânsızlaşır
ve JSON'dan kolona taşıma bir veri migrasyonu gerektirir.

## 4. Fazlara dağılmış "ilk kez" listesi

Her faz bir kavramı **ilk kez** getirir. Öğrenme sırası budur:

| Faz | İlk kez görülen |
|---|---|
| 1 | İstek yaşam döngüsü · middleware · merkezi exception handling · enum |
| 2 | FormRequest → Action → Resource zinciri · Sanctum · factory · enumeration savunması |
| 3 | Migration · Eloquent ilişkileri · Policy (IDOR) · nested koleksiyon senkronizasyonu |
| 4 | Cache · cache invalidation · Event/Listener · ETag / koşullu istek |
| 5 | Rate limit · honeypot · özel exception → HTTP eşlemesi · Job/kuyruk · KVKK hash |
| 6 | Dosya güvenliği · disk soyutlaması (`Storage`) · şema evrimi (sonradan FK) |
| 7 | Strategy Pattern · Dependency Inversion · idempotans · race condition |
| 8 | Ports & Adapters ile dış servis · kotalı proxy · sağlayıcı hatasını yutma |
| 9 | Üretim yapılandırması · Redis · süpervizör · imza doğrulama |

## 5. Kalite kapısı takvimi

| Ne | Ne zaman |
|---|---|
| `composer check` yeşil | **Her dosyadan sonra** |
| PHPStan level 5 → **6** | Faz 2 sonunda |
| PHPStan level 6 → **8** | Faz 5 sonunda |
| `docs/rehber/fazlar/FAZ-N.md` özeti | Her faz sonunda |

## 6. Her fazda geçerli sabit kurallar

Tam liste `docs/rehber/fazlar/FAZ-0.md` §4'te (31 kural). En sık ihlal edilen 8'i:

| # | Kural |
|---|---|
| 1 | Rotalar `/api/...` — **`/api/v1/...` değil**. Versiyon namespace'te |
| 2 | Auth başarı yanıtı **zarfsız** `{user, token}`; diğerleri `{data: ...}` |
| 3 | Hata yanıtı `{error: {code, fields?, params?}}` — **metin yok** (K20) |
| 4 | Alan adları camelCase; dönüşüm **sadece** Resource katmanında |
| 5 | Modellerde `$guarded = []` **yasak** — sadece `$fillable` |
| 6 | Action'da `$request->all()` değil **`validated()`** |
| 7 | Kod içinde **`env()` çağrılmaz** — `config()` |
| 8 | Sahiplik yoksa **404**, 403 değil (H7) |

---

# BÖLÜM II — FAZ DETAYLARI

---

## FAZ 1 — İlk nefes + hata zarfı ✅ TAMAMLANDI

> Faz özeti ve kurulan kurallar: [`rehber/fazlar/FAZ-1.md`](rehber/fazlar/FAZ-1.md).
> Aşağıdaki plan tarihsel kayıt olarak duruyor; gerçekleşen 8 dosyadır
> (`ApiExceptionRenderer` ve `HealthController` plan dışı eklendi — K26, K30).

**Amaç (tek cümle):** Bir HTTP isteğinin Laravel içinde nereden girip nereden
çıktığını görmek ve K20 hata sözleşmesini **tek merkeze** kurmak.

**Neden hata zarfı bu kadar erken?** Çünkü merkezi bir yerde kurulmazsa her
controller kendi hata biçimini üretir. Faz 1'de yazılan exception handler,
sonraki 8 fazda **hiç tekrar edilmez** — Faz 5'in `RsvpQuotaExceededException`'ı
da, Faz 7'nin `PaywallViolationException`'ı da aynı kapıdan geçer.

### Dosyalar

| # | Dosya | İşi |
|---|---|---|
| 1.1 | `app/Enums/ErrorCode.php` | Kod kataloğu + `status()` HTTP eşlemesi + `allowedParams()` beyaz listesi |
| 1.2 | `app/Http/Middleware/ForceJsonResponse.php` | API her zaman JSON döner (HTML hata sayfası dönmesin) |
| 1.3 | `bootstrap/app.php` | Middleware kaydı + exception handler (zarf üretimi) |
| 1.4 | `routes/api.php` | `GET /api/ping` · varsayılan `/user` rotası temizlenir |
| 1.5 | `tests/Feature/HealthTest.php` | Ping + `RESOURCE_NOT_FOUND` + `APP_DEBUG=false` sızıntı testi |
| 1.6 | `app/Console/Commands/ExportErrorCodes.php` | `php artisan errors:export` → frontend çeviri senkronizasyonu |

**Sıralamanın mantığı:** Bağımlılık yönünde ilerlenir. 1.1 sözlüktür, kalan beş
dosyanın hepsi ona referans verir. 1.2 olmadan 1.3 JSON üretemez. 1.6 enum
tamamlanmadan anlamsızdır, o yüzden en sonda.

### Bitti ölçütü

```
http://localhost:8000/api/ping        → {"status":"ok"}
http://localhost:8000/api/olmayan     → {"error":{"code":"RESOURCE_NOT_FOUND"}}   (HTML DEĞİL)
composer check                        → yeşil
```

### Öğrenilecek

İstek yaşam döngüsü (`public/index.php` → bootstrap → router → middleware →
response) · Laravel 11+ ile `bootstrap/app.php`'nin `Kernel.php`'den devraldığı rol ·
merkezi exception handling · PHP 8 backed enum ile sihirli string'i yok etme.

### Açık kararlar

| # | Soru | Öneri |
|---|---|---|
| 1 | `bootstrap/app.php` zaten `shouldRenderJsonWhen()` içeriyor — `ForceJsonResponse` gerekli mi? | **Gerekli.** `shouldRenderJsonWhen` yalnızca exception render'ını etkiler; `Accept` başlığı olmadan `ValidationException` hâlâ redirect üretmeye çalışır ve `wantsJson()` false döner |
| 2 | `ErrorCode` tam katalog (~18 case) mı, minimal (5 case) mı? | **Tam katalog.** H5: "kod adı yayınlandıktan sonra sözleşmedir" — adları tek oturumda tutarlı düşünmek, parça parça eklerken isim tutarsızlığı üretmekten iyidir |
| 3 | Boş `app/Actions/Invitation/PublishInvitationAction.php` iskeleti | Karar bekliyor — silinip Faz 7'de `make:class` ile yeniden üretilebilir |

---

## FAZ 2 — Auth özellik dilimi 🎯

**Amaç:** Tüm katmanları **bir arada** çalışırken görmek. Bu bir walking
skeleton'dır; kalan 7 fazın tamamı bu kalıbın tekrarıdır. Öğrenme eğrisi bir kez
tırmanılır.

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

### Dosyalar

| # | Dosya | Katman |
|---|---|---|
| 2.1 | `app/Models/User.php` (düzenleme) | `$fillable`, `casts()`, `HasApiTokens` |
| 2.2 | `database/factories/UserFactory.php` | Test verisi |
| 2.3 | `app/Http/Resources/UserResource.php` | `full_name` → `fullName` |
| 2.4 | `app/Http/Requests/Auth/RegisterRequest.php` | Doğrulama |
| 2.5 | `app/Actions/Auth/RegisterUserAction.php` | İş kuralı |
| 2.6 | `app/Http/Controllers/Api/V1/AuthController.php` | Yönlendirme |
| 2.7 | `routes/api.php` (ekleme) | `/auth/register` |
| 2.8 | `LoginRequest` + `LoginUserAction` + rota | Giriş |
| 2.9 | `RevokeTokenAction` + `logout` + `me` | Token iptali, doğrulama |
| 2.10 | `tests/Feature/AuthTest.php` | Zarfsız sözleşme + enumeration testi |

### Endpoint'ler

| Method | Path | Auth | Yanıt |
|---|---|:---:|---|
| POST | `/api/auth/register` | — | 🔴 **zarfsız** `{user, token}` |
| POST | `/api/auth/login` | — | 🔴 **zarfsız** `{user, token}` |
| POST | `/api/auth/logout` | ✅ | Aktif token'ı siler |
| GET | `/api/auth/me` | ✅ | Token doğrulama |

### 🔴 Bu fazın iki kritik güvenlik işi

**1. Kullanıcı sayımı (enumeration) savunması.** Saldırgan 10.000 e-postalık
listeyi kayıt formuna girip hangilerinin "zaten kayıtlı" dediğine bakar — form
bir hesap tarayıcısına dönüşür. Savunma: `register` başarısızlığında
`REGISTRATION_FAILED`, `fields` **yok**. `login` başarısızlığında parola mı
kullanıcı mı ayrımı yapılmadan `INVALID_CREDENTIALS`.

**2. Zamanlama saldırısı savunması.** Kullanıcı bulunamazsa parola karşılaştırması
hiç çalışmaz → yanıt ~250 ms daha hızlı döner. Saldırgan bunu ölçerek e-postanın
kayıtlı olup olmadığını anlar. Savunma: kullanıcı bulunamasa bile **sahte bir
hash'e karşı** doğrulama yapılır.

### Bitti ölçütü

Frontend'i `npm run dev` ile açıp **gerçek hesapla giriş yapabilmek**. Token
localStorage'a düşüyor, sayfa yenilenince oturum korunuyor.

### Öğrenilecek

FormRequest ↔ Action ↔ Resource iş bölümü · `validated()` ile `all()` farkı ·
Sanctum token mimarisi · Argon2id'ye geçiş · 401 ile 403 ayrımı.

**Kalite kapısı:** PHPStan level 5 → **6**.

---

## FAZ 3 — Invitation CRUD ✅ TAMAMLANDI

> **Bitiş:** 19 Ağustos 2026 · Özet, kronoloji ve kurulan **15 kural**:
> [`rehber/fazlar/FAZ-3.md`](rehber/fazlar/FAZ-3.md)

**Amaç:** Sahiplik, yetkilendirme (IDOR) ve iç içe koleksiyon yönetimi. Projenin
en büyük fazı ve veri modelinin kalbi.

### Dosyalar

| # | Dosya | Not | Durum |
|---|---|---|:---:|
| 3.1 | `app/Enums/InvitationStatus.php` | ⚠️ **K38** — `draft` atıldı: `saved \| published` | ✅ |
| 3.2 | `..._create_invitations_table.php` | ⚠️ **K40** — ULID **PK**, ayrı slug yok · **K39** CHECK · **K41** `phone_background` yok | ✅ |
| 3.3 | `..._create_timeline_events_table.php` | `foreignUlid` · `sort_order` · CASCADE | ✅ |
| 3.4 | `app/Models/Invitation.php` | `#[Fillable]` özniteliği · `immutable_*` · `user_id` int cast | ✅ |
| 3.5 | `app/Models/TimelineEvent.php` | + `User::invitations()` | ✅ |
| **3.6** | `InvitationFactory` + **`TimelineEventFactory`** + `DatabaseSeeder` | 🆕 İkinci fabrika · seeder yeniden yazıldı | ✅ |
| 3.7 | `app/Policies/InvitationPolicy.php` | 🔴 IDOR savunması, reddi **404** | ✅ |
| **3.8** | `Requests/Invitation/` — **3 dosya** | 🆕 Soyut taban + iki alt sınıf (C3) | ✅ |
| 3.9 | `Resources/` — 3 dosya | ⚠️ `whenLoaded()` **kullanılmadı** (sapma) | ✅ |
| 3.10 | `Actions/Invitation/` — 3 dosya | Transaction + senkronizasyon | ✅ |
| 3.11 | `InvitationController` + rotalar | ⚠️ `authorizeResource` yerine `Gate::authorize()` (sapma) | ✅ |
| 3.12 | `tests/Feature/InvitationTest.php` | **18 test** | ✅ |
| — | Frontend uyarlaması | 🆕 **8 dosya** — K37 + K44'ün sonucu | ✅ |

### Endpoint'ler

| Method | Path | Açıklama | Durum |
|---|---|---|:---:|
| GET | `/api/invitations` | Kullanıcının tüm davetiyeleri | ✅ |
| POST | `/api/invitations` | Yeni taslak — `201` | ✅ |
| GET | `/api/invitations/{id}` | Sahibin okuması; başkasınınkinde **404** | ✅ |
| PUT | `/api/invitations/{id}` | Güncelle (debounce'lu autosave) | ✅ |
| DELETE | `/api/invitations/{id}` | Soft delete — `204` | ✅ |
| POST | `/api/invitations/{id}/publish` | ⚠️ **AÇILMADI — Faz 7'ye taşındı** | ⬜ |

⚠️ Plan bu rotanın Faz 3'te açılmasını, iş kuralının Faz 7'de yazılmasını
öngörüyordu. Açılmadı: çağıracak bir iş kuralı yokken sözleşmeye uç nokta
eklemek, tutulamayan bir söz olurdu (B4).

### 🔴 Hibrit veri modeli — uygulanan hâli

| Veri | Nerede | Durum |
|---|---|---|
| 6 × `show_*` | **Ayrı boolean kolon** | ✅ K6 uygulandı |
| `status`, `event_at`, `published_at`, `rsvp_deadline` | Kolon | ✅ |
| `title`, `subtitle`, `names`, `venue`, `map_url`, `iban` | Kolon — **hepsi nullable** | ✅ |
| `gift_options: number[]` | **`jsonb` kolon** | ✅ |
| `timeline_events[]` | **Ayrı tablo** | ✅ |
| `gallery_images[]` | Ayrı tablo (`media`) | ⬜ Faz 6 — Resource şimdilik `[]` döner |
| `phone_background` | ❌ **Kolon açılmadı** | ✅ K41 — `preset_id`'den türetilir |

**⚠️ `public_slug` kolonu YOK.** Plan ayrı bir slug kolonu öngörüyordu; **K40**
ile `id`'nin kendisi ULID yapıldı. Frontend zaten `/invite/{record.id}`
kullanıyordu — iki kimlik tutmak gereksiz karmaşıklıktı.

**İndeksler — uygulanan:** `INDEX(user_id, status)` → dashboard sorgusu ·
`INDEX(invitation_id, sort_order)` → timeline sıralaması ·
~~`UNIQUE(public_slug)`~~ → gereksiz, birincil anahtar zaten benzersiz.

**Neden içerik alanları nullable?** Autosave yarım veri gönderir: kullanıcı
başlığı silip yenisini yazmak için duraklarsa o boş hâl sunucuya gider.
Eksiksizlik kuralı **yayın anında** aranır (D3'ün aynı biçimi).

### 🔴 Kısıt neden yalnızca `status`'te?

`palette`, `category_id` ve `preset_id` de kapalı kümeler ama CHECK almadılar.
Ölçüt **sahiplik** (**E6**): `status` backend'in malı ve güvenlik sınırı
(Faz 4'ün public sorgusu ona bakacak). Diğer üçü frontend kataloğunun
anahtarları — kısıtlansaydı tasarımcının eklediği her yeni tema bir backend
deploy'u gerektirirdi.

### Bitti ölçütü — karşılandı ✅

Dashboard'da davetiye listesi gerçek veritabanından geliyor; editörde autosave
çalışıyor. Uçtan uca doğrulama:
[`rehber/fazlar/FAZ-3-ELLE-DOGRULAMA.md`](rehber/fazlar/FAZ-3-ELLE-DOGRULAMA.md) §11.

### Öğrenilen

Migration ve indeks stratejisi · Eloquent ilişkileri · mass assignment
güvenliği · Policy ile IDOR kapatma · iç içe koleksiyon senkronizasyonu ·
N+1 önleme · **sahipliğin bir `if` değil sorgunun kapsamı olduğu** ·
**çalıştırılmayan kodun doğru varsayıldığı**.

### Açık kararlar — kapandı ✅

| Soru | Karar |
|---|---|
| ~~Migration'da gerçek `ENUM` mü, `CHECK` kısıtı mı?~~ | **K39** — `VARCHAR + CHECK`; değerler enum'dan beslenir |
| ~~`InvitationStatus`'te `draft` durumu kalacak mı?~~ | **K38** — hayır; onu doğuran olay yok |

### Bu fazda doğan yeni kararlar

| # | Karar |
|---|---|
| **K37** | `/api/invitations` REST koleksiyonu (upsert değil) |
| **K40** | ULID birincil anahtar; `timeline_events.id` bigint kalır |
| **K41** | `phone_background` türetilir, saklanmaz |
| **K42** | Yayın hakkı iki kaynaktan, tek arayüzden sorulur (Faz 7) |
| **K43** | Plan kotası **yayınlananı** sayar, taslağı değil (Faz 7) |
| **K44** | Kimliği backend üretir; `id: null` = yeni satır |

---

## FAZ 4 — Public davetiye 🔥

**Amaç:** Sistemin en yüksek trafikli noktası. Davetiye linki WhatsApp grubuna
düşer, 500 kişi 2 dakikada açar — ama veri **neredeyse hiç değişmez**. Kitap gibi
bir okuma yükü.

### Dosyalar

| # | Dosya |
|---|---|
| 4.1 | `ResolvePublicInvitationAction` — slug → yayınlanmış davetiye |
| 4.2 | `PublicInvitationController` — auth'suz, cache'li |
| 4.3 | `routes/api.php` → `/api/public/invitations/{slug}` |
| 4.4 | `Events/InvitationPublished` + `Listeners/ClearInvitationCache` |
| 4.5 | ETag middleware veya controller içi `304` |
| 4.6 | `tests/Feature/PublicInvitationTest.php` — 🔴 taslak sızmıyor |

### İki katmanlı optimizasyon

```
1. katman — Cache::remember(...6 saat)   → veritabanına hiç gitme
2. katman — ETag / 304 Not Modified      → gövdeyi hiç gönderme
```

Cache temizleme `InvitationPublished` / `InvitationUpdated` event'leriyle yapılır —
TTL beklenmez, yayın anında geçersiz kılınır.

### `/api/public/` öneki neden var?

Auth gerektirmeyen rotaları tek grupta toplamak, `auth:sanctum` middleware'ini
yanlışlıkla unutma riskini **yapısal olarak** kaldırır. Varsayılan kapalı, istisna
açıkça işaretli — bir *fail-safe* tasarımıdır (K12).

### Bitti ölçütü

`/invite/{slug}` sayfası gerçek backend'den yükleniyor; ikinci istek
`304 Not Modified` dönüyor. Yayınlanmamış davetiye **sızmıyor**.

### Öğrenilecek

Okuma-ağırlıklı yük · cache invalidation stratejileri · ETag ve koşullu istek ·
Event/Listener ile modüller arası gevşek bağ.

---

## FAZ 5 — RSVP

**Amaç:** **Auth'suz yazma yolu** — sistemin en çok saldırıya açık noktası.
Katmanlı savunma (defense in depth) burada öğrenilir.

### Dosyalar

| # | Dosya |
|---|---|
| 5.1 | `app/Enums/RsvpStatus.php` — DB İngilizce, çeviri frontend'de (K21) |
| 5.2 | `..._create_rsvps_table.php` — `ip_hash`, `INDEX(invitation_id, status)` |
| 5.3 | `app/Models/Rsvp.php` |
| 5.4 | `StoreRsvpRequest` — honeypot alanı |
| 5.5 | `app/Exceptions/RsvpQuotaExceededException.php` |
| 5.6 | `SubmitRsvpAction` — 🔴 deadline + kota + IP hash |
| 5.7 | `RsvpResource` + `RsvpController` (public submit + owner list) |
| 5.8 | Rate limit kaydı (`bootstrap/app.php`) |
| 5.9 | `Jobs/SendRsvpNotification` |
| 5.10 | `tests/Feature/RsvpTest.php` — 🔴 kota `SUM(guest_count)` ile |

### Endpoint'ler

| Method | Path | Auth |
|---|---|:---:|
| POST | `/api/public/invitations/{slug}/rsvps` | — |
| GET | `/api/invitations/{id}/rsvps` | ✅ (ETag'li polling) |
| DELETE | `/api/rsvps/{id}` | ✅ |

### 🔴 Katmanlı savunma

| Katman | Önlem |
|---|---|
| Rate limit | IP başına 10/dakika, davetiye başına 60/saat |
| Honeypot | Formda gizli alan; doluysa bot → sessizce 200 dön, **kaydetme** |
| İş kuralı | `rsvp_deadline` geçti mi · `show_rsvp` açık mı · `status = published` mi |
| Kota | 🔴 `SUM(guest_count) < limit` |
| Dosya | MIME içerikten doğrula, boyut limiti, rastgele isim |
| Veri | `ip_hash = hash(ip + app_key)` — ham IP saklanmaz (KVKK) |

**`guest_count` detayı — neden `COUNT(*)` değil:** `LiveRsvpPanel` toplamları
`reduce((s, r) => s + r.guestCount, 0)` ile hesaplıyor. Backend kotasını `COUNT(*)`
ile kurarsak 100 kayıt × 4 kişi = **400 misafir** geçer. Aynı metriği kullanmak
zorunludur.

**`params` beyaz listesi:** Kota hatasında `remaining` ve `limit` **yalnızca
davetiye sahibine** verilir. Anonim misafir kota durumunu bilmemeli (H9).

### Bitti ölçütü

Misafir LCV gönderiyor; sahip panelde 15 saniyede bir güncellenen listeyi görüyor.

### Öğrenilecek

Katmanlı savunma · rate limiting · honeypot · KVKK veri minimizasyonu · özel
exception → HTTP kodu eşlemesi · kuyruk.

**Kalite kapısı:** PHPStan level 6 → **8**.

---

## FAZ 6 — Media

**Amaç:** Dosya kabul etmenin güvenlik yükü ve 15 saniye kuralı.

### Dosyalar

| # | Dosya |
|---|---|
| 6.1 | `app/Enums/MediaKind.php` — `gallery \| rsvp_photo \| rsvp_video` |
| 6.2 | `..._create_media_table.php` |
| 6.3 | `app/Models/Media.php` |
| 6.4 | `StoreUploadedMediaAction` — MIME içerikten doğrulama, rastgele ad |
| 6.5 | `MediaController` |
| 6.6 | `Jobs/OptimizeUploadedImage` |
| 6.7 | `tests/Feature/MediaTest.php` |
| 6.8 | `..._add_media_foreign_keys_to_rsvps_table.php` — Faz 5'in askıda kalan FK'si |

### Endpoint'ler

| Method | Path | Not |
|---|---|---|
| POST | `/api/media/upload` | ⚠️ Plan `/api/media` diyordu; **frontend kazanır**, o böyle çağırıyor. Yanıt `{url}` |
| POST | `/api/public/media` | LCV foto/video — sıkı limitli |

### 🔴 Dosya güvenliği kuralları

| Kural | Sebep |
|---|---|
| MIME **içerikten** doğrulanır | Uzantı kullanıcı girdisidir; `.jpg` adlı PHP dosyası yüklenebilir |
| Dosya adı **rastgele** üretilir | Orijinal ad path traversal veya üzerine yazma taşıyabilir |
| Yüklenenler **çalıştırılabilir dizinde durmaz** | Yüklenen kodun sunucuda çalışmasını yapısal olarak engeller |
| Optimizasyon **kuyruğa** gider | `api.ts` timeout'u 15 saniye |

### Şema evrimi notu

Faz 5'te `rsvps.photo_media_id` kolonu **nullable ve kısıtsız** açılmıştı, çünkü
`media` tablosu henüz yoktu. Kısıt burada eklenir:

```php
Schema::table('rsvps', function (Blueprint $table) {
    $table->foreign('photo_media_id')->references('id')->on('media')->nullOnDelete();
});
```

Bu bir kirlilik değildir; şema zamanla evrilir, her migration bir adımdır.

### Bitti ölçütü

Editörden galeri fotoğrafı yükleniyor, önizlemede görünüyor.

---

## FAZ 7 — Ödeme ve paywall 🔴

**Amaç:** Projenin **ticari çekirdeği**. Faz 0'da yazılan `SubscriptionTier`
enum'u nihayet burada kullanılır.

### Dosyalar

| # | Dosya | Not |
|---|---|---|
| 7.1 | `app/Enums/OrderStatus.php` | `pending \| paid \| failed \| refunded` |
| 7.2 | `..._create_orders_table.php` | 🔴 `provider_ref` **UNIQUE** |
| 7.3 | `app/Models/Order.php` | `tier` cast'i → `SubscriptionTier` |
| 7.4 | `app/Services/Payment/PaymentGateway.php` | interface — Strategy Pattern |
| 7.5 | `app/Services/Payment/FakeGateway.php` | Sağlayıcı anlaşması beklenmez |
| 7.6 | `AppServiceProvider` | Arayüz → sürücü bağlama |
| 7.7 | `app/Services/Pricing/TierResolver.php` | 🔴 `getRequiredTier()`'ın sunucu ikizi |
| 7.8 | `app/Exceptions/PaywallViolationException.php` | → 402 + `requiredTier` |
| 7.9 | `StartCheckoutAction` + `HandlePaymentCallbackAction` | |
| 7.10 | `PublishInvitationAction` | Policy → tier → order → yayınla |
| 7.11 | `PaymentController` + webhook rotası | |
| 7.12 | `tests/Feature/PaywallTest.php` | 🔴 Yetersiz plan reddedilir, webhook idempotan |

### Endpoint'ler

| Method | Path | Auth | Not |
|---|---|:---:|---|
| POST | `/api/payments/checkout` | ✅ | Order oluştur → ödeme URL'i |
| POST | `/api/payments/webhook` | — | 🔒 İmza doğrulamalı, CSRF muaf |

### `PublishInvitationAction` akışı

```
1. Policy: bu davetiye bu kullanıcının mı?          → değilse 404 (403 değil — H7)
2. TierResolver::requiredFor($invitation)            → SUNUCUDA hesapla
3. Kullanıcının paid order'ı bu tier'ı kapsıyor mu?  → değilse 402 PAYWALL_TIER_INSUFFICIENT
4. public_slug üret, status = published, published_at = now()
5. InvitationPublished event → cache temizle
```

🔴 **İstemciden gelen `tier` bilgisi asla kullanılmaz.** Frontend'in `activeTier`'ı
yalnızca arayüz kararıdır ve DevTools'tan değiştirilebilir.

### Neden `provider_ref` UNIQUE?

Ödeme sağlayıcıları webhook'u **birden fazla kez** gönderir (ağ hatası, retry).
UNIQUE kısıt, aynı ödemenin iki kez işlenmesini **veritabanı seviyesinde** imkânsız
kılar. Uygulama kodundaki `if (already_processed)` kontrolü eşzamanlı iki webhook'ta
yarış koşuluna girer; veritabanı kısıtı girmez.

### Bitti ölçütü

Standart planla galeri açık davetiye yayınlanamıyor (**402**); sahte ödeme sonrası
yayınlanabiliyor. Aynı webhook iki kez gelince **tek** order.

### Öğrenilecek

Strategy Pattern · Dependency Inversion · idempotans · veritabanı kısıtıyla race
condition önleme.

---

## FAZ 8 — AI asistan ve iletişim

### Dosyalar

| # | Dosya |
|---|---|
| 8.1 | `app/Services/Ai/AiProvider.php` (interface) + `GeminiProvider` + `NullProvider` |
| 8.2 | `AssistantController` — kotalı proxy |
| 8.3 | `app/Enums/ContactSubject.php` |
| 8.4 | `..._create_contact_messages_table.php` + model |
| 8.5 | `ContactRequest` + `ContactController` |

### Endpoint'ler

| Method | Path | Auth |
|---|---|:---:|
| POST | `/api/assistant/chat` | ✅ (kotalı) |
| POST | `/api/contact` | — |

### Sır yönetimi

`GEMINI_API_KEY` **yalnızca** `GeminiProvider` içinde görünür:
`.env` → `config/ai.php` → Service. Frontend'e sızma yolu mimari olarak yoktur —
Vite yalnızca `VITE_` önekli değişkenleri paketler.

Sağlayıcının ham hatası dışarı çıkmaz: log'a gider, dışarıya
`PROVIDER_UNAVAILABLE` (503) döner (H8).

**`NullProvider` neden var?** Testte ve API anahtarı olmayan ortamda gerçek
Gemini çağrısı yapılmamalıdır. Null Object Pattern, `if ($provider !== null)`
kontrollerini gereksizleştirir.

### ❌ Bu fazdan çıkarılan

`SetLocaleFromHeader` middleware'i **iptal edildi** (K21). Backend tek dil konuşur;
`Accept-Language` okunmaz.

### Bitti ölçütü

Asistan sohbeti gerçek yanıt veriyor; iletişim formu kaydediyor.

---

## FAZ 9 — Üretim hazırlığı

| # | İş |
|---|---|
| 9.1 | Üretim PostgreSQL kurulumu, yedekleme, bağlantı havuzu (PgBouncer) |
| 9.2 | `APP_DEBUG=false` · `config:cache` · `route:cache` · `view:cache` |
| 9.3 | Redis'e geçiş (cache + queue) + `queue:work` süpervizörü |
| 9.4 | Gerçek ödeme sağlayıcısı (`IyzicoGateway`) + imza doğrulaması |
| 9.5 | S3 uyumlu depolama + `storage:link` |
| 9.6 | HTTPS · CORS · güvenlik başlıkları |
| 9.7 | Yedekleme ve log rotasyonu |

> 🔴 `config:cache` sonrası `env()` çağrıları **`null` döner**. Y1 kuralına
> (kod içinde `env()` çağrılmaz) uyulmadıysa hata **ilk kez burada** ortaya çıkar
> ve nedeni bulunması zordur. Bu, Faz 0'da konan kuralın ödemesinin alındığı andır.

---

## Ek — Bilinen frontend uyuşmazlıkları

| Konu | Plan diyor | Frontend gerçekte | Karar |
|---|---|---|---|
| Medya yükleme rotası | `POST /api/media` | `POST /media/upload`, yanıt `{url}` | **Frontend kazanır** — Faz 6 |
| `InvitationStatus` | `draft \| saved \| published` | `'published' \| 'saved'` | Faz 3'te çözülecek |
| `RsvpStatus` değerleri | `attending \| pending \| declined` | `'Katılıyor' \| 'Bekleniyor' \| 'Katılamıyor'` | DB İngilizce, çeviri frontend'de (K21) |
| Hata gösterimi | — | `errors.*` çeviri anahtarları yok | `Notlar/03` — frontend yapacak |
| CORS | — | Vite proxy sayesinde same-origin | Faz 9'a kadar gereksiz |

---

## Ek — Bu dokümandaki düzeltmeler

Kaynak dokümanlardaki eskimiş satırlar burada güncel hâliyle yazıldı:

| Nerede | Eski | Doğrusu |
|---|---|---|
| `07` §2.3 | Pest test framework'ü | **PHPUnit** (K24) |
| `03` §0 | Veritabanı MySQL 8 | **PostgreSQL 18** (K9') |
| `03` §4.5 | Hata formatı `{message, errors}` | **`{error: {code, fields?, params?}}`** (K20) |
| `03` §7 | Testte SQLite in-memory | **PostgreSQL `davetkart_test`** (K19) |
| `03` §8 | 12 adımlık katman-katman inşa | **9 faz, özellik-özellik** (K17) |
| `03` §5.1 | Sahiplik reddi → 403 | **404** (H7) |
| `07` Faz 8 | `SetLocaleFromHeader` | **İptal** (K21) |

---

## Bağlantılar

| İlgili | Nerede |
|---|---|
| Güncel plan (kaynak) | `docs/07-GELISTIRME-YOL-HARITASI.md` |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
| 31 kural | `docs/rehber/fazlar/FAZ-0.md` §4 |
| Veri modeli detayı | `docs/03-MIMARI-PLAN.md` §3 |
| Dosya yerleşimi | `docs/05-KLASOR-VE-DOSYA-REFERANSI.md` |
| Kod standartları | `CLAUDE.md` |
| Proje devir dosyası | `claude/PHP-LARAVEL-SETUP.md` |
