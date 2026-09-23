# DavetKart — Devir Dosyası (Faz 9 sonu)

> **Tarih:** 11 Eylül 2026
> **Kimin için:** Bu projede **ilk kez** çalışacak bir AI asistanı
> **Ne kadar sürer:** Bu dosya + `CLAUDE.md` = ~15 dakika. Sonra çalışabilirsin.
> **Öncekiler:** `FAZ-7-DEVIR.md` · `FAZ-6-DEVIR.md` · `FAZ-5-DEVIR.md`
>
> 🔴 **23 Eylül 2026 güncellemesi — önce bunu oku.** Bu dosya 11 Eylül'de yazıldı.
> O günden beri: (1) **frontend yakalama fazı F1–F7 tamamlandı** (13–16 Eylül),
> (2) backend'e plan dışı eklemeler geldi (galeri silme ucu, görsel optimizasyonu +
> `DeleteReplacedMediaFile`, PHP **^8.5**, **Sentry**, Gemini **2.5** + `thinking_budget`,
> GitHub Actions CI), (3) backend **"bitti"** ilan edildi ve tam gözden geçirildi.
> Açık bulgular — ikisi kritik — **`claude/GOZDEN-GECIRME-RAPORU.md`**'de. §0, §4,
> §7, §8 ve §10 bu tarihe göre güncellendi.

---

## 0. Otuz saniyede proje

**DavetKart**, dijital davetiye SaaS'ı. Kullanıcı davetiye tasarlar, **bir plan
satın alır**, yayınlar, linkini paylaşır; misafirler linkten davetiyeyi görür,
"katılıyorum / katılamıyorum" der (LCV = RSVP) ve isterse fotoğraf/video ekler.

| | |
|---|---|
| **Backend** | PHP **^8.5** · Laravel 13 · PostgreSQL 18 · Sanctum · Modüler Monolit · Sentry |
| **Frontend** | React 19 · TypeScript · Vite · Zustand · **ayrı depo** — yakalama fazı F1–F7 ✅ (13–16 Eylül), F8 doğrulaması ⬜ |
| **Geliştirici** | İsmail — bilgisayar mühendisliği 3. sınıf öğrencisi |
| **Amaç** | Kod üretmek değil, **mimari vizyon öğretmek** |
| **Yöntem** | 9 fazlık dikey dilimler; her faz uçtan uca çalışan bir özellik |

**Şu an (23 Eylül 2026):** Faz 0-4 ✅ · Faz 5-8 kod ✅ / elle doğrulama ⬜ ·
**Faz 9 kod ✅ · `composer check` YEŞİL (238 test, 11 Eylül)** / elle doğrulama ⬜ ·
**Faz 9 sonrası eklemeler** kod ✅ / `composer check` **kayıt yok** (274 test metodu) ·
**Frontend yakalama F1–F7 ✅**
**Sıradaki: 🔴 `GOZDEN-GECIRME-RAPORU.md` §1'in iki kritik bulgusu** → `composer check`
→ Faz 5-9 + frontend F8 elle doğrulama → ödeme sağlayıcısı (**Shopier**).

---

## 1. 🔴 İsmail'in çalışma kuralları

| # | Kural |
|---|---|
| 1 | **Tek dosya:** bir cevapta asla birden fazla dosya yazma |
| 2 | **Gerekçe anlat:** neden bu yaklaşım, hangi desen, güvenlik/performans kazancı ne |
| 3 | **Onay bekle:** dosyayı yazıp anlattıktan sonra DUR |
| 4 | **Onun yerine geçme:** komutları İsmail çalıştırır (Windows + Laravel Herd) |
| 5 | **Plandan sapma:** yanlış olduğunu düşünüyorsan **önce söyle ve tartış** |
| 6 | SOLID, Clean Code, Laravel standartları · PHPStan level 8 |
| 7 | **Türkçe**, öğrenciye açıklar gibi |
| 8 | **Açıklama nereye:** koda kısa yorum; detay `docs/rehber/<kod-yolu>.md` (K18) |
| 9 | **Ritim:** komut ver → kod → kılavuz → `composer check` → DUR |
| 10 | **Her adım yeşil bitmeli:** var olmayan sınıfa referans verme |
| 11 | **Tahmin yürütme, kaynağa bak:** `vendor/` okunabilir |
| 12 | **Her faz sonunda:** `FAZ-N.md` + `FAZ-N-ELLE-DOGRULAMA.md`; `docs/07`, `docs/09`, `claude/` güncellenir |
| 13 | **"Yeşil gördüm" için zincirin tamamı koşmalı.** `composer check` fail-fast — **SON** satıra bak |
| 14 | **Beklediğin yanıtı almak, beklediğin sebeple aldığın anlamına gelmez.** Mutasyon sor |

