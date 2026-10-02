# FAZ 10 — Sertleştirme ve Lansman Eksikleri (Plan)

> **Tarih:** 25 Eylül 2026
> **Kaynaklar:** `claude/GOZDEN-GECIRME-RAPORU.md` (23 Eylül, kod okuması) ·
> `claude/TEST-DENETIMI-2026-09-24.md` (24 Eylül, testler kum havuzunda gerçekten koşturuldu)
> **Başlangıç noktası:** `edit-test` dalı, `ef7c692` (25 Eylül 07:28)
> **Durum:** ✅ **Dilim 0, A, B ve C bitti** (25 Eylül – 1 Ekim 2026, 10.0–10.29). `composer check`
> İsmail'in makinesinde (**PHP 8.5.8 + PostgreSQL 18.4**) her adımda yeşil; son koşu **422/422** (B7).
> Frontend `npm run check` yeşil. Sıradaki: §8. Ayrıntı, sapmalar ve yeni bulgular: **§9**

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
| **K95** | Mail kanalı kodda **sağlayıcıdan bağımsız**; geliştirmede `MAIL_MAILER=log`. SES mi alan adının SMTP'si mi, deploy'da karar verilir | M-1 · 1 Ekim 2026. Kod Laravel'in mailer soyutlamasını kullanıyor; kanal yalnızca `.env`. Seçenekler ve SPF/DKIM notu `docs/10` → *Posta* | 10.30 |
| **K96** | Kullanıcıya giden mailler **Türkçe** (K21'in istisnası). Dil `config/davetkart.php` → `mail.locale` | M-1 · 1 Ekim 2026. K21 API yanıtları için *tek dil* diyordu; mail bir yanıt değil, kullanıcının okuyacağı metin | 10.34 |
| **K97** | Hesap silinince **anonimleştir**: kullanıcı, davetiyeleri, LCV'leri, medyası (dosyalarıyla) silinir; `orders` satırı kalır, `user_id = NULL` (`nullOnDelete`) | H-1 · 1 Ekim 2026. Muhasebe kaydı kullanıcıdan uzun yaşar (K82) | 10.38–10.41 |
| **K98** | Saklama süreleri: çöp kutusundaki davetiye **30 gün** · misafir verisi etkinlikten **6 ay** · iletişim mesajı **12 ay**. Gece 03:45'te `data:purge` | S-1 · 1 Ekim 2026. Sayılar config'te (E6), testlerde sabit | 10.42–10.45 |
| **K99** | Paket (davetiyesiz alınan sipariş) **tek davetiye** yayınlar: bağsız tekil sipariş olarak açılır, ilk yayında bağlanır (`ClaimReleasedOrderAction`). `'account'` kapsamı artık yazılmaz | 10.58 · 1 Ekim 2026. Fiyatlar davetiye başına; *"549 TL'ye sınırsız davetiye"* hiç vaat edilmemişti | 10.58 |
| **K100** | İade, davetiyeyi kapsayan başka ödenmiş sipariş bırakmıyorsa davetiye **yayından kalkar** ve taslağa döner | 10.61 · Para geri verildiyse hizmet de durur. Yalnızca iadede; başarısız yükseltme dokunmaz | 10.61 |
| **K101** | Misafire **düzenleme kodu**: aynı tarayıcıdan ikinci gönderim yeni satır açmaz, eskisini günceller (`PUT /public/invitations/{id}/rsvps/{rsvp}`). Kod yalnızca özetiyle saklanır | 10.59 · Kişi sayısı kotadan iki kez düşüyordu | 10.59 · FE 10.18 |
| **K102** | P-1 **karışık**: Elit'te *"DavetKart ile hazırlandı"* yok · premium = **videolu 13 tema**, en az Gold · *"video galeri"* karttan çıkar | 10.66 · Logo ve tema vaadi ucuz ve satış değeri yüksek; video işleme/depolama pahalı | 10.66 · FE 10.20 |
| **K103** | Zamanlanmış işler **Sentry Cron Monitors**'a bağlı (sabit izleyici adları) | 10.55 · Zamanlayıcı durursa kimse bilmiyordu (Faz 9 açık #1). İzleyici kotası deploy öncesi kontrol edilir | 10.55 |
| **K104** | Hız sınırları düğüne göre: LCV IP 20/dk, davetiye 300/saat · medya IP 15/dk, davetiye 150/saat · IPv6 kovası **/64** | 10.60 · 300 kişilik davetiye aynı akşam gönderiliyor, salonda herkes aynı Wi-Fi'da | 10.60 |
| **K105** | IBAN **her kayıtta** biçim + mod-97 ile doğrulanır (TR 26 karakter); değer yazıldığı gibi saklanır | 10.62 · İsmail önerilen "yayınlarken" yerine bunu seçti. Bedeli: yarım IBAN'la otomatik kayıt 422 alır; editör alanın altında uyarır | 10.62 · FE 10.19 |
| **K106** | Asistanın günlük kotası **İstanbul** gece yarısında yenilenir (`default_timezone`) | 10.57 · UTC'de İstanbul 03:00'te yenileniyordu (açık karar #4) | 10.57 |
| **D-5** ✅ | E-postada `İ` → **`i`** (seçenek a), tek kaynaklı `EmailNormalizer`. ASCII dışını reddetmek **değil** | 27 Eylül 2026. Türkçe klavyeli kullanıcıyı cezalandırmaz; büyük sağlayıcılar zaten ASCII dışı yerel kısım kabul etmiyor | 10.12–10.15 |

### 2.2 `ef7c692`'de uygulanmış, karar kaydına geçmemiş (10.0'da kayda geçer)

| # | Karar | Kaynak |
|---|---|---|
| **K91** | Bozuk girdi (yarım JSON, NUL) → **400**, alan başına değil `api` grubunda tek middleware | D-1 |
| **K92** | Sayı alanları `integer:strict` (`true` ve `"3"` reddedilir) | D-2 |
| **K93** | 429 ve 503 yanıtları `Retry-After` **başlığını** da taşır | D-3 · `docs/08` §4.1 |

### 2.3 🔴 Hâlâ açık: senin cevabını bekleyenler

| # | Soru | Öneri | Bloke ettiği adım |
|---|---|---|---|
| ~~**D-5**~~ | ~~E-postada `İ`~~ → ✅ cevaplandı (27 Eylül, seçenek a), §2.1 | — | — |
| ~~**M-1**~~ ✅ K95 · K96 | Mail kanalı (K79): Amazon SES mi, alan adının SMTP'si mi? Mailler hangi dilde? (K21 API için *tek dil* diyor, ama mail kullanıcıya giden metindir) | SES (AWS rehberiyle uyumlu) · mail dili Türkçe, K21'in istisnası olarak kayda geçer | 10.30 → tüm Dilim D'nin parola kısmı |
| ~~**H-1**~~ ✅ K97 | Hesap silinince ne olur? | **Anonimleştir**: kişisel veri silinir, `orders` satırı muhasebe için kalır (bugün `orders.user_id` `cascadeOnDelete` — kullanıcı silinirse sipariş kayıtları da silinir, K82 ile çelişir) | 10.38 |
| ~~**S-1**~~ ✅ K98 | Saklama süreleri: silinmiş davetiye kaç gün, etkinlikten sonra misafir verisi (ad, mesaj, foto) kaç ay, iletişim mesajı kaç ay? | 30 gün · 6 ay · 12 ay | 10.42 |
| ~~**P-1**~~ ✅ K102 | Fiyat kartı vaatleri — aşağıdaki kanıtlara bak | Karar senin | 10.66 |

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
| **10.0** ✅ | `ef7c692` commit'lendi. `composer check` İsmail'in makinesinde (PHP 8.5.8 + PostgreSQL 18.4, 27 Eylül): **347/347**, Pint · PHPStan L8 · `errors:export --check` yeşil (B7). K91–K93 kayda geçti (§2.2) | Son satır yeşil · RsvpTest'in 7 kırmızı vakası yeşile döndü |

---

### Dilim A — 🔴 Para ve paywall

**Neden önce:** ikisi de gerçek para kaybı. Biri müşterinin 300 ₺'sini bizden, öbürü bizim
tahsil ettiğimiz parayı müşteriden alıyor.

| # | Dosya | İş | Bulgu | Test / mutasyon |
|---|---|---|---|---|
| **10.1** ✅ | `app/Actions/Invitation/UpdateInvitationAction.php` | Satırı **kilitle ve yeniden oku** (`lockForUpdate`, E9: eşzamanlı yayınla yarışmasın). `published` ise `fill()`'den sonra `TierResolver::requiredFor()` ↔ `PublishEntitlementResolver::highestTierFor()`. Yetmiyorsa `PaywallViolationException::insufficientTier()`, transaction geri alınır. Taslak (`saved`) serbest kalır (K43'ün ruhu: denemenin bedeli olmaz). Modül **kapatmak** her zaman serbest | Rapor §1.1 · K-1 · **K88** | 10.2 |
| **10.2** ✅ | `tests/Feature/PaywallTest.php` | 5 test: yayındaki davetiyede plan üstü modül → 402 + **DB'de bayrak `false`** (T14) · plan içi modül → 200 · modül kapatma → 200 · plan yükseltilince aynı istek → 200 · taslakta her şey serbest. Mutasyon: 10.1'deki `if`'i sil → ilk test kırılmalı | — | — |
| **10.3** ✅ | `app/Enums/OrderStatus.php` | `case Expired = 'expired'`. `canTransitionTo`: `Pending → Paid/Failed/Expired`, **`Expired → Paid`**. `grantsPublishRight()` ve `hasBeenPaid()` değişmez | Rapor §1.2 · K-4 · **K89** | 10.7 |
| **10.4** ✅ | `database/migrations/…_add_expired_to_orders_status.php` | `orders_status_check`'i enum'dan yeniden kur (K39 deseni: düşür → yeniden yaz). `paid_at` CHECK'i etkilenmez (`Expired` ödenmiş sayılmaz) | — | `migrate` + `migrate:rollback` |
| **10.5** ✅ | `app/Console/Commands/ExpireStaleOrders.php` | `Failed` yerine `Expired` yazar. Koşullu `UPDATE` (eşzamanlı yarış koruması) aynen kalır | — | 10.7 |
| **10.6** ✅ | `app/Actions/Payment/HandlePaymentCallbackAction.php` | Geçiş reddedildiğinde gelen durum `paid` ise **`Log::critical`** (sipariş, sağlayıcı ref, mevcut durum). Sentry'ye düşer. Para alındı, hak açılamadı: elle müdahale gerekir | K-4 | 10.7 |
| **10.7** ✅ | `tests/Feature/PaywallTest.php` · `MaintenanceTest.php` | `orders:expire` → `expired` · expire sonrası imzalı `paid` → sipariş `paid`, `paid_at` dolu, yayın açılır · `failed` sonrası `paid` → reddedilir **ve** critical log (`Log::spy()`). Mutasyon: `Expired → Paid` kolunu sil | — | — |
| **10.8** ✅ | **FE** `components/create/EditorWorkspace.tsx` (+ `useInvitationStore`) | Autosave'de 402 gelirse: açılan anahtarı geri al, paywall'ı `reason: 'upgrade'` + sunucunun `requiredTier`'ıyla aç. Autosave bir 402 fırtınası üretmesin (aynı alan için tek paywall) | K88 | Elle: Standart ile yayınla → galeriyi aç |
| **10.9** ✅ | **FE** `src/types.ts` | `OrderStatus`'a `'expired'` | K89 | `npm run lint` |

> ✅ Dilim A'nın kodu yazıldı (25 Eylül) ve İsmail'in makinesinde doğrulandı (27 Eylül,
> PHP 8.5.8 + PostgreSQL 18.4, 347/347). Planın metni **değiştirilmedi**; uygulamada
> dokuz sapma/ekleme oldu → **§9.1**. En önemlisi: 10.6'daki *"Sentry'ye düşer"* doğru
> değil (yeni satır **10.55b**).

---

### Dilim B — 🟠 Güvenlik ve veri bütünlüğü

| # | Dosya | İş | Bulgu | Test / mutasyon |
|---|---|---|---|---|
| **10.10** ✅ | `config/sanctum.php` · `routes/console.php` | `'expiration' => 60 * 24 * 30`. `sanctum:prune-expired --hours=24` (tablo gerçekten küçülür). `console.php`'deki yanlış *"iz kalsın"* yorumu düzeltilir: çıkışta token zaten siliniyor | Rapor §2.2 · **K90** | 10.11 |
| **10.11** ✅ | `tests/Feature/AuthTest.php` | `travel(31)->days()` → 401 `UNAUTHENTICATED` · prune gerçekten siler (MaintenanceTest) · denetimin bulduğu iki `actingAs()` → `withToken()` (**T10** ihlali) | — | Mutasyon: `expiration`'ı `null` yap → test kırılmalı |
| **10.12** ✅ | `app/Support/EmailNormalizer.php` (yeni) | Tek kaynak: `trim` → `İ→i` → `mb_strtolower`. **D-5 kararı gerekir** | K-3 | 10.15 |
| **10.13** ✅ | `RegisterRequest` · `LoginRequest` · `User` mutator · `AppServiceProvider::authLimits` | Dördü de 10.12'yi çağırır (bugün dördü ayrı `mb_strtolower` yazıyor: C3 ihlali). Throttle anahtarı da aynı normalizasyondan geçmezse `İ`/`i` iki ayrı kova olur | K-3 | 10.15 |
| **10.14** ✅ | `app/Console/Commands/NormalizeUserEmails.php` (yeni) | `users:normalize-emails --dry-run`: mevcut `i̇` (U+0307) içeren satırları düzeltir. **Çakışma** (aynı adresin iki hesabı) varsa yazmaz, raporlar (K84: bakım komutu önce elle) | K-3 | MaintenanceTest |
| **10.15** ✅ | `tests/Feature/AuthTest.php` | `İsmail.Cankaya@…` ile kayıt → `ismail.cankaya@…` ile giriş 200 · aynı adres büyük `İ` ile ikinci kez → `REGISTRATION_FAILED` · gerçek Türkçe adlar (denetim: *"ASCII isimler"*) | — | — |
| **10.16** ✅ | `bootstrap/app.php` | `$exceptions->dontReportWhen(fn ($e) => $e instanceof HasErrorCode && $e->errorCode()->status() < 500)`. Yan etki (bilinçli): 4xx iş istisnaları `laravel.log`'a da düşmez | Rapor §5.1 | `Exceptions::fake()` + `assertNotReported(InvalidCredentialsException::class)` · 502 hâlâ raporlanır |
| **10.17** ✅ | `phpunit.xml` · `tests/TestCase.php` | `SENTRY_LARAVEL_DSN=""` · `setUp()`'ta `Http::preventStrayRequests()` | Rapor §6.4-5 | Bir testte sağlayıcı bağlamayı unut → gerçek ağa değil hataya düşmeli |
| **10.18** ✅ | `config/davetkart.php` + `AppServiceProvider` | `trusted_proxies` (env `TRUSTED_PROXIES`, **varsayılan boş**). Boot'ta `TrustProxies::at(...)` (config'ten okunur, böylece `config:cache` altında da çalışır). `'*'` yalnızca sunucuya doğrudan erişim ağ seviyesinde kapalıysa | Rapor §2.3 | HardeningTest: güvenilir proxy'den gelen `X-Forwarded-For` → `ip()` istemciyi döner, güvenilmeyenden gelen yok sayılır |
| **10.19** ✅ | `app/Http/Requests/Invitation/InvitationRequest.php` | `mapUrl` → `url:http,https` · `giftOptions.*` → `integer:strict` | K-7 · K-8 · Rapor §6.3 | InvitationTest: `smb://` 422, `[true, 500]` 422 |
| **10.20** ✅ | `tests/Feature/MalformedInputTest.php` (yeni) | 10.0'ın kapısını **her grupta** sına: auth, invitation, public LCV/iletişim, asistan, webhook. Ayrıca `Retry-After` başlığı auth/contact/asistan 429'unda | K-2 · K-5 · K-6 | Mutasyon: middleware kaydını sil → hepsi kırılmalı |

