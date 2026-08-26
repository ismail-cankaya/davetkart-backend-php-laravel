# Faz 2 — Auth Özellik Dilimi

> **Durum:** ✅ Tamamlandı (backend **ve** frontend) · 6 Ağustos 2026
> **Yazılan/düzenlenen kod dosyası:** 17 backend + 7 frontend · **Kılavuz:** 17
> **Bitiş ölçütü:** 4 uç nokta çalışıyor · `composer check` yeşil (**22 test**) ·
> PHPStan **level 6** · frontend sözleşmeye uyumlu

---

## 1. Fazın amacı

**Tek cümle:** Tüm katmanları **bir arada** çalışırken görmek.

Bu bir **walking skeleton**'dır. Kalan 7 fazın tamamı bu kalıbın tekrarıdır:

```
rota → FormRequest → Controller → Action → Model → Resource → yanıt
```

Öğrenme eğrisi bir kez tırmanıldı. Faz 3'ün `InvitationController`'ı aynı
şekli taşıyacak; yeni olan yalnızca iş kuralı olacak.

### Neden auth ile başlandı?

Bağımlılık zorunluluğu: **kimlik olmadan sahiplik yoktur.** Faz 3'ün
`InvitationPolicy`'si "bu davetiye bu kullanıcının mı?" diye soracak — o soruyu
sorabilmek için önce bir kullanıcı gerekiyor.

### Öğrenme hedefleri

| Soru | Cevabın bulunduğu kılavuz |
|---|---|
| Migration nedir, neden PHP'de yazılır? | [`kavramlar/veritabani-ve-migration.md`](../kavramlar/veritabani-ve-migration.md) |
| Bir istek kod içinde nereden geçer? | [`kavramlar/istek-yasam-dongusu.md`](../kavramlar/istek-yasam-dongusu.md) |
| FormRequest ↔ Action ↔ Resource iş bölümü nedir? | [`RegisterRequest.md`](../app/Http/Requests/Auth/RegisterRequest.md) · [`RegisterUserAction.md`](../app/Actions/Auth/RegisterUserAction.md) |
| `validated()` ile `all()` farkı? | [`RegisterRequest.md`](../app/Http/Requests/Auth/RegisterRequest.md) §2.4 |
| Sanctum token mimarisi nasıl çalışır? | [`app/Models/User.md`](../app/Models/User.md) §3.5 · [`RevokeTokenAction.md`](../app/Actions/Auth/RevokeTokenAction.md) |
| Argon2id neden bcrypt'ten iyi? | [`config/hashing.md`](../config/hashing.md) §3 |
| Kullanıcı sayımı (enumeration) nasıl kapatılır? | [`RegisterRequest.md`](../app/Http/Requests/Auth/RegisterRequest.md) §3.1 |
| Zamanlama saldırısı nedir? | [`LoginUserAction.md`](../app/Actions/Auth/LoginUserAction.md) §1 |
| 401 ile 403 farkı? | [`InvalidCredentialsException.md`](../app/Exceptions/InvalidCredentialsException.md) §2 |

---

## 2. Hedefler ve sonuçlar

| # | Hedef | Sonuç |
|---|---|---|
| 2.0 | *(plan dışı)* `users` şeması — ad/soyad ayrımı | ✅ **K35** |
| 2.1 | `User` modeli | ✅ `$fillable`, `hashed` cast, `HasApiTokens`, mutator |
| — | *(plan dışı)* K32'nin **uygulanması** | ✅ Argon2id devrede |
| 2.2 | `UserFactory` | ✅ Memoization + `PASSWORD` sabiti |
| 2.3 | `UserResource` | ✅ **K35 ile revize** — `firstName`/`lastName` ayrı |
| 2.4 | `RegisterRequest` | ✅ `unique` **bilerek yok** |
| 2.5 | `RegisterUserAction` | ✅ **+ plan dışı** `RegistrationFailedException` |
| 2.6 | `AuthController` | ✅ 3 satırlık metotlar |
| 2.7 | `routes/api.php` | ✅ İlk gerçek uç nokta |
| — | *(plan dışı)* Rate limit | ✅ **K36** — Faz 5'ten öne çekildi |
| 2.8 | Giriş dilimi | ✅ **+ plan dışı** `InvalidCredentialsException` |
| 2.9 | `RevokeTokenAction` + `logout` + `me` | ✅ Rota yapısı yeniden düzenlendi |
| 2.10 | `AuthTest` | ✅ 15 test **+ plan dışı** `TestCase::forgetAuthState()` (T13) |
| — | PHPStan 5 → 6 | ✅ **K22 takvimi tutuldu** |
| — | *(plan dışı)* Frontend K35 uyarlaması | ✅ 7 dosya |

