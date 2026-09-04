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
| **4** | Public davetiye + cache + ETag 🔥 | `/invite/{id}` sayfası ✅ | 6 → **8 + 2 FE** | ✅ |
| **5** | RSVP (public submit + owner list) | LCV gönderimi + canlı panel ⬜ | 10 → **16** | ⚠️ 17/17 adım ✅ · **doğrulama bekliyor** |
| **6** | Media + Job | Galeri yüklemesi | 7 | ⬜ **SIRADAKİ** |
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
| PHPStan level 6 → **8** | Faz 5 sonunda ✅ (5.14 · ⚠️ doğrulanmadı) |
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
| 3 | ~~Boş `app/Actions/Invitation/PublishInvitationAction.php` iskeleti~~ | ✅ **Faz 7'de dolduruldu.** Silinmedi — üç faz boyunca "burada bir şey olacak" diye durdu ve kimseyi yanıltmadı (K47) |

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
| POST | `/api/invitations/{id}/publish` | ✅ **Faz 7'de AÇILDI** — paywall kapısı | ✅ |

⚠️ Plan bu rotanın Faz 3'te açılmasını, iş kuralının Faz 7'de yazılmasını
öngörüyordu. **Açılmadı** — çağıracak bir iş kuralı yoktu ve boş bir uç nokta
sözleşmede yalan bir söz olurdu (B4). Gerekçe **K47** olarak kaydedildi:
*"şimdi yazılırsa paywall'sız bir bedava yayın yolu açılır."*

✅ **Faz 7'de açıldı** (3 Eylül 2026) — kapıyı kilitleyecek anahtarlar
(`TierResolver`, `PublishEntitlementResolver`) ancak o gün vardı.
**Ders:** bir rotayı erken açmak, onu korumasız açmaktır.

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
| **K42** | Yayın hakkı iki kaynaktan, tek arayüzden sorulur — ✅ **Faz 7'de uygulandı** (`PublishEntitlementResolver`) |
| **K43** | Plan kotası **yayınlananı** sayar, taslağı değil — ⚠️ **Faz 7'de UYGULANMADI**: paket alımın kaç yayın açtığı hâlâ sınırsız (açık ticari karar, `FAZ-7.md` §9) |
| **K44** | Kimliği backend üretir; `id: null` = yeni satır |

---

## FAZ 4 — Public davetiye 🔥 ✅ **TAMAMLANDI** (27 Ağustos 2026)

**Amaç:** Sistemin en yüksek trafikli noktası. Davetiye linki WhatsApp grubuna
düşer, 500 kişi 2 dakikada açar — ama veri **neredeyse hiç değişmez**. Kitap gibi
bir okuma yükü.

### Dosyalar — planlanan 6, gerçekleşen 8 backend + 2 frontend

| # | Dosya | Durum |
|---|---|---|
| 4.1 | `ResolvePublicInvitationAction` — id → **yalnızca yayınlanmış** davetiye | ✅ |
| 4.2a | `PublicTimelineEventResource` — misafire `id` gitmez | ✅ **planda yoktu** |
| 4.2b | `PublicInvitationResource` — kapalı modülün verisi gövdeye girmez (C6) | ✅ |
| 4.3 | `PublicInvitationController` — auth'suz, cache'li | ✅ |
| 4.4 | `routes/api.php` → `/api/public/invitations/{id}` | ✅ |
| 4.5 | `Http/Middleware/SetEtag.php` | ✅ **middleware seçildi** (K46) |
| 4.6 | `Events/InvitationChanged` + `Listeners/ClearInvitationCache` | ✅ **ad değişti** (K48) |
| 4.7 | `tests/Feature/PublicInvitationTest.php` | ✅ **25 test** |
| 4.8 | Frontend: `publicInvitation.ts` + `InvitePage.tsx` | ✅ |

### İki katmanlı optimizasyon — uygulandı

```
1. katman — Cache::remember(...6 saat)   → veritabanına hiç gitme      ✅
2. katman — ETag / 304 Not Modified      → gövdeyi hiç gönderme        ✅
```

