# Faz 19 — denetimin orta öncelikli bulguları (`AUD-2026-11-12`)

> **Başlangıç:** 20 Kasım 2026 · **Entegrasyon dalı:** `faz-19-audit-fixes` (`faz-18-audit-fixes`'in ucundan: backend `a968a67`, frontend `fefa647`)
> **Durum:** 1/28 · 19.1 (`SEC-07`) kod ✅ · yeni `composer.lock` temiz (`audit --locked`) · ⚠️ `vendor` kurulmadı (GitHub erişilemedi): `composer install` + `composer check` + `composer audit` İsmail'de, sonra commit ve `v1.1.0`. Her bulgu ayrı bir `fix/…` (ya da `docs/…`) dalında kapanır, `faz-19-audit-fixes`'e birleşir. 19.1 sonunda `v1.1.0` (= Faz 18 + `SEC-07`), faz sonunda `v1.2.0`.
> **Kaynak:** docs deposu `audits/2026-11-12-full-system-audit/REPORT.md` §5.1 · takip tablosu `phases/faz-19/README.md`
> **Kararlar:** docs deposu `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-19.md`
> **Önceki:** [`FAZ-18.md`](FAZ-18.md). Faz 16–17'nin kaydı yok (`ARC-02`, Faz 19.3).

Denetim 29 orta (🟡) bulgu çıkardı. `TST-01` Faz 18.1'de kapandı (K150); kalan 28'i burada, sırası
docs `phases/faz-19/README.md`'deki gerekçeyle: önce yayın engeli, sonra kayıt ve işletim, güvenlik,
frontend, en son çok dosyaya dokunan `ARC-03`.

| Sıra | Bulgu | Konu | Dal | K | Durum |
|---|---|---|---|---|---|
| 19.1 | `SEC-07` | Composer bağımlılıklarında bilinen açıklar; `composer audit` kırmızı | `fix/2026-11-20-sec-07-composer-audit` | K179–K181 | kod ✅ · lock temiz (`audit --locked`) · ⚠️ `vendor` kurulmadı; yeni `vendor` ile `check`/`audit` koşmadı (§19.1) |
| 19.2 | `ARC-01` | Karar indeksi K128–K145'i bilmiyor | — | — | bekliyor |
| 19.3 | `ARC-02` | Faz 16 ve 17'nin faz kaydı yok | — | — | bekliyor |
| 19.4 | `ARC-05` | Eski `claude/…` yolları; backend'in `claude/` klasörü ikinci ev | — | — | bekliyor |
| 19.5 | `ARC-04` | K55 "yerel disk kalır" geçerli görünüyor ama yayın planı S3 kuruyor | — | — | bekliyor |
| 19.6 | `OPS-04` | Üretim `.env` şablonu sunucudaki gerçek dosyadan sapmış | — | — | bekliyor |
| 19.7 | `OPS-03` | İzleme ve alarm yok; sağlık ucu yalnızca PHP'nin ayakta olduğunu söylüyor | — | — | bekliyor |
| 19.8 | `OPS-05` | Tek hata noktaları: alan adı yenilemesi, posta ve kredi bitiş tarihleri | — | — | bekliyor |
| 19.9 | `SEC-05` | Zayıf parolalar kabul ediliyor | — | — | bekliyor |
| 19.10 | `SEC-06` | Genel hız sınırı IP anahtarlı: misafir trafiği davetiye sahibini de kilitliyor | — | — | bekliyor |
| 19.11 | `TST-02` | Kilitlerin hiçbiri testle korunmuyor | — | — | bekliyor |
| 19.12 | `FE-51` | Misafir fotoğraf/video yüklemesine arayüzden ulaşılamıyor | — | — | bekliyor |
| 19.13 | `SEC-08` | Kimliksiz misafir davetiye başına 5 GB yükleyebilir; yetim dosya kotayı 24 saat tutar | — | — | bekliyor |
| 19.14 | `SEC-09` | E-posta doğrulaması yok | — | — | bekliyor |
| 19.15 | `SEC-10` | Shopier'e uydurma TCKN ve telefon gönderiliyor | — | — | bekliyor |
| 19.16 | `SEC-13` | `data:purge` tarihi olmayan davetiyelerin misafir verisini hiç silmiyor | — | — | bekliyor |
| 19.17 | `SEC-12` | Her sayfa Google Fonts'u Google'dan yüklüyor | — | — | bekliyor |
| 19.18 | `SEC-11` | Gizlilik metni "hemen silinir" diyor; yedekler tutuyor; üçüncü taraflar adıyla yok | — | — | bekliyor |
| 19.19 | `FE-52` | Fiyat sayfası SSS'si backend'de olmayan "yayın süresi" ve ücretli uzatmayı anlatıyor | — | — | bekliyor |
| 19.20 | `FE-01` | Dil seçici on dil sunuyor, arayüzün büyük kısmı yalnızca Türkçe | — | — | bekliyor |
| 19.21 | `FE-02` | Hata sınırı yok: render hatası ve yüklenemeyen chunk ham hata ekranı veriyor | — | — | bekliyor |
| 19.22 | `TST-04` | Gerçek frontend + gerçek backend'i koşturan otomatik test yok | — | — | bekliyor |
| 19.23 | `FE-04` | Davetiye bağlantısının paylaşım önizlemesi yok (Open Graph) | — | — | bekliyor |
| 19.24 | `FE-10` | Ana giriş paketi 1,64 MB: 299 şablon her sayfaya, misafir sayfasına bile yükleniyor | — | — | bekliyor |
| 19.25 | `FE-53` | Kısıtlı mobil koşulda LCP 3,9–5,7 sn; giriş ve fiyat sayfalarında CLS 0,33–0,63 | — | — | bekliyor |
| 19.26 | `FE-50` | Sihirbaz her ziyarette sunucuda boş taslak açıyor; yenilemede editör kayboluyor | — | — | bekliyor |
| 19.27 | `FE-54` | Hata ve başarı mesajları ekran okuyucuya duyurulmuyor; kontrast yetersiz | — | — | bekliyor |
| 19.28 | `ARC-03` | K18 dosyaların %92'sinde çiğneniyor; 79 dosyanın kılavuzu yok | — | — | bekliyor |

Docs deposunda `phases/faz-19/FAZ-19-*-KAYIT.md` yok (20 Kasım); taşınacak bölüm yok.

---

## 19.1 — `SEC-07`: Composer bağımlılıklarında bilinen açıklar; `composer audit` kırmızı

### Sorun

`composer audit` 6 pakette 9 bilinen açık gösteriyordu (20 Kasım, `faz-19-audit-fixes` @ `a968a67`,
çıkış 1): `laravel/framework` v13.35.0 (1, düşük), `symfony/http-foundation` v8.1.9 (4),
`symfony/http-kernel` v8.1.1 (1), `symfony/routing` v8.1.0 (1), `symfony/mime` v8.1.0 (1, orta),
`symfony/filesystem` v8.1.9 (1). Denetim günü 8'di; 13 Kasım akşamı Laravel'in tavsiyesi eklendi.
CI'ın "Composer güvenlik taraması" adımı bu yüzden kırmızıydı ve `v1.1.0` etiketi bekliyordu (K150,
`OPS-01`). Dokuz açıktan biri ("üretilen URL'ler istemcinin seçtiği host'u gösterebilir") bizim kodumuza
dokunuyordu: `ShopierGateway::startCheckout` callback adresini `url(CALLBACK_PATH)` ile **isteğin
host'undan** üretiyordu. Denetimin anlattığı satır yerindeydi (Faz 18 bu satıra dokunmamıştı). Bugün
sömürü yolu yok (Nginx `default_server` tanımadığı host'u `444` ile keser, `TRUSTED_PROXIES` boş,
saldırgan yalnızca kendi formunu bozabilir), yani düzeltme derinlemesine savunma.

