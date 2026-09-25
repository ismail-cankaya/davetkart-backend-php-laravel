# FAZ 10 — Sertleştirme ve Lansman Eksikleri (Plan)

> **Tarih:** 25 Eylül 2026
> **Kaynaklar:** `claude/GOZDEN-GECIRME-RAPORU.md` (23 Eylül, kod okuması) ·
> `claude/TEST-DENETIMI-2026-09-24.md` (24 Eylül, testler kum havuzunda gerçekten koşturuldu)
> **Başlangıç noktası:** `edit-test` dalı, `ef7c692` (25 Eylül 07:28)
> **Durum:** 📋 Plan. 10.0'ın kodu commit'lendi, `composer check` kaydı bekliyor. Diğer adımlara başlanmadı

---

## 0. Bu faz ne yapar, ne yapmaz

Faz 0-9 **özellikleri** kurdu. Faz 10'da yeni özellik yok (parola sıfırlama ve hesap silme
hariç). Amaç, iki incelemenin bulduğu eksikleri **önem sırasıyla** kapatmak ve backend'i
gerçek ödeme ile lansmana hazır hâle getirmek.

| Dilim | Konu | Öncelik | Ne zamana kadar |
|:---:|---|:---:|---|
| **0** | Denetim sonrası ilk düzeltmeleri doğrula (`ef7c692`) | — | Hemen |
| **A** | Para ve paywall | 🔴 Kritik | Gerçek ödeme açılmadan **önce** |
| **B** | Güvenlik ve veri bütünlüğü | 🟠 Yüksek | Gerçek ödeme açılmadan önce |
| **C** | Ödeme/yayın akışının eksik uçları (backend + frontend) | 🟠 Yüksek | Gerçek ödeme açılmadan önce |
| **D** | Hesap yaşam döngüsü ve KVKK | 🟠 Yüksek | Lansmandan önce |
| **E** | Test denetiminin kalan dosyaları | 🟡 Orta | A-D ile paralel |
| **F** | Ürün ve operasyon kararları | 🟡 Orta | Karar geldikçe |
| **G** | Shopier entegrasyonu | 💳 | A ve C bittikten sonra |
| **H** | Temizlik | 🟢 Düşük | Araya serpiştirilebilir |
| **Z** | Kapanış | — | En son |

**Kapsam dışı** (deploy/altyapı fazına kalır): Redis, S3, kuyruk süpervizörü, Argon2id
ölçümü, log rotasyonu, yedekleme, e-posta doğrulama. Hepsi AWS rehberinde ve `docs/10`'da
zaten yazılı.

---

## 1. Başlangıç durumu

### 1.1 Doğrulanmış olan

- 24 Eylül test denetimi `c85dbc9`'da **274 test yeşil**, Pint + PHPStan L8 yeşil
  (PHP 8.4 + PostgreSQL 16 kum havuzunda). Bu, rapor §5.8'deki *"Faz 9 sonrası kayıt yok"*
  borcunun yarısını kapatıyor. İsmail'in makinesinde PHP 8.5 + PostgreSQL 18 ile koşu hâlâ gerekli.
- Kritik iki bulgu çalışma anında **yeniden üretildi** (denetim K-1 ve K-4).

### 1.2 Denetim sonrası ilk düzeltmeler (`ef7c692`, 25 Eylül 2026, `edit-test` dalı)

Bu plan yazılırken çalışma ağacında commit'lenmemiş duruyordu, aynı sabah commit'lendi.
Eksik olan yalnızca PHP 8.5 ile `composer check` kaydı (10.0).

| Dosya | Ne yapıyor | Hangi bulgu |
|---|---|---|
| `app/Http/Middleware/RejectMalformedInput.php` (+ kılavuzu) | Yarım JSON ve NUL baytı → **400** `MALFORMED_REQUEST`, `api` grubunda tek kapı | K-2, K-6 · D-1 |
| `bootstrap/app.php` | Middleware kaydı + `SubstituteBindings` önceliği | aynı |
| `app/Exceptions/ApiExceptionRenderer.php` | 429/503'te `Retry-After` **başlığı**; `integer` kuralının parametresi sızmaz | K-5 · D-3 |
| `app/Http/Requests/Rsvp/StoreRsvpRequest.php` | `guestCount` → `integer:strict` | K-7 · D-2 (yalnızca LCV) |
| `tests/Feature/RsvpTest.php` + kılavuzu | Denetimin 7 kırmızı vakası | — |

> ⚠️ `ad6dcad` commit'inin mesajı (*"Refactor code structure…"*) içeriği anlatmıyor. Değişen
> şey `RsvpTest`'in yeniden yazımı. Geri alınmasına gerek yok, ama `git log` okuyan biri
> yanılır.

---

## 2. Kararlar

### 2.1 Alındı (25 Eylül 2026, İsmail)