🔴 **Ama cache Action'ın İÇİNDE değil (K45):** Action saf bir okuma olarak
kaldı, cache controller'da ve **Resource çıktısı olan dizi** üzerinde çalışıyor.
Sebep: cache'te serileşmiş bir Eloquent modeli tutmak, model şeması değişince
bayat bir nesne canlandırır; dizi ise neyse odur. Ayrıca ETag aynı diziden
hesaplanabiliyor.

Cache temizleme **`InvitationChanged`** olayıyla yapılıyor (plandaki
`InvitationPublished` **değil** — yayın akışı Faz 7'de, o olayı bugün fırlatan
kod yok). Olay modelden **yapısal** olarak fırlıyor (`$dispatchesEvents`:
`updated`, `deleted`, `restored`), yani yeni bir yazma yolu eklendiğinde
kimsenin bir şey hatırlaması gerekmiyor.

🔴 **TTL bir tazelik garantisi değil, üst sınırdır (O3).** Tazeliği olay
sağlar; TTL yalnızca olayın kaçırıldığı durumlar (ham SQL, `event:cache` bayat)
için emniyet kemeridir.

### `/api/public/` öneki neden var?

Auth gerektirmeyen rotaları tek grupta toplamak, `auth:sanctum` middleware'ini
yanlışlıkla unutma riskini **yapısal olarak** kaldırır. Varsayılan kapalı, istisna
açıkça işaretli — bir *fail-safe* tasarımıdır (K12). ✅ Uygulandı.

### 🔴 Kapalı modülün verisi gönderilmez (C6)

Fazın sözleşme kararı: `show_gift = false` iken `iban`, `bankName`,
`accountHolder`, `giftOptions` gövdeye **hiç girmez** — boş string olarak değil,
**anahtar olarak da** yok. Aynı kural kapalı olan her modüle uygulandı.

Sebep: kullanıcı hediye modülünü açıp IBAN'ını girip sonra kapatabilir. Modül
kapalıysa ekranda hiçbir şey görünmez — ama veri gövdedeyse DevTools açan
misafir onu okur. **Ekranda görünmemek ile gönderilmemek farklı şeylerdir.**

### Bitti ölçütü ✅

`/invite/{id}` sayfası gerçek backend'den yükleniyor; `If-None-Match` ile ikinci
istek `304 Not Modified` dönüyor. Yayınlanmamış, silinmiş ve hiç var olmayan
davetiye **ayırt edilemez** biçimde 404 dönüyor.

### Öğrenilen

Okuma-ağırlıklı yük · iki katmanlı optimizasyon · cache invalidation · ETag ve
koşullu istek (RFC 7232) · `/api/public/` fail-safe grubu · Event/Listener ile
gevşek bağ · **bir savunmanın neyi kapatmadığını yazmak** (B6).

### ⚠️ Faz 5'e devredilen borçlar

| Konu | Not |
|---|---|
| Genel API hız sınırı yok | Public uçta 404'ler cache'lenmiyor; rastgele ULID yağdıran biri her istekte bir sorgu açtırıyor → **5.8** |
| `event_at` saat dilimi | Duvar saati saklanıyor; geri sayım başka saat diliminde kayıyor. Doğru çözüm `invitations.timezone` kolonu + iki alan |
| Cache invalidation uçtan uca test edilemiyor | `RefreshDatabase` rollback ediyor; kanıt `FAZ-4-ELLE-DOGRULAMA.md` **adım 12** |