**Dersin özü:** bağımlılık açığı senin yazmadığın ama senin sorumluluğunda olan koddur. Kırmızıya
alışılmış bir kapı okunmaz; gerçek bir kritik açık aynı gürültüde kaybolur. Dış adres üretirken isteğin
`Host`'una değil yapılandırmaya güvenmek aynı dersin kod tarafıdır.

### Kararlar

| K | Karar (tek cümle) |
|---|---|
| **K179** | Güvenlik güncellemesi yalnızca `composer.lock`'u değiştirir ve majör getirmez: `composer update "symfony/*" laravel/framework -W` + geçici `--with` kısıtları (`guzzlehttp/psr7:^2.13`, `guzzlehttp/uri-template:^1.0.10`, `brick/math:^0.18`); Guzzle 8 / PSR-7 3 / `brick/math` 1.0 ayrı karar. |
| **K180** | Dışarıya verilen mutlak adresler istekten değil yapılandırmadan üretilir; Shopier callback = `rtrim(config('app.url'), '/')` + `CALLBACK_PATH` (açık üretim, küresel `URL::forceRootUrl` yok). |
| **K181** | Yayın adayı = `composer check` ✅ ve `composer audit` ✅; `audit` `check` zincirine girmez (ağ ister, zamana bağlı), CI'da ayrı adım ve yayın listesinde ayrı madde. K150'yi tamamlar. |