**Plan 10 dosyaydı, 17 oldu.** Genişleme üç kaynaktan geldi: K35 (şema
değişikliği), H10/H11'in zorunlu kıldığı iki exception sınıfı, ve K36 (rate
limit). Üçü de plan yazılırken görülmemiş **zorunluluklardı**, kapsam kayması
değil.

---

## 3. Yazılan dosyalar

### 3.1 Kod

| # | Dosya | İşi | Kılavuz |
|---|---|---|---|
| 2.0 | `..._create_users_table.php` | `first_name` + `last_name`, `VARCHAR(60)` | [↗](../database/migrations/0001_01_01_000000_create_users_table.md) |
| 2.1 | `app/Models/User.php` | Beyaz liste, hash cast, token, mutator | [↗](../app/Models/User.md) |
| 2.2 | `database/factories/UserFactory.php` | Test verisi | [↗](../database/factories/UserFactory.md) |
| 2.3 | `app/Http/Resources/UserResource.php` | Dış sözleşme | [↗](../app/Http/Resources/UserResource.md) |
| 2.4 | `Requests/Auth/RegisterRequest.php` | Kayıt doğrulaması | [↗](../app/Http/Requests/Auth/RegisterRequest.md) |
| 2.5a | `Exceptions/RegistrationFailedException.php` | Sebepsiz kayıt hatası | [↗](../app/Exceptions/RegistrationFailedException.md) |
| 2.5b | `Actions/Auth/RegisterUserAction.php` | İlk iş kuralı | [↗](../app/Actions/Auth/RegisterUserAction.md) |
| 2.6 | `Controllers/Api/V1/AuthController.php` | 4 uç nokta | [↗](../app/Http/Controllers/Api/V1/AuthController.md) |
| 2.7 | `routes/api.php` | Auth grubu | [↗](../routes/api.md) |
| 2.8b | `Requests/Auth/LoginRequest.php` | Giriş doğrulaması | [↗](../app/Http/Requests/Auth/LoginRequest.md) |
| 2.8c1 | `Exceptions/InvalidCredentialsException.php` | Parametresiz kurucu | [↗](../app/Exceptions/InvalidCredentialsException.md) |
| 2.8c2 | `Actions/Auth/LoginUserAction.php` | Zamanlama savunması | [↗](../app/Actions/Auth/LoginUserAction.md) |
| 2.9 | `Actions/Auth/RevokeTokenAction.php` | Token izolasyonu | [↗](../app/Actions/Auth/RevokeTokenAction.md) |
| 2.10 | `tests/Feature/AuthTest.php` | 15 test, 5'i güvenlik regresyonu | [↗](../tests/Feature/AuthTest.md) |
| 2.10 | `tests/TestCase.php` | `forgetAuthState()` — guard önbelleği (**T13**) | [↗](../tests/TestCase.md) |

### 3.2 Düzenlenen mevcut dosyalar

| Dosya | Değişiklik | Sebep |
|---|---|---|
| `.env` · `.env.example` | `HASH_DRIVER=argon2id` + `ARGON_*` | **K32 uygulandı** |
| `phpunit.xml` | Test için `ARGON_MEMORY=1024` | Süre; `BCRYPT_ROUNDS` argon'a etkisiz |
| `app/Providers/AppServiceProvider.php` | `configureRateLimiting()` | **K36** |
| `app/Exceptions/ApiExceptionRenderer.php` | 2 yeni `match` kolu | **H11** |
| `bootstrap/app.php` | `is('api/*') \|\|` · `use Throwable;` silindi | Hata düzeltmesi |
| `phpstan.neon` | level 5 → **6** | **K22** |
| `docs/03-MIMARI-PLAN.md` | §3.2, §3.3, §4.3 | **K35** |

### 3.3 Kavram dokümanları (plan dışı, talep üzerine)

| Doküman | Kapsam |
|---|---|
| [`kavramlar/veritabani-ve-migration.md`](../kavramlar/veritabani-ve-migration.md) | Veritabanı mantığı, migration'ın çözdüğü problem |
| [`kavramlar/php-dili.md`](../kavramlar/php-dili.md) | TS bilenler için PHP referansı — 20 bölüm |
| [`kavramlar/komutlar.md`](../kavramlar/komutlar.md) | Terminal komutları referansı |
| [`kavramlar/istek-yasam-dongusu.md`](../kavramlar/istek-yasam-dongusu.md) | Framework kaynağı üzerinden istek izleme (Bölüm 1/4) |
| [`fazlar/FAZ-2-ELLE-DOGRULAMA.md`](FAZ-2-ELLE-DOGRULAMA.md) | 13 adımlık elle doğrulama |