| # | Karar | Gerekçe | Adım |
|---|---|---|---|
| **K87** | PHP **^8.5** kalır | *"Daha güncel"*. Sonuç: `phpstan.neon` → `phpVersion: 80500`, barındırma 8.5 sunmalı (AWS Yol C'de önceden doğrula) | 10.78 |
| **K88** | Yayındaki davetiyede modül açma **kayıt anında** plan kontrolünden geçer → **402** `PAYWALL_TIER_INSUFFICIENT` | Rapor §1.1 · denetim D-4. Okuma anında maskeleme bu fazda **yok** | 10.1 |
| **K89** | `OrderStatus::Expired` eklenir. `orders:expire` onu yazar, `Expired → Paid` geçişine izin verilir. `Failed` final kalır | Rapor §1.2 · D-6. `failed` iki gerçeği anlatıyordu (**E12**'nin ikinci örneği) | 10.3–10.7 |
| **K90** | Sanctum token ömrü **30 gün**, mutlak (oluşturmadan itibaren) | *"Aşırı hassas işlem yok"*. Kayan pencere gerekmez; 30. günde yeniden giriş istenir | 10.10 |

### 2.2 `ef7c692`'de uygulanmış, karar kaydına geçmemiş (10.0'da kayda geçer)

| # | Karar | Kaynak |
|---|---|---|
| **K91** | Bozuk girdi (yarım JSON, NUL) → **400**, alan başına değil `api` grubunda tek middleware | D-1 |
| **K92** | Sayı alanları `integer:strict` (`true` ve `"3"` reddedilir) | D-2 |
| **K93** | 429 ve 503 yanıtları `Retry-After` **başlığını** da taşır | D-3 · `docs/08` §4.1 |

### 2.3 🔴 Hâlâ açık: senin cevabını bekleyenler

| # | Soru | Öneri | Bloke ettiği adım |
|---|---|---|---|
| **D-5** | E-postada `İ`: `İ→i` dönüşümü mü, ASCII dışı yerel kısmı reddetmek mi? | `İ→i` + `mb_strtolower`, tek kaynaklı normalizer | 10.12 |
| **M-1** | Mail kanalı (K79): Amazon SES mi, alan adının SMTP'si mi? Mailler hangi dilde? (K21 API için *tek dil* diyor, ama mail kullanıcıya giden metindir) | SES (AWS rehberiyle uyumlu) · mail dili Türkçe, K21'in istisnası olarak kayda geçer | 10.30 → tüm Dilim D'nin parola kısmı |
| **H-1** | Hesap silinince ne olur? | **Anonimleştir**: kişisel veri silinir, `orders` satırı muhasebe için kalır (bugün `orders.user_id` `cascadeOnDelete` — kullanıcı silinirse sipariş kayıtları da silinir, K82 ile çelişir) | 10.38 |
| **S-1** | Saklama süreleri: silinmiş davetiye kaç gün, etkinlikten sonra misafir verisi (ad, mesaj, foto) kaç ay, iletişim mesajı kaç ay? | 30 gün · 6 ay · 12 ay | 10.42 |
| **P-1** | Fiyat kartı vaatleri — aşağıdaki kanıtlara bak | Karar senin | 10.66 |

#### P-1'in kanıtları

*"Paketlerde eksik yok"* dedin. Kodda gördüğüm üç yer aşağıda. Belki bilinçli tercihler,
ama öyleyse fiyat kartındaki metin koddan farklı bir şey vaat ediyor:

| Kartta ne yazıyor | Kodda ne var |
|---|---|
| Elit: **"Logosuz özel yayın"** ✅, Standart/Gold ❌ | `components/templates/shared/InvitationComposition.tsx:171` alt bilgiyi **koşulsuz** çiziyor: *"DavetKart ile hazırlandı"*. Public yanıtta planı ya da markayı söyleyen bir alan yok, yani misafir sayfası kimin Elit olduğunu bilemiyor |
| Standart: **"Temel şablon koleksiyonu"** · Gold/Elit: **"Premium tema koleksiyonu"** | `types.ts` → `TemplatePreset`'te plan alanı yok. `TierResolver` yalnızca `show_*` bayraklarına bakıyor, `preset_id`'ye bakmıyor. Standart ile her tema yayınlanabiliyor |
| Elit: **"Fotoğraf & Video galerisi"** | Galeri yalnızca `image/jpeg, png, webp` kabul ediyor (`config/davetkart.php` → `gallery.mimes`, `GalleryUploader.tsx:22`). Video yalnızca misafirin LCV'sinde var, o da her planda |

Üç seçenek var: (a) kod vaadi karşılasın, (b) kart kodu anlatsın, (c) karışık.

---

## 3. Çalışma ritmi (değişmedi)

```
1 cevap = 1 dosya      kod → kılavuz (docs/rehber/<yol>.md, K18) → test → composer check → DUR
```

- Tablodaki bir **satır** bir iş birimidir. Satırda birden fazla dosya varsa her dosya ayrı
  bir cevaptır (ör. 10.1 önce Action, sonra kılavuzu, sonra test).
- Her satırın **mutasyon** sorusu vardır (kural 14 / T16): *"bu satırı bozunca hangi test kırılır?"*
- Frontend satırları (**FE**) `davetkart-frontent` deposundadır. Dosyalar **tek tek** eklenir,
  çünkü çalışma ağacı CRLF ve `git add -A` 500+ dosya sürükler.
- Yeni hata kodu = `ErrorCode` + `errors:export` + frontend `src/contracts/error-codes.json` +
  **10 dilde** `errors.json` (F1 sözleşmesi, `npm run verify:errors` bunu denetler).

---

## 4. Dilimler

### Dilim 0 — Denetim sonrası ilk düzeltmeleri doğrula

| # | İş | Doğrulama |
|---|---|---|
| **10.0** | `ef7c692` commit'lendi ✅. Kalan: `composer check`'i **PHP 8.5 ile kendi makinende** koştur, sonucu buraya yaz (B7). K91–K93 kayda geçer | Son satır yeşil · RsvpTest'in 7 kırmızı vakası yeşile döndü |

---

### Dilim A — 🔴 Para ve paywall

**Neden önce:** ikisi de gerçek para kaybı. Biri müşterinin 300 ₺'sini bizden, öbürü bizim
tahsil ettiğimiz parayı müşteriden alıyor.

| # | Dosya | İş | Bulgu | Test / mutasyon |
|---|---|---|---|---|
| **10.1** | `app/Actions/Invitation/UpdateInvitationAction.php` | Satırı **kilitle ve yeniden oku** (`lockForUpdate`, E9: eşzamanlı yayınla yarışmasın). `published` ise `fill()`'den sonra `TierResolver::requiredFor()` ↔ `PublishEntitlementResolver::highestTierFor()`. Yetmiyorsa `PaywallViolationException::insufficientTier()`, transaction geri alınır. Taslak (`saved`) serbest kalır (K43'ün ruhu: denemenin bedeli olmaz). Modül **kapatmak** her zaman serbest | Rapor §1.1 · K-1 · **K88** | 10.2 |
| **10.2** | `tests/Feature/PaywallTest.php` | 5 test: yayındaki davetiyede plan üstü modül → 402 + **DB'de bayrak `false`** (T14) · plan içi modül → 200 · modül kapatma → 200 · plan yükseltilince aynı istek → 200 · taslakta her şey serbest. Mutasyon: 10.1'deki `if`'i sil → ilk test kırılmalı | — | — |
| **10.3** | `app/Enums/OrderStatus.php` | `case Expired = 'expired'`. `canTransitionTo`: `Pending → Paid/Failed/Expired`, **`Expired → Paid`**. `grantsPublishRight()` ve `hasBeenPaid()` değişmez | Rapor §1.2 · K-4 · **K89** | 10.7 |
| **10.4** | `database/migrations/…_add_expired_to_orders_status.php` | `orders_status_check`'i enum'dan yeniden kur (K39 deseni: düşür → yeniden yaz). `paid_at` CHECK'i etkilenmez (`Expired` ödenmiş sayılmaz) | — | `migrate` + `migrate:rollback` |
| **10.5** | `app/Console/Commands/ExpireStaleOrders.php` | `Failed` yerine `Expired` yazar. Koşullu `UPDATE` (eşzamanlı yarış koruması) aynen kalır | — | 10.7 |
| **10.6** | `app/Actions/Payment/HandlePaymentCallbackAction.php` | Geçiş reddedildiğinde gelen durum `paid` ise **`Log::critical`** (sipariş, sağlayıcı ref, mevcut durum). Sentry'ye düşer. Para alındı, hak açılamadı: elle müdahale gerekir | K-4 | 10.7 |
| **10.7** | `tests/Feature/PaywallTest.php` · `MaintenanceTest.php` | `orders:expire` → `expired` · expire sonrası imzalı `paid` → sipariş `paid`, `paid_at` dolu, yayın açılır · `failed` sonrası `paid` → reddedilir **ve** critical log (`Log::spy()`). Mutasyon: `Expired → Paid` kolunu sil | — | — |
| **10.8** | **FE** `components/create/EditorWorkspace.tsx` (+ `useInvitationStore`) | Autosave'de 402 gelirse: açılan anahtarı geri al, paywall'ı `reason: 'upgrade'` + sunucunun `requiredTier`'ıyla aç. Autosave bir 402 fırtınası üretmesin (aynı alan için tek paywall) | K88 | Elle: Standart ile yayınla → galeriyi aç |
| **10.9** | **FE** `src/types.ts` | `OrderStatus`'a `'expired'` | K89 | `npm run lint` |

---

### Dilim B — 🟠 Güvenlik ve veri bütünlüğü

| # | Dosya | İş | Bulgu | Test / mutasyon |
|---|---|---|---|---|
| **10.10** | `config/sanctum.php` · `routes/console.php` | `'expiration' => 60 * 24 * 30`. `sanctum:prune-expired --hours=24` (tablo gerçekten küçülür). `console.php`'deki yanlış *"iz kalsın"* yorumu düzeltilir: çıkışta token zaten siliniyor | Rapor §2.2 · **K90** | 10.11 |
| **10.11** | `tests/Feature/AuthTest.php` | `travel(31)->days()` → 401 `UNAUTHENTICATED` · prune gerçekten siler (MaintenanceTest) · denetimin bulduğu iki `actingAs()` → `withToken()` (**T10** ihlali) | — | Mutasyon: `expiration`'ı `null` yap → test kırılmalı |
| **10.12** | `app/Support/EmailNormalizer.php` (yeni) | Tek kaynak: `trim` → `İ→i` → `mb_strtolower`. **D-5 kararı gerekir** | K-3 | 10.15 |
| **10.13** | `RegisterRequest` · `LoginRequest` · `User` mutator · `AppServiceProvider::authLimits` | Dördü de 10.12'yi çağırır (bugün dördü ayrı `mb_strtolower` yazıyor: C3 ihlali). Throttle anahtarı da aynı normalizasyondan geçmezse `İ`/`i` iki ayrı kova olur | K-3 | 10.15 |
| **10.14** | `app/Console/Commands/NormalizeUserEmails.php` (yeni) | `users:normalize-emails --dry-run`: mevcut `i̇` (U+0307) içeren satırları düzeltir. **Çakışma** (aynı adresin iki hesabı) varsa yazmaz, raporlar (K84: bakım komutu önce elle) | K-3 | MaintenanceTest |
| **10.15** | `tests/Feature/AuthTest.php` | `İsmail.Cankaya@…` ile kayıt → `ismail.cankaya@…` ile giriş 200 · aynı adres büyük `İ` ile ikinci kez → `REGISTRATION_FAILED` · gerçek Türkçe adlar (denetim: *"ASCII isimler"*) | — | — |
| **10.16** | `bootstrap/app.php` | `$exceptions->dontReportWhen(fn ($e) => $e instanceof HasErrorCode && $e->errorCode()->status() < 500)`. Yan etki (bilinçli): 4xx iş istisnaları `laravel.log`'a da düşmez | Rapor §5.1 | `Exceptions::fake()` + `assertNotReported(InvalidCredentialsException::class)` · 502 hâlâ raporlanır |
| **10.17** | `phpunit.xml` · `tests/TestCase.php` | `SENTRY_LARAVEL_DSN=""` · `setUp()`'ta `Http::preventStrayRequests()` | Rapor §6.4-5 | Bir testte sağlayıcı bağlamayı unut → gerçek ağa değil hataya düşmeli |
| **10.18** | `config/davetkart.php` + `AppServiceProvider` | `trusted_proxies` (env `TRUSTED_PROXIES`, **varsayılan boş**). Boot'ta `TrustProxies::at(...)` (config'ten okunur, böylece `config:cache` altında da çalışır). `'*'` yalnızca sunucuya doğrudan erişim ağ seviyesinde kapalıysa | Rapor §2.3 | HardeningTest: güvenilir proxy'den gelen `X-Forwarded-For` → `ip()` istemciyi döner, güvenilmeyenden gelen yok sayılır |
| **10.19** | `app/Http/Requests/Invitation/InvitationRequest.php` | `mapUrl` → `url:http,https` · `giftOptions.*` → `integer:strict` | K-7 · K-8 · Rapor §6.3 | InvitationTest: `smb://` 422, `[true, 500]` 422 |
| **10.20** | `tests/Feature/MalformedInputTest.php` (yeni) | 10.0'ın kapısını **her grupta** sına: auth, invitation, public LCV/iletişim, asistan, webhook. Ayrıca `Retry-After` başlığı auth/contact/asistan 429'unda | K-2 · K-5 · K-6 | Mutasyon: middleware kaydını sil → hepsi kırılmalı |