> ⚠️ Faz 6'nın 6.15+, Faz 7'nin tamamı ve **Faz 9'un 9.8–9.16'sı** İsmail'in
> açık talebiyle kural 1 ve 3 askıya alınarak tek oturumda yazıldı. Varsayılan
> yine yukarıdaki hâlidir.

---

## 2. Okuma sırası

```
0. claude/GOZDEN-GECIRME-RAPORU.md           ← 🔴 23 Eylül: güncel durum + açık bulgular
1. claude/PHP-LARAVEL-SETUP.md               ← ANA GİRİŞ: kararlar, dersler, harita
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-5.md    (K49-K53, L1-L4)
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-6.md    (K54-K63, F1-F5)
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-8.md    (K72-K79, Q/X/L8/C8/B9)
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-9.md    (K80-K86, B10/E12, dersler 60-64)
     🔴 Master'a işlenmeyi bekliyorlar
2. CLAUDE.md                                 ← bağlayıcı kod standartları
3. docs/08-HATA-SOZLESMESI.md                ← API hata sözleşmesi (K20)
4. docs/rehber/fazlar/FAZ-0.md … FAZ-9.md
5. docs/07-GELISTIRME-YOL-HARITASI.md · docs/09-TUM-FAZLAR-PLANI.md
6. docs/10-URETIM-ENV-SABLONU.md             🆕 Faz 9
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
| `app/Models/` | `#[Fillable]` beyaz listesi, cast, ilişki, **sorgu kapsamları** |
| `app/Http/Resources/` | snake→camel, **beyaz liste** |
| `app/Policies/` | Sahiplik / IDOR — cevabı `bool`, bilgi taşıyamaz (P6) |
| `app/Contracts/` | Uygulamanın kendi soyutlamaları |
| `app/Services/<Alan>/` | Dış servis arayüzleri + uygulamaları |
| `app/Console/Commands/` | 🆕 Bakım komutları (Faz 9) |
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
| Dal | `main` (23 Eylül: tüm dallar birleşmiş; son commit `c85dbc9`, 21 Eylül) — Faz 9 sonunda `faz-9` idi |
| Uç nokta | **22** — Faz 9 sonrası `DELETE /api/invitations/{id}/media/{media}` eklendi (Faz 9 sonunda 21) |
| Test | **274** test metodu (23 Eylül sayımı) · son **kayıtlı** yeşil koşu 238 test · ilk **6 birim** testi (`tests/Unit/OrderScopeTest`) |
| PHPStan | level **8** · 164 dosya · 0 hata |
| Kural | **140** · **Karar** 86 · **Ders** 64 |
| Kalite | `pint` · `phpstan` · `errors:export --check` · `phpunit` → `composer check` |
| Komut | `errors:export` · 🆕 `orders:expire` · 🆕 `media:prune-orphans` |

### Faz 9'un eklediği kalıcı yapılar

| Yapı | Ne işe yarar |
|---|---|
| `orders.scope` (`OrderScope`) | Siparişin **ne satın aldığı** — `invitation_id`'nin yokluğundan türetilmiyor |
| `DeleteInvitationAction` | Silme + 3 günlük hak serbest bırakma penceresi |
| `ClaimReleasedOrderAction` | Serbest hakkı yeni davetiyeye bağlama (yeten en düşük) |
| `routes/console.php` zamanlayıcı | Projenin ilk **kendiliğinden çalışan** kodu |
| `config/cors.php` | 🔴 `exposed_headers: ['ETag']` — Faz 4/5 buna bağlı |
| `SecurityHeaders` middleware | 6 tarayıcı sertleştirme başlığı, global yığında |
| `docs/10-URETIM-ENV-SABLONU.md` | Üretim `.env` şablonu, her satırın gerekçesiyle |