---

## 4. Kurulan kurallar

Faz 0'ın 31 ve Faz 1'in 19 kuralına ek olarak **21 kural**; bundan sonraki her
fazda geçerli.

### 4.1 Kimlik ve güvenlik

| # | Kural | Gerekçe |
|---|---|---|
| **A1** | Auth uçlarında `unique` / `exists` **doğrulama kuralı kullanılmaz** | `fields` yanıtı kayıtlı hesapları ifşa eder; form hesap tarayıcısına döner |
| **A2** | Auth başarısızlığı **ayırt edilebilir sebep taşımaz** | "Kullanıcı yok" ile "parola yanlış" aynı kod, aynı gövde, aynı durum |
| **A3** | Kullanıcı bulunamasa bile **hash doğrulaması yapılır** | Aksi hâlde ~200 ms fark zamanlama saldırısına kapı açar |
| **A4** | Güvenlik kodunda **kısa devre değerlendirmesi yasaktır** | `\|\|` sol tarafı doğruysa sağ taraf çalışmaz — savunma sessizce iptal olur |
| **A5** | **Pahalı bir işlem sınırsız çağrılabilir olamaz** | Argon2id = 64 MB × istek. Hız sınırı yalnızca brute-force değil, DoS savunmasıdır |
| **A6** | Çıkış **yalnızca isteği taşıyan token'ı** iptal eder | Bir cihazdan çıkmak diğerlerini düşürmemeli |
| **A7** | Durum kodu da bir **sızıntı kanalıdır** | `409` yerine `422`: e-posta çakışması diğer doğrulama hatalarından ayırt edilemez |

### 4.2 Doğrulama (FormRequest)

| # | Kural | Gerekçe |
|---|---|---|
| **D1** | Kurallar **isteğin gönderdiği adlarla** yazılır (camelCase) | Doğrulama gelen veriye bakar; `fields` anahtarları da o adları taşır |
| **D2** | `prepareForValidation()` içindeki veri **güvenilmezdir** | `email[]=x` → `TypeError` → 500. Tip kontrolü zorunlu |
| **D3** | Kalite kuralı yalnızca **üretim anında** uygulanır, okuma anında değil | Girişe `min:8` koymak, politika değişince mevcut kullanıcıları kilitler |
| **D4** | camelCase → snake_case eşlemesi **FormRequest'te** yapılır | Action'ın HTTP alan adlarını bilmesi katman ihlalidir |
| **D5** | Action'a giden veri **`validated()`**'ten gelir | `all()` beklenmeyen alanları geçirir |

### 4.3 Veri katmanı

| # | Kural | Gerekçe |
|---|---|---|
| **E1** | **Türetilebilen veri saklanmaz** | `full_name` kolonu iki doğruluk kaynağı üretirdi |
| **E2** | Benzersizlik **veritabanı kısıtıyla** korunur, `if` ile değil | "Önce sor sonra yap" eşzamanlılıkta yarış koşulu üretir |
| **E3** | Yolun üzerindeki dönüşüm **çağrı yerlerinde tekrarlanmaz** | `Hash::make()` + `hashed` cast = çift hash, hiçbir giriş çalışmaz |
| **E4** | Birden çok yazma varsa **transaction** | Ölçüt: *"yarım kalan durum kimin için ne anlama gelir?"* |
| **E5** | Ayrık veri **ayrık gönderilir** | Birleştirmek kolay, birleşmiş veriyi ayırmak imkânsız |

### 4.4 Sözleşme

| # | Kural | Gerekçe |
|---|---|---|
| **C1** | Resource bir **beyaz listedir** | API'ye alan eklemek kolay, çıkarmak imkânsıza yakın |
| **C2** | Zarf istisnası **ad ad** tanımlıdır ve **gerekçesiyle** taşınır | `/auth/me` zarflıdır; "aynı klasörde" bir gerekçe değildir |
| **C3** | Aynı sözleşmeyi üreten iki uç **tek yerden** üretir | DRY'ın amacı satır tasarrufu değil, tek doğruluk kaynağıdır |