**§A:** iki satır da boştu. §A-1 için varsayılan uygulandı, ama kuru koşu varsayılan komutun beş dolaylı
paketi **majör** atlattığını gösterdi (`guzzle` 7 → 8, `psr7` 2 → 3, `promises` 2 → 3, `uri-template` 1 → 2,
`brick/math` 0.18 → 1.0). Prompt §8 gereği durdum; İsmail oturumda majörsüz yolu seçti (K179). §A-2 için
varsayılan "evet" uygulandı (K180). İkisi de K kaydında "varsayılan (İsmail cevaplamadı)" diye işaretli.

**Tamamlanan karar:** K150 → K181 (geçersiz kılınmadı). Geçersiz kılınan karar yok.

### Değişen dosyalar

**Backend** (`fix/2026-11-20-sec-07-composer-audit`)

| Adım | Dosya | Ne | Kılavuz |
|---|---|---|---|
| 19.1.1 | `composer.lock` | 55 paket (54 güncelleme + `symfony/polyfill-time`); `laravel/framework` 13.35.0 → 13.43.0, açıklı altı Symfony paketi 8.1.10/8.1.11; majör yok, dev paketleri aynı, `content-hash` aynı | [`composer.md`](../composer.md) (yeni; sürüm tablosu §4, dokuz açık §5) |
| 19.1.2 | `app/Services/Payment/ShopierGateway.php` | `'callback' => $this->callbackUrl()`; `callbackUrl()` = `APP_URL` + `CALLBACK_PATH` | [`ShopierGateway.md`](../app/Services/Payment/ShopierGateway.md) §12 (yeni), §5, §9 |
| 19.1.2 | `tests/Feature/PaymentDriverResolutionTest.php` | `the_shopier_callback_url_ignores_the_request_host` (2 veri) | [`PaymentDriverResolutionTest.md`](../tests/Feature/PaymentDriverResolutionTest.md) |
| 19.1.2 | `tests/Feature/ShopierGatewayTest.php` | Beklenen callback `url()` yerine `app.url` + yol (1 satır) | — |
| 19.1.2 | `docs/rehber/config/trustedproxy.md` | B6 notuna durum notu ("hiçbir yer yok" Faz 12'den beri yanlıştı) | — |
| 19.1.3 | `docs/rehber/fazlar/FAZ-19.md` (yeni) · `fazlar/README.md` · `fazlar/FAZ-18.md` | Bu kayıt; faz dizinine Faz 19 satırı; Faz 18 başlığına ve dizin satırına "→ 19.1" durum notu | — |

`composer.json`, `contracts/`, göç, config, hata kodu, `.github/workflows/ci.yml` **değişmedi** (CI'da
`composer audit` zaten ayrı adımdı; K181 bunu karara bağladı). Yeni bağımlılık yok.

**Frontend:** değişmedi, commit yok. `npm audit --omit=dev`: `found 0 vulnerabilities`.

**Docs** (`docs/2026-11-20-faz-19-audit-fixes`), adım 19.1.4: `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-19.md`
(yeni), `decisions/DECISION-INDEX.md` (K179–K181, K150 notu, sıradaki K182), `decisions/README.md`,
`operations/deployment/YAYIN-SIRASI.md` (§2 madde 2, §3 etiket mesajı), `phases/faz-19/README.md`,
`phases/faz-19/FAZ-19-SEC-07-COMMIT-KOMUTLARI.md` (yeni), `phases/README.md`,
`phases/faz-18/FAZ-18-SEC-04-COMMIT-KOMUTLARI.md` (§5 durum notu), `CHANGELOG.md`.

### Eklenen testler ve öldürdükleri mutantlar

`PaymentDriverResolutionTest::the_shopier_callback_url_ignores_the_request_host`: uçtan uca HTTP
checkout (`payment.default=shopier`), `app.url=https://davetkart.test`. Veri satırları:
`Host: evil.example` (istek mutlak adresle: `http://evil.example/api/payments/checkout`) ve
`TRUSTED_PROXIES=*` + `X-Forwarded-Host: evil.example`. Beklenen:
`https://davetkart.test/api/public/payments/shopier/callback`.

Önce kırmızı (düzeltmeden önce, TDD): iki satır da `http://evil.example/…` ve `https://evil.example/…`
döndü. Düzeltmeden sonra yeşil.

| # | Mutant | Sonuç | Kırmızıya dönen |
|---|---|---|---|
| M1 | `$this->callbackUrl()` → `url(self::CALLBACK_PATH)` (eski `vendor`, Laravel 13.35 / Symfony 8.1.9) | ✅ öldü | iki veri satırı (`http://evil.example/…`, `https://evil.example/…`) |
| M1' | Aynı mutant, güncellenmiş `vendor` (Laravel 13.43 / Symfony 8.1.11) | koşmadı | `vendor` kurulamadı (GitHub); elle doğrulama 3 |
| D1 | Kapı: `composer audit --locked`, eski lock'a karşı (`faz-18-audit-fixes:composer.lock`, geçici dizin) | ✅ kırmızı | çıkış 1, `Found 9 security vulnerability advisories affecting 6 packages` (yeni lock: çıkış 0) |
| — | Denetim satırı: `withHeader('Host', 'evil.example')` (M1 uygulanmışken, geçici) | etkisiz | callback `http://localhost:8000/…`: başlık yok sayıldı. Testin mutlak adres kullanma nedeni ([kılavuz](../tests/Feature/PaymentDriverResolutionTest.md)) |

Geri alma: `git diff | sha1sum` mutanttan önce ve sonra `d2e13d271d5d`.

### Doğrulama durumu (B7)

Makine: Windows 11, PHP 8.5 (Herd), PostgreSQL 18.4, Node 24, Windows PowerShell 5.1. Koşular 20 Kasım 2026.

| Komut | Sonuç | Çıktıdan |
|---|---|---|
| Önkoşullar (prompt §2) | ✅ | Üç ağaç boş; Faz 18'in altı `fix/…` dalı `faz-18-audit-fixes`'e birleşmiş (`git branch --no-merged` boş); `faz-19-audit-fixes` iki depoda `faz-18-audit-fixes`'in ucunda (İsmail açtı) |
| `git fetch origin` (backend, frontend) | ❌ koşmadı | `Recv failure: Connection was reset`: GitHub bu makineden erişilemedi (aşağıda) |
| `composer audit` (öncesi) | ❌ (beklenen) | çıkış 1 · `Found 9 security vulnerability advisories affecting 6 packages` |
| `composer update … --dry-run` (üç biçim) | ✅ | yalnızca adlı paketler: çözülmedi (`laravel/prompts` kilitli); `-W`: 5 majör; `-W` + üç `--with`: `1 install, 54 updates, 0 removals`, majör ve düşürme yok |
| Packagist güvenlik API'si (Guzzle, PSR-7, `uri-template`, `brick/math`) | ✅ | Guzzle tavsiyeleri `< 7.15.2`, PSR-7 `< 2.12.3`; `uri-template` ve `brick/math` için tavsiye yok |
| `composer update … -W --with …` | ⚠️ yarım | iki koşu da çıkış 100: lock yazıldı (`Writing lock file`), `api.github.com`'dan indirmeler `curl error 35 … Connection was reset` |
| `composer audit --locked` (yeni lock) | ✅ | `No security vulnerability advisories found.` çıkış 0 |
| `composer install` (lock → `vendor`) | koşmadı | GitHub 12:40–13:22 arası kesintisiz erişilemedi (`curl` 81 deneme: `Recv failure: Connection was reset`; Packagist ve npm 200). `composer install --dry-run`: `1 install, 54 updates, 0 removals` bekliyor; `vendor/composer/tmp-*` artığı yok |
| **`composer audit`** (kurulu paketler) | koşmadı | `vendor` eski (13.35.0); yerine yeni lock `--locked` ile denetlendi (yukarıda) |
| `pint --test` · `phpstan analyse` · ilgili testler (eski `vendor`) | ✅ | `pint passed` · `phpstan errors: 0` · Shopier + sürücü testleri `60 passed` |
| `composer check` (19.1.2'nin kodu, **eski** `vendor`: Laravel 13.35 / Symfony 8.1.9) | ✅ | `pint passed` · `phpstan errors: 0` · `Katalog guncel.` · `Plan kurallari sozlesmesi guncel.` · `tests: 1168, passed: 1168, assertions: 4326` · çıkış 0 |
| **`composer check`** (yeni `vendor`) | koşmadı | `vendor` kurulamadı; eski `vendor` ile koşusu aşağıda |
| Frontend `npm audit --omit=dev` | ✅ | `found 0 vulnerabilities`, çıkış 0 |
| Frontend `npm ci` | ✅ | çıkış 0 · `found 0 vulnerabilities` (`allow-scripts` uyarısı: `esbuild` kurulum betikleri) |
| Frontend `npm run check` | ✅ | çıkış 0 · `✓ built in 10.86s` · `✓ Plan kuralları backend sözleşmesiyle aynı …` · `✓ Ekrana çizilen doğru …` |
| `tinker` örneği (kılavuz §12 "Kendin dene") | ✅ | `url(...)` → `http://evil.example/api/public/payments/shopier/callback`; `APP_URL` yolu → yerel `.env` adresi |
| Sunucudaki `APP_URL`, sunucuda `composer audit --no-dev`, gerçek Shopier dönüşü | koşmadı | canlı sisteme bağlanılmadı (prompt §0.10) |

### Elle doğrulama (İsmail)

1. 🔴 **Commit'ten önce (bu oturumda koşmadı):** GitHub erişilebilirken `composer install` → ardından
   `composer install --dry-run` → `Nothing to install, update or remove` (lock ile `vendor` aynı).
2. `composer audit` → `No security vulnerability advisories found.` (çıkış 0) · `composer check` → son satır
   `"result":"passed"`, `tests: 1168`. Biri kırmızıysa commit etme; çıktıyla birlikte bana getir (K150: kırmızı
   kapı yayın adayı değildir). Docs `phases/faz-19/FAZ-19-SEC-07-COMMIT-KOMUTLARI.md` §0.
3. Mutantı kendin dene: `ShopierGateway::startCheckout`'ta `$this->callbackUrl()` yerine
   `url(self::CALLBACK_PATH)` → `php artisan test --filter=the_shopier_callback_url_ignores_the_request_host`
   kırmızı (`evil.example`) → geri al.
4. **Yayından önce, sunucuda:** `grep '^APP_URL=' /var/www/davetkart/backend/.env` → `APP_URL=https://davetkart.com`
   (K180: callback adresi artık buradan; yanlışsa ödeme sonrası tarayıcı kırık sayfaya gider, sipariş OSB ile
   yine `paid` olur).
5. **Yayından sonra, sunucuda:** `cd /var/www/davetkart/backend && composer audit --no-dev` → açık yok.
6. Kart açıldığında (ilk gerçek ödeme): DevTools → Network → `checkout` yanıtında `checkout.fields.callback`
   = `https://davetkart.com/api/public/payments/shopier/callback`.
7. `v1.1.0`: docs `phases/faz-19/FAZ-19-SEC-07-COMMIT-KOMUTLARI.md` §5 (birleştirme, etiket, sıra).

### Plandan sapmalar

- **19.1.1 komutu (`--with` kısıtları).** §A-1'in varsayılanı majör atlatıyordu; §8 dur koşulu. İsmail
  majörsüz yolu seçti. `uri-template:^1.0` yerine `^1.0.10`: `^1.0` çözücüyü 1.0.0'a düşürdü (kuru koşuda
  `Downgrading`).
- **Kılavuzun adı `composer.md`** (prompt "`composer-guncelleme.md` ya da deseninle uyumlu bir yer"):
  araç kılavuzları `phpstan.md`, `pint.md`, `phpunit.md` biçiminde.
- **Test yeni bir sınıf değil**, Shopier'in uçtan uca checkout testinin durduğu `PaymentDriverResolutionTest`'te;
  `Host` için mutlak istek adresi (`withHeader('Host')` testte etkisiz, denendi) ve ikinci veri satırı
  (`X-Forwarded-Host`). Prompt yalnızca `Host` diyordu; vekil yolu aynı açığın ikinci kapısı.
- **D2'nin kapsamı tek satır.** Taramada istekten mutlak adres üreten başka yer çıkmadı (liste:
  [`ShopierGateway.md`](../app/Services/Payment/ShopierGateway.md) §12); ortak yardımcı yazılmadı.
