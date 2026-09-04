# DavetKart — Devir Dosyası (Faz 7 sonu)

> **Tarih:** 3 Eylül 2026
> **Kimin için:** Bu projede **ilk kez** çalışacak bir AI asistanı
> **Ne kadar sürer:** Bu dosya + `CLAUDE.md` = ~15 dakika. Sonra çalışabilirsin.
> **Öncekiler:** `FAZ-6-DEVIR.md` · `FAZ-5-DEVIR.md` (hâlâ geçerli bağlam taşırlar)

---

## 0. Otuz saniyede proje

**DavetKart**, dijital davetiye SaaS'ı. Kullanıcı davetiye tasarlar, **bir plan
satın alır**, yayınlar, linkini paylaşır; misafirler linkten davetiyeyi görür,
"katılıyorum / katılamıyorum" der (LCV = RSVP) ve isterse fotoğraf/video ekler.

| | |
|---|---|
| **Backend** | PHP 8.3+ · Laravel 13 · PostgreSQL 18 · Sanctum · Modüler Monolit |
| **Frontend** | React 19 · TypeScript · Vite · Zustand · **ayrı depo, 4 faz geride** |
| **Geliştirici** | İsmail — bilgisayar mühendisliği 3. sınıf öğrencisi |
| **Amaç** | Kod üretmek değil, **mimari vizyon öğretmek** |
| **Yöntem** | 9 fazlık dikey dilimler; her faz uçtan uca çalışan bir özellik |

**Şu an:** Faz 0-4 ✅ · Faz 5 kod ✅ / elle doğrulama ⬜ · Faz 6 kod ✅ /
kapanış listesi ⬜ · **Faz 7 kod ✅ / `composer check` HİÇ KOŞMADI** ·
Sıradaki: **Faz 6 ve 7'yi kapat, sonra Faz 8.**

---

## 1. 🔴 İsmail'in çalışma kuralları

| # | Kural |
|---|---|
| 1 | **Tek dosya:** bir cevapta asla birden fazla dosya yazma |
| 2 | **Gerekçe anlat:** neden bu yaklaşım, hangi desen, güvenlik/performans kazancı ne |
| 3 | **Onay bekle:** dosyayı yazıp anlattıktan sonra DUR |
| 4 | **Onun yerine geçme:** komutları İsmail çalıştırır (Windows + Laravel Herd) |
| 5 | **Plandan sapma:** yanlış olduğunu düşünüyorsan **önce söyle ve tartış** |
| 6 | SOLID, Clean Code, Laravel standartları |
| 7 | **Türkçe**, öğrenciye açıklar gibi |
| 8 | **Açıklama nereye:** koda kısa yorum; detay `docs/rehber/<kod-yolu>.md` |
| 9 | **Ritim:** komut ver → kod → kılavuz → `composer check` → DUR |
| 10 | **Her adım yeşil bitmeli:** var olmayan sınıfa referans verme |
| 11 | **Tahmin yürütme, kaynağa bak:** `vendor/` okunabilir |
| 12 | **Her faz sonunda:** `FAZ-N.md` + `FAZ-N-ELLE-DOGRULAMA.md`; `docs/07`, `docs/09`, `claude/` güncellenir |
| 13 | **"Yeşil gördüm" için zincirin tamamı koşmalı.** `composer check` fail-fast — **SON** satıra bak |
| 14 | **Beklediğin yanıtı almak, beklediğin sebeple aldığın anlamına gelmez.** Mutasyon sor |

> ⚠️ **Faz 6'nın 6.15+ ve Faz 7'nin tamamı** İsmail'in açık talebiyle kural 1 ve
> 3 askıya alınarak tek oturumda yazıldı. Varsayılan yine yukarıdaki hâlidir.

---

## 2. Okuma sırası

```
1. claude/PHP-LARAVEL-SETUP.md          ← ANA GİRİŞ: kararlar, dersler, harita
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-5.md   (K49-K53, L1-L4)
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-6.md   (K54-K63, F1-F5)
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-7.md   (K64-K71, M5-M8, W1-W3)
     🔴 Üçü de master'a HÂLÂ İŞLENMEDİ
2. CLAUDE.md                            ← bağlayıcı kod standartları
3. docs/08-HATA-SOZLESMESI.md           ← API hata sözleşmesi (K20)
4. docs/rehber/fazlar/FAZ-0.md … FAZ-7.md
5. docs/07-GELISTIRME-YOL-HARITASI.md · docs/09-TUM-FAZLAR-PLANI.md
```