### 4.5 Test

| # | Kural | Gerekçe |
|---|---|---|
| **T10** | Token/oturum testleri **`withToken()`** ile yazılır | `actingAs()` guard'ı atlar; `currentAccessToken()` null döner ve test **boş yeşil** yanar |
| **T11** | Ayırt edilemezlik **ham gövde karşılaştırmasıyla** doğrulanır | `assertJsonPath` yalnızca baktığın yeri kontrol eder |
| **T12** | Ölçümü kararsız olan şey **teste konmaz** | Zamanlama farkı elle doğrulanır; flaky test güveni yok eder |
| **T13** | Aynı testte ikinci kimlikli istekten **önce** `forgetAuthState()` çağrılır | `RequestGuard` çözdüğü kullanıcıyı özellikte tutar; `setRequest()` temizlemez. Çağrılmazsa ikinci istek **token'a hiç bakmadan** ilk kullanıcıyı döner — iptal edilmiş token geçerli, başkasının token'ı "sahibin" görünür |

### 4.6 Belgeleme

| # | Kural | Gerekçe |
|---|---|---|
| **B4** | Dokümanda verilen söz, kodda karşılığı yoksa **yalandır** | `rehash_on_login` sözü `hashing.md`'de verilmiş ama kodda karşılığı yoktu; faz kapanışında çapraz kontrol zorunlu |

---

## 5. Faz boyunca alınan kararlar

| # | Karar | Gerekçe |
|---|---|---|
| **K35** | `users` → `first_name` + `last_name` (tek `full_name` değil) | Birleştirmek kolay, ayırmak imkânsız. Fatura (Faz 7) soyadı tek başına ister. **API'de de ayrı döner** — birleştirme bir sunum kararıdır |
| **K36** | Auth uçlarına **hız sınırı**, Faz 5'ten öne çekildi | Brute-force + K32'nin doğurduğu bellek tüketimi saldırısı. İki limit: 5/dk (e-posta+IP), 20/dk (IP) |

### 5.1 Uygulanan eski kararlar

| # | Durum |
|---|---|
| **K22** | ✅ PHPStan level 5 → **6** |
| **K32** | ✅ Argon2id **gerçekten devrede** — `.env`'de eksikti, Faz 2'de tamamlandı |
| **K33 · K34** | ✅ Katalog repoda ve `composer check` zincirinde |
| **K11** | ✅ Auth yanıtları zarfsız, diğerleri zarflı |
| **K5** | ✅ Sanctum token iptali çalışıyor |

### 5.2 Düzeltilen hatalar

| Ne | Nasıl bulundu |
|---|---|
| `html_request_to_api_still_receives_json` testi **hiç geçmemişti** | Faz 2'de testler ilk kez koştu; `Router` kaynağı okunarak sebep bulundu |
| 🔴 **Guard önbelleği testleri boş yeşil yapıyordu** (T13) | `logout_revokes_only_the_current_token` hem haksız kırmızı hem haksız yeşil üretiyordu; `$laptop` yerine anlamsız bir string yazılsa da test geçerdi |
| `rehash_on_login` sözü **tutulmuyordu** | `hashing.md` ↔ `LoginUserAction` çapraz kontrolü (**B4**) |
| `logout`/`me` yanlış rate limit kovasında | Limiter anahtarı incelenirken: `email` yok → `'anonim\|IP'` → aynı IP'deki herkes tek kovayı paylaşır |
| `bootstrap/app.php` → `use Throwable;` uyarısı | Her `composer check` çıktısını kirletiyordu |

---

## 6. Ortaya çıkan zincir

Faz 1'in zincirine **üç halka** eklendi:

```
1. public/index.php
2. bootstrap/app.php
3. [9 global middleware]           ← TrimStrings, ConvertEmptyStringsToNull
4. Router::findRoute()             ← eşleşme YOKSA middleware hiç çalışmaz
5. [ForceJsonResponse]             ← M3: grubun başında
6. [throttle:auth]                 ← 🆕 K36 · yalnızca kimlik bilgisi uçlarında
7. [auth:sanctum]                  ← 🆕 yalnızca token gerektiren uçlarda
8. FormRequest                     ← 🆕 prepareForValidation → authorize → rules
9. Controller                      ← Action'ı çağırır
10. Action                         ← 🆕 iş kuralı; HTTP bilmez
11. Model                          ← mutator + cast + SQL
12. Resource                       ← 🆕 dış sözleşme
    │
    ├─ başarılı ────────────────→ JSON
    └─ exception fırladı
         ↓
13. Kernel::handle() try/catch
14. bootstrap/app.php render()      is('api/*') || expectsJson()
15. ApiExceptionRenderer            { error: { code, fields?, params?, debug? } }
16. ErrorCode                       status() + filterParams()
```