---

## 5. 🔴 Faz 9 ne buldu? (en önemli bölüm)

Faz 9'un asıl işi planlanan liste değildi. `DeleteInvitationAction`'a
hazırlanırken şu çıktı:

```php
// OrderEntitlementResolver — Faz 7
$query->whereNull('invitation_id')          // "paket alımı"
    ->orWhere('invitation_id', $invitation->getKey());
```

`orders.invitation_id` **`nullOnDelete`**. Kalıcı silme geldiği gün:

```
249 ₺ Standart (tek davetiye için)  →  orders(invitation_id = X, paid)
Davetiye X kalıcı silinir           →  nullOnDelete → invitation_id = NULL
Aynı satır artık "paket" görünür     →  hesabın TÜM davetiyeleri bedava
```

Kimse hata yapmamıştı — şema tutarlı, FK doğru, sorgu doğru. Yanlış olan tek
şey **`NULL`'un iki gerçeği birden anlatmasıydı**. Plan düz uygulansaydı delik
**bu fazda açılacaktı**.

Çözüm `orders.scope` ve dört kombinasyon:

| `scope` | `invitation_id` | Anlamı |
|---|---|---|
| `account` | `NULL` | Paket alımı — her davetiyeye |
| `invitation` | dolu | Tekil, bağlı — yalnızca o davetiyeye |
| 🔴 `invitation` | `NULL` | **Serbest bırakılmış** — hiçbirine, bağlanana kadar |
| `account` | dolu | Anlamsız — CHECK kısıtı reddeder |

**Genelleme (E12):** *bir kolonun anlamı, ona yazan tüm yolların toplamıdır.*

---

## 6. Faz 9'da bilinmesi gereken beş ince nokta

1. **Genişlet/daralt deseni.** `scope` üç adımda eklendi: nullable kolon →
   yazıcılar → `SET NOT NULL`. Üçü aynı deploy'a sıkıştırılırsa desen bir
   **tören** olur; koruduğu pencere hiç açılmaz.
2. **CHECK, `NULL`'u reddetmez.** `NULL IN (...)` → `NULL`; CHECK yalnızca
   `FALSE` olduğunda reddeder. Zorunluluğu kuran tek şey `NOT NULL`'dır.
3. **Tüketimde refleks terstir.** Resolver "en yüksek"i döndürür (okuma);
   `ClaimReleasedOrderAction` "yeten en düşük"ü harcar (tüketim).
4. **Temizlikte fail-safe yön silmemektir.** `PruneOrphanMedia` ham tablo
   sorgusu kullanır (soft-delete'li LCV'ler de sayılsın) ve **önce dosyayı,
   sonra satırı** siler — ters sıra kalıcı disk sızıntısı üretir.
5. **`exposed_headers: ['ETag']`.** ETag CORS güvenli listesinde **değil**.
   Bu satır olmadan çapraz kaynakta JS onu okuyamaz, `If-None-Match`
   gönderilemez ve K7/K46'nın polling optimizasyonu **sessizce** ölür.

---

## 7. 🔴 Bekleyen işler

### 7.1 Hemen

- [ ] `docs/rehber/fazlar/FAZ-9-ELLE-DOGRULAMA.md` (22 adım) — **hiç koşulmadı**
- [ ] Faz 5/6/7/8'in elle doğrulama betikleri — **hâlâ açık**
- [ ] Dört EK dosyası master `PHP-LARAVEL-SETUP.md`'ye işlensin
- [x] ~~🔴 **Frontend yakalama fazı**~~ — F1–F7 ✅ 13–16 Eylül; F8 (17 senaryo) ⬜
- [ ] 🔴 **`GOZDEN-GECIRME-RAPORU.md` §1** — yayın sonrası paywall aşımı + geç gelen ödeme
- [ ] `composer check` Faz 9 sonrası eklemelerle (PHP 8.5, Sentry, galeri silme…) — sonucu kayda geçir (**B7**)

### 7.2 Cevap bekleyen açık kararlar