**Ayrıntı:** `docs/rehber/fazlar/FAZ-4.md` (kronoloji, K45-K48, 11 kural,
dersler 34-41, Faz 3'te bulunan üç kusur).

---

## FAZ 5 — RSVP ⚠️ KOD YAZILDI, DOĞRULANMADI

> **Fazın kaydı:** [`rehber/fazlar/FAZ-5.md`](rehber/fazlar/FAZ-5.md)
> **Kapanış ölçütü:** [`rehber/fazlar/FAZ-5-ELLE-DOGRULAMA.md`](rehber/fazlar/FAZ-5-ELLE-DOGRULAMA.md)
>
> 🔴 `composer check` hiç koşmadı. Aşağıdaki plan **uygulandı**, ama üç sapmayla:
>
> | Plandaki | Olan |
> |---|---|
> | 5.1 `label()` Türkçe | **Yazılmadı** — K21 o notu geçersiz kılmıştı |
> | 5.5 tek exception | \+ `RsvpDeadlinePassedException` \+ `HasErrorCode` arayüzü |
> | 5.7 tek `RsvpController` | İki controller (public + owner) |
> | (plan dışı) | `RsvpQuotaResolver` arayüzü — K51 |
> | 🔴 5.9 `Jobs/SendRsvpNotification` | **YAZILMADI** — K53, gerekçe `FAZ-5.md` §7 |
>
> **17 geliştirme adımının tamamı ✅ commit'lendi** (`faz-5` dalı).
> Kalan tek iş **doğrulama**: `composer check` + 16 adımlık elle doğrulama.

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

## FAZ 6 — Media ⚠️ KOD YAZILDI (24/24), DOĞRULAMA BEKLİYOR

**Amaç:** Dosya kabul etmenin güvenlik yükü ve 15 saniye kuralı.

> 🔴 **Durum (29 Ağustos 2026):** 24 geliştirme adımı tamamlandı ve commit'lendi.
> 6.1–6.14 arası `composer check` ile **doğrulandı**; 6.15–6.24 arası PHP'siz
> bir ortamda yazıldı ve **hiç koşmadı**. Kapanış ölçütü:
> `docs/rehber/fazlar/FAZ-6-ELLE-DOGRULAMA.md` (18 adım).
> Faz kaydı: `docs/rehber/fazlar/FAZ-6.md`.

### 🔴 Plan 8 adımdı, 24 oldu — neden?

Orijinal plan **yalnızca sahibin galerisini** hesaba katıyordu. İki şey
eksikti:

1. **Misafirin LCV foto/videosu.** `MediaKind` üç tür tanımlıyordu ama ikisinin
   ucu yoktu. Yazılmasaydı `StorePublicMediaRequest`,
   `MediaQuotaExceededException::forGuest()` ve `guestUploadableValues()` ölü
   kod olarak kalırdı (ders 26).
2. **LCV'ye bağlama.** `rsvps` medya kolonlarının bir **yazanı** ve bir
   **okuyanı** olmadan açılması, Faz 5'te o kolonları açmama gerekçemizin
   aynısına düşmek olurdu.

### Dosyalar (gerçekleşen)

| # | Dosya | Durum |
|---|---|---|
| 6.1 | `app/Enums/MediaKind.php` | ✅ |
| 6.2 | `..._create_media_table.php` | ✅ |
| 6.3 | `app/Models/Media.php` | ✅ |
| 6.4 | `database/factories/MediaFactory.php` | ✅ |
| 6.5 | `Exceptions/MediaQuotaExceededException.php` + `ErrorCode` | ✅ |
| 6.6 | `Requests/Media/{MediaRequest, StoreMediaRequest, StorePublicMediaRequest}.php` | ✅ |
| 6.7 | `app/Jobs/OptimizeUploadedImage.php` | ✅ |
| 6.8 | `Actions/Media/StoreUploadedMediaAction.php` | ✅ |
| 6.9 | Eksik kılavuz + kalite kapısı düzeltmeleri | ✅ |
| 6.10 | `app/Http/Resources/MediaResource.php` | ✅ |
| 6.11 | `Controllers/Api/V1/MediaController.php` | ✅ |
| 6.12 | `Actions/Rsvp/ResolveOpenRsvpInvitationAction.php` 🆕 | ✅ |
| 6.13 | `SubmitRsvpAction` refactor (üç kontrol devredildi) | ✅ |
| 6.14 | `Actions/Media/StoreGuestMediaAction.php` 🆕 | ✅ |
| 6.15 | `Controllers/Api/V1/PublicMediaController.php` 🆕 | ✅ |
| 6.16 | `routes/api.php` + `throttle:media` limiter | ✅ |
| 6.17 | `..._add_media_columns_to_rsvps_table.php` | ✅ |
| 6.18 | `Rsvp` modeli: `photoMedia()` / `videoMedia()` | ✅ |
| 6.19 | `StoreRsvpRequest`: `photoMediaId` / `videoMediaId` | ✅ |
| 6.20 | `SubmitRsvpAction`: 🔴 medya **sahiplik doğrulaması** | ✅ |
| 6.21 | `RsvpResource`: `photoUrl` / `videoUrl` + eager loading | ✅ |
| 6.22 | `tests/Feature/MediaTest.php` — **28 test** + 20 satırlık mutasyon tablosu | ✅ |
| 6.23 | `docs/rehber/fazlar/FAZ-6.md` | ✅ |
| 6.24 | `docs/rehber/fazlar/FAZ-6-ELLE-DOGRULAMA.md` | ✅ |

### Endpoint'ler (gerçekleşen)

| Method | Path | Auth | Not |
|---|---|:---:|---|
| POST | `/api/invitations/{invitation}/media` | ✅ | Sahibin galerisi · yalnızca `gallery` |
| POST | `/api/public/invitations/{invitation}/media` | — | Misafirin LCV medyası · `throttle:media` |

> ⚠️ **ESKİ PLAN GEÇERSİZ.** Plan `POST /api/media/upload` diyordu ve gerekçesi
> *"frontend böyle çağırıyor"*du. O not misafir yüklemesini hesaba katmadan
> yazılmıştı: düz bir uçta davetiye kimliği **gövdeden** gelirdi, yani
> istemcinin sözüne kalırdı (**N1**). İç içe kaynakta aidiyet URL'nin
> **yapısında** durur ve `whereUlid()` biçimsiz kimliği veritabanına hiç
> ulaştırmaz (**O6**).
>
> `POST /api/public/media` de geçersiz — aynı gerekçe.
>
> 🔴 Sonuç: `davetkart-frontent/src/services/media.ts` uyarlanacak.
> Frontend listesi: `FAZ-6.md` §8.

### Yanıt sözleşmesi

```json
{ "data": { "id": "01k3n8…q7", "url": "http://localhost:8000/storage/media/gallery/aB3x…q7.jpg" } }
```

Plan `{url}` diyordu; `id` eklendi (**süperset**, frontend kırılmaz). Kimlik
olmadan misafir yüklediği dosyayı LCV'ye bağlayamaz.

### 🔴 Dosya güvenliği kuralları (hepsi uygulandı)

| Kural | Nerede | Serisi |
|---|---|---|
| MIME **içerikten** doğrulanır | `MediaRequest` → `mimetypes:` | **F1** |
| Dosya adı **rastgele** üretilir | `store()` → `hashName()` | **F2** |
| Diske yazma **transaction dışıdır**, elle telafi edilir | `StoreUploadedMediaAction` | **F3** |
| Depolama konumu **satırda** saklanır | `media.disk` | **F4** |
| Sözleşme URL taşır, şema **kimlik** tutar | `MediaResource`, `RsvpResource` | **F5** |
| Optimizasyon **kuyruğa** gider | `OptimizeUploadedImage` | 15 sn kuralı |

⚠️ *"Yüklenenler çalıştırılabilir dizinde durmaz"* kuralı **bugün karşılanmıyor**:
`disk = public` ve `storage:link` dosyaları web kökü altına koyuyor. Bugünkü
savunma MIME beyaz listesi — yani **kurala bağlı, yapısal değil**. Karar **K55**
(kapsam), borç Faz 9'a yazıldı.

### Şema evrimi notu — çözüldü

Faz 5 medya kolonlarını hiç açmadı (ders 26). 6.17 kolonları **ve** FK'leri
birlikte ekledi; 6.19–6.21 aynı fazda **yazanını ve okuyanını** getirdi.

FK'ler `nullOnDelete` (**K60**): dosya silinince LCV yanıtı **silinmemeli**.

### Bitti ölçütü

Editörden galeri fotoğrafı yükleniyor, önizlemede görünüyor **ve** misafir
LCV formuna fotoğraf ekleyip gönderebiliyor.

⚠️ İkincisi frontend uyarlaması olmadan çalışmaz (`FAZ-6.md` §8).

---

## FAZ 7 — Ödeme ve paywall 🔴 ⚠️ KOD TAMAMLANDI · DOĞRULAMA BEKLİYOR

> 🔴 **DURUM (3 Eylül 2026):** 25/25 adım yazıldı ve commit'lendi; `composer
> check` **hiç koşmadı**. Kapanış ölçütü:
> `docs/rehber/fazlar/FAZ-7-ELLE-DOGRULAMA.md` (20 adım).
> Tam kayıt, 10 kural ve 8 karar: `docs/rehber/fazlar/FAZ-7.md`.

**Amaç:** Projenin **ticari çekirdeği**. Faz 0'da yazılan `SubscriptionTier`
enum'u nihayet burada kullanıldı — `covers()`, `rank()`, `price()` ve
`rsvpLimit()` metotlarının ilk gerçek çağıranları bu fazda doğdu.

### Gerçekleşen dosyalar (25 adım)

| # | Dosya | Not |
|---|---|---|
| 7.1 | `app/Enums/OrderStatus.php` | `pending \| paid \| failed \| refunded` + **durum makinesi** |
| 7.2 | `..._create_orders_table.php` | 🔴 `provider_ref` UNIQUE + 4 CHECK · `invitation_id` nullable (K42) |
| 7.3 | `app/Models/Order.php` | Boş `#[Fillable]` · `tier`/`status` cast · `scopeGrantingPublishRight` |
| 7.4 | `database/factories/OrderFactory.php` | Tutar **plandan** türetilir |
| 7.5 | 4 exception + `ErrorCode` | `PaywallViolation` · `InvitationAlreadyPublished` · `InvalidWebhookSignature` · `PaymentProvider` — **hepsi `HasErrorCode`** |
| 7.6 | `Services/Payment/PaymentGateway.php` + `CheckoutSession` + `PaymentNotification` | Strategy Pattern (K8) |
| 7.7 | `Services/Payment/FakeGateway.php` + `AppServiceProvider` | 🔴 **Gerçek HMAC**, sahte para |
| 7.8 | `Services/Pricing/TierResolver.php` | `getRequiredTier()`'ın sunucu ikizi |
| 7.9 | `Contracts/PublishEntitlementResolver.php` + `OrderEntitlementResolver` | 🔴 **K42** — iki kaynak, tek arayüz |
| 7.10 | `Actions/Payment/StartCheckoutAction.php` + `CheckoutResult` | Sunucu fiyatı + F3 telafisi |
| 7.11 | `Actions/Payment/HandlePaymentCallbackAction.php` | 🔴 İdempotans (kilit + durum makinesi) |
| 7.12 | `Actions/Invitation/PublishInvitationAction.php` | 🔴 **Paywall kapısı** — Faz 3'ten beri boştu |
| 7.13 | `StoreCheckoutRequest` + `OrderResource` | Tek alan / üç alan |
| 7.14 | `PaymentController` + `PublicPaymentWebhookController` + `InvitationController::publish` | İki tehdit modeli |
| 7.15 | `routes/api.php` | 4 yeni uç |
| 7.16 | `Services/Rsvp/SubscriptionRsvpQuotaResolver.php` | 🔴 K51 dikiş yeri kapandı; `TierRsvpQuotaResolver` **silindi** |
| 7.17 | `..._add_timezone_to_invitations_table.php` + 6 dosya | 🔴 **K63** — Faz 4'ten beri üçüncü erteleme |
| 7.18 | (düzeltme) `'in:'` kuralı | **D6** ihlali önlendi |
| 7.19 | `tests/Feature/PaywallTest.php` | **33 test** + 33 satırlık mutasyon tablosu (T16) |
| 7.20–7.26 | Kılavuzlar · `CLAUDE.md` · `FAZ-7.md` · `FAZ-7-ELLE-DOGRULAMA.md` | K18 · Faz 6'nın B4 borcu |

### Endpoint'ler (gerçekleşen)

| Method | Path | Auth | Not |
|---|---|:---:|---|
| POST | `/api/invitations/{id}/publish` | ✅ | 🔴 Paywall kapısı · 200/402/409 |
| POST | `/api/invitations/{id}/checkout` | ✅ | **Tekil** alım (K42) |
| POST | `/api/payments/checkout` | ✅ | **Paket** alım (K42) |
| POST | `/api/public/payments/webhook` | — | 🔒 İmza doğrulamalı, CSRF **yapısal olarak** muaf |

⚠️ **Bu planın üç maddesi değişti** — üçü de **daha eski ve daha güçlü** bir
kararın uygulaması, keyfî sapma değil (`docs/09` Faz 3'ten önce yazılmıştı):

| Plan | Gerçek | Neden |
|---|---|---|
| Tek `POST /api/payments/checkout`, gövdede kimlik | İki uç, kimlik **URL'de** | **N1** — aidiyet gövdeden gelseydi istemcinin sözüne kalırdı. Faz 6 aynı kararı medya uçlarında zaten vermişti (**K64**) |
| `POST /api/payments/webhook` | `/api/public/payments/webhook` | **K12** — auth'suz her rota tek yerde, fail-safe (**K65**) |
| Akışta *"`public_slug` üret"* | Üretilmiyor | **K40** — `invitations.id` zaten ULID ve paylaşılan linkin kendisi (**K66**) |

### `PublishInvitationAction` akışı (gerçekleşen)

```
1. Policy: bu davetiye bu kullanıcının mı?          → değilse 404 (H7)
2. Satırı KİLİTLE ve yeniden oku                    → E9 (eşzamanlı iki yayın)
3. Zaten yayında mı?                                → 409 INVITATION_ALREADY_PUBLISHED
4. TierResolver::requiredFor()                      → SUNUCUDA hesapla (K6)
5. PublishEntitlementResolver::highestTierFor()     → K42: tekil + paket, tek arayüz
     ├─ null            → 402 PAYMENT_REQUIRED
     └─ !covers()       → 402 PAYWALL_TIER_INSUFFICIENT
6. status = published, published_at = now()
7. save() → InvitationChanged → ClearInvitationCache (K48 — Action hatırlamıyor)
```

🔴 **İstemciden gelen `tier` bilgisi hiçbir adımda kullanılmaz.**

### 🔴 `provider_ref` UNIQUE tek başına yetmiyor (M8 / B6)

Bu planın *"UNIQUE kısıtı idempotansın tek garantisi"* cümlesi **yarım
doğrudur**:

| Katman | Neyi imkânsız kılar | Neyi kılmaz |
|---|---|---|
| `provider_ref` UNIQUE | Aynı ödeme için **ikinci satır** | Var olan satırın iki kez **güncellenmesi** |
| `OrderStatus::canTransitionTo()` + `lockForUpdate()` | Bir satırın iki kez ilerlemesi | — |

İkisi **farklı yarışları** kapatır. Ayrıntı:
`docs/rehber/app/Enums/OrderStatus.md` §4.

### Bitti ölçütü

Standart planla galeri açık davetiye yayınlanamıyor (**402**); sahte ödeme
sonrası yayınlanabiliyor. Aynı webhook iki kez gelince **tek** order ve
`paid_at` **değişmiyor**.

### Öğrenilecek

Strategy Pattern · Dependency Inversion · idempotans · veritabanı kısıtıyla
race condition önleme · HMAC imza doğrulaması · para aritmetiği (minor unit) ·
duvar saati vs. `timestamptz`.

### 🔴 Açık ticari karar

**Paket alımın kaç yayın açtığı sınırlanmadı.** Bugünkü hâliyle tek bir 399 ₺'lik
paket **sınırsız** davetiye yayınlatır. Önerilen çözüm `orders.publish_quota`
(int) + `PublishInvitationAction`'da sayaç. K43 ancak o zaman tam uygulanmış
olur. Ayrıntı: `docs/rehber/fazlar/FAZ-7.md` §9.

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
