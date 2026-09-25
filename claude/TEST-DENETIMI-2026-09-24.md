# DavetKart Backend — Test Paketi Denetimi (Adversarial QA)

> **Tarih:** 24 Eylül 2026 · **Dal:** `payment-servide` @ `c85dbc9`
> **Yöntem:** Depo yalıtılmış bir kum havuzunda klonlandı ve **gerçekten koşturuldu**
> (PHP 8.4.21 + PostgreSQL 16.13; proje hedefi 8.5 + 18 — B7: İsmail'in makinesinde
> `composer check` şart). Başlangıç: 274 test yeşil, pint + phpstan(L8) yeşil.
> Bulgular üç yolla üretildi: (1) mutasyon — üretim kodu tek satır bozulup testler koşturuldu,
> (2) gerçek Türkçe veriyle sonda istekleri, (3) kod okuması.
> **İlişki:** `claude/GOZDEN-GECIRME-RAPORU-2026-09-23.md`'nin §1.1, §1.2, §2.3 ve §6.3
> bulguları burada **çalışma anında doğrulandı**. Aşağıdaki K-2…K-7 o raporda yok.
>
> *(Bu dosya claude.ai Project'teki `claude/TEST-DENETIMI-2026-09-24.md`'nin 25 Eylül 2026'da
> depoya alınmış kopyasıdır. Faz 10 planı buna atıf yapıyor: `claude/FAZ-10-PLANI.md`.)*

---

## 1. Doğrulanmış KOD HATALARI

| # | Seviye | Bulgu | Kanıt (kum havuzu) | Testin evi | Durum |
|---|---|---|---|---|---|
| K-1 | 🔴 Kritik | Yayından sonra `PUT` Elit modüllerini açıyor (paywall bypass) | Standart ödendi → yayın 200 → `PUT {showGallery,showGift,...}` 200 → public'te IBAN + galeri | InvitationTest / PaywallTest | ⬜ |
| K-2 | 🟠 Yüksek | NUL baytı (`\0`) doğrulamayı geçiyor, PostgreSQL metni **kesiyor** | `"Ali\0Veli"` → 201, satırda `"Ali"`; `"Z\0ZZZZZ"` → satırda `"Z"` (min:2 atlatıldı); başlık, kayıt adı, e-posta (`"zeynep\0@gmail.com"` → `"zeynep"`) | RsvpTest ✅ (4 vaka kırmızı), diğerleri ⬜ | ⬜ |
| K-3 | 🟠 Orta-Yüksek | Türkçe `İ` e-posta normalizasyonu | `İsmail.Cankaya@gmail.com` → `i̇smail...` (U+0307) saklanıyor; `ismail.cankaya@gmail.com` ile giriş **401**; aynı adresle ikinci kayıt **201** (iki hesap) | AuthTest | ⬜ |
| K-4 | 🟠 Orta | Geç gelen `paid` webhook'u (`orders:expire` sonrası) sessizce yutuluyor | pending → 61 dk → expire → failed → imzalı `paid` → 204, sipariş `failed`, `paid_at` null, log yok | PaywallTest / MaintenanceTest | ⬜ |
| K-5 | 🟡 Orta | 429/503'te `Retry-After` BAŞLIĞI yok (docs/08 §4.1 vaat ediyor) | Gövdede `params.retryAfter: 60`, başlık null — renderer başlıkları düşürüyor | RsvpTest ✅ (kırmızı), Auth/Contact/Assistant ⬜ | ⬜ |
| K-6 | 🟡 Orta | Yarım JSON → 422 + sahte `required` (docs/08 §4: bozuk istek 400) | Kapanmamış gövde → 422 üç alanda required | RsvpTest ✅ (kırmızı) | ⬜ |
| K-7 | 🟡 Düşük | `integer` kuralı `true`'yu kabul ediyor | `guestCount: true` → 201 (1 kişi); `giftOptions: [true, 500]` kaydediliyor ve **aynen** frontend'e dönüyor (`number[]` sözleşmesi) | RsvpTest ✅ (kırmızı), InvitationTest ⬜ | ⬜ |
| K-8 | 🟡 Düşük | `mapUrl` `smb://`, `ms-settings://` kabul ediyor (`javascript:`/`file:`/`data:` reddediliyor) | PUT → 200 | InvitationTest | ⬜ |