| # | Konu | Öneri |
|---|---|---|
| 1 | 🔴 **Paket alım kaç yayın açar?** Bugün sınırsız | `orders.publish_quota` + sayaç. K43 ancak o zaman tam uygulanır. **`scope` kolonu artık yerini hazırlıyor** |
| 2 | **Bildirim kanalı** (K79) | Kanal + dil + politika + şablon — dört ayrı karar |
| 3 | `SubscriptionTier::label()` dokuz fazdır çağrılmıyor | Ders 26 gereği **silinmeli** |
| 4 | Asistan kotasının gün sınırı UTC | İstanbul'da 03:00'te yenileniyor |
| 5 | `contact_messages` okuma ucu yok | Yazan var, okuyan yok |
| 6 | `rsvps.id` ULID (K52) | ✅ Kodda **uygulanmış** (migration + `whereUlid`); yalnızca resmî onay kaydı eksik |
| 7 | İade var olan yayını geri çekmiyor | İade akışı doğduğunda |
| 8 | 🆕 **Zamanlanmış iş koşmazsa kimse bilmez** | İzleme kararı gerekiyor |
| 9 | 🆕 **Silme sonucu kullanıcıya söylenmiyor** | `DELETE` → 204; bir "siparişlerim" ucu gerekli. Frontend ayrıca `publishedAt` istiyor (F7.2) |
| 10 | 🆕 (23 Eylül) **Token'lar hiç sona ermiyor** | `sanctum.expiration = null` → `sanctum:prune-expired` hiçbir şey silmiyor. Süre kararı gerekiyor |
| 11 | 🆕 (23 Eylül) **Sentry'ye 4xx iş istisnaları da gidiyor** | `dontReportWhen` kararı — rapor §2 |
| 12 | 🆕 (23 Eylül) **PHP ^8.5 kararı kayıtsız** | `phpstan.neon` `phpVersion: 80300` ile tutarsız; barındırma seçeneğini daraltıyor |

### 7.3 Sonraki fazlara