> ✅ Dilim B'nin kodu yazıldı (27 Eylül – 1 Ekim) ve her adımda İsmail'in makinesinde
> `composer check` yeşil (son: **407/407**). Plandan on sapma oldu (S10–S19) ve iki
> yeni satır doğdu (**10.79b**, **10.70**'e not) → **§9.2**. En önemlileri:
> 10.17'de `<env force>` CI'daki bir değişkeni **ezemiyor** (`<server>` gerekti);
> 10.18'de Laravel'in kendi `config/trustedproxy.php` anahtarı plandaki
> provider kodunun yerini aldı.

---

### Dilim C — 🟠 Ödeme ve yayın akışının eksik uçları

**Neden:** Dilim A parayı doğru saydırıyor, ama kullanıcı ödemeden sonra hâlâ hiçbir şey
göremiyor (rapor §2.1). Shopier'den **önce** gerekiyor, çünkü dönüş sayfası sağlayıcıdan
bağımsız.

| # | Dosya | İş | Bulgu | Test |
|---|---|---|---|---|
| **10.21** ✅ | `app/Http/Resources/InvitationResource.php` | `publishedAt` (ISO 8601 ya da `null`). **Yalnızca sahip** Resource'u; public'te yok (C5) | Frontend F7.2 · rapor §2.1 | InvitationTest + PublicInvitationTest (sızmaz) |
| **10.22** ✅ | `app/Policies/OrderPolicy.php` (yeni) | `view`: sahiplik. Red → 404 (H7) | — | 10.25 |
| **10.23** ✅ | `app/Http/Controllers/Api/V1/OrderController.php` (yeni) + `routes/api.php` | `GET /api/orders` (liste, **sorgu kapsamıyla** — P3) · `GET /api/orders/{order}` (`whereUlid`, Policy) | Rapor §2.1 · Faz 9 açık #9 | 10.25 |
| **10.24** ✅ | `app/Http/Resources/OrderResource.php` | Beyaz listeye `invitationId` (nullable), `createdAt`, `paidAt`. `providerRef` yine **yok** | — | 10.25 |
| **10.25** ✅ | `tests/Feature/OrderTest.php` (yeni) | Sahibi görür · başkası 404 · liste yalnızca kendi · `providerRef` sızmaz · `{data}` zarfı · `pending/paid/expired` doğru gelir | — | — |
| **10.26** ✅ | **FE** `src/services/payments.ts` | `getOrder(id)` · `listOrders()` | — | `npm run verify:endpoints` |
| **10.27** ✅ | **FE** `src/pages/PaymentReturnPage.tsx` (yeni) + `App.tsx` | `/odeme/basarili` ve `/odeme/hata`. `?order=` ile siparişi birkaç saniye yoklar: `paid` → *"Ödemen onaylandı, şimdi yayınlayabilirsin"* (K67: ödeme yayınlamaz); `pending` → bekliyor; `expired/failed` → tekrar dene | Rapor §2.1 | F8'e yeni senaryo |
| **10.28** ✅ | **FE** `src/pages/DashboardPage.tsx` | Silme uyarısı `publishedAt`'e bakarak kesin: *"hakkınız X tarihine kadar serbest kalır"* ya da *"yanacak"* | F7.2 | Elle |
| **10.29** ✅ | **FE** `src/services/auth.ts` · `stores/useAuthStore.ts` | Açılışta `GET /auth/me`: iptal ya da süresi dolmuş token hemen düşer, kullanıcı bilgisi tazelenir (30 günlük token'la daha önemli) | Rapor §3 | Elle |

> ✅ Dilim C'nin kodu yazıldı (1 Ekim). Backend `composer check` **422/422**, frontend
> `npm run check` yeşil. Frontend commit dizisi geçici bir index'te baştan sona oynatıldı:
> her adım kendi başına `tsc` ile derleniyor. Sapmalar (S20–S27) ve yeni bulgular → **§9.3**.
> En önemlisi: 10.21 `publishedAt`'in yanında **`releasableUntil`** de dönüyor, böylece
> frontend *"3 gün"*ü kendisi hesaplamıyor ve kural tek yerde kalıyor.

---

### Dilim D — 🟠 Hesap yaşam döngüsü ve KVKK

**Neden:** Parolasını unutan kullanıcı, parasını ödediği davetiyeye bir daha erişemiyor.
Misafirlerin kişisel verileri süresiz duruyor.

| # | Dosya | İş | Karar | Test |
|---|---|---|---|---|
| **10.30** ✅ | `config/mail.php` · `.env.example` · `docs/10` | Mail kanalı ve dili. SES ise `MAIL_MAILER=ses` + `aws/aws-sdk-php` | **M-1** | `php artisan tinker` → test maili |
| **10.31** ✅ | `app/Enums/ErrorCode.php` (+ `contracts/`, FE `errors.json` ×10) | `PASSWORD_RESET_INVALID` (400 ya da 422 — bu adımda tartışılır) | — | `errors:export --check` |
| **10.32** ✅ | `ForgotPasswordRequest` + `SendPasswordResetLinkAction` | `POST /api/auth/forgot-password` → **her durumda 202** (enumeration yok, `docs/08` §3.1), `throttle:auth` | — | 10.36 |
| **10.33** ✅ | `ResetPasswordRequest` + `ResetPasswordAction` | `POST /api/auth/reset-password` (token + e-posta + yeni parola). Başarıda **tüm** token'lar iptal | — | 10.36 |
| **10.34** ✅ | `AppServiceProvider` | `ResetPassword::createUrlUsing()` → frontend URL'i (`/sifre-sifirla?token=…&email=…`). Mail şablonu M-1'in diliyle | M-1 | 10.36 |
| **10.35** ✅ | `AuthController` + `routes/api.php` | İki uç `auth` grubunun `throttle:auth` alt grubunda | — | 10.36 |
| **10.36** ✅ | `tests/Feature/PasswordResetTest.php` (yeni) | Kayıtlı/kayıtsız e-posta **aynı** yanıt (A2) · `Notification::fake()` · geçersiz/süresi dolmuş token · eski token'lar 401 · throttle | — | — |
| **10.37** ✅ | **FE** `ForgotPasswordPage` · `ResetPasswordPage` · `auth.ts` · `LoginPage` linki | — | — | Elle |
| **10.38** ✅ | Karar kaydı + `database/migrations/…_make_orders_user_id_nullable.php` | H-1 anonimleştirme seçilirse: `orders.user_id` nullable + `nullOnDelete` (muhasebe kaydı kullanıcıdan uzun yaşar) | **H-1** | `migrate` |
| **10.39** ✅ | `app/Actions/Auth/DeleteAccountAction.php` (yeni) | Parola onayı → davetiyeler **Action üzerinden** kalıcı silinir (DB `cascade` model olaylarını ve dosyaları atlar: disk sızıntısı) → medya dosyaları → token'lar → kullanıcı anonimleşir/silinir | H-1 | 10.41 |
| **10.40** ✅ | `AuthController::destroy` + `DELETE /api/auth/me` | 204 | — | 10.41 |
| **10.41** ✅ | `tests/Feature/AccountDeletionTest.php` (yeni) | Yanlış parola 422 · davetiye/LCV/medya satırı **ve dosyası** gider · `orders` kalır · token 401 · başka kullanıcıya dokunulmaz | — | — |
| **10.42** ✅ | Karar kaydı + `config/davetkart.php` → `retention` | S-1'in sayıları config'e (E6: iş tercihi) | **S-1** | — |
| **10.43** ✅ | `app/Console/Commands/PurgeExpiredData.php` (yeni) | `data:purge --dry-run`: süresi dolan silinmiş davetiyeler + etkinliği geçmiş davetiyelerin misafir verisi + eski iletişim mesajları. **Önce dosya, sonra satır** (PruneOrphanMedia deseni) | S-1 | 10.45 |
| **10.44** ✅ | `routes/console.php` | Günlük, gece, `withoutOverlapping` + `onOneServer` | — | `schedule:list` |
| **10.45** ✅ | `tests/Feature/MaintenanceTest.php` | Sınırın bir gün öncesi/sonrası · dosyalar silinir · `orders` dokunulmaz | — | — |
| **10.46** ✅ | **FE** hesap ayarları (silme düğmesi, parola onayı) + `legal/` KVKK metni | Metnin **içeriği** senin/hukukçunun işi, kod değil | — | Elle |

> ✅ Dilim D'nin kodu yazıldı (1 Ekim). Kararlar **K95–K98** (§2.1). Backend `composer check`
> **447/447**, frontend `npm run check` yeşil. Frontend adımları İsmail'in numaralandırmasıyla
> **FE 10.14–10.17** (10.37 → FE 10.16, 10.46 → FE 10.17; FE 10.14 ve 10.15 plan dışı). Sapmalar
> (S28–S37) ve yeni bulgular → **§9.4**. En önemlisi: hesap silmede siparişler kalıyor ama
> sahipsiz (`user_id = NULL`), ve gizlilik metni (`PrivacyPage`) saklama süreleri konusunda
> kodla **çelişiyor** (hukukçunun işi, değiştirilmedi).


---

### Dilim E — 🟡 Test denetiminin kalan dosyaları

`TEST-DENETIMI` §2'nin sırası. Her satırın hedefi, o dosyada **hayatta kalan mutant bırakmamak**.
A, B ve C'deki test adımlarıyla aynı dosyalara dokunanlar o adımla birleştirilebilir.

| # | Dosya | Hayatta kalan mutantlar (denetimden) |
|---|---|---|
| **10.47** ✅ | `PublicInvitationTest` | 🔴 **Cache anahtarından `id` çıkarılınca yeşil**. Tüm davetiyeler tek cache girdisine düşse (çiftler arası sızıntı) hiçbir test kırılmıyor · `names/venue/mapUrl` boş dönse yeşil · `date` ISO'ya dönse yeşil · `show_rsvp=false` iken `rsvpDeadline` sızsa yeşil (C6) |
| **10.48** ✅ | `InvitationTest` | İstek eşlemesinden `names`, `venue`, `mapUrl`, `iban`/`bankName`/`accountHolder`, `showGift` düşürülse yeşil · liste sıralaması · oyuncak veri |
| **10.49** ✅ | `PaywallTest` | `SubscriptionTier::price()` → 1 yeşil (beklenen aynı fonksiyonla hesaplanıyor; sabit **24900** yazılmalı) · `currency` → USD yeşil · `show_envelope` Gold→Standart yeşil |
| **10.50** ✅ | `MediaTest` | İçerik MIME'ı yalnızca `UploadedFile::fake()` ile sınanıyor. Gerçek baytlı dosyalarla: PHP-as-JPG, SVG, polyglot |
| **10.51** ✅ | `ContactTest` | Saatlik kova silinse yeşil · NUL · Türkçe veri |
| **10.52** ✅ | `AssistantTest` | `retryAfter` yalnızca `assertIsInt` → `travelTo` ile sabitlenmeli |
| **10.53** ✅ | `HardeningTest` | HSTS bloğu silinse yeşil → `https://localhost` isteğiyle otomatik test |
| **10.54** ✅ | Kılavuzlar | `rehber/tests/Feature/HardeningTest.md` · `MaintenanceTest.md` (K18 borcu) — 🟡 `MaintenanceTest.md` 10.5'te yazıldı, `HardeningTest.md` bekliyor |
| **10.54b** 🆕 ✅ | `MaintenanceTest` | `every_scheduled_command_guards_against_overlapping` **boş yeşil**: `mutexName()` her iş için dolu döner, `withoutOverlapping()` silinse de geçer (10.5'te kum havuzunda kanıtlandı). `onOneServer()` hiç sınanmıyor. Doğrusu: `assertTrue($event->withoutOverlapping)` · `assertTrue($event->onOneServer)` |

> ✅ Dilim E bitti (1 Ekim). `composer check` **473/473** (447'den +26). Denetimin bu dokuz
> satırda hayatta bıraktığı mutantların hepsi öldü; adım başına mutasyon tabloları ilgili
> kılavuzlarda. Yeni bulgu: medyada `mimetypes:` kuralı silinse fotoğraflar `dimensions`
> kuralına takılıyordu, ama **video** türünde tek savunma oydu (S41). → **§9.5**


---

### Dilim F — 🟡 Ürün ve operasyon kararları

Kod adımından önce karar gelir. Önerim her satırda.

| # | Konu | Öneri | Kod |
|---|---|---|---|
| **10.55** ✅ | Zamanlanmış işler koşmazsa kimse bilmiyor (Faz 9 açık #1) | Sentry **Cron Monitors**: `->sentryMonitor()` makrosu paketle geliyor | `routes/console.php`, 3 satır |
| **10.55b** 🆕 ✅ | 10.6'nın `Log::critical`'ı (*"para alındı, hak açılamadı"*) **Sentry'ye gitmiyor**: `Integration::handles()` yalnızca istisnaları yollar, üretimde `LOG_STACK=daily` | `config/logging.php` → `'sentry' => ['driver' => 'sentry', 'level' => 'critical']` + üretim `.env` → `LOG_STACK=daily,sentry`. Seviye `critical` olmalı: paketin kendi kaydettiği kanal seviyesiz, istisnalar `error` ile log'a da yazıldığı için Sentry'ye **iki kez** giderdi | `config/logging.php` + `docs/10` (+ kılavuz) |
| **10.56** ✅ | `AI_PROVIDER` varsayılanı `gemini`, belgesi `null` diyor (rapor §5.7) | Kodu `null` yap. Üretim anahtarıyla `gemini-2.5-flash`'ı ilk gün dene (Google 2.5 erişimini kısıtlıyor) | `config/ai.php` |
| **10.57** ✅ | Asistan kotası günü UTC (İstanbul'da 03:00'te yenileniyor) | `davetkart.default_timezone` | `AskAssistantAction` + test |
| **10.58** ✅ | **K43** — paket alım kaç yayın açar? Bugün sınırsız | Frontend paket satın almayı zaten göstermiyor. Karar verilene kadar `POST /payments/checkout` **kapatılsın** (ya da `orders.publish_quota`) | Route ya da migration + Action |
| **10.59** ✅ | Aynı misafirin ikinci LCV'si ayrı satır, kota iki kez sayıyor | Misafire bir *yanıt kodu* (ULID) dönüp güncellemeye izin ver, ya da panelde aynı adı grupla | Karara göre |
| **10.60** ✅ | Hız sınırları: LCV davetiye kovası saatte 60 · misafir medyası IP başına dakikada 5 (salon Wi-Fi/CGNAT) · IPv6'da `/64` kovası yok | Gerçek bir düğün senaryosuyla ölç. IPv6'da anahtarı `/64` önekine indir | `AppServiceProvider` + config |
| **10.61** ✅ | İade yayını geri çekmiyor (#7) | K88'le birlikte bir okuma-anı kontrolü mü, yoksa iade webhook'unda `unpublish` mı? | Karara göre |
| **10.62** ✅ | IBAN biçim/mod-97 doğrulaması yok | TR IBAN'ı için özel kural (yanlış IBAN = kaybolan hediye) | `InvitationRequest` + Rule |
| **10.63** ✅ | Büyük harfli ULID `whereUlid`'den geçiyor ama bulunamıyor (QR kodu URL'i büyük harf yapabilir) | Public uçta `strtolower` | `ResolvePublicInvitationAction` |
| **10.64** ✅ | Misafir, aynı davetiyedeki **başka misafirin** medyasını kendi LCV'sine iliştirebilir | *"Henüz bir LCV'ye bağlanmamış"* koşulu | `SubmitRsvpAction` |
| **10.65** ✅ | `contact_messages` okunamıyor | Admin paneli yerine `contact:list` komutu | Yeni komut |
| **10.66** ✅ | **P-1** — fiyat kartı vaatleri | §2.3'teki kanıtlar | Karara göre (FE ve/veya backend) |

> ✅ Dilim F'nin kodu yazıldı (1–2 Ekim). Kararlar **K99–K106** (§2.1); sekiz kararın yedisi önerilen
> seçenek, IBAN'da (K105) İsmail *"her kayıtta"*yı seçti. Backend `composer check` **543/543** (473'ten
> +70), frontend `npm run check` yeşil. Frontend adımları **FE 10.18–10.20**. Sapmalar (S45–S56) ve yeni
> bulgular → **§9.6**. En önemlisi: paket artık tek davetiyelik (K99) ve iade yayını geri çekiyor (K100);
> ikisi birlikte *"ödenmeden yayında"* kalabilen son iki yolu kapatıyor.


---

### Dilim G — 💳 Shopier entegrasyonu

**Önkoşul:** Dilim A (para doğru sayılıyor) ve C (dönüş sayfası var). Rapor §4'ün tablosu
bu dilimin girdisidir.

| # | Dosya | İş |
|---|---|---|
| **10.67** | Karar kaydı **K94** | Shopier panelindeki **güncel** dokümanla: klasik form akışı mı, REST API mi? İmzalanan alanlar, sıraları, bildirim kanalı (tarayıcı mı sunucu mu), test ortamı. Tahminle kod yazılmaz (kural 11) |
| **10.68** | `app/Services/Payment/PaymentGateway.php` + `CheckoutSession` + `FakeGateway` | Arayüz revizyonu: `CheckoutSession` *redirect* ya da *imzalı form* taşıyabilsin; `parseNotification(Request $request)`: imzayı nereden okuyacağına sürücü karar verir. `FakeGateway` uyarlanır, **mevcut PaywallTest'in hepsi yeşil kalır** (DIP'in bedeli burada ödenir) |
| **10.69** | `config/payment.php` · `.env.example` · `docs/10` | `shopier` bloğu, `IYZICO_*` kaldırılır. `webhook.tolerance_seconds` ya bu dilimde kullanılır ya silinir (ders 26) |
| **10.70** | `app/Services/Payment/ShopierGateway.php` (yeni) | `startCheckout`: imzalı alanlar (`platform_order_id` = `orders.id`). `parseNotification`: `hash_equals` (W2) + **tutar ve para birimi doğrulaması** (denetim: bildirim bugün tutar taşımıyor) + tekrar oynatma koruması · 🆕 (10.16'dan) imza reddinde tek satırlık `Log::warning`: `InvalidWebhookSignatureException` artık raporlanmıyor, sahte webhook denemelerinin başka izi yok |
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
| **10.79b** 🆕 | `phpunit.xml`: `DB_DATABASE` (ve kalan `<env>` satırları) → `<server>`. `<env>`, `force="true"` ile bile, kabukta/CI'da tanımlı gerçek bir ortam değişkenini **ezemiyor** (Laravel `$_SERVER`'ı önce okur, 10.17'de denendi). `DB_DATABASE=davetkart` tanımlı bir makinede testler geliştirme veritabanında koşar ve `RefreshDatabase` onun tablolarını **siler** | 10.17 · `rehber/phpunit.md` §4.1 |
| **10.80** | `SubscriptionTier::label()` — dokuz fazdır çağrılmıyor (P-1'in sonucuna bağlı: fatura metni doğarsa kalır) · 🆕 `OrderStatus::isFinal()` de Faz 7'den beri çağrılmıyor (10.3'te durum makinesinden türetildi, silinmedi) | Faz 9 açık #3 · ders 26 |
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
| 1 | Kilitsiz plan kontrolü yarışa açık: eşzamanlı *"yayınla"* ile *"galeriyi aç"* ikisi de eski satırı görür | 10.1 → `lockForUpdate` şart (E9) — ✅ yazıldı; test edilemez (T15) |
| 2 | Autosave her tuşta PUT atar: 402 bir kez değil **onlarca** kez gelebilir | 10.8 — ✅ anahtar geri alınıyor; formun bayat taslağı da (10.8c) |
| 3 | `ExpireStaleOrders`'ın mevcut testleri `failed` bekliyor. Yeşil kalıyorsa test etkiyi değil yanıtı doğruluyordur | 10.5 / 10.7 — ✅ test beklendiği gibi **kırmızıya döndü** |
| 4 | Normalizasyon dört yerde birden değişmeli. Biri unutulursa throttle kovası ve kayıt ayrı anahtarla çalışır | 10.13 — ✅ dört yer tek fonksiyon; her biri ayrı bir testle korunuyor (10.15) |
| 5 | Mevcut `i̇smail@…` satırı ile yeni `ismail@…` çakışabilir (UNIQUE ihlali) | 10.14 — ✅ çakışma yazılmaz, raporlanır, çıkış kodu 1. 🔴 Deploy: 10.13 ile aynı gün koşulmalı (`NormalizeUserEmails.md` §5) |
| 6 | `dontReportWhen` log dosyasını da susturur. Bilerek | 10.16 — ✅ bilerek. En görünür kayıp: sahte webhook denemelerinin izi (→ 10.70 notu) |
| 7 | `TRUSTED_PROXIES='*'` sunucuya doğrudan erişim açıksa sahte `X-Forwarded-For` ile bütün kovalar atlatılır | 10.18 — ✅ varsayılan boş; `'*'`in tehlikesi test ediliyor |
| 8 | Veritabanındaki `cascadeOnDelete` model olaylarını **tetiklemez**: kullanıcı silinince dosyalar diskte kalır, cache temizlenmez | 10.39 → silme yalnızca Action'dan |
| 9 | Mail metni backend'de üretilir. K21 (*"backend tek dil"*) bunu hiç düşünmemişti | 10.30 / M-1 |
| 10 | Yeni hata kodu frontend'de 10 dile çevrilmeden sözleşme eksik kalır | 10.31 |
| 11 | Shopier klasik akışında bildirim **tarayıcıdan** gelebilir: kullanıcı sekmeyi kapatırsa bildirim hiç gelmez. K89 bu yüzden Shopier'den önce | 10.67 |

---

## 7. Faz 10 bitti ölçütü

- [x] Standart planla yayınlanmış davetiyede galeri açma isteği **402**, veritabanı değişmedi — kod + test (Dilim A), İsmail'in makinesinde yeşil (27 Eylül)
- [x] Süresi dolmuş siparişe gelen imzalı `paid` bildirimi hakkı açıyor — kod + test (Dilim A), İsmail'in makinesinde yeşil (27 Eylül)
- [x] 30. günün sonunda token 401 alıyor, zamanlayıcıdaki `sanctum:prune-expired` satır siliyor (10.11 · `AuthTest` §3.6 · `MaintenanceTest` §6b)
- [x] `İsmail@…` ile kayıt olan `ismail@…` ile giriş yapabiliyor (10.15 · `AuthTest` §3.7) · eski satırlar `users:normalize-emails` ile (10.14)
- [x] Ödeme dönüş sayfası siparişin durumunu gösteriyor (10.27 · `verify:payment`; elle doğrulama `PaymentReturnPage.md` §8, Z 10.83)
- [ ] Parola sıfırlama uçtan uca çalışıyor (gerçek mail kutusuna)
- [ ] Hesap silindiğinde dosyalar da gidiyor, sipariş kaydı kalıyor
- [ ] Test denetiminin 8 dosyasında hayatta kalan mutant yok
- [ ] `composer check` (PHP 8.5) son satırı yeşil · elle doğrulama betikleri işaretli
- [ ] (G bittiyse) Shopier test ortamında bir ödeme uçtan uca geçti

---

## 8. Nereden başlıyoruz?

~~**10.0**: `ef7c692`'nin `composer check` sonucunu senin makinende (PHP 8.5) alıyoruz.
Sonra **10.1**.~~ → Dilim A yazıldı (§9.1).

~~**Sıradaki:** Dilim A'nın commit'leri + `composer check` (PHP 8.5) kaydı, sonra **Dilim B**.~~
→ Dilim A doğrulandı, Dilim B yazıldı (§9.2).

~~**Sıradaki:** Dilim B'nin kalan commit'leri, sonra **Dilim C**.~~ → Dilim C yazıldı (§9.3).

**Sıradaki:** Dilim C'nin commit'leri (§9.3), sapmaların onayı (§9.1–§9.3). Sonra üç yol:

| Yol | Ne gerekiyor | Not |
|---|---|---|
| **G** (Shopier) | **K94**: Shopier panelindeki güncel doküman (akış, imza, bildirim kanalı, test ortamı) | Gerçek ödeme için son dilim. Tahminle kod yazılmaz (kural 11) |
| **D** (hesap yaşam döngüsü) | **M-1** (mail kanalı + dili), **H-1** (silme = anonimleştirme mi), **S-1** (saklama süreleri) | Lansmandan önce |
| **E / H** (test denetimi, temizlik) | Karar gerektirmiyor | Hemen yapılabilir; **10.79b** küçük bir onay istiyor |

P-1 (fiyat kartı vaatleri) hiçbir şeyi bloke etmiyor.

---

## 9. İlerleme kaydı

### 9.1 Dilim A — 25 Eylül 2026 (kod yazıldı · 27 Eylül'de İsmail'in makinesinde doğrulandı)

**Commit'ler** — her satır bir commit, adım adım:

| Adım | Depo | Başlık | Dosyalar |
|---|---|---|---|
| 10.1 | backend | `10.1 - fix(paywall): Re-check the tier when a published invitation enables a module` | `UpdateInvitationAction` + kılavuz |
| 10.2 | backend | `10.2 - test(paywall): Cover module changes on published invitations (7 tests)` | `PaywallTest` + kılavuz §11 |
| 10.3 | backend | `10.3 - feat(orders): Add the expired status and allow expired to paid` | `OrderStatus` + kılavuz · 🆕 `tests/Unit/OrderStatusTest` + kılavuz |
| 10.4 | backend | `10.4 - feat(orders): Rebuild the status check constraint to accept expired` | migration + kılavuz |
| 10.5 | backend | `10.5 - fix(orders): Mark stale pending orders expired instead of failed` | `ExpireStaleOrders` · `MaintenanceTest` · `OrderFactory` + üç kılavuz (`MaintenanceTest.md` yeni) |
| 10.6 | backend | `10.6 - feat(payments): Log a rejected paid notification as critical, a late one as a warning` | `HandlePaymentCallbackAction` + kılavuz §13 |
| 10.7 | backend | `10.7 - test(paywall): Prove a late payment after expiry still grants the order` | `PaywallTest` + kılavuz §12 |
| — | backend | `docs(phase10): Record the Dilim A progress, deviations and new findings` | bu dosya · `TEST-DENETIMI` (K-1, K-4 ✅) |
| 10.8a | frontend | `10.8a - feat(paywall): Map a paywall 402 to paywall options in one place` | `useSubscriptionStore` + kılavuz (yeni) |
| 10.8b | frontend | `10.8b - fix(editor): Roll back a module the server rejected on autosave` | `useInvitationStore` + kılavuz eki |
| 10.8c | frontend | `10.8c - fix(editor): Write only the fields a form changed when a draft flushes` | `useInvitationDraft` + kılavuz §6 |
| 10.8d | frontend | `10.8d - fix(editor): Show the paywall in every wizard stage` | `CreatePage` · `EditorWorkspace` · `PaywallModal` + iki kılavuz (yeni) |
| 10.8e | frontend | `10.8e - test(editor): Verify the autosave 402 rollback and the draft flush` | `scripts/verify-editor-state.ts` |
| 10.9 | frontend | `10.9 - feat(types): Add the expired order status` | `types.ts` + kılavuz §10 |

**Doğrulama — nerede, neyle (B7):**

| Ne | Nerede | Sonuç |
|---|---|---|
| `composer check`, **her** backend commit'inde ayrı ayrı | Kum havuzu: PHP 8.4.21 + PostgreSQL 16.13 | Pint · PHPStan L8 · `errors:export --check` yeşil; test sayısı 331 → 338 → 343 → 343 → 343 → 343 → **347** |
| Migration 10.4: `migrate` → `expired` satır → `migrate:rollback` → `migrate` | Kum havuzu, gerçek veritabanı | Satır `failed`'a döndü, kısıt daraldı, `expired` yazımı reddedildi, yeniden genişledi |
| Mutasyon, backend | Kum havuzu | 29 mutasyon: 24'ü beklenen testi kırdı · 4 bilinen boşluk (kilit ×1 T15, `save()` sırası ×1, `withoutOverlapping` ×1, `onOneServer` ×1) · 1 eşdeğer mutant |
| Mutasyon, frontend (`verify:state`) | Kum havuzu | 6/6 beklenen kontrolü kırdı |
| `npm run check` (lint + build + 7 doğrulama betiği) | Kum havuzu, Node 22 | Yeşil; her frontend commit'inde `tsc` + `verify:state` ayrı ayrı yeşil |
| ✅ `composer check` — PHP 8.5.8 + PostgreSQL 18.4 | **İsmail'in makinesi** (27 Eylül, Dilim B'nin başında) | **347/347** · Pint · PHPStan L8 · `errors:export --check` yeşil. 10.0'ın kaydı da bu koşu |
| Elle (Standart ile yayınla → galeriyi aç) | Tarayıcı | **Koşmadı** — adımlar `CreatePage.md` §5 ve `useInvitationStore.md` Faz 10 ekinde |

**Plandan sapmalar ve eklemeler — onayını bekliyor:**

| # | Adım | Plan ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|---|
| S1 | 10.1 | `fill()`'den sonra `requiredFor()` ↔ `highestTierFor()`, yetmiyorsa 402 | Kural **farka** bakıyor: değişiklik gereken planı **yükseltiyorsa** yeni gereksinim sahip olunanla kıyaslanır | Son hâle bakan kural, iade ya da fiyat haritası değişikliğinden sonra tarih düzeltmeyi, hatta modül **kapatmayı** 402'ye çevirirdi (planın *"kapatmak her zaman serbest"* cümlesiyle çelişki). Normal durumda iki kural aynı cevabı verir. `UpdateInvitationAction.md` §9.3 |
| S2 | 10.1 | Yalnızca `insufficientTier()` | Hiç hak yoksa (iade) `noPurchase()` → `PAYMENT_REQUIRED` | Yayın ucuyla aynı iki kod; `insufficientTier()` sahip olunan planı ister, `null` alamaz |
| S3 | 10.2 | 5 test | 7 test | Son ikisi S1'in kanıtı |
| S4 | 10.3 | Enum + geçişler | + `isFinal()` makineden türetildi · + `tests/Unit/OrderStatusTest.php` | Eski `isFinal()` `expired`'ı (ve `paid`'i) sonlu sayıyordu; çağıranı yok, davranış değişmedi |
| S5 | 10.5 | Komut | + `OrderFactory::failed()` yorumu (B4) · + `MaintenanceTest.md` (10.54'ün yarısı) | Dosyaya dokunan adım kılavuzunu da yazar (K18) |
| S6 | 10.6 | Yalnızca `Log::critical` | + `expired → paid` kabulünde `Log::warning` | Çifte tahsilatın tek izi |
| S7 | 10.6 | *"Sentry'ye düşer"* | **Düşmüyor** — belgelendi, yeni satır **10.55b** | `Integration::handles()` yalnızca istisnaları yollar |
| S8 | 10.7 | `PaywallTest` · `MaintenanceTest` | 4 yeni test `PaywallTest`'te + `a_signed_webhook_marks_the_order_paid`'e *"uyarı yok"* iddiası. `MaintenanceTest`'in değişikliği 10.5'e taşındı | 10.5, test değişmeden kırmızı kalırdı (commit'ler tek tek yeşil olmalı) |
| S9 | 10.8 | `EditorWorkspace.tsx` (+ store) | Beş alt adım (10.8a–e), yukarıdaki tablo | Modül anahtarları stüdyoda değil **formda** (`build` aşaması): duvar yalnızca stüdyoda yaşasaydı 402 hiçbir ekran açmazdı. Formun bekleyen taslağı geri alınan anahtarı geri yazıyordu (fırtınanın ikinci kaynağı) |

**Yeni bulgular (bu plana eklendi):**

- **10.54b** — `MaintenanceTest`'te boş yeşil bir test (`withoutOverlapping`), `onOneServer` hiç sınanmıyor.
- **10.55b** — `Log::critical` Sentry'ye gitmiyor.
- **10.80** — `OrderStatus::isFinal()` de ölü kod.
- **Z (10.84) için belge borcu** — `docs/03` (§ağaç, satır ~107), `docs/05` (satır ~146) ve `docs/11`
  (§komutlar ~277, §zamanlayıcı ~1863) hâlâ dört durumlu enum ve *"`orders:expire` `failed` yapar"*
  diyor. O güne kadar doğru olan kılavuzlardır.
- **10.27 için not** — `OrderStatus`'u `switch`'leyen tek yer henüz yok; dönüş sayfası durum
  eşlemesini `Record<OrderStatus, …>` olarak kurmalı ki `expired` unutulamasın (`types.md` §10).

### 9.2 Dilim B — 27 Eylül – 1 Ekim 2026 (kod yazıldı · her adımda İsmail'in makinesinde doğrulandı)

**Commit'ler** — İsmail atıyor, adım adım:

| Adım | Commit | Başlık | Dosyalar |
|---|---|---|---|
| 10.10 | `bdea384` | `10.10 - feat(auth): Expire tokens after 30 days and prune them daily.` | `config/sanctum.php` + kılavuz · `LoginUserAction.md` §3.4 |
| 10.10b | `f58dc0f` | `10.10b - fix(schedule): Prune expired tokens a day after expiry, not a month` | `routes/console.php` + kılavuz |
| 10.11 | `d1a89f4` | `10.11 - test(auth): Prove tokens expire exactly 30 days after issue` | `AuthTest` + kılavuz · `sanctum.md` |
| 10.11b | `2fe0741` | `10.11b - test(schedule): Prove the scheduled token prune actually deletes rows` | `MaintenanceTest` + kılavuz |
| 10.12 | `6c43d65` | `10.12 - feat(auth): Add a single email normalizer that folds the Turkish İ` | `EmailNormalizer` + birim testi + iki kılavuz · (İsmail'in `bootstrap/app.php` yorum düzenlemesi de bu commit'te) |
| 10.13 | `a8036d2` | `10.13 - fix(auth): Route every email normalization through EmailNormalizer` | dört çağrı yeri + dört kılavuz · `bootstrap/app.php` Pint düzeltmesi |
| 10.15 | `93b9377` | `10.15 - test(auth): Prove both forms of the Turkish İ reach one account and one bucket` | `AuthTest` + kılavuz · `bootstrap/app.php` Pint düzeltmesi |
| 10.14 | `862f842` | `10.14 - feat(users): Add a one-off command that folds legacy dotted-i emails` | komut + kılavuz · `MaintenanceTest` + kılavuz · `bootstrap/app.php` gerekçe yorumları |
| 10.16 | `667f672` | `10.16 - fix(errors): Stop reporting 4xx business exceptions to Sentry and the log` | `bootstrap/app.php` · `ExceptionReportingTest` (yeni) · üç belge |
| 10.17 | `bb75458` | `10.17 - test(infra): Keep the test suite away from Sentry and the network` | `phpunit.xml` · `TestCase` · `TestSuiteIsolationTest` (yeni) · üç belge |
| — | `a6709be` | `refactor(app): Clean up middleware and exception handling in app configuration` | İsmail: `bootstrap/app.php` yorumları · ⚠️ `dontReportWhen` satırı da silindi (S19) |
| — | ⬜ | `fix(errors): Restore the 4xx reporting rule removed in a6709be` | `bootstrap/app.php` |
| 10.18 | ⬜ | `10.18 - feat(http): Trust configured load balancer addresses for the client IP` | `config/trustedproxy.php` (yeni) · `.env.example` · `docs/10` · `TrustedProxiesTest` (yeni) · üç kılavuz |
| 10.19 | ⬜ | `10.19 - fix(invitations): Accept only web map links and real integer gift amounts` | `InvitationRequest` · `InvitationTest` · iki kılavuz |
| 10.20 | ⬜ | `10.20 - test(http): Prove the malformed-input gate and Retry-After on every route group` | `MalformedInputTest` (yeni) + kılavuz |
| — | ⬜ | `docs(phase10): Record the Dilim B progress, deviations and new findings` | bu dosya · `TEST-DENETIMI` |

**Doğrulama — nerede, neyle (B7):**

| Ne | Nerede | Sonuç |
|---|---|---|
| `composer check`, **her** adımda ayrı ayrı | İsmail'in makinesi: PHP 8.5.8 + PostgreSQL 18.4 | Yeşil; test sayısı 347 → 348 → 349 → 357 → 362 → 367 → 371 → 374 → 381 → 388 → **407** |
| Mutasyon | Aynı makine | **42 mutasyon:** 39'u beklenen testi kırdı. İkisi ancak yeni bir test eklendikten **sonra** kırıldı (10.18: config dosyasını silmek · 10.20: öncelik satırını silmek), ilk hâllerinde hayatta kalmışlardı. **2 eşdeğer mutant:** 10.15'te `RegisterRequest` normalizasyonu · 10.16'da `status() <= 500`. **1 bilinen boşluk:** 10.14'te okuma-yazma yarışı (T15). 10.17'nin `<server>` mutasyonu yalnızca ortamda bir DSN varken kırılıyor. Her biri kendi kılavuzunun mutasyon tablosunda |
| `schedule:list` | Aynı makine | `0 0 * * * sanctum:prune-expired --hours=24` |
| `users:normalize-emails --dry-run` | Geliştirme veritabanı | 0 bozuk satır, çıkış kodu 0 |
| Sentry DSN yalıtımı: kabukta `SENTRY_LARAVEL_DSN` / `SENTRY_DSN` tanımlı | Aynı makine | Beş yapılandırma denendi; yalnızca `<server>` ezdi (S16) |
| Elle (tarayıcı) | — | Dilim B'de elle doğrulama gerektiren adım yok. 10.18'in üretim doğrulaması (iki ağdan istek → farklı `ip_hash`) barındırma seçilince: `trustedproxy.md` §5 |

**Plandan sapmalar ve eklemeler — onayını bekliyor:**

| # | Adım | Plan ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|---|
| S10 | 10.11 | `travel(31)->days()` → 401 | Login'den alınan token · 30. günün son saniyesi **200**, tam sınır **401** | Yalnızca "31. günde 401" ömür kısalsa da yeşil kalırdı. Son saniyedeki kullanım aynı testte "mutlak"ı da kanıtlıyor |
| S11 | 10.11 | "prune gerçekten siler" | Komut **zamanlayıcıdaki satırla** aynen koşturuluyor (`scheduledArtisanCommand`) | Argüman testte elle yazılsaydı zamanlayıcının `--hours=720`'ye dönmesi yakalanmazdı (mutasyonla kanıtlandı) |
| S12 | 10.12 | `trim` → `İ→i` → `mb_strtolower` | `trim` → `mb_strtolower` → `i̇→i` · + `tests/Unit/EmailNormalizerTest` | Aynı harfin üç biçimi (U+0130, `I`+U+0307, eski DB satırı) küçültülünce aynı diziye düşüyor; planın sırası yalnızca ilkini yakalardı. 10.14 aynı fonksiyonu kullanabildi |
| S13 | 10.14 ↔ 10.15 | 10.14 sonra 10.15 | Önce 10.15 | 10.13'ün değişikliği bir adım boyunca testsiz kalmasın |
| S14 | 10.15 | Üç senaryo | Beş test, her biri **bir** çağrı yerini koruyor · `registerPayload()` Türkçe adlarla | `RegisterRequest`'teki çağrı **eşdeğer mutant** (değer mutator'dan geçiyor); belgelendi, bırakıldı |
| S15 | 10.16 | Testler (dosya belirtilmemiş) | Yeni `ExceptionReportingTest` | `HardeningTest`'in kılavuzu yok (10.54); oraya eklemek o borcu bu adıma çekerdi |
| S16 | 10.17 | `SENTRY_LARAVEL_DSN=""` | `<server name="SENTRY_LARAVEL_DSN" value=""/>` · güvence "istemci yok" değil **"istemcinin DSN'i null"** · yeni `TestSuiteIsolationTest` | Deney: `<env force="true">` kabuktaki bir değişkeni ezmiyor (Laravel önce `$_SERVER`'ı okuyor). DSN boşken de bir `Sentry\Client` kuruluyor; `HttpTransport` DSN `null` iken olayı atlıyor |
| S17 | 10.18 | `davetkart.trusted_proxies` + `AppServiceProvider`'da `TrustProxies::at()` | Laravel'in kendi `config/trustedproxy.php` anahtarı · yeni `TrustedProxiesTest` | Global `TrustProxies` middleware'i `config('trustedproxy.proxies')`'i her istekte kendisi okuyor: provider kodu yok, statik durum yok, `Config::set()` ile test edilebilir |
| S18 | 10.20 | Gruplar + `Retry-After` | + "bağlamadan önce" testi `invitations.update`'te · + asistan **kota** 429'unun başlığı | İlk yazım LCV ucunu kullanıyordu; orada örtük bağlama yok, öncelik satırı silinince test yeşil kaldı |
| S19 | — | — | `a6709be`'de silinen `dontReportWhen` satırı ve `HasErrorCode` import'u geri kondu | İsmail'in yorum sadeleştirmesi 10.16'nın kodunu da götürmüştü; `ExceptionReportingTest` 2 kırmızı. İsmail'in yorumları korundu |

**Yeni bulgular (bu plana eklendi):**

- **10.79b** — `phpunit.xml`'in `<env>` satırları gerçek bir ortam değişkenini ezemiyor. `DB_DATABASE` için
  tehlikeli: `RefreshDatabase` yanlış veritabanını silebilir.
- **10.70'e not** — sahte webhook imzası artık hiçbir iz bırakmıyor (10.16'nın bilinçli bedeli). Shopier'in
  imza kontrolüne tek satırlık `Log::warning`.
- **Deploy sırası (Z / deploy fazı)** — 10.13 yayına alındığı gün `users:normalize-emails` da koşulmalı; arada
  `İ` ile kaydolmuş eski kullanıcılar giriş **yapamaz** (Faz 9'da iki taraf aynı yanlış kuralla eşleştiği
  için yapabiliyorlardı). Adımlar: `NormalizeUserEmails.md` §5.
- **10.57'ye not** — `MalformedInputTest`'in asistan kota testi saniye değerini bilerek sabitlemiyor
  (başlık = gövde iddiası), gün sınırı değişince kırılmasın diye.
- **Z (10.84) için belge borcu, eklendi** — `docs/11` §zamanlayıcı (~1865) `sanctum:prune-expired --hours=720`
  diyor (10.10b'den beri `24`). `docs/03` / `docs/05` ağaçlarında yeni dosyalar yok: `app/Support/EmailNormalizer.php`,
  `app/Console/Commands/NormalizeUserEmails.php`, `config/trustedproxy.php` ve dört yeni test dosyası
  (`ExceptionReportingTest`, `TestSuiteIsolationTest`, `TrustedProxiesTest`, `MalformedInputTest`).
- **Denetim numaraları** — `TEST-DENETIMI`'nde K-2 = NUL, K-6 = yarım JSON. §1.2'deki tablo ikisini birlikte
  anıyor; `MalformedInputTest`'in ilk hâli ikisini ters yazmıştı, düzeltildi.

### 9.3 Dilim C — 1 Ekim 2026 (kod yazıldı · backend ve frontend yeşil)

**Commit'ler** — İsmail atıyor, adım adım (komutlar oturum çıktısında):

| Adım | Depo | Başlık |
|---|---|---|
| — | backend | `chore(config): Say stale orders become expired, not failed` (`config/payment.php` yorumu, K89'dan kalma) |
| 10.21 | backend | `10.21 - feat(invitations): Expose when an invitation was published and until when its purchase is releasable` |
| 10.22 | backend | `10.22 - feat(orders): Add an owner-only order policy` |
| 10.23 | backend | `10.23 - feat(orders): Let owners read their orders` |
| 10.24 | backend | `10.24 - feat(orders): Expose invitation, creation and payment times on orders` |
| 10.25 | backend | `10.25 - test(orders): Prove order reads are owner-only, ordered and leak-free` |
| — | backend | `docs(phase10): Record the Dilim C progress, deviations and new findings` (bu dosya) |
| 10.26 | frontend | `10.26 - feat(payments): Read a single order and the order list` |
| 10.27 | frontend | `10.27 - feat(payments): Add the payment return page that polls the order status` |
| 10.28 | frontend | `10.28 - feat(dashboard): Tell the exact release deadline before deleting a published invitation` |
| 10.29 | frontend | `10.29 - feat(auth): Verify the cached session on startup` |

Frontend'de üç dosya iki adıma bölünüyor (`types.ts`, `verify-payment.ts`, `verify-editor-state.ts`).
Ara hâlleri `.git/faz10-dilim-c/*.patch` olarak hazırlandı; `git apply --cached` ile stage'leniyor.
Dizi geçici bir index'te baştan sona oynatıldı: **her adım `tsc` ile yeşil**, sonda index = çalışma ağacı.

**Doğrulama (B7):**

| Ne | Nerede | Sonuç |
|---|---|---|
| `composer check` | İsmail'in makinesi, PHP 8.5.8 + PostgreSQL 18.4 | 407 → 409 (10.21) → **422** (10.25) |
| `npm run check` (lint + build + 8 doğrulama) | İsmail'in makinesi, Node 24.18 | Yeşil; `verify:endpoints` +3 uç · `verify:payment` +25 · `verify:state` +6 kontrol |
| Mutasyon, backend | Aynı makine | 10 mutasyon (10.21: 3 · 10.25: 6 + bir teşhis denemesi): hepsi kırıldı. **Biri ancak test düzeltildikten sonra:** sıralama tamamen silinince iki kayıtlı liste testi şansla yeşil kalıyordu (S22) |
| Mutasyon, frontend | Aynı makine | 9 mutasyon (10.26: 1 · 10.27: 4 · 10.28: 1 · 10.29: 3): hepsi kırıldı. `Record<OrderStatus,…>`'tan `expired` silinince `tsc` derleme hatası verdi |
| Elle (tarayıcı) | — | **Koşmadı.** Betikler: `PaymentReturnPage.md` §8 · `useAuthStore.md` §6 · `DashboardPage.md` §6 → Z (10.83) |

**Plandan sapmalar ve eklemeler — onayını bekliyor:**

| # | Adım | Plan ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|---|
| S20 | 10.21 | Yalnızca `publishedAt` | + **`releasableUntil`** · kural `Invitation::releaseWindowEndsAt()`'e taşındı, `DeleteInvitationAction` onu kullanıyor | Frontend `publishedAt + 3 gün` hesaplasaydı "3" iki yerde yaşardı; config yorumu bunu açıkça yasaklıyor. Mutasyon: pencere modelden kalkınca hem silme hem tarih testi kırılıyor |
| S21 | 10.23 | Liste + tek kayıt | `apiResource(...)->only(['index','show'])` · `SetEtag` yok · sayfalama yok | `only()` olmasa yazma rotaları tabloda görünürdü. Dönüş sayfası birkaç saniye yokluyor, ETag kazancı ölçülemez |
| S22 | 10.25 | "Liste yalnızca kendi" | + sıra testi **üç kayıt, karışık ekleme** · + aynı saniye testi (açık ULID'ler) | İlk yazımda `ORDER BY` silinince test yeşil kaldı: PostgreSQL sıralamasız sonucu tesadüfen doğru döndürdü |
| S23 | 10.26 | `getOrder`, `listOrders` | + `OrderRecord` tipi (`CheckoutResult`'tan ayrı) · kimlik `encodeURIComponent` · `payments.md` (K18) | `redirectUrl?` ile `paidAt: string \| null` farklı sözleşmeler. Kimlik sorgu dizesinden geliyor |
| S24 | 10.27 | Sayfa + rota | Karar mantığı `utils/paymentReturn.ts`'te, `verify:payment` sınıyor · `/odeme/hata` tek okuma · `expired` yoklanıyor (K89) | Saf fonksiyon tarayıcısız sınanır. Ödenmemiş hiçbir durum hiçbir bağlamda "onaylandı" göstermiyor (genel kural olarak sınanıyor) |
| S25 | 10.28 | `publishedAt`'e bakarak | `releasableUntil`'e bakarak · `utils/releaseWindow.ts` · tarih okunamazsa eski iki olasılıklı metin | S20'nin frontend yüzü. Sınır anı backend'le aynı (`>`, `isFuture()`) |
| S26 | 10.29 | `auth.ts` + store | + çağrı `main.tsx`'te · + `api.ts`: **eski token'ın 401'i yeni oturumu düşürmez** · ağ hatasında oturum kalır | Açılış isteği yeni bir yarış açıyordu: süresi dolmuş token'la `/me` yoldayken giriş yapılırsa geç gelen 401 yeni oturumu düşürürdü. `useEffect` StrictMode'da iki istek atardı |
| S27 | — | — | `config/payment.php` yorumu *"failed işaretlenir"* → *"expired"* | K89'dan kalma yanlış |

**Yeni bulgular:**

- **Deploy sırası:** backend 10.21 frontend 10.28'den **önce**. Tersinde `releasableUntil` gelmez ve silme uyarısı
  `unknown` yedeğine (eski iki olasılıklı metin) düşer. Bozulmaz ama kesinlik kaybolur.
- **Frontend satır sonları:** `core.autocrlf=true`, çalışma ağacı CRLF, depo LF. LF yazan araçlar karışık dosya
  üretebiliyor (`types.md` bu adımda eşitlendi). **10.81**'in `.gitattributes` maddesi bunu kalıcı çözer.
- **`listOrders()`'ın ekranı yok:** servis ve uç hazır, *"siparişlerim"* ekranı bir ürün kararı.
- **B6 — `releasableUntil` siparişin türünü bilmiyor:** paket siparişiyle açılmış bir davetiyede serbest
  bırakılacak tekil sipariş yok. Paket satışı açılırsa (10.58) uyarının dili bu ayrımı öğrenmeli.
- **Z (10.84) belge borcu, eklendi:** `docs/11` Ek A'ya `GET /orders`, `GET /orders/{order}`; `docs/03` / `docs/05`
  ağaçlarına `OrderController`, `OrderPolicy`, `OrderTest`; frontend F8'e `/odeme/basarili` senaryosu.

### 9.4 Dilim D — 1 Ekim 2026 (kod yazıldı · backend ve frontend yeşil)

**Kararlar:** M-1 → **K95** (kanal deploy'da) + **K96** (mail Türkçe) · H-1 → **K97** (anonimleştir) ·
S-1 → **K98** (30 gün · 6 ay · 12 ay). Dördü de önerilen seçenek.

**Commit'ler** — İsmail atıyor, adım adım. Birden çok adıma dokunan dosyalar (`config/davetkart.php`,
`AuthController`, `routes/api.php`, `MaintenanceTest`, frontend'de `auth.ts`, `App.tsx`,
`verify-endpoints.ts`) adım sınırlarında alınmış görüntülerden ayrıldı. Her adım
`.git/faz10-dilim-d/*.patch` olarak hazırlandı ve `git apply --cached` ile stage'leniyor.

| Adım | Depo | Başlık |
|---|---|---|
| 10.30 | backend | `10.30 - feat(mail): Add the frontend URL and mail locale, keep the channel provider-agnostic` |
| 10.31 | backend | `10.31 - feat(errors): Add PASSWORD_RESET_INVALID` |
| 10.32 | backend | `10.32 - feat(auth): Send a password reset link without revealing who has an account` |
| 10.33 | backend | `10.33 - feat(auth): Reset the password and revoke every token` |
| 10.34 | backend | `10.34 - feat(auth): Send the reset link as a queued Turkish mail to the frontend` |
| 10.35 | backend | `10.35 - feat(auth): Expose forgot-password and reset-password under the auth throttle` |
| 10.36 | backend | `10.36 - test(auth): Prove the password reset is enumeration-safe and single-use` |
| 10.38 | backend | `10.38 - feat(orders): Keep orders when their owner is deleted` |
| 10.39 | backend | `10.39 - feat(auth): Delete an account with its invitations, files and tokens` |
| 10.40 | backend | `10.40 - feat(auth): Expose DELETE /auth/me behind a password confirmation` |
| 10.41 | backend | `10.41 - test(auth): Prove account deletion removes files and keeps anonymous orders` |
| 10.42 | backend | `10.42 - feat(config): Add the retention periods` |
| 10.43 | backend | `10.43 - feat(maintenance): Purge expired personal data` |
| 10.44 | backend | `10.44 - feat(schedule): Run the data purge nightly at 03:45` |
| 10.45 | backend | `10.45 - test(maintenance): Prove the purge boundaries and that orders survive` |
| FE 10.14 | frontend | `10.14 - fix(payments): Address the user formally on the payment return page` |
| FE 10.15 | frontend | `10.15 - feat(errors): Add PASSWORD_RESET_INVALID and the current_password rule` |
| FE 10.16 | frontend | `10.16 - feat(auth): Add the forgot-password and reset-password pages` |
| FE 10.17 | frontend | `10.17 - feat(account): Add the account page with password-confirmed deletion` |

**Doğrulama (B7):**

| Ne | Nerede | Sonuç |
|---|---|---|
| `composer check` | İsmail'in makinesi, PHP 8.5.8 + PostgreSQL 18.4 | 422 → **447** |
| `npm run check` (lint + build + 8 doğrulama) | Aynı makine | Yeşil · `verify:errors` +2 kontrol · `verify:endpoints` +3 uç · `verify:state` +3 kontrol |
| Mutasyon, backend | Aynı makine | 27 mutasyon (parola sıfırlama 9 · hesap silme 8 · `data:purge` 10): 26 kırıldı, **1 eşdeğer** (`onlyTrashed` → `withTrashed`, `MaintenanceTest.md` §8) |
| Mutasyon, frontend | Aynı makine | 6 (FE 10.15: 3 · FE 10.16: 2 · FE 10.17: 1): hepsi kırıldı. İlki ilk denemede **kaçtı**: Türkçe metin silinince i18n İngilizce'ye düştü; denetim anahtar düzeyine taşındı |
| Tarayıcı | Vite, API erişilemez bir portta | `/sifremi-unuttum`, `/sifre-sifirla` (form ve *"geçersiz"* hâli), `/hesap` (iki adımlı form) gözle görüldü. Uçtan uca akış (mail → bağlantı → yeni şifre, gerçek silme) **koşmadı** → Z (10.83). Betikler: `ForgotPasswordPage.md` §5 · `ResetPasswordPage.md` §6 · `AccountPage.md` §6 |

**Plandan sapmalar ve eklemeler — onayını bekliyor:**

| # | Adım | Plan ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|---|
| S28 | 10.34 | `AppServiceProvider` → `ResetPassword::createUrlUsing()` | Kendi bildirim sınıfı `ResetPasswordNotification` (`ShouldQueue`, Türkçe, `resetUrl()` override) + `User::sendPasswordResetNotification()` | `createUrlUsing` global bir statik kanca; sınıf test edilebilir ve kuyruğa gider (15 saniye kuralı). Adres config'ten, Host başlığından değil (*reset poisoning*, testte kanıtlı) |
| S29 | 10.31 | *"400 ya da 422, bu adımda tartışılır"* | **422**, tek kod, alan yok | İstek biçimsel olarak geçerli (400 bozuk istek içindir, K91). Alan bildirmek *"adres doğru, token yanlış"* bilgisini sızdırırdı (H6) |
| S30 | 10.30 | `config/mail.php` · `.env.example` | + `config/davetkart.php` → `frontend.url` (varsayılan `http://localhost:3000`) ve `mail.locale` · + `lang/tr.json` (10.34'te) | Maildeki bağlantı mutlak olmalı. Laravel mail şablonunun *"Hello!"*, *"Regards,"* metinleri `lang/` olmadan İngilizce kalıyordu |
| S31 | 10.40 | `DELETE /api/auth/me` | + `throttle:auth` | Parola onayı, çalınmış bir token'la parola denemenin yolu olmasın |
| S32 | 10.39 | *"… → medya dosyaları → token'lar → kullanıcı"* | **Satırlar** transaction içinde, **dosyalar commit'ten sonra** · davetiyeler model üzerinden `forceDelete()` · sıfırlama token'ı da silinir | Geri dönüşü olmayan bir kullanıcı işleminde yarım kalan iş *"hesap duruyor, dosyalar gitti"* olmamalı. DB `cascade` model olaylarını atlar: cache temizlenmezdi (mutasyon M1 yalnızca cache testinden kırıldı) |
| S33 | 10.43 | *"Etkinliği geçmiş davetiyelerin misafir verisi"* | LCV satırları + misafir medyası (dosyalarıyla); davetiye ve galeri kalır; `event_at = NULL` dokunulmaz | Galeri sahibin verisi. Tarihsiz davetiyenin etkinliği *"bitmedi"* değil *"bilinmiyor"* (N4) |
| S34 | FE 10.14 | — | Ödeme dönüş sayfasının metinleri *"sen"* → *"siz"* | Uygulamanın geri kalanı *"siz"* diyor; Dilim C'de tutarsız yazılmıştı. Parola maili de *"siz"* |
| S35 | FE 10.16 | `auth.ts` + iki sayfa | + `logout({ revoke })` / `signOut({ revoke })` | Şifre sıfırlama ve hesap silme token'ı sunucuda zaten siliyor; iptal isteği yalnızca 401 dönerdi |
| S36 | FE 10.17 | *"Hesap ayarları + `legal/` KVKK metni"* | `/hesap` sayfası, girişi panelde · KVKK metnine **dokunulmadı** | Metnin içeriği hukuki (plan da öyle diyor). Çelişkiler aşağıda bulgu olarak |
| S37 | — | — | Adım sınırlarında görüntü + adım başına yama | Birden çok adıma dokunan dosyalar `git add` ile ayrılamıyordu. Yamalar geçici bir index'te sırayla oynatıldı; sonda index = çalışma ağacı |

**Yeni bulgular:**

- 🔴 **Gizlilik metni kodla çelişiyor** (frontend `src/pages/legal/PrivacyPage.tsx` → *Saklama Süreleri*):
  *"hesabın silinmesinden itibaren yasal zamanaşımı süresi boyunca"* (kod: hemen siler) ·
  *"yayın bitiminden itibaren 6 ay"* (kod: **etkinlikten** 6 ay, yalnızca misafir verisi) ·
  30 günlük çöp kutusu ve 12 aylık iletişim süresi metinde yok. Hangi yöne düzeltileceği hukuki karar.
- **Geliştirme veritabanı:** `php artisan migrate` (yeni `orders.user_id` nullable migration'ı).
- **Deploy sırası:** backend **önce**. Frontend'in yeni sayfaları backend uçları olmadan 404 alır.
- **Mail için kuyruk işçisi şart:** bildirim `ShouldQueue`. İşçi yoksa *"bağlantı gönderdik"* denir ama mail
  hiç gitmez. SES seçilirse deploy'da `composer require aws/aws-sdk-php` ve SPF/DKIM (`docs/10` → *Posta*).
- **K84:** `data:purge`'ün ilk koşusu elle ve `--dry-run` ile. Faz 10'dan önce hiçbir şey silinmediği için
  ilk gerçek koşu birikmiş verinin hepsini bir kerede siler.
- **Terim:** arayüz *"şifre"* (giriş/kayıt formları), backend maili ve hata metinleri *"parola"*. Kullanıcı
  mailde *"Parolamı Sıfırla"*ya basıp *"Yeni Şifrenizi Belirleyin"* sayfasına geliyor. Tek terime inmek
  ayrı bir metin geçişi (Dilim H'ye aday).
- **Frontend commit numaraları:** §9.3'te 10.26–10.29 diye listelenen frontend adımları **10.26, 10.11, 10.12,
  10.13** numaralarıyla atıldı. Dilim D'nin frontend'i bu yüzden FE 10.14'ten devam ediyor.
- **10.79b kararı hâlâ açık.**

### 9.5 Dilim E — 1 Ekim 2026 (test denetiminin kalan dosyaları · `composer check` 473/473)

**Commit'ler:**

| Adım | Başlık |
|---|---|
| 10.47 | `10.47 - test(public): Prove each invitation has its own cache entry` |
| 10.48 | `10.48 - test(invitations): Prove every field reaches its column and the list order` |
| 10.49 | `10.49 - test(paywall): Hard-code the published prices and module tiers` |
| 10.50 | `10.50 - test(media): Validate real bytes, not the client's MIME claim` |
| 10.51 | `10.51 - test(contact): Prove the hourly bucket with Turkish data` |
| 10.52 | `10.52 - test(assistant): Pin the clock and assert exact retry hints` |
| 10.53 | `10.53 - test(hardening): Prove HSTS is sent over HTTPS` |
| 10.54 | `10.54 - docs(tests): Add the HardeningTest guide` |
| 10.54b | `10.54b - test(schedule): Assert the overlap and one-server flags` |
| — | `docs(phase10): Record the Dilim D and E progress, deviations and findings` (bu dosya + `TEST-DENETIMI`) |

**Mutasyon özeti** (1 Ekim 2026, İsmail'in makinesi; tablolar kılavuzlarda):

| Adım | Dosya | Mutasyon | Önce yeşil olan | Şimdi |
|---|---|---|---|---|
| 10.47 | PublicInvitationTest | 8 | 8 | 8 kırıldı |
| 10.48 | InvitationTest | 16 | 15 | 16 kırıldı |
| 10.49 | PaywallTest | 9 | 7 | 9 kırıldı |
| 10.50 | MediaTest | 4 | 3 | 4 kırıldı |
| 10.51 | ContactTest | 4 | 3 | 3 kırıldı · **1 eşdeğer** |
| 10.52 | AssistantTest | 4 | 4 | 4 kırıldı |
| 10.53 | HardeningTest | 4 (+ Faz 9'un 7'si yeniden) | 3 | 11 kırıldı |
| 10.54b | MaintenanceTest | 3 | 3 | 3 kırıldı |

**Sapmalar ve eklemeler:**

| # | Adım | Plan ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|---|
| S38 | 10.47 | Dört mutant | + `subtitle`, `palette` · + LCV ayarlarının **varlık** testi (T6) | Yokluk testi tek başına koşulu `if (false)` yapan mutantı öldürmez |
| S39 | 10.48 | Eşleme · sıralama · oyuncak veri | + `null_clears_a_field_and_absence_leaves_it` | `array_key_exists` → `isset` mutantı da yeşildi (kodda gerekçesi yazılıydı, testi yoktu) |
| S40 | 10.49 | *"Sabit 24900"* | Elit testi **54900** + üç planın fiyatı veri sağlayıcıyla (24900 · 39900 · 54900) + altı modülün planı | 24900 Standart'ın fiyatı; sınanan sipariş Elit'ti. Modül haritası bir bütün olarak kilitlendi |
| S41 | 10.50 | PHP-as-JPG · SVG · polyglot | + **misafirin PHP "videosu"** | `mimetypes:` silinince fotoğraf testleri `dimensions` yüzünden yine 422 aldı. Videoda piksel sınırı yok; mutant altında kimliksiz misafir `.mp4` adıyla PHP kodu yükleyebiliyordu (201) |
| S42 | 10.51 | Saatlik kova · NUL · Türkçe | NUL **eklenmedi** · + karakter/bayt sınırı testi | NUL 10.20'den beri `MalformedInputTest`'te bütün rota gruplarında. Saatlik ve dakikalık anahtar aynı yazılsa Laravel `fallbackKey()` ile ayırıyor: eşdeğer mutant |
| S43 | 10.53 | `HardeningTest` | + `SecurityHeaders.md`'deki *"yalnızca elle doğrulanır"* cümlesi düzeltildi | Yanlıştı; aynı adımda düzeltilmesi gereken belge |
| S44 | 10.54 | `HardeningTest.md` · `MaintenanceTest.md` | Yalnızca `HardeningTest.md`; Faz 9'un mutasyonları yeniden koşturuldu | `MaintenanceTest.md` 10.5'te yazılmıştı |

**Yeni bulgular:**

- **Windows Defender** web-shell'e benzeyen test yüklerini (`system($_GET…)`) karantinaya alıyor; test 500
  görüyordu. Yük zararsız bir `echo`'ya çevrildi (`MediaTest.md` → Faz 10). Üretimde bir AV yüklenen
  dosyayı doğrulama sırasında silerse kullanıcı 500 görür.
- **Mutasyon koşucusu:** filtre `Tests\Feature\X` biçiminde verilince Windows kabuğunda **0 test** koştu ve
  sonuç `0/0` göründü (denetim §4'ün uyarısı). Sıfır testli bir sonuç *"öldü"* değil *"ölçülmedi"*dir.
- **CORS varsayılanı** `http://localhost:5173`, `FRONTEND_URL` varsayılanı `http://localhost:3000`. Geliştirmede
  Vite API'yi proxy'lediği için CORS devreye girmiyor; üretimde `CORS_ALLOWED_ORIGINS` mutlaka yazılmalı.

### 9.6 Dilim F — 1–2 Ekim 2026 (kararlar K99–K106 · `composer check` 543/543)

**Kararlar** (İsmail, 1 Ekim 2026; seçenekler sorulmadan önce açıklandı): paket tek davetiye (K99) ·
iade yayından kaldırır (K100) · misafire düzenleme kodu (K101) · P-1 karışık, premium = videolu 13 tema
(K102) · Sentry Cron Monitors (K103) · hız sınırları büyür + IPv6 /64 (K104) · IBAN **her kayıtta** (K105,
önerilen *"yayınlarken"*di) · asistan günü İstanbul (K106). Açık hata düzeltmeleri (10.55b, 10.56, 10.63,
10.64, 10.65, IPv6) sorulmadan yapıldı.

**Commit'ler** — İsmail atıyor; her adım `.git/faz10-dilim-f/*.patch`, `git apply --cached` ile:

| Adım | Depo | Başlık |
|---|---|---|
| 10.55 | backend | `10.55 - feat(schedule): Report every scheduled job to a Sentry cron monitor` |
| 10.55b | backend | `10.55b - feat(logging): Send critical logs to Sentry` |
| 10.56 | backend | `10.56 - fix(ai): Default the assistant provider to null` |
| 10.57 | backend | `10.57 - feat(assistant): Reset the daily quota at Istanbul midnight` |
| 10.58 | backend | `10.58 - feat(orders): Make a package publish a single invitation` |
| 10.59 | backend | `10.59 - feat(rsvp): Let guests update their reply with an edit code` |
| 10.60 | backend | `10.60 - feat(rate-limit): Fit guest limits to a wedding and bucket IPv6 by /64` |
| 10.61 | backend | `10.61 - feat(payments): Unpublish an invitation a refund leaves uncovered` |
| 10.62 | backend | `10.62 - feat(invitations): Validate the gift IBAN on every save` |
| 10.63 | backend | `10.63 - fix(public): Accept uppercase invitation ids` |
| 10.64 | backend | `10.64 - fix(rsvp): Refuse media already attached to another reply` |
| 10.65 | backend | `10.65 - feat(contact): Add contact:list to read contact messages` |
| 10.66 | backend | `10.66 - feat(pricing): Keep the white-label and premium theme promises` |
| — | backend | `docs(phase10): Record the Dilim F decisions, progress and findings` (bu dosya) |
| FE 10.18 | frontend | `10.18 - feat(rsvp): Update the guest's own reply instead of adding a second one` |
| FE 10.19 | frontend | `10.19 - feat(invitations): Warn about an invalid gift IBAN while typing` |
| FE 10.20 | frontend | `10.20 - feat(pricing): Keep the pricing card promises` |

**Doğrulama (B7):**

| Ne | Sonuç |
|---|---|
| `composer check` (PHP 8.5.8 + PostgreSQL 18.4) | 473 → **543** · Pint · PHPStan L8 · `errors:export --check` |
| `npm run check` | Yeşil · `verify:state` +7 (kendi yanıtını güncelleme) · `verify:endpoints` +1 uç · `verify:payment` +19 (IBAN 14 + fiyat kartı 5) · `verify:errors` +1 kural |
| Mutasyon, backend | 63 mutasyon, hepsi kırıldı. **Altısı ilk denemede hayatta kaldı** ve testi güçlendirdi: `user_id` savunması (10.58) · medya/iletişim sınırlayıcısının ham IP'ye dönmesi (10.60, mutasyondan önce fark edildi) · iade dışı durumda yayından kaldırma (10.61) · IBAN biçim kontrolü (10.62) · video kolonu (10.64) |
| Mutasyon, frontend | 12 mutasyon, hepsi kırıldı. 404'e düşmeyi silen mutant betiği **çökertiyordu**; senaryo istisnayı kontrole çevirecek şekilde yeniden yazıldı |
| Tarayıcı | *"Premium · Gold+"* rozeti yalnızca videolu temalarda (2 Ekim). İmzanın Elit'te kalkması ve LCV güncellemenin uçtan uca akışı **koşmadı** → Z (10.83) |

**Plandan sapmalar ve eklemeler — onayını bekliyor:**

| # | Adım | Plan ne diyordu | Ne yapıldı | Neden |
|---|---|---|---|---|
| S45 | 10.55 | `->sentryMonitor()`, 3 satır | Dört işe (data:purge dahil) **sabit adlı** izleyici · test geri çağrının varlığını ve adını yansımayla okuyor | Ad komut satırından türetilseydi `--hours=24` gibi bir argüman değiştiğinde Sentry geçmişi koparırdı |
| S46 | 10.57 | `AskAssistantAction` + test | + `AssistantUsageFactory`'nin varsayılan tarihi de İstanbul | Fabrika UTC'de kalsaydı 21:00–24:00 UTC arasında koşan testler ara sıra kırılırdı |
| S47 | 10.58 | Ucu kapat ya da `orders.publish_quota` | Paket **bağsız tekil sipariş** (mevcut bağlama mekanizması) · `grantsAcrossAccount()` ve resolver'ın paket kolu **kaldırıldı** · eski `'account'` satırlarını çeviren veri migration'ı | CHECK kısıtı `'account'` satırına davetiye yazmayı yasaklıyor; yeni mekanizma yazmak yerine Faz 9'unki kullanıldı |
| S48 | 10.59 | Yanıt kodu ya da panelde gruplama | Kod `data.editCode`'da, **yalnızca** misafirin kendi yanıtında · Store/Update ortak `RsvpRequest` · medya ve kota kontrolleri ayrı eylemlere çıkarıldı · bot da sahte bir kod alıyor | Kural iki yerde durmasın (C3); kodsuz bot yanıtı *"yakalandın"* derdi |
| S49 | 10.60 | LCV ve medya kovaları | `IpBucket` **bütün** IP anahtarlı sınırlayıcılara (auth, api, iletişim, asistan yedeği) · gömülü IPv4 ayrı | Gömülü IPv4'ler önek alınsaydı hepsi tek kovaya düşerdi |
| S50 | 10.61 | Okuma anı kontrolü mü, webhook'ta mı? | Webhook'ta, ayrı `WithdrawUncoveredInvitationAction` · `published_at` da siliniyor · `Log::warning` | Kontrol plan karşılama sorusu (yayınla aynı); yalnızca iadede |
| S51 | 10.62 | TR IBAN özel kuralı | Genel IBAN (TR'de 26 karakter) · değer **yazıldığı gibi** saklanıyor · `max:34` kaldı · adlı kural (`iban`, D6) · editörde anlık uyarı | Normalize edip saklamak otomatik kaydetmenin cevabıyla input'u bozardı; kolon 34 |
| S52 | 10.63 | `strtolower` | + önbellek anahtarı da küçültülüyor | Büyük harfli istek hiç temizlenmeyen ayrı bir girdi açardı |
| S53 | 10.64 | *"Henüz bağlanmamış"* koşulu | + güncellemede misafirin **kendi** medyası serbest | Yoksa misafir her güncellemede fotoğrafını kaybederdi |
| S54 | 10.65 | `contact:list` | `--since` (İstanbul günü), `--limit`, `--full` · kontrol karakterleri (C1 dahil) görünür kılınıyor | Mesaj misafirden gelir ve terminale yazılır |
| S55 | 10.66 | Karara göre | Public `showBranding` + ödeme/iade bağlı davetiyenin önbelleğini yeniliyor · premium liste config'te · tema kartında rozet | Önbellek 6 saat; yükseltmeden sonra imza o kadar görünürdü |
| S56 | FE | — | Önceki oturumdan kalan Vite süreci 5179'da çalışıyordu (durdurma komutu alt süreci kapatmamış); bu adımda kapatıldı | Araç notu |

**Yeni bulgular:**

- **Geliştirme veritabanı:** `php artisan migrate` (iki yeni migration: paket siparişlerini çevirme,
  `rsvps.edit_code_hash`).
- **Deploy (`docs/10`):** `LOG_STACK=daily,sentry` · `AI_PROVIDER=gemini` artık **açıkça** yazılmalı
  (varsayılan `null`) · Sentry izleyici kotası · sıra yine backend önce.
- **Editör önizlemesi** Elit sahibine imzayı gösteriyor (sahibin yanıtında `showBranding` yok). Misafir görmüyor.
- **Frontend'de bileşen render eden doğrulama yok:** imza koşulu yalnızca tip ve elle doğrulamayla korunuyor.
- **Hâlâ açık:** gizlilik metni (PrivacyPage) saklama süreleriyle çelişiyor (§9.4) · terim *"şifre"*/*"parola"* ·
  10.79b.