**Gözlemler (hata değil / karar):** TrustProxies yoksa CDN/ALB arkasında tüm IP kovaları tek
kovaya düşer (review §2.3) · IPv6'da /64 başına kova yok (IP kovası kolay aşılır, davetiye kovası
tutar) · büyük harfli ULID `whereUlid`'den geçer ama bulunamaz (ULID spesifikasyonu harf duyarsız;
QR alfanümerik kipi URL'yi büyütür) · webhook bildirimi tutar/para birimi taşımıyor (gerçek
sağlayıcıda tutar doğrulaması için yer yok) · IBAN biçim/mod-97 doğrulaması yok (ürün kararı) ·
asistan kotası gün sınırı UTC (bilinen açık karar #4).

---

## 2. Dosya bazında denetim haritası

"Hayatta" = testler bozulmuş kodla YEŞİL kaldı (boş yeşil).

| Dosya | Hayatta kalan mutantlar (kanıtlı) | Öncelik | Durum |
|---|---|---|---|
| **RsvpTest** | 18/34 → **0/34** | — | ✅ Yeniden yazıldı (46 metot, 86 vaka, 7 kırmızı = K-2/K-5/K-6/K-7) |
| **InvitationTest** | İstek eşlemesinden `names`, `venue`+`mapUrl`, `iban`/`bankName`/`accountHolder`, `showGift` düşürülünce hepsi yeşil; liste sıralaması; K-1 hiç sınanmıyor; oyuncak veri (`Dugunumuz`) | 🔴 1 | ⬜ SIRADAKİ |
| **PublicInvitationTest** | 🔴 Cache anahtarından `id` çıkarılınca (tüm davetiyeler tek cache girdisi = çiftler arası sızıntı) yeşil; `names`/`venue`/`mapUrl` boş dönse yeşil; `date` ISO'ya dönse yeşil; `show_rsvp=false` iken `rsvpDeadline`/`askMenuPreference` sızsa yeşil (C6) | 🔴 2 | ⬜ |
| **PaywallTest** | `SubscriptionTier::price()` → `1` yeşil (beklenen değer aynı fonksiyonla hesaplanıyor); `currency` → `USD` yeşil; `show_envelope` Gold→Standart yeşil; K-1, K-4 yok | 🔴 3 | ⬜ |
| **AuthTest** | K-3; NUL; iki testte `actingAs()` (T10 ihlali); ASCII isimler; 429 başlığı | 🟠 4 | ⬜ |
| **MediaTest** | Güçlü (LCV medyası mutantları öldü). İçerikten MIME doğrulaması yalnızca `UploadedFile::fake()` ile — sahte dosya bildirilen MIME'ı raporlar, `getClientMimeType()` mutantı yeşil. Gerçek baytlı dosyayla (PHP-as-JPG, SVG, polyglot) otomatik test mümkün | 🟡 5 | ⬜ |
| **ContactTest** | Saatlik kova silinince yeşil; NUL; Türkçe veri | 🟡 6 | ⬜ |
| **AssistantTest** | `retryAfter` yalnızca `assertIsInt` → sabit `1` dönse yeşil (travelTo ile sabitlenebilir); oyuncak istemler | 🟡 7 | ⬜ |
| **HardeningTest** | HSTS "yalnızca elle doğrulanır" deniyor — `https://localhost` ile otomatik test edilebiliyor; HSTS bloğu silinince yeşil | 🟡 8 | ⬜ |
| MaintenanceTest · OptimizeUploadedImageTest · HealthTest · LocalServerEnvironmentTest · OrderScopeTest | Yüzeysel incelendi; örneklenen mutantlar öldü. Tam denetim yapılmadı | 🟢 | ⬜ |

---

## 3. Karar bekleyenler

| # | Soru | Öneri |
|---|---|---|
| D-1 | NUL + yarım JSON: global middleware → 400 `MALFORMED_REQUEST`, mi alan başına 422 mi? | 400, tek middleware (`api` grubu) |
| D-2 | `integer` → `integer:strict` (metin `"3"` de reddedilir) | Evet |
| D-3 | `ApiExceptionRenderer` 429/503'te `Retry-After` başlığını göndersin | Evet (sözleşme zaten söylüyor) |
| D-4 | K-1: yayındaki davetiyede modül açma 402 mi, `INVITATION_LOCKED` 403 mü? | 402 `PAYWALL_TIER_INSUFFICIENT` (review §1.1) |
| D-5 | K-3: `İ`→`i` dönüşümü mü, ASCII dışı yerel kısmı reddetmek mi? | `İ`→`i` + `mb_strtolower`, ardından tek kaynaklı normalizasyon (Request + mutator) |
| D-6 | K-4: `OrderStatus::Expired` mi, yalnızca `Log::critical` mı? | `Expired` (review §1.2) |

---

## 4. Çalışma notları (kum havuzunu yeniden kurmak için)

- `git clone https://github.com/ismail-cankaya/davetkart-backend-php-laravel.git` (herkese açık).
- `composer install --prefer-source --ignore-platform-req=php`; `phpstan/phpstan` yalnızca dist
  verdiği için `git clone --branch 2.2.7 github.com/phpstan/phpstan` → zip → `composer.lock`'taki
  dist URL'si geçici olarak `file://` yapıldı (sonra geri alındı).
- Mutasyon koşucusu: vaka sayısı tabana eşit değilse sonucu REDDETMELİ (filtre `Tests\Feature\X`
  tek ters bölüyle verilince PHPUnit `\R`'yi regex sanıp 0 test koşuyor — bir kez yaşandı).
- Laravel JSON'u `\uXXXX` ile kaçırır: Türkçe karakterli sızıntı işaretini ham gövdede aramak
  boş yeşil üretir; çözülmüş JSON'a bakılmalı.