---

### Dilim C — 🟠 Ödeme ve yayın akışının eksik uçları

**Neden:** Dilim A parayı doğru saydırıyor, ama kullanıcı ödemeden sonra hâlâ hiçbir şey
göremiyor (rapor §2.1). Shopier'den **önce** gerekiyor, çünkü dönüş sayfası sağlayıcıdan
bağımsız.

| # | Dosya | İş | Bulgu | Test |
|---|---|---|---|---|
| **10.21** | `app/Http/Resources/InvitationResource.php` | `publishedAt` (ISO 8601 ya da `null`). **Yalnızca sahip** Resource'u; public'te yok (C5) | Frontend F7.2 · rapor §2.1 | InvitationTest + PublicInvitationTest (sızmaz) |
| **10.22** | `app/Policies/OrderPolicy.php` (yeni) | `view`: sahiplik. Red → 404 (H7) | — | 10.25 |
| **10.23** | `app/Http/Controllers/Api/V1/OrderController.php` (yeni) + `routes/api.php` | `GET /api/orders` (liste, **sorgu kapsamıyla** — P3) · `GET /api/orders/{order}` (`whereUlid`, Policy) | Rapor §2.1 · Faz 9 açık #9 | 10.25 |
| **10.24** | `app/Http/Resources/OrderResource.php` | Beyaz listeye `invitationId` (nullable), `createdAt`, `paidAt`. `providerRef` yine **yok** | — | 10.25 |
| **10.25** | `tests/Feature/OrderTest.php` (yeni) | Sahibi görür · başkası 404 · liste yalnızca kendi · `providerRef` sızmaz · `{data}` zarfı · `pending/paid/expired` doğru gelir | — | — |
| **10.26** | **FE** `src/services/payments.ts` | `getOrder(id)` · `listOrders()` | — | `npm run verify:endpoints` |
| **10.27** | **FE** `src/pages/PaymentReturnPage.tsx` (yeni) + `App.tsx` | `/odeme/basarili` ve `/odeme/hata`. `?order=` ile siparişi birkaç saniye yoklar: `paid` → *"Ödemen onaylandı, şimdi yayınlayabilirsin"* (K67: ödeme yayınlamaz); `pending` → bekliyor; `expired/failed` → tekrar dene | Rapor §2.1 | F8'e yeni senaryo |
| **10.28** | **FE** `src/pages/DashboardPage.tsx` | Silme uyarısı `publishedAt`'e bakarak kesin: *"hakkınız X tarihine kadar serbest kalır"* ya da *"yanacak"* | F7.2 | Elle |
| **10.29** | **FE** `src/services/auth.ts` · `stores/useAuthStore.ts` | Açılışta `GET /auth/me`: iptal ya da süresi dolmuş token hemen düşer, kullanıcı bilgisi tazelenir (30 günlük token'la daha önemli) | Rapor §3 | Elle |

---

### Dilim D — 🟠 Hesap yaşam döngüsü ve KVKK

**Neden:** Parolasını unutan kullanıcı, parasını ödediği davetiyeye bir daha erişemiyor.
Misafirlerin kişisel verileri süresiz duruyor.

| # | Dosya | İş | Karar | Test |
|---|---|---|---|---|
| **10.30** | `config/mail.php` · `.env.example` · `docs/10` | Mail kanalı ve dili. SES ise `MAIL_MAILER=ses` + `aws/aws-sdk-php` | **M-1** | `php artisan tinker` → test maili |
| **10.31** | `app/Enums/ErrorCode.php` (+ `contracts/`, FE `errors.json` ×10) | `PASSWORD_RESET_INVALID` (400 ya da 422 — bu adımda tartışılır) | — | `errors:export --check` |
| **10.32** | `ForgotPasswordRequest` + `SendPasswordResetLinkAction` | `POST /api/auth/forgot-password` → **her durumda 202** (enumeration yok, `docs/08` §3.1), `throttle:auth` | — | 10.36 |
| **10.33** | `ResetPasswordRequest` + `ResetPasswordAction` | `POST /api/auth/reset-password` (token + e-posta + yeni parola). Başarıda **tüm** token'lar iptal | — | 10.36 |
| **10.34** | `AppServiceProvider` | `ResetPassword::createUrlUsing()` → frontend URL'i (`/sifre-sifirla?token=…&email=…`). Mail şablonu M-1'in diliyle | M-1 | 10.36 |
| **10.35** | `AuthController` + `routes/api.php` | İki uç `auth` grubunun `throttle:auth` alt grubunda | — | 10.36 |
| **10.36** | `tests/Feature/PasswordResetTest.php` (yeni) | Kayıtlı/kayıtsız e-posta **aynı** yanıt (A2) · `Notification::fake()` · geçersiz/süresi dolmuş token · eski token'lar 401 · throttle | — | — |
| **10.37** | **FE** `ForgotPasswordPage` · `ResetPasswordPage` · `auth.ts` · `LoginPage` linki | — | — | Elle |
| **10.38** | Karar kaydı + `database/migrations/…_make_orders_user_id_nullable.php` | H-1 anonimleştirme seçilirse: `orders.user_id` nullable + `nullOnDelete` (muhasebe kaydı kullanıcıdan uzun yaşar) | **H-1** | `migrate` |
| **10.39** | `app/Actions/Auth/DeleteAccountAction.php` (yeni) | Parola onayı → davetiyeler **Action üzerinden** kalıcı silinir (DB `cascade` model olaylarını ve dosyaları atlar: disk sızıntısı) → medya dosyaları → token'lar → kullanıcı anonimleşir/silinir | H-1 | 10.41 |
| **10.40** | `AuthController::destroy` + `DELETE /api/auth/me` | 204 | — | 10.41 |
| **10.41** | `tests/Feature/AccountDeletionTest.php` (yeni) | Yanlış parola 422 · davetiye/LCV/medya satırı **ve dosyası** gider · `orders` kalır · token 401 · başka kullanıcıya dokunulmaz | — | — |
| **10.42** | Karar kaydı + `config/davetkart.php` → `retention` | S-1'in sayıları config'e (E6: iş tercihi) | **S-1** | — |
| **10.43** | `app/Console/Commands/PurgeExpiredData.php` (yeni) | `data:purge --dry-run`: süresi dolan silinmiş davetiyeler + etkinliği geçmiş davetiyelerin misafir verisi + eski iletişim mesajları. **Önce dosya, sonra satır** (PruneOrphanMedia deseni) | S-1 | 10.45 |
| **10.44** | `routes/console.php` | Günlük, gece, `withoutOverlapping` + `onOneServer` | — | `schedule:list` |
| **10.45** | `tests/Feature/MaintenanceTest.php` | Sınırın bir gün öncesi/sonrası · dosyalar silinir · `orders` dokunulmaz | — | — |
| **10.46** | **FE** hesap ayarları (silme düğmesi, parola onayı) + `legal/` KVKK metni | Metnin **içeriği** senin/hukukçunun işi, kod değil | — | Elle |

---

### Dilim E — 🟡 Test denetiminin kalan dosyaları

`TEST-DENETIMI` §2'nin sırası. Her satırın hedefi, o dosyada **hayatta kalan mutant bırakmamak**.
A, B ve C'deki test adımlarıyla aynı dosyalara dokunanlar o adımla birleştirilebilir.

| # | Dosya | Hayatta kalan mutantlar (denetimden) |
|---|---|---|
| **10.47** | `PublicInvitationTest` | 🔴 **Cache anahtarından `id` çıkarılınca yeşil**. Tüm davetiyeler tek cache girdisine düşse (çiftler arası sızıntı) hiçbir test kırılmıyor · `names/venue/mapUrl` boş dönse yeşil · `date` ISO'ya dönse yeşil · `show_rsvp=false` iken `rsvpDeadline` sızsa yeşil (C6) |
| **10.48** | `InvitationTest` | İstek eşlemesinden `names`, `venue`, `mapUrl`, `iban`/`bankName`/`accountHolder`, `showGift` düşürülse yeşil · liste sıralaması · oyuncak veri |
| **10.49** | `PaywallTest` | `SubscriptionTier::price()` → 1 yeşil (beklenen aynı fonksiyonla hesaplanıyor; sabit **24900** yazılmalı) · `currency` → USD yeşil · `show_envelope` Gold→Standart yeşil |
| **10.50** | `MediaTest` | İçerik MIME'ı yalnızca `UploadedFile::fake()` ile sınanıyor. Gerçek baytlı dosyalarla: PHP-as-JPG, SVG, polyglot |
| **10.51** | `ContactTest` | Saatlik kova silinse yeşil · NUL · Türkçe veri |
| **10.52** | `AssistantTest` | `retryAfter` yalnızca `assertIsInt` → `travelTo` ile sabitlenmeli |
| **10.53** | `HardeningTest` | HSTS bloğu silinse yeşil → `https://localhost` isteğiyle otomatik test |
| **10.54** | Kılavuzlar | `rehber/tests/Feature/HardeningTest.md` · `MaintenanceTest.md` (K18 borcu) |

---

### Dilim F — 🟡 Ürün ve operasyon kararları

Kod adımından önce karar gelir. Önerim her satırda.

| # | Konu | Öneri | Kod |
|---|---|---|---|
| **10.55** | Zamanlanmış işler koşmazsa kimse bilmiyor (Faz 9 açık #1) | Sentry **Cron Monitors**: `->sentryMonitor()` makrosu paketle geliyor | `routes/console.php`, 3 satır |
| **10.56** | `AI_PROVIDER` varsayılanı `gemini`, belgesi `null` diyor (rapor §5.7) | Kodu `null` yap. Üretim anahtarıyla `gemini-2.5-flash`'ı ilk gün dene (Google 2.5 erişimini kısıtlıyor) | `config/ai.php` |
| **10.57** | Asistan kotası günü UTC (İstanbul'da 03:00'te yenileniyor) | `davetkart.default_timezone` | `AskAssistantAction` + test |
| **10.58** | **K43** — paket alım kaç yayın açar? Bugün sınırsız | Frontend paket satın almayı zaten göstermiyor. Karar verilene kadar `POST /payments/checkout` **kapatılsın** (ya da `orders.publish_quota`) | Route ya da migration + Action |
| **10.59** | Aynı misafirin ikinci LCV'si ayrı satır, kota iki kez sayıyor | Misafire bir *yanıt kodu* (ULID) dönüp güncellemeye izin ver, ya da panelde aynı adı grupla | Karara göre |
| **10.60** | Hız sınırları: LCV davetiye kovası saatte 60 · misafir medyası IP başına dakikada 5 (salon Wi-Fi/CGNAT) · IPv6'da `/64` kovası yok | Gerçek bir düğün senaryosuyla ölç. IPv6'da anahtarı `/64` önekine indir | `AppServiceProvider` + config |
| **10.61** | İade yayını geri çekmiyor (#7) | K88'le birlikte bir okuma-anı kontrolü mü, yoksa iade webhook'unda `unpublish` mı? | Karara göre |
| **10.62** | IBAN biçim/mod-97 doğrulaması yok | TR IBAN'ı için özel kural (yanlış IBAN = kaybolan hediye) | `InvitationRequest` + Rule |
| **10.63** | Büyük harfli ULID `whereUlid`'den geçiyor ama bulunamıyor (QR kodu URL'i büyük harf yapabilir) | Public uçta `strtolower` | `ResolvePublicInvitationAction` |
| **10.64** | Misafir, aynı davetiyedeki **başka misafirin** medyasını kendi LCV'sine iliştirebilir | *"Henüz bir LCV'ye bağlanmamış"* koşulu | `SubmitRsvpAction` |
| **10.65** | `contact_messages` okunamıyor | Admin paneli yerine `contact:list` komutu | Yeni komut |
| **10.66** | **P-1** — fiyat kartı vaatleri | §2.3'teki kanıtlar | Karara göre (FE ve/veya backend) |

---

### Dilim G — 💳 Shopier entegrasyonu

**Önkoşul:** Dilim A (para doğru sayılıyor) ve C (dönüş sayfası var). Rapor §4'ün tablosu
bu dilimin girdisidir.

| # | Dosya | İş |
|---|---|---|
| **10.67** | Karar kaydı **K94** | Shopier panelindeki **güncel** dokümanla: klasik form akışı mı, REST API mi? İmzalanan alanlar, sıraları, bildirim kanalı (tarayıcı mı sunucu mu), test ortamı. Tahminle kod yazılmaz (kural 11) |
| **10.68** | `app/Services/Payment/PaymentGateway.php` + `CheckoutSession` + `FakeGateway` | Arayüz revizyonu: `CheckoutSession` *redirect* ya da *imzalı form* taşıyabilsin; `parseNotification(Request $request)`: imzayı nereden okuyacağına sürücü karar verir. `FakeGateway` uyarlanır, **mevcut PaywallTest'in hepsi yeşil kalır** (DIP'in bedeli burada ödenir) |
| **10.69** | `config/payment.php` · `.env.example` · `docs/10` | `shopier` bloğu, `IYZICO_*` kaldırılır. `webhook.tolerance_seconds` ya bu dilimde kullanılır ya silinir (ders 26) |
| **10.70** | `app/Services/Payment/ShopierGateway.php` (yeni) | `startCheckout`: imzalı alanlar (`platform_order_id` = `orders.id`). `parseNotification`: `hash_equals` (W2) + **tutar ve para birimi doğrulaması** (denetim: bildirim bugün tutar taşımıyor) + tekrar oynatma koruması |
| **10.71** | Callback ucu | Tarayıcı POST'u ise: idempotan işle → frontend'e **yönlendir** (`/odeme/basarili?order=…`). Sunucudan sunucuya ise mevcut webhook ucu kalır |
| **10.72** | `AppServiceProvider::resolvePaymentGateway` | `'shopier' => ShopierGateway` (K70: bilinmeyen sürücü yine 503) |
| **10.73** | `tests/Feature/ShopierGatewayTest.php` (yeni) | Belgeden alınan örnek imzalarla: geçerli/geçersiz imza, tutar uyuşmazlığı, tekrar, bilinmeyen sipariş |
| **10.74** | **FE** `components/payment/PaywallModal.tsx` | `redirectUrl` yerine imzalı form geldiğinde otomatik POST eden gizli form |
| **10.75** | Elle doğrulama | Shopier test ortamında uçtan uca: ödeme → dönüş sayfası → `paid` → yayın · geç bildirim (K89) |

---

### Dilim H — 🟢 Temizlik (tek commit'lik işler)

Her biri bağımsız. İki büyük dilim arasında nefes almak için iyi.

| # | İş | Kaynak |
|---|---|---|
| **10.76** | `git rm routes/web.php resources/views/welcome.blade.php` + Laravel'in frontend iskeleti (`resources/css`, `resources/js`, `vite.config.js`, `package.json`) + `composer.json`'daki `setup`/`dev` betiklerinin npm adımları | Rapor §6.1 (dokümanlar *"silindi"* diyor) |
| **10.77** | Ölü config: `davetkart.auth.*`, `rsvp.poll_interval_seconds` | Rapor §6.2 · ders 26 |
| **10.78** | `phpstan.neon` → `phpVersion: 80500` · CI yorumu (`^8.3` → `^8.5`) · K1 satırına K87 notu | **K87** |
| **10.79** | `composer.json` → `ext-pdo_pgsql` | Rapor §6.6 |
| **10.80** | `SubscriptionTier::label()` — dokuz fazdır çağrılmıyor (P-1'in sonucuna bağlı: fatura metni doğarsa kalır) | Faz 9 açık #3 |
| **10.81** | Git hijyeni: 4 ajan worktree'si, 178 commit geride dallar, `payment-servide` · frontend `.gitattributes` (530 sahte değişiklik) · `D:\Projects\davetkart\Claude outputs\FRONTEND-YAKALAMA-PLANI.md` (eski kopya) | Rapor §6.9-10 |

---

### Dilim Z — Kapanış

| # | İş |
|---|---|
| **10.82** | `composer check` — PHP 8.5 + PostgreSQL 18, **son satır** (kural 13). Sonuç `FAZ-10.md`'ye yazılır (B7) |
| **10.83** | `FAZ-10-ELLE-DOGRULAMA.md` yazılır ve koşulur. Aynı turda birikmiş borç: Faz 5-9 betikleri + frontend F8 (17 + yeni senaryolar; F8 #11'in beklentisi **201** olarak düzeltilir) |
| **10.84** | `FAZ-10.md` · `PHP-LARAVEL-SETUP-EK-FAZ-10.md` (K87–K94+) · `docs/07` · `docs/09` · `docs/11` Ek A (yeni uçlar) · `FAZ-10-DEVIR.md`. Beş EK dosyasının (5-9) master'a işlenmesi de bu adımda |

---

## 5. Sıra ve bağımlılıklar

```
10.0 ──► A (10.1–10.9) ──► C (10.21–10.29) ──► G (10.67–10.75, Shopier)
           │                    ▲
           └──► B (10.10–10.20) ┘ ← B bağımsız; A'dan hemen sonra
                    │
                    └──► D (10.30–10.46)  ← M-1, H-1, S-1 kararlarını bekler
E (10.47–10.54)  : A-D ile paralel; aynı test dosyasına dokunan adımla birleşebilir
F (10.55–10.66)  : karar geldikçe; 10.55 ve 10.56 kararsız, hemen yapılabilir
H (10.76–10.81)  : her an
Z (10.82–10.84)  : en son
```

Gerçek ödeme için minimum yol: **10.0 → A → B → C → G**. D lansmandan önce bitmeli, gerçek
ödemeyi bloke etmez.

---

## 6. Bu fazın tuzakları (baştan bil)

| # | Tuzak | Nerede |
|---|---|---|
| 1 | Kilitsiz plan kontrolü yarışa açık: eşzamanlı *"yayınla"* ile *"galeriyi aç"* ikisi de eski satırı görür | 10.1 → `lockForUpdate` şart (E9) |
| 2 | Autosave her tuşta PUT atar: 402 bir kez değil **onlarca** kez gelebilir | 10.8 |
| 3 | `ExpireStaleOrders`'ın mevcut testleri `failed` bekliyor. Yeşil kalıyorsa test etkiyi değil yanıtı doğruluyordur | 10.5 / 10.7 |
| 4 | Normalizasyon dört yerde birden değişmeli. Biri unutulursa throttle kovası ve kayıt ayrı anahtarla çalışır | 10.13 |
| 5 | Mevcut `i̇smail@…` satırı ile yeni `ismail@…` çakışabilir (UNIQUE ihlali) | 10.14 → önce `--dry-run` |
| 6 | `dontReportWhen` log dosyasını da susturur. Bilerek | 10.16 |
| 7 | `TRUSTED_PROXIES='*'` sunucuya doğrudan erişim açıksa sahte `X-Forwarded-For` ile bütün kovalar atlatılır | 10.18 |
| 8 | Veritabanındaki `cascadeOnDelete` model olaylarını **tetiklemez**: kullanıcı silinince dosyalar diskte kalır, cache temizlenmez | 10.39 → silme yalnızca Action'dan |
| 9 | Mail metni backend'de üretilir. K21 (*"backend tek dil"*) bunu hiç düşünmemişti | 10.30 / M-1 |
| 10 | Yeni hata kodu frontend'de 10 dile çevrilmeden sözleşme eksik kalır | 10.31 |
| 11 | Shopier klasik akışında bildirim **tarayıcıdan** gelebilir: kullanıcı sekmeyi kapatırsa bildirim hiç gelmez. K89 bu yüzden Shopier'den önce | 10.67 |

---

## 7. Faz 10 bitti ölçütü

- [ ] Standart planla yayınlanmış davetiyede galeri açma isteği **402**, veritabanı değişmedi
- [ ] Süresi dolmuş siparişe gelen imzalı `paid` bildirimi hakkı açıyor
- [ ] 31 günlük token 401 alıyor, `sanctum:prune-expired` satır siliyor
- [ ] `İsmail@…` ile kayıt olan `ismail@…` ile giriş yapabiliyor
- [ ] Ödeme dönüş sayfası siparişin durumunu gösteriyor
- [ ] Parola sıfırlama uçtan uca çalışıyor (gerçek mail kutusuna)
- [ ] Hesap silindiğinde dosyalar da gidiyor, sipariş kaydı kalıyor
- [ ] Test denetiminin 8 dosyasında hayatta kalan mutant yok
- [ ] `composer check` (PHP 8.5) son satırı yeşil · elle doğrulama betikleri işaretli
- [ ] (G bittiyse) Shopier test ortamında bir ödeme uçtan uca geçti

---

## 8. Nereden başlıyoruz?

**10.0**: `ef7c692`'nin `composer check` sonucunu senin makinende (PHP 8.5) alıyoruz.
Sonra **10.1**.

Başlamadan önce §2.3'teki beş sorudan en az **D-5**'i cevaplarsan Dilim B kesintisiz
ilerler. M-1, H-1 ve S-1 ancak Dilim D'de gerekiyor. P-1 hiçbir şeyi bloke etmiyor.