**Faz 3 bu zincire iki halka daha ekleyecek:** Policy (9 ile 10 arasına) ve
Route Model Binding (7 ile 8 arasına).

---

## 7. Bitiş ölçütü — doğrulama

```powershell
composer check                          # pint + phpstan(6) + katalog + 22 test
php artisan route:list --path=api       # 5 rota
php artisan test --filter=AuthTest      # 15 test
```

Çalışan uç noktalar:

| Method | Path | Auth | Yanıt |
|---|---|:---:|---|
| POST | `/api/auth/register` | — | `201` · zarfsız `{user, token}` |
| POST | `/api/auth/login` | — | `200` · zarfsız `{user, token}` |
| POST | `/api/auth/logout` | ✅ | `204` · gövde yok |
| GET | `/api/auth/me` | ✅ | `200` · **zarflı** `{data: {...}}` |

Elle doğrulama: [`FAZ-2-ELLE-DOGRULAMA.md`](FAZ-2-ELLE-DOGRULAMA.md) — 13 adım.

### 7.1 ✅ Frontend uyarlaması tamamlandı

Yol haritasındaki bitiş ölçütü: *"Frontend'i `npm run dev` ile açıp gerçek
hesapla giriş yapabilmek."*

**Backend ve frontend sözleşmesi uyumlu.** Frontend tarafında yapılanlar:

| Dosya | Değişiklik |
|---|---|
| `src/types.ts` | `AuthUser` · `RegisterPayload` → `firstName` + `lastName` |
| `src/utils/user.ts` | 🆕 `fullName()` — birleştirmenin **tek yeri** |
| `src/pages/RegisterPage.tsx` | İki ayrı input, `maxLength={60}`, `minLength={8}` |
| `src/pages/LoginPage.tsx` | `fullName(user)` · parola `minLength` **kaldırıldı** (D3) |
| `src/pages/DashboardPage.tsx` · `Header.tsx` | `fullName(user)` |
| `src/services/api.ts` | `apiErrorCode()` / `apiErrorParams()` + 🔴 **401 ayrımı** |
| `LoginPage` · `RegisterPage` | `429` → `retryAfter` ile doğru mesaj |

🔴 **En kritik frontend düzeltmesi — 401'in iki anlamı:**

```ts
if (status === 401 && apiErrorCode(error) !== 'INVALID_CREDENTIALS') {
  useAuthStore.getState().logout();
}
```

Ayrım yapılmasaydı, kullanıcı yanlış parola girdiğinde `logout()` tetiklenir,
giriş sayfası yeniden kurulur ve **yazdıkları kaybolurdu**. `ErrorCode`
enum'unda `Unauthenticated` ile `InvalidCredentials`'ı ayrı case olarak
tutmanın (Faz 1) karşılığı burada alındı.

**D3 kuralı frontend'de de uygulandı:** `LoginPage`'in parola alanındaki
`minLength={6}` kaldırıldı; `RegisterPage`'deki `minLength={8}` duruyor. Giriş
formu politika uygulamaz, kayıt formu uygular — **kural veri üretilirken
geçerlidir, okunurken değil.** Aynı ilkenin backend ve frontend'de ayrı ayrı
görünmesi tesadüf değil; sözleşme iki tarafta da aynı mantığı taşımalı.

### 7.2 Kalan frontend işi — Faz 2'yi bloke etmiyor

`claude/Notlar/03` §14'teki kalan maddeler **K20'nin (hata sözleşmesi) frontend
tarafıdır**, K35'in değil: `errors`/`validation`/`fields` çeviri bölümleri ve
`toDisplayError()` katmanı. Şu an toast'larda sabit Türkçe metinler var; o
katman geldiğinde 10 dile açılacaklar.

---

## 8. Faz 3'e devir

**Hazır olanlar:** Kullanıcı modeli · token mimarisi · FormRequest/Action/Resource
kalıbı · hata zarfı genişletildi · hız sınırı altyapısı · PHPStan level 6.

**Faz 3'te yazılacaklar (Invitation CRUD):**