> 🔴 `docs/04-KURULUM...` §1 ve §4 **GEÇERSİZ** (MySQL diyor; K9'/K19 ile
> PostgreSQL 18). `docs/03-MIMARI-PLAN.md` §8 **GEÇERSİZ** (12 adım → 9 faz).

---

## 3. Mimarinin özeti

**Repository Pattern ve Fat Service YASAK.** Yerine **Action-Based
Architecture**:

```
rota → FormRequest → Controller → Action → Model → Resource → yanıt
```

| Katman | Sorumluluk |
|---|---|
| `app/Http/Requests/` | Doğrulama, camelCase→snake_case |
| `app/Http/Controllers/Api/V1/` | Action'a yönlendir, Resource döndür (3-8 satır) |
| `app/Actions/` | Tek eylem, iş kuralı, DB + yan görevler |
| `app/Models/` | `#[Fillable]` beyaz listesi, cast, ilişki |
| `app/Http/Resources/` | snake→camel, **beyaz liste** |
| `app/Policies/` | Sahiplik / IDOR — 🔴 **cevabı `bool`, bilgi taşıyamaz (P6)** |
| `app/Contracts/` | 🆕 Uygulamanın kendi soyutlamaları (`RsvpQuotaResolver`, `PublishEntitlementResolver`) |
| `app/Services/<Alan>/` | Dış servis arayüzleri + uygulamaları (`Payment`, `Pricing`, `Rsvp`) |
| `app/Enums/` | Sihirli string yasağı + **kural taşıyıcısı** |

**Sözleşme (ihlal edilirse frontend kırılır):**

- Rotalar `/api/...` — **`/api/v1/...` değil**
- Auth yanıtları **zarfsız** `{user, token}`; diğerleri `{data: ...}`
- Hata: `{error: {code, fields?, params?}}` — **metin yok** (K20/K21)
- Alan adları camelCase; dönüşüm **yalnızca** Resource'ta
- Sahiplik yoksa **404**, 403 değil (H7)
- `id` alanları **string** (ULID)

---

## 4. Bugünkü teknik durum

| | |
|---|---|
| Dal | `faz-5-and-6` — Faz 7'nin 28 commit'i de bu dalda |
| Uç nokta | **19** |
| Test | **156** (123 + 33 doğrulanmamış) |
| PHPStan | level **8** |
| Kural | **127** · **Karar** 71 · **Ders** 54 |
| Kalite | `pint` · `phpstan` · `errors:export --check` · `phpunit` → `composer check` |

### Çalışan uçlar

| Method | Path | Auth | Faz |
|---|---|:---:|:---:|
| GET | `/api/ping` | — | 1 |
| POST | `/api/auth/register` · `/login` | — | 2 |
| POST | `/api/auth/logout` · GET `/api/auth/me` | ✅ | 2 |
| GET/POST/PUT/DELETE | `/api/invitations[/{id}]` | ✅ | 3 |
| GET | `/api/public/invitations/{id}` | — | 4 |
| POST | `/api/public/invitations/{id}/rsvps` | — | 5 |
| GET | `/api/invitations/{id}/rsvps` · DELETE `/api/rsvps/{id}` | ✅ | 5 |
| POST | `/api/invitations/{id}/media` · `/api/public/invitations/{id}/media` | ✅ / — | 6 |
| **POST** | **`/api/invitations/{id}/publish`** | ✅ | **7** |
| **POST** | **`/api/invitations/{id}/checkout`** · **`/api/payments/checkout`** | ✅ | **7** |
| **POST** | **`/api/public/payments/webhook`** | — | **7** |

---

## 5. 🔴 İlk iş: iki fazı birden kapat

### 5.1 Faz 6 (Adım 0)

```powershell
php artisan storage:link      # 🔴 HİÇ ÇALIŞTIRILMADI
dir public\storage
```

Onsuz **her medya URL'i 404 verir** ve hiçbir test bunu söylemez
(`Storage::fake()` gerçek diski görmez). Sonra `FAZ-6.md` §11 listesini
işaretle.

### 5.2 Faz 7

```powershell
php artisan migrate                          # 2 yeni migration
php artisan errors:export                    # 🔴 katalog ELLE düzenlendi
git diff contracts/error-codes.json          #    generatedAt dışında fark OLMAMALI
composer lint
composer check                               # 🔴 SON satıra bak
php artisan test --filter=PaywallTest        # 33 test
```

Sonra `docs/rehber/fazlar/FAZ-7-ELLE-DOGRULAMA.md` (20 adım).

> **Faz 6'nın ilk `composer check` koşusu üç gerçek hata buldu** (`FAZ-6.md` §6).
> Faz 7 **hiç koşmadı** — daha fazlasını bulacağını varsay. En riskli üç nokta
> `FAZ-7.md` §7'de listeli.