| Konu | Ne zaman |
|---|---|
| 🔴 Gerçek ödeme sürücüsü — **Shopier** (Eylül 2026'da hesap açıldı; eskiden `IyzicoGateway` planlanıyordu) + imza + replay penceresi | `PaymentGateway` (K8) hazır ama imzayı **başlıktan** okuyor; Shopier'in klasik akışında imza form gövdesinde — rapor §4 |
| Redis (cache + queue) + `queue:work` süpervizörü | Barındırma netleşince (K80: **yükseltme**, varsayım değil) |
| S3 uyumlu disk (K55) | Aynı — `media.disk` kolonda (F4), göç eski satırları kırmaz |
| Argon2id ölçümü | Üretim donanımı bilinince — hedef ~250 ms/hash |
| Log rotasyonu · yedekleme | Sunucu kurulumunda |
| Ters yön yetim (diskte var, satırı yok) | S3 göçünden sonra bir kez elle sayım |

---

## 8. Ortam ve depo

```
D:\Projects\davetkart\
├─ claude\                            bağlam repo'su (ayrı git)
├─ davetkart-backend-php-laravel\     git: main (23 Eylül; Faz 9 sonunda faz-9)
└─ davetkart-frontent\                git: edit-design-motion-principles — yakalama F1–F7 ✅
                                       ⚠️ ~530 dosya yalnızca CRLF farkıyla "değişmiş" (.gitattributes kararı bekliyor)
```

**Ortam:** Windows + Laravel Herd + PostgreSQL 18 (pgAdmin 4).
İki veritabanı zorunlu: `davetkart` ve `davetkart_test` (**V2**).

---

## 9. Sık düşülen tuzaklar (bu projede yaşandı)

| Tuzak | Nerede |
|---|---|
| Elle yazılan rota kısıtı sessizce yanlış olabilir | Faz 3 ULID regex'i → 3 IDOR testi boş yeşil |
| `create()` sonrası DB varsayılanı bellekte yok | `CreateInvitationAction` → 500 |
| Bir aracın kurulu olması, işini yaptığı anlamına gelmez | Larastan `casts()`'i hiç okumuyordu |
| `actingAs()` guard'ı atlar | `withToken()` + `forgetAuthState()` (T13) |
| Doğrulama kuralı **nesnesi** sınıf adı sızdırır | `Password::min(8)` (D6) · `Rule::enum()` (Faz 7) |
| `composer check` fail-fast | phpstan kırılırsa testler **hiç koşmaz** |
| Soft delete ilişkiyi `null` yapar | `RsvpPolicy` → `TypeError` → 500 (Faz 6) |
| `Storage::fake()` gerçek diski hiç görmez | `storage:link` testlerde görünmez |
| Bir fiyat alanı "doğrulanabilir"dir ama kabul edilemez | `{"price":1}` (Faz 7, M6) |
| UNIQUE kısıt `UPDATE`'i engellemez | Webhook idempotansı (Faz 7, M8) |
| SQL'de `AND`, `OR`'dan önce bağlar | Yetki sorgusunda parantezsiz `OR` (Faz 7) |
| Bir tarihin saat dilimi yoktur | `setTimezone()` tarihi bir gün kaydırır (Faz 7, K71) |
| 🆕 **Git boş dizin saklamaz** | `tests/Unit` yoktu; kapı sekiz fazdır yalnızca bir makinede yeşildi (**B10**) |
| 🆕 **CHECK kısıtı `NULL`'u geçirir** | Üç değerli mantık; zorunluluk `NOT NULL`'dan gelir |
| 🆕 **Tek origin varsa CORS başlığı koşulsuz gider** | `CorsService.php:209` — test varsayımı yanlıştı (ders 64) |
| 🆕 **`$this->artisan()` dönüş tipi birleşimdir** | `PendingCommand\|int` — `Artisan::call()` tek tipli |

---

## 10. ~~🔴 Sıradaki iş: FRONTEND YAKALAMA FAZI~~ ✅ tamamlandı (13–16 Eylül 2026)

> ✅ **23 Eylül notu — bu bölüm TARİHSEL.** Aşağıdaki tablonun tüm satırları
> frontend'in F1–F7 dilimlerinde kapandı (`FRONTEND-YAKALAMA-PLANI.md` §4). Açık
> kalanlar: F8 uçtan uca doğrulama; `publishedAt` alanı (backend'den istendi,
> yazılmadı); ödeme dönüş sayfası — backend `PAYMENT_SUCCESS_URL=/odeme/basarili`
> diyor ama frontend'de bu rota **yok** (`path: '*'` ana sayfaya atıyor); sipariş
> durumunu sorgulayacak bir uç da yok. Hepsi: `GOZDEN-GECIRME-RAPORU.md` §3.

Backend **altı faz** önde (4/5/6/7/8/9) ve bu, Faz 9'un ortaya çıkardığı en
büyük tek risk. Bugün elimizde **çalışan ama kimsenin konuşamadığı** bir
üretim backend'i var:

| Frontend'de | Gerçek durum |
|---|---|
| `services/payments.ts` | **Tamamen mock** — 1.8 sn bekleyip `status: 'paid'` döner |
| `useSubscriptionStore.activeTier` | **Oturum içi mock** — gerçek ödeme açıldığı gün kullanıcı ödediğini sanıp 402 alır |
| Yayınlama ucu | **YOK** — `POST /invitations/{id}/publish` hiç çağrılmıyor |
| `services/rsvps.ts` | **Yanlış uçlar** — `/rsvps` yazıyor, backend `/invitations/{id}/rsvps` |
| `services/media.ts` | **Yanlış uç ve yanlış yanıt** — `/media/upload` + `{url}` bekliyor |
| `services/contact.ts` | **Yanlış yol** — `/contact`, backend `/public/contact` (K76) |
| ContactPage honeypot | **YOK** — tuzak hiç kurulmamış (**B9**) |
| AssistantWidget | **Giriş duvarı yok** — uç auth'lu (K72) |
| `useAssistantChat` | **Mock** |
| ETag / polling | **YOK** — Faz 4 ve 5'in tüm optimizasyonu kullanılmıyor |
| `errors.json` | **YOK** — K20'nin frontend yarısı hiç yazılmadı |

**Tam plan, sıra ve gerekçeleriyle:**
`davetkart-frontent/docs/FRONTEND-YAKALAMA-PLANI.md`