- **19.1.2'de `ShopierGatewayTest` ve `trustedproxy.md` de değişti** (beklenen değer ve yanlış hâle gelmiş
  cümleye durum notu). Kendi adımlarının commit'inde.

### Gördüm, dokunmadım

| Bulgu | Ne |
|---|---|
| `OPS-04` | 🔴 `docs/10-URETIM-ENV-SABLONU.md` `APP_URL=https://api.davetkart.com` diyor (aynı kökenli yayından önceki şablon; Y8.3b üretimde `https://davetkart.com` yazdı). K180'den sonra bu satır **ödeme dönüşünü kırar**: yeni sunucu şablondan kurulursa callback var olmayan alan adına gider. Aynı eski bilgi: `config/davetkart.md` "Neden `APP_URL` değil?" paragrafı, `FAZ-12-ELLE-DOGRULAMA.md` panel adresleri |
| — | Guzzle 8 / PSR-7 3 / `brick/math` 1.0 bekliyor (K179). Bir sonraki düz `composer update` getirir; ayrı dal + HTTP istemcisi (`GeminiProvider`), S3, Sentry yollarının sınanması |
| — | `routes/web.php` + `resources/views/welcome.blade.php` (Laravel iskeleti; `url('/dashboard')`, `route('login')`) **ölü**: `bootstrap/app.php` `web:` rotalarını yüklemiyor. Silinebilir |
| — | `FAZ-18.md` kapanış tablosunda `SEC-04` "commit bekliyor" yazıyor; `a968a67` ile birleşmiş |
| `ARC-08` | `faz-18-audit-fixes` `origin/ccr-9a346997-bu6gve`'yi izliyor ve 28 commit önde (yerel); dal düzeni kapsam dışı |

### Açık kalanlar (karar İsmail'in)

1. Guzzle 8 / PSR-7 3 / `brick/math` 1.0 geçişi: ne zaman, hangi dalda (K179'un devamı).
2. Sunucudaki `APP_URL`'in yayından önce gözle doğrulanması (elle 4).
3. `docs/10` üretim şablonunun düzeltilmesi (`OPS-04`, Faz 19.6; K180 yüzünden önceliği arttı).
4. `v1.0.0` (bugün yayındaki commit'ler) etiketi: docs `YAYIN-SIRASI.md` §3 önerisi.