---

## 6. Faz 7 ne inşa etti?

**Amaç:** yayınlamayı bir **ödemeye** bağlamak ve bunu **istemciye güvenmeden**
yapmak.

### Bir yayın isteğinin geçtiği dokuz katman

```
throttle:api → auth:sanctum → whereUlid → rota bağlaması
  → Gate::authorize('publish')          → değilse 404 (H7)
  → satır kilidi + yeniden oku          → E9
  → zaten yayında mı?                   → 409
  → TierResolver (SUNUCUDA hesapla)     → K6'nın bedeli burada ödendi
  → PublishEntitlementResolver          → K42: iki kaynak, tek arayüz
       ├─ ödeme yok    → 402 PAYMENT_REQUIRED
       └─ plan yetmez  → 402 PAYWALL_TIER_INSUFFICIENT
  → published + published_at
       → InvitationChanged → cache temizlendi (K48, Action hatırlamıyor)
```

### Üç fazlık üç borç kapandı

| Borç | Kaynağı | Nasıl kapandı |
|---|---|---|
| `PublishInvitationAction` boş iskeleti | Faz 3 (**K47**) | Dolduruldu — kapıyı kilitleyecek anahtarlar ancak bugün vardı |
| `RsvpQuotaResolver`'ın gerçek kaynağı | Faz 5 (**K51**) | Bağlama **tek satır** değişti; `TierRsvpQuotaResolver` silindi |
| `invitations.timezone` | Faz 4→5→6 (**K63**) | Kolon + 6 dosya; LCV son tarihi artık **davetiyenin** diliminde |

### Bilinmesi gereken dört ince nokta

1. **🔴 `provider_ref` UNIQUE tek başına yetmez (M8).** UNIQUE "ikinci **satır**
   olamaz" der; "bir satır iki kez **ilerleyemez**" demez. İkincisini
   `OrderStatus::canTransitionTo()` + `lockForUpdate()` söyler.
2. **Bir fiyat alanı doğrulanabilir değildir (M6).** `{"price":1}`
   `integer|min:1`'i geçer. Çözüm doğrulama değil, alanı **hiç kabul
   etmemektir**.
3. **Dış servis transaction'a dâhil değildir (L7).** Sipariş **önce** yazılır,
   sağlayıcı **sonra** çağrılır; patlarsa sipariş `failed` işaretlenir. Faz
   6'nın F3'ünün (dosya sistemi) ikizi.
4. **Policy'nin cevabı `bool`dur (P6).** Paywall reddi bilgi taşımak zorunda
   (`requiredTier`), Policy reddi ise 404'e çevriliyor. Kural iki katmana
   **doğru yerlerinden** bölündü.

### Yeni kalıcı yapılar

| Yapı | Ne işe yarar | Sonraki fazlarda |
|---|---|---|
| `PaymentGateway` arayüzü | Sağlayıcı anlaşması beklenmeden doğru akış | Faz 9'da `IyzicoGateway` — **tek satır** |
| `PublishEntitlementResolver` | K42'nin iki kolunu tek cevaba indirir | Yeni bir hak kaynağı (kampanya) eklenirse tek yerde |
| `OrderStatus` durum makinesi | İdempotansın uygulama yarısı | İade/admin uçları aynı kuralı kullanır |
| `orders.provider` / `currency` kolonları | Göç ucuzlatıcı (F4) | Sağlayıcı/para birimi değişimi |
| Mutasyon tablosu (`PaywallTest.md` §7) | 33 satır | **T16**: faz kapanış ölçütü |

---

## 7. 🔴 Bekleyen işler

### 7.1 Hemen

- [ ] `php artisan storage:link` (Faz 6)
- [ ] `php artisan errors:export` + diff kontrolü (Faz 7) 🔴
- [ ] `composer check` yeşil
- [ ] `FAZ-6-ELLE-DOGRULAMA.md` (18 adım) · `FAZ-7-ELLE-DOGRULAMA.md` (20 adım)
- [ ] Üç ek dosya master `PHP-LARAVEL-SETUP.md`'ye işlensin
- [ ] Frontend uyarlaması — **Faz 4/5/6/7 birikti** (`FAZ-7.md` §8)

### 7.2 🔴 Cevap bekleyen açık kararlar