| Dosya | Not |
|---|---|
| `app/Enums/InvitationStatus.php` | ⚠️ Frontend `'published' \| 'saved'` diyor; `draft` uyuşmazlığı **burada** çözülür |
| `..._create_invitations_table.php` | ULID slug, 6 `show_*` kolonu, indeksler |
| `..._create_timeline_events_table.php` | FK CASCADE, `sort_order` |
| `Models/Invitation.php` · `TimelineEvent.php` | İlişkiler |
| `InvitationFactory` + `DatabaseSeeder` | |
| `Policies/InvitationPolicy.php` | 🔴 IDOR savunması — reddi **404** (H7) |
| `Store/UpdateInvitationRequest` | 🔴 28 camelCase alan → **D4** sınavı |
| `InvitationResource` ailesi | 🔴 **C1** sınavı |
| `Create/Update/SyncTimelineEventsAction` | |
| `InvitationController` + rotalar | |
| `tests/Feature/InvitationTest.php` | 🔴 "başkasının davetiyesini okuyamaz" |

**Faz 3 bitiş ölçütü:** Dashboard'da davetiye listesi gerçek veritabanından
geliyor; editörde autosave çalışıyor.

### 8.1 Faz 3'e taşınan açık konular

| Konu | Not |
|---|---|
| Migration'da `ENUM` mü `CHECK` kısıtı mı? | Faz 3'te karar |
| `InvitationStatus`'te `draft` kalacak mı? | Frontend `saved \| published` diyor |
| Rota sırası tuzağı | `/invitations/{id}` **sonra** yazılmalı; `{id}` sabit rotayı yutar |
| `routes/web.php` closure'ı | R1/R4 ihlali; `route:cache` Faz 9'da kırılır — temizlenmeli |
| `contracts/error-codes.json` frontend'e kopyalanması | Faz 2'de gerekli oldu, yapılmadı |
| `sanctum:prune-expired` zamanlanmış görevi | Token tablosu sınırsız büyüyor — Faz 9 |
| Genel API hız sınırı (`throttleApi`) | `logout`/`me` şu an sınırsız — Faz 5 |
| `prepareForValidation`'daki `trim` | `TrimStrings` global middleware zaten yapıyor; ölü kod mu? |

---

## 9. Terim özeti

| Terim | Anlamı |
|---|---|
| **Walking skeleton** | Uçtan uca çalışan en küçük dilim |
| **User enumeration** | Yanıt farkından kayıtlı hesapları tespit etme |
| **Zamanlama saldırısı** | İşlem süresini ölçerek bilgi çıkarma |
| **Yan kanal (side-channel)** | İçerik dışı gözlemlerden sızan bilgi |
| **Kısa devre** | `\|\|` / `&&`'de sağ tarafın hiç çalışmaması |
| **Race condition** | Eşzamanlı işlemlerin sıraya bağlı hatalı sonucu |
| **Check-then-act** | "Önce sor sonra yap" — eşzamanlılıkta hatalı kalıp |
| **Transaction** | Ya hepsi ya hiçbiri çalışan yazma bloğu |
| **Argon2id** | Bellek de tüketen parola hash algoritması |
| **Rehash** | Parametreler eskiyince hash'i yeniden üretme |
| **Bearer token** | `Authorization: Bearer ...` ile taşınan kimlik |
| **Token revocation** | Sunucunun bir token'ı geçersiz kılması |
| **Autowiring** | Tip bildiriminden bakarak bağımlılık çözme |
| **Memoization** | Pahalı hesabın sonucunu saklama |
| **Nullsafe operatör** | `?->` — sol taraf null ise ifade null |
| **Kovaryans** | Alt sınıfın dönüş tipini daraltabilmesi (genişletememesi) |
| **Password spraying** | Çok hesaba az parola deneme |
| **Lockout DoS** | Kurbanı kilitleyerek erişimini engelleme |
| **Flaky test** | Kod değişmeden bazen geçen bazen kalan test |

---

## 10. Bağlantılar

| İlgili | Nerede |
|---|---|
| Önceki faz | [`FAZ-1.md`](FAZ-1.md) |
| Elle doğrulama | [`FAZ-2-ELLE-DOGRULAMA.md`](FAZ-2-ELLE-DOGRULAMA.md) |
| Tüm fazların planı | `docs/09-TUM-FAZLAR-PLANI.md` |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
| Veri modeli | `docs/03-MIMARI-PLAN.md` §3 |
| Kod standartları | `CLAUDE.md` |
| Frontend'e düşen iş | `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md` **Bölüm II** |
| Proje devir dosyası | `claude/PHP-LARAVEL-SETUP.md` |