| # | Konu | Öneri |
|---|---|---|
| 1 | 🔴 **Paket alım kaç yayın açar?** | Bugün **sınırsız** — tek 399 ₺'lik paket 100 davetiye yayınlatır. `orders.publish_quota` + sayaç önerilir. K43 ancak o zaman tam uygulanır |
| 2 | `hashIp()` `hash()` kullanıyor, webhook `hash_hmac()` | Aynı depoda iki refleks. `hash_hmac('sha256', $ip, $key)` doğrusu; eski `ip_hash`'ler karşılaştırılmadığı için değişim zararsız |
| 3 | `app/Contracts/` klasörü | ✅ İkinci kez kullanıldı, `CLAUDE.md`'ye işlendi — onay bekliyor |
| 4 | `SubscriptionTier::label()` hâlâ çağrılmıyor | Faz 8'de fatura/e-posta metni doğmazsa **silinmeli** (ders 26) |
| 5 | `rsvps.id` ULID (K52) | Faz 5'ten beri onay bekliyor |

### 7.3 Sonraki fazlara

| Konu | Ne zaman |
|---|---|
| Süresi dolmuş `pending` siparişler (`orders:expire`) | Faz 9 |
| Sağlayıcı IP'lerini `throttleApi`'den muaf tutma | Faz 9 |
| İadenin var olan yayını geri çekmesi | İade akışı doğunca |
| 🔴 Yetim medya temizliği | Faz 9 |
| 🔴 Yüklenenler web kökü altında (K55) | Faz 9 |
| `DeleteInvitationAction` + dosya temizliği | Faz 8/9 |
| `Jobs/SendRsvpNotification` (K62) | Faz 8 |
| `routes/web.php` closure'ı | Faz 9 |
| `IyzicoGateway` + gerçek imza sırrı | Faz 9 |

---

## 8. Ortam ve depo

```
D:\Projects\davetkart\
├─ claude\                            bağlam repo'su (ayrı git)
├─ davetkart-backend-php-laravel\     git: faz-5-and-6 dalı
└─ davetkart-frontent\                git: main — 🔴 DÖRT FAZ GERİDE
```

**Ortam:** Windows + Laravel Herd + PostgreSQL 18 (pgAdmin 4).
İki veritabanı zorunlu: `davetkart` ve `davetkart_test` (**V2**).

| Uyarı | Ayrıntı |
|---|---|
| 🔴 Frontend deposu geride | Faz 4/5/6/7 uyarlamaları yapılmadı |
| 🔴 Frontend'de `.gitattributes` yok | 491 dosya sahte "değişmiş" görünüyor |
| 🔴 `public/storage` sembolik bağı yok | `php artisan storage:link` |
| `faz-5-and-6` dalı push edilmemiş olabilir | `git push origin faz-5-and-6` |

---

## 9. Sık düşülen tuzaklar (bu projede yaşandı)

| Tuzak | Nerede |
|---|---|
| Elle yazılan rota kısıtı sessizce yanlış olabilir | Faz 3 ULID regex'i → 3 IDOR testi **boş yeşil** |
| `create()` sonrası DB varsayılanı bellekte yok | `CreateInvitationAction` → 500 |
| Bir aracın kurulu olması, işini yaptığı anlamına gelmez | Larastan `casts()`'i hiç okumuyordu |
| `actingAs()` guard'ı atlar | `withToken()` + `forgetAuthState()` (T13) |
| Doğrulama kuralı **nesnesi** sınıf adı sızdırır | `Password::min(8)` (D6) · 🆕 `Rule::enum()` (Faz 7) |
| `composer check` fail-fast | phpstan kırılırsa testler **hiç koşmaz** |
| Soft delete ilişkiyi `null` yapar | `RsvpPolicy` → `TypeError` → 500 (Faz 6) |
| Zaman damgası saniye hassasiyetinde | `touch()` aynı saniyede olay fırlatmaz (Faz 6) |
| `Storage::fake()` gerçek diski hiç görmez | `storage:link` testlerde görünmez |
| 🆕 **Bir fiyat alanı "doğrulanabilir"dir ama kabul edilemez** | `{"price":1}` (Faz 7, M6) |
| 🆕 **UNIQUE kısıt `UPDATE`'i engellemez** | Webhook idempotansı (Faz 7, M8) |
| 🆕 **SQL'de `AND`, `OR`'dan önce bağlar** | Yetki sorgusunda parantezsiz `OR` → herkes geçer (Faz 7) |
| 🆕 **Bir tarihin saat dilimi yoktur** | `setTimezone()` tarihi bir gün kaydırır (Faz 7, K71) |

---

## 10. Sıradaki iş

Faz 6 **ve** Faz 7 doğrulandıktan sonra **Faz 8 — AI asistan ve iletişim**
(`docs/09` §Faz 8).
