# Faz 19 — denetimin orta öncelikli bulguları (`AUD-2026-11-12`)

> **Başlangıç:** 20 Kasım 2026 · **Entegrasyon dalı:** `faz-19-audit-fixes` (`faz-18-audit-fixes`'in ucundan: backend `a968a67`, frontend `fefa647`)
> **Durum:** 28/28 kod ✅ ve birleşti (19.0 hazırlık + 19.1–19.28; 25 Kasım 2026) · ✅ `composer check` yeşil (25 Kasım, Faz 19 ortak düzeltme: PHPStan iç hatası = worktree'ler arası ortak sonuç önbelleği; docs `phases/faz-19/HAT-C-ACIK-HATALAR.md` #11, §E) → `v1.2.0` etiketi İsmail'de. Kapanış özeti aşağıda.
> **Kaynak:** docs deposu `audits/2026-11-12-full-system-audit/REPORT.md` §5.1 · takip tablosu `phases/faz-19/README.md`
> **Kararlar:** docs deposu `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-19.md`
> **Önceki:** [`FAZ-18.md`](FAZ-18.md). Faz 16–17'nin kaydı 19.3'te (`ARC-02`) yazıldı: [`FAZ-16.md`](FAZ-16.md), [`FAZ-17.md`](FAZ-17.md).

## Faz 19 kapanış özeti (25 Kasım 2026)

- **Bulgular:** 28/28 kapandı ve `faz-19-audit-fixes`'e birleşti; `SEC-10` yalnızca hazırlık (kod değişmedi, Shopier cevabı bekliyor), `OPS-05` yalnızca docs.
- **Kararlar:** K179–K192, K220–K232, K250–K259 (K193–K219 ve K233–K249 ayrılmıştı, kullanılmadı). Sıradaki K260.
- **19.90 eksiksizliği:** 19.0 ve 19.2–19.27'nin 27 bölümü bu dosyada, numara sırasıyla (19.90 betiği her kayıt dosyasının "Faz bölümü"nü taşıdı; sayı 27).

| Kapı (bu dalda, 25 Kasım) | Sonuç |
|---|---|
| Backend `composer check` | ❌ PHPStan (Larastan) iç hatası (`Class mixed was not found`, `TemplateCatalogueTest`) zinciri durdurdu: docs `HAT-C-ACIK-HATALAR.md` #11 |
| … zincirin geri kalanı tek tek | ✅ Pint · `errors:export` / `plans:export` / `env:template --check` · `k18:check` (4 dosya, 0 ihlal) · `php artisan test` 1271/1271 (4613 assertion) |
| Backend `composer audit` | ✅ açık yok |
| Frontend `npm ci` + `npm run check` | ✅ (entegrasyon ucu `fe70b62`) |
| Frontend `npm run e2e` | ✅ 4/4 (gerçek backend: bu dal) |
| Frontend `npm run perf` | ✅ bütçe içinde; LCP ana 3824 · misafir 2896 · giriş 3848 · fiyat 4616 ms; CLS 0–0,014 |

**Kalan elle işler (İsmail):**

1. PHPStan iç hatası (#11) çözülmeden `composer check` yeşil olmaz → `v1.2.0` etiketi bekliyor (§11.5).
2. Shopier'in alıcı alanları cevabı (`SEC-10`, docs `notes/04-SHOPIER-ALICI-ALANLARI-SORUSU.md`; K125).
3. Süre takvimi: `operations/deployment/SURE-TAKVIMI.md` tarihlerinin doldurulması ve `.ics` içe aktarımı (`OPS-05`).
4. İzleme hesabı ve kalp atışı adresleri (`OPS-03`, `IZLEME.md` §4; K156).
5. Gizlilik metninin hukukçu gözden geçirmesi (`SEC-11`, K231).
6. Sunucuda `APP_URL` (K180), CSP zorlama kararı (K175), Gold onayı kaydı (K252), Guzzle 8 geçişi (K179).

**Faz 19'dan sonra sıradaki işler (öneri; karar İsmail'in).** 22 🟢 + 5 💡 bulgudan yayından sonra ilk ele alınacaklar:

| Bulgu | Konu | Neden önce |
|---|---|---|
| `OPS-08` | Günlüklerde istek kimliği yok | Yayından sonraki ilk olayda hangi isteğin neyi bozduğu bulunamaz; ucuz ve hemen yarar. |
| `SEC-16` | Hesap bazlı kaba kuvvet freni yok | IP değiştiren saldırgana karşı tek fren e-posta+IP kovası; giriş açık bir uç. |
| `ARC-09` | Checkout idempotent değil | Çift tıklama ikinci `pending` sipariş açar; ödeme yolunda karışıklık ve destek yükü. |
| `OPS-06` | Dağıtım yerinde, bakım kipi yok | Her dağıtım kısa bir kırık pencere açar; trafik geldikçe görünür olur. |
| `SEC-15` | Kayıt ucu e-postanın kayıtlı olduğunu söylüyor | Hesap tarama; `SEC-09` sonrası düzeltmesi küçük. |
| `FE-58` | Ödeme dönüşünde onaysız yayın (K67 sorusu) | Ürün kararı gerekiyor; kullanıcı beklentisiyle çelişebilir. |
| `TST-03` | Satır kapsamı ölçülmüyor | `k18:check` gibi bir cırcırla kapsam düşüşü de yakalanabilir. |
| `SEC-18` 💡 | Token httpOnly çerezde | K177 açık kararı; CSP zorlamasından (K175) sonra. |

Frontend için aynı K18 cırcırı (`verify:k18`) önerilir (D4); bu fazda frontend'e dokunulmadı.

Denetim 29 orta (🟡) bulgu çıkardı. `TST-01` Faz 18.1'de kapandı (K150); kalan 28'i burada, sırası
docs `phases/faz-19/README.md`'deki gerekçeyle: önce yayın engeli, sonra kayıt ve işletim, güvenlik,
frontend, en son çok dosyaya dokunan `ARC-03`.

| Sıra | Bulgu | Konu | Dal | K | Durum |
|---|---|---|---|---|---|
| 19.0 | — | Paralel hatlar için hat başına test veritabanı (hazırlık) | backend `fix/2026-11-20-faz19-parallel-test-db`<br>docs `docs/2026-11-20-faz19-parallel-test-db` | K182 | birleşti |
| 19.1 | `SEC-07` | Composer bağımlılıklarında bilinen açıklar; `composer audit` kırmızı | `fix/2026-11-20-sec-07-composer-audit` | K179–K181 | birleşti |
| 19.2 | `ARC-01` | Karar indeksi K128–K145'i bilmiyor | docs `docs/2026-11-20-arc-01-decision-index-hat-c` | K250–K251 | birleşti |
| 19.3 | `ARC-02` | Faz 16 ve 17'nin faz kaydı yok | backend `fix/2026-11-20-arc-02-phase-16-17-records`<br>docs `docs/2026-11-20-arc-02-phase-16-17-records` | K252 | birleşti |
| 19.4 | `ARC-05` | Eski `claude/…` yolları; backend'in `claude/` klasörü ikinci ev | backend `fix/2026-11-22-arc-05-claude-folder`<br>docs `docs/2026-11-22-arc-05-claude-folder` | K253–K254 | birleşti |
| 19.5 | `ARC-04` | K55 "yerel disk kalır" geçerli görünüyor ama yayın planı S3 kuruyor | backend `fix/2026-11-20-arc-04-media-disk`<br>docs `docs/2026-11-20-arc-04-media-disk` | K183 | birleşti |
| 19.6 | `OPS-04` | Üretim `.env` şablonu sunucudaki gerçek dosyadan sapmış | backend `fix/2026-11-22-ops-04-env-template`<br>docs `docs/2026-11-22-ops-04-env-template` | K184–K185 | birleşti |
| 19.7 | `OPS-03` | İzleme ve alarm yok; sağlık ucu yalnızca PHP'nin ayakta olduğunu söylüyor | backend `fix/2026-11-22-ops-03-health-monitoring`<br>docs `docs/2026-11-22-ops-03-health-monitoring` | K255–K256 | birleşti |
| 19.8 | `OPS-05` | Tek hata noktaları: alan adı yenilemesi, posta ve kredi bitiş tarihleri | docs `docs/2026-11-22-ops-05-expiry-calendar` | — | birleşti |
| 19.9 | `SEC-05` | Zayıf parolalar kabul ediliyor | backend ve frontend `fix/2026-11-23-sec-05-password-policy`<br>docs `docs/2026-11-23-sec-05-password-policy` | K258 | birleşti |
| 19.10 | `SEC-06` | Genel hız sınırı IP anahtarlı: misafir trafiği davetiye sahibini de kilitliyor | backend `fix/2026-11-22-sec-06-rate-limit-keys`<br>docs `docs/2026-11-22-sec-06-rate-limit-keys` | K186 | birleşti |
| 19.11 | `TST-02` | Kilitlerin hiçbiri testle korunmuyor | backend `fix/2026-11-22-tst-02-lock-tests`<br>docs `docs/2026-11-22-tst-02-lock-tests` | K187–K188 | birleşti |
| 19.12 | `FE-51` | Misafir fotoğraf/video yüklemesine arayüzden ulaşılamıyor | backend ve frontend `fix/2026-11-23-fe-51-guest-media-upload`<br>docs `docs/2026-11-23-fe-51-guest-media-upload` | K189 | birleşti |
| 19.13 | `SEC-08` | Kimliksiz misafir davetiye başına 5 GB yükleyebilir; yetim dosya kotayı 24 saat tutar | backend `fix/2026-11-23-sec-08-guest-upload-budget`<br>docs `docs/2026-11-23-sec-08-guest-upload-budget` | K190 | birleşti |
| 19.14 | `SEC-09` | E-posta doğrulaması yok | backend ve frontend `fix/2026-11-23-sec-09-email-verification`<br>docs `docs/2026-11-23-sec-09-email-verification` | K191 | birleşti |
| 19.15 | `SEC-10` | Shopier'e uydurma TCKN ve telefon gönderiliyor | docs `docs/2026-11-22-sec-10-shopier-buyer-fields` | — | birleşti |
| 19.16 | `SEC-13` | `data:purge` tarihi olmayan davetiyelerin misafir verisini hiç silmiyor | backend `fix/2026-11-23-sec-13-purge-undated`<br>docs `docs/2026-11-23-sec-13-purge-undated` | K257 | birleşti |
| 19.17 | `SEC-12` | Her sayfa Google Fonts'u Google'dan yüklüyor | backend ve frontend `fix/2026-11-23-sec-12-self-hosted-fonts`<br>docs `docs/2026-11-23-sec-12-self-hosted-fonts` | K226 | birleşti |
| 19.18 | `SEC-11` | Gizlilik metni "hemen silinir" diyor; yedekler tutuyor; üçüncü taraflar adıyla yok | frontend `fix/2026-11-24-sec-11-privacy-text`<br>docs `docs/2026-11-24-sec-11-privacy-text` | K231 | birleşti |
| 19.19 | `FE-52` | Fiyat sayfası SSS'si backend'de olmayan "yayın süresi" ve ücretli uzatmayı anlatıyor | frontend `fix/2026-11-24-fe-52-pricing-faq`<br>docs `docs/2026-11-24-fe-52-pricing-faq` | K232 | birleşti |
| 19.20 | `FE-01` | Dil seçici on dil sunuyor, arayüzün büyük kısmı yalnızca Türkçe | frontend `fix/2026-11-20-fe-01-language-scope`<br>docs `docs/2026-11-20-fe-01-language-scope` | K220–K221 | birleşti |
| 19.21 | `FE-02` | Hata sınırı yok: render hatası ve yüklenemeyen chunk ham hata ekranı veriyor | frontend `fix/2026-11-20-fe-02-error-boundary`<br>docs `docs/2026-11-20-fe-02-error-boundary` | K222–K223 | birleşti |
| 19.22 | `TST-04` | Gerçek frontend + gerçek backend'i koşturan otomatik test yok | backend ve frontend `fix/2026-11-22-tst-04-e2e`<br>docs `docs/2026-11-22-tst-04-e2e` | K224–K225 | birleşti |
| 19.23 | `FE-04` | Davetiye bağlantısının paylaşım önizlemesi yok (Open Graph) | backend ve frontend `fix/2026-11-23-fe-04-share-preview`<br>docs `docs/2026-11-23-fe-04-share-preview` | K230 | birleşti |
| 19.24 | `FE-10` | Ana giriş paketi 1,64 MB: 299 şablon her sayfaya, misafir sayfasına bile yükleniyor | frontend `fix/2026-11-23-fe-10-bundle-split`<br>docs `docs/2026-11-23-fe-10-bundle-split` | K227 | birleşti |
| 19.25 | `FE-53` | Kısıtlı mobil koşulda LCP 3,9–5,7 sn; giriş ve fiyat sayfalarında CLS 0,33–0,63 | frontend `fix/2026-11-23-fe-53-mobile-vitals`<br>docs `docs/2026-11-23-fe-53-mobile-vitals` | K228 | birleşti |
| 19.26 | `FE-50` | Sihirbaz her ziyarette sunucuda boş taslak açıyor; yenilemede editör kayboluyor | backend ve frontend `fix/2026-11-23-fe-50-draft-lifecycle`<br>docs `docs/2026-11-23-fe-50-draft-lifecycle` | K192 | birleşti |
| 19.27 | `FE-54` | Hata ve başarı mesajları ekran okuyucuya duyurulmuyor; kontrast yetersiz | frontend `fix/2026-11-23-fe-54-accessibility`<br>docs `docs/2026-11-23-fe-54-accessibility` | K229 | birleşti |
| 19.28 | `ARC-03` | K18 dosyaların %92'sinde çiğneniyor; 79 dosyanın kılavuzu yok | `fix/2026-11-24-arc-03-k18-ratchet`<br>docs `docs/2026-11-24-arc-03-k18-ratchet` | K259 | kod ✅ · birleşti (25 Kas) |

19.0 ve 19.2–19.27'nin bölümleri paralel hatların kayıt dosyalarından (`davetkart-docs: phases/faz-19/kayit/`)
19.90'da (24 Kasım) taşındı; numara sırasıyla aşağıda. Kararların gerekçesi docs `EK-FAZ-19.md`'de.

---

## 19.0 — Hazırlık: paralel hatlar için hat başına test veritabanı

> **Dallar:** backend `fix/2026-11-20-faz19-parallel-test-db` · docs `docs/2026-11-20-faz19-parallel-test-db` · **K:** K182 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-00-HAZIRLIK.md`

**Sorun.** Üç hat aynı anda `composer check` koşarsa hepsi `davetkart_test`'i kullanır; bir hattın
`RefreshDatabase`'i diğerinin tablolarını siler, testler rastgele kırmızı olur. `phpunit.xml` adı
`<server>` ile bilinçli olarak sabitliyor (10.79b), kabuktan ezilemiyor.

**Karar (K182).** `config/database.php`: yalnızca `APP_ENV=testing` **ve** `DAVETKART_TEST_DB_SUFFIX`
doluyken `pgsql.database = davetkart_test_<son ek>`. Ad sabit `davetkart_test_` ön ekiyle kurulur
(`DB_DATABASE`'ten türetilmez). Son ek `[a-z0-9_]` dışındaysa `InvalidArgumentException` (fail-closed,
K70/K123): sessizce ortak veritabanına düşmek yarışı geri getirirdi. Başka ortamda etkisi yok.
`TestSuiteIsolationTest::the_test_suite_runs_on_its_own_database` genişledi: `assertSame` →
`\Adavetkart_test(_[a-z0-9_]+)?\z`; `davetkart`, `davetkart_hat_x`, boş ad yine kırmızı.

**Değişen dosyalar (backend).** `config/database.php` · `tests/Feature/TestSuiteIsolationTest.php` ·
`docs/rehber/config/database.md` (yeni bölüm "Faz 19.0 (K182)") · `docs/rehber/tests/Feature/TestSuiteIsolationTest.md` §5.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `--filter=TestSuiteIsolationTest` (son ek yok) | ✅ 4/4 |
| 2 | `.env`'e geçici `DAVETKART_TEST_DB_SUFFIX=hat_x`, aynı test | ✅ 4/4 |
| 3 | Aynı `.env` + `APP_ENV=testing`, `tinker` ile `config(...)` | `davetkart_test_hat_x / testing` |
| 4 | Aynı `.env`, `APP_ENV=local` | `davetkart / local` (etkisiz) |
| 5 | Son ek `Hat-X` | 🔴 4 hata: `DAVETKART_TEST_DB_SUFFIX may only contain [a-z0-9_].` |
| M1 | Mutant: config'te ön ek `'davetkart_'.` (yani `davetkart_hat_x` kabul) | 🔴 test kırmızı, mesajda `davetkart_hat_x` |
| 6 | Tam `composer check` (pint, phpstan, errors/plans export, test) | ✅ 1168/1168 |

`.env` ve `config/database.php` her denemeden sonra yedekten geri kondu (hash eşit).

**B7.**

| İddia | Durum |
|---|---|
| Son ekli ad yalnızca `testing`'de | ✅ görüldü (3, 4) |
| Test son ekli adı görüyor ve kabul ediyor | ✅ görüldü (2, M1) |
| `composer check` | ✅ görüldü (6) |
| `composer audit` | koşmadı (bağımlılık değişmedi; prompt yalnızca `check` istedi) |
| `davetkart_test_hat_a/b/c` üzerinde gerçek koşu | koşmadı (veritabanları yok) |

**İsmail'e kalan elle işler.** (1) Üç veritabanını yarat:
`& "C:\Program Files\PostgreSQL\18\bin\createdb.exe" -U postgres davetkart_test_hat_a` (`hat_b`, `hat_c` aynı).
(2) Her hattın backend worktree'sinin `.env`'ine `DAVETKART_TEST_DB_SUFFIX=hat_a|b|c` ekle (dosya
newline ile bitmiyorsa önce satır sonu). (3) Her hatta bir kez `php artisan test --compact --filter=TestSuiteIsolationTest`.

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

---

## 19.2 — `ARC-01`: Karar indeksi K128–K145'i bilmiyor

> **Dallar:** docs `docs/2026-11-20-arc-01-decision-index-hat-c` · **K:** K250–K251 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-02-ARC-01.md`

**Sorun.** K128–K145 yalnızca backend `docs/rehber/fazlar/FAZ-13.md` §4 ve `FAZ-14.md` §4'te tanımlıydı;
`decisions/DECISION-INDEX.md`'de satırları yoktu (yalnızca ⚠️ notu). İndeksi kaynaklarla karşılaştıran bir
denetim olmadığı için boşluk fark edilmedi. `build_decision_index.py` argümansız koşunca indeksi K1–K127'ye ezerdi.

**Kararlar.** D1: 18 satır eklendi; kaynak sütunu düz metin `backend docs/rehber/fazlar/FAZ-1x.md §4`;
K131 → "daraltıldı → K159", K139 → "daraltıldı → K166". D2 (K250): `--check` docs + `../davetkart-backend-php-laravel`
içindeki bütün `*.md` tanımlarını toplar. D3: ⚠️ notu yerine denetim satırı ve hat aralıkları (K183–K279 ayrılmış) notu.
K251: argümansız çalıştırma kullanım yazar, çıkış 2; eski üretim `--generate` ile.

**Değişen dosyalar (docs).** `_restructure/build_decision_index.py` · `decisions/DECISION-INDEX.md`
(⚠️ bloğu, K128–K145, Notlar "Eksik numara: yok (K1–K181)").

**Testler + mutantlar.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `--check`, satırlar eklenmeden | çıkış 1; `indekste yok: K128` … `K145` (18 numara, başka yok) |
| 2 | `--check`, satırlarla | çıkış 0; `indeks 182 · tanım 182 · eksik 0 · tanımsız 0` |
| M1 | İndeksten K140 satırı silindi | çıkış 1; `indekste yok: K140 (… FAZ-14.md:73)` |
| M2 | İndekse `K999` eklendi | çıkış 1; `tanımı yok: K999` |
| 3 | `BE` = `davetkart-backend-YANLIS` | çıkış 2; `UYARI: kardeş backend deposu yok` |
| 4 | Argümansız | çıkış 2, kullanım metni; dosya yazılmadı |

İndeks her mutanttan sonra yedekten geri kondu; son `--check` çıkış 0. Satır sonları LF (`.gitattributes eol=lf`).

**B7.**

| İddia | Durum |
|---|---|
| İndeks ve tanımlar eşit | ✅ görüldü (2) |
| Eksik ve tanımsız numara yakalanıyor | ✅ görüldü (M1, M2) |
| Kardeş depo yoksa başarı sayılmıyor | ✅ görüldü (3) |
| Gerçek numara çakışması (§6) | yok — kural başlık karşılaştırmaz; bilinen K18/K24 notu indekste duruyor |
| `composer check` / `npm run check` | koşmadı (kod değişmedi) |

**Plandan sapma.** Prompt'taki dal adı `docs/2026-11-20-arc-01-decision-index` zaten vardı ve
`D:\Projects\davetkart\davetkart-docs.worktrees\arc-01-decision-index` worktree'sinde açıktı (temiz, commit'siz,
eski taban 625a733 — 19.0'dan önce). Dokunulmadı; iş `…-hat-c` sonekli yeni dalda yapıldı.

**Gördüm, dokunmadım.** `phases/faz-14/f14/backend/14.11/docs/rehber/fazlar/FAZ-14.md` backend `FAZ-14.md`'nin
docs'taki kopyası (K137–K145 iki yerde tanımlı görünür; aynı metin). "Sıradaki boş numara: K182" 19.90'da güncellenir.

**İsmail'e kalan elle işler.** Eski boş worktree ve dalı kaldırmak (commit dosyası §4, isteğe bağlı).

---

## 19.3 — `ARC-02`: Faz 16 ve 17'nin faz kaydı yok

> **Dallar:** backend `fix/2026-11-20-arc-02-phase-16-17-records` · docs `docs/2026-11-20-arc-02-phase-16-17-records` · **K:** K252 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-03-ARC-02.md`

**Sorun.** Faz 16 (10) ve Faz 17 (9) şablonu `preset_tiers`'e Gold olarak ekledi (`82c7312`, `d810410`);
faz özeti, elle doğrulama ve K kaydı yazılmadı. Fiyat kararı yalnızca commit mesajında ve kod yorumunda
duruyordu. `FAZ-15.md` "kapı koşmadı" diyordu ve kapanışı kaydedilmemişti.

**Kararlar.** D1: `FAZ-16.md`, `FAZ-17.md` (şablon tablosu, K, dürüst doğrulama satırı). D2 (K252): §A-1
**varsayılan (İsmail cevaplamadı)**; gerekçe commit'lerden, K ⚠️ "onaylatılmadı". **Plandan sapma:** varsayılan
"İsmail onaylamadı" diyordu; `d810410` ve `config/davetkart.php` yorumu "approved by Ismail, 2026-11-05"
diyor, bu yüzden metin "onay yalnızca kod yorumunda; kayıt onaylatılmadı" oldu. Faz 16'nın onu K102'nin
(video) uygulaması. D3: `FAZ-15.md` §3 sonuna durum notu (geçmiş yazıya dokunulmadı). D4: §A-2
**varsayılan (İsmail cevaplamadı)** = evet: `FAZ-16/17-ELLE-DOGRULAMA.md` (kısa).

**Değişen dosyalar (backend, yalnızca `docs/`).** `docs/rehber/fazlar/FAZ-16.md` · `FAZ-17.md` ·
`FAZ-16-ELLE-DOGRULAMA.md` · `FAZ-17-ELLE-DOGRULAMA.md` (yeni) · `FAZ-15.md` (durum notu).

**`fazlar/README.md` satırları (19.90 işler).**
- `| **16** | Premium sinematik koleksiyon: 10 Gold videolu şablon | ⚠️ kod ✅ · \`composer check\` yazıldığı ortamda koşmadı, yeşil Faz 18.1 (K150) · elle doğrulama bekliyor | [FAZ-16.md](FAZ-16.md) | 🔴 [3 adım](FAZ-16-ELLE-DOGRULAMA.md) |`
- `| **17** | Premium etkileşimli koleksiyon: 9 Gold + 2 Standart şablon, "premium" genişledi (K252) | (aynı) | [FAZ-17.md](FAZ-17.md) | 🔴 [4 adım](FAZ-17-ELLE-DOGRULAMA.md) |`

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | Kimlik karşılaştırması (FAZ-16/17 Gold satırları ↔ `plan-rules.json` `presetTiers` − K102'nin 13'ü) | çıkış 0; `docs 19 · plan-rules (K102 hariç) 19 · K102 13` |
| M1 | `FAZ-17.md`'den `nisan-koi` satırı silindi | çıkış 1; `dosyalarda yok: nisan-koi` (geri alındı, tekrar 0) |
| 2 | `php artisan plans:export --check` | ✅ `Plan kurallari sozlesmesi guncel.` |
| 3 | `composer check` | ❌ pint passed · **phpstan 2 hata** (aşağıda) · testler bu zincirde koşmadı |
| 4 | `php artisan test --compact` (hat_c veritabanı) | ✅ 1168/1168, 4326 assertion |

Karşılaştırma betiği oturumun geçici klasöründeydi (depoya girmedi): `presetTiers` anahtarlarından
`config/davetkart.php`'de "// Faz 16" yorumundan önceki 13 kimlik çıkarılır, kalan küme dosya tablolarıyla eşit olmalı.

**B7.**

| İddia | Durum |
|---|---|
| Dosyalardaki 19 Gold kimlik = `presetTiers` (K102 dışı) | ✅ görüldü (1, M1) |
| Frontend `minimumTier: 'gold'` sayısı 32 = backend 32 | ✅ görüldü (`grep -c`) |
| Dalın kapısı | ❌ `composer check` kırmızı, **bu daldan bağımsız** (`app/` entegrasyonla aynı) |
| Elle doğrulama | koşmadı (İsmail'de) |

**Kapı kırmızı (kapsam dışı, dokunmadım).** `app/Console/Commands/Concerns/ResolvesRefundRequests.php:29`
`(string) $id` → phpstan `cast.string` (`ApproveRefundRequest`, `RejectRefundRequest`). Dosya 14.6'dan beri
aynı; hat-c `vendor`'u yeni lock'la (Laravel 13.43, phpstan 2.2.7, larastan 3.10.0). 19.1 (`SEC-07`)
`composer check`'i yalnızca **eski** `vendor` ile yeşil gördü ("yeni `vendor` ile koşmadı"); kırmızı oradan.

**İsmail'e kalan elle işler.** (1) phpstan kırmızısı için karar: `SEC-07`'nin devamı mı, ayrı iş mi.
(2) `FAZ-16/17-ELLE-DOGRULAMA.md`. (3) K252'yi onayla ya da düzelt.

---

## 19.4 — `ARC-05`: Eski `claude/…` yolları; backend'in `claude/` klasörü ikinci ev

> **Dallar:** backend `fix/2026-11-22-arc-05-claude-folder` · docs `docs/2026-11-22-arc-05-claude-folder` · **K:** K253–K254 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-04-ARC-05.md`

**Sorun.** Backend'in `claude/` klasörü (22 dosya: EK-FAZ-5…9, `FAZ-10-PLANI.md`, devirler, üç gözden geçirme,
test denetimi, Faz 6–9 prompt'ları) taşınmamıştı; K49–K106'nın tek kaynağı oradaydı. Backend'de 25 dosya
(55 satır) 12 Kasım'dan beri var olmayan `claude/…` yollarını gösteriyordu.

**Kararlar.** §A-1 ve §A-2 **varsayılan (İsmail cevaplamadı)**. D1: eşleme `_restructure/2026-11-backend-claude-map.md`.
D2: 22 dosya birebir kopya (her blob backend'dekiyle aynı; commit 19.4.2 `update-index --cacheinfo` ile
birebir içeriği işler), sonra 13 göreli bağlantı (19.4.3). Backend'de yalnızca `claude/README.md` kalır; 22 `git rm`
commit dosyasında. D3 (K254): biçim `` `davetkart-docs: <yol>` ``; kılavuz backend
`docs/rehber/kavramlar/davetkart-docs-yollari.md`. D4: `DECISION-INDEX.md` K49–K106 kaynak sütunu (50 satır)
yeni yollara bağlantı; `build_decision_index.py` `--generate` kaynakları docs içinden.
**Plandan sapma:** (1) adlar korundu (`EK-FAZ-5`, `EK-FAZ-05` değil; docs `CLAUDE.md` §4). (2) `--check`
K252 (19.3, backend `FAZ-16/17.md`) yüzünden 1 döndü: K182–K279 "bekleyen" sayılır (K253). (3) `prompts/README.md`
paylaşılan dosya: satırları aşağıda, 19.90 işler. (4) `decisions/README.md`'nin eskimiş "K128–K145 yok" notu düzeltildi.
(5) `claude/FAZ-13-PLANI.md`, `FAZ-14-PLANI.md` hiçbir deponun geçmişinde yok: atıf "(plan dosyası hiçbir depoda yok)".

**Değişen dosyalar.** Backend: 25 atıf dosyası · `claude/README.md` (yeni) · kılavuz (yeni) · 22 `git rm`.
Docs: 22 kopya · eşleme · `DECISION-INDEX.md` · `build_decision_index.py` · `decisions/`, `audits/`, `phases/`, `notes/` README'leri.

**`prompts/README.md` satırları (19.90 işler).** `PROMPT-FAZ-6.md`, `-7.md`, `-7-KAPANIS.md`, `-7-TEK-SEFERDE.md`,
`-8.md`, `-9.md`: "Faz N (backend `claude/`'dan taşındı, 2026-11-22) | Kayıtlı değil | `phases/faz-0N/` | Tamamlandı (tarihsel)".

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | Kopya blob'ları = backend blob'ları | ✅ 22/22 eşit |
| 2 | Denetimin `grep`'i (backend, `claude/` hariç) | ✅ `0` (önce 25) |
| 3 | Göreli bağlantı taraması (22 kopya + eşleme + 4 README + indeks) | ✅ `236 bağlantı · 0 kırık` |
| M1 | `OKUBENI-FAZ-5.md`'de `PROMPT-FAZ-6` → `PROMPT-FAZ-66` | 🔴 `KIRIK … :39`, çıkış 1 (geri alındı, 0) |
| 4 | Backend bağlantıları (`claude/README.md`, kılavuz, iki README) | ✅ `33 · 0 kırık` (ilk koşu kod bloğunu sayıp yanlış alarm verdi; betik düzeltildi) |
| 5 | `build_decision_index.py --check` | ✅ çıkış 0 · `eksik 0 · tanımsız 0 · bekleyen 1` (K252) |
| 6 | Aynı, backend `claude/` taranmadan (`git rm` sonrası) | ✅ çıkış 0 |
| 7 | `composer check` | ❌ pint passed · phpstan 2 hata (19.3'teki `cast.string`, bu daldan bağımsız; İsmail'in "a" kararı) |

Bağlantı betiği oturumun geçici klasöründeydi (depoya girmedi).

**B7.** Taşıma ve bağlantılar ✅ görüldü (1–6, M1). Backend kapısı ❌ (bağımsız). Elle: `git rm` İsmail'de.

**Gördüm, dokunmadım.** Docs'taki diğer tarihsel metinlerde backend `claude/…` geçişleri (`_restructure/`, `audits/2026-11-12-…`,
`phases/faz-18|19`) · `archive/davetkart-claude/` · kopyaların metnindeki bağlantısız `claude/…` yolları (`CLAUDE.md` §8).

**İsmail'e kalan.** Commit dosyası (sıra önemli: 19.4.2 → 19.4.3). Açık hatalar: `davetkart-docs: phases/faz-19/HAT-C-ACIK-HATALAR.md`.

---

## 19.5 — `ARC-04`: K55 "yerel disk kalır" geçerli görünüyor ama yayın planı S3 kuruyor

> **Dallar:** backend `fix/2026-11-20-arc-04-media-disk` · docs `docs/2026-11-20-arc-04-media-disk` · **K:** K183 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-05-ARC-04.md`

**Sorun.** K55 "medya yerel `public` diskinde kalır" diyordu; yayın planı (Y0.1, Y3, YD-2) medyayı S3'e
koyuyor ve kararı geçersiz kılan bir K yoktu. `DAVETKART_MEDIA_DISK` varsayılanı `public` olduğu için
üretim `.env`'inde değişken unutulursa misafir fotoğrafları sessizce sunucunun diskine yazılırdı.

**Kararlar.** K183 (aşağıda). §A boş bırakıldı → iki satır da **varsayılan (İsmail cevaplamadı)**:
§A-1 fail-closed 503 + `PROVIDER_UNAVAILABLE` (yeni hata kodu yok); §A-2 beklenen disk `s3` (YAYIN-PLANI Y0.1/Y3 ile doğrulandı).
- **Plandan sapma 1:** `Log::critical` açılışta değil ret anında. PHP-FPM her istekte uygulamayı açar; açılış logu
  sağlık sondası dahil her isteği alarma çevirirdi. Açılış görünürlüğü `php artisan about` satırıyla.
- **Plandan sapma 2:** yalnızca `public` değil, üretimde `s3` dışındaki **her** disk reddedilir (allowlist; `local`, `S3` gibi yazım hataları da sessiz kalmasın — K70 ilkesi).
- **Plandan sapma 3:** 19.5.2 (`about`) ayrı commit değil 19.5.1'in içinde: satırı üreten `aboutLine()` guard sınıfında, test aynı dosyada.
- D3: `DAVETKART_MEDIA_DISK=s3` şablonda zaten vardı; yalnızca K183 açıklaması eklendi.

**Değişen dosyalar (backend).** `app/Support/MediaDiskGuard.php` (yeni) · `app/Exceptions/MediaStorageUnavailableException.php` (yeni) ·
`app/Actions/Media/StoreUploadedMediaAction.php` (ilk satırda kontrol) · `app/Providers/AppServiceProvider.php` (`about` bölümü) ·
`docs/rehber/app/Support/MediaDiskGuard.md` (yeni) · `tests/Feature/MediaDiskGuardTest.php` (yeni) ·
`docs/10-URETIM-ENV-SABLONU.md` · `docs/11-PROJE-TARIHCESI-VE-IS-AKISLARI.md`.

**Testler** (`MediaDiskGuardTest`, 5): `production_refuses_the_public_media_disk_for_uploads` (sahip + misafir 503, dosya ve satır yok, critical log) ·
`local_environment_still_uses_the_public_disk` · `production_with_s3_accepts_uploads` (`Storage::fake('s3')`) ·
`read_endpoints_keep_working_in_production_with_the_public_disk` · `about_command_shows_the_media_disk_and_the_refusal`.
**Mutantlar (2):** M1 ortam kontrolü kaldırıldı → `local_environment_…` kırmızı (201 yerine 503) · M2 Action'daki çağrı kaldırıldı → `production_refuses_…` kırmızı (503 yerine 201).

**B7 tablosu**

| Kontrol | Sonuç (görülen çıktı) |
|---|---|
| `pint --test` | geçti |
| `phpstan analyse` | 🔴 2 hata — `app/Console/Commands/Concerns/ResolvesRefundRequests.php:29` (`cast.string`, 14.6'dan; bu bulgunun dışında, dokunulmadı). Bu bulgunun dosyalarında hata yok |
| `errors:export --check` · `plans:export --check` | geçti · geçti |
| `php artisan test` (tam) | geçti — 1173 test, 4346 doğrulama |
| `composer audit` | geçti — açık yok |
| Frontend / e2e | koşulmadı (frontend'e dokunulmadı) |

**İsmail'e kalan elle işler.** (1) PHPStan kırmızısı (`ResolvesRefundRequests.php`) için karar: entegrasyon dalında da kırmızıysa ayrı bulgu.
(2) Canlı sunucuda `php artisan about --only=davetkart` → `Media disk: s3` görülmeli. (3) Yerelde biriken `public` dosyaların S3'e taşınması kapsam dışı.

---

## 19.6 — `OPS-04`: Üretim `.env` şablonu sunucudaki gerçek dosyadan sapmış

> **Dallar:** backend `fix/2026-11-22-ops-04-env-template` · docs `docs/2026-11-22-ops-04-env-template` · **K:** K184–K185 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-06-OPS-04.md`

**Sorun.** Üretim `.env`'i Y8.3b'de şablondan elle düzeltilerek kuruldu; `APP_URL` (`api.` alt alanı yok),
`AWS_URL` (CloudFront, şablonda hiç yoktu) ve `MAIL_MAILER=ses` şablona geri işlenmedi. Sunucu yeniden
kurulursa görseller 403 olur, parola sıfırlama maili sessizce `laravel.log`'a gider.

**Kararlar.** K184, K185 (aşağıda). §A boş → iki satır da **varsayılan (İsmail cevaplamadı)**:
§A-1 sunucu anahtar listesi yok → fark tablosu "sunucu görülmedi", yalnızca Y8.3b düzeltmeleri işlendi (D4 kısaltıldı);
§A-2 `log` posta → fail-closed değil, görünür (CRITICAL + `about` satırı).
- D1: `APP_URL=https://davetkart.com`, `AWS_URL=https://media.davetkart.com`, `AWS_BUCKET` örnek değer
  (`davetkart-media-000000`; gerçek ad şablona yazılmadı), `MAIL_MAILER=ses`, Shopier callback/OSB adresleri
  `api.` olmadan (Y8.3a notu), `AI_PROVIDER` satırına "sunucuda Y11'e kadar null" notu, ölü `BROADCAST_CONNECTION` silindi
  (`config/broadcasting.php` yok). Faz 18'in `config/`'e eklediği yeni `env()` anahtarı yok (komut bunu da doğruladı).
- **Plandan sapma 1:** `Log::critical` açılışta değil `MessageSending` anında (K183 ile aynı gerekçe: açılış her istekte ve
  her dakika zamanlayıcıda olur; Sentry'yi her istekte bir olayla doldururdu).
- **Plandan sapma 2:** dal `faz-19-audit-fixes`'ten değil `fix/2026-11-20-arc-04-media-disk`'ten açıldı (İsmail'in kararı:
  ARC-04 commit edildi ama birleştirilmedi; iki bulgu `AppServiceProvider.php` ve `docs/10`'u paylaşıyor). Birleştirme sırası: önce ARC-04.
- **Plandan sapma 3:** test dosyası (`ProductionConfigTest`) D2 ve D3'ü birlikte sınıyor; 19.6.3'te commit edilir.

**Değişen dosyalar (backend).** `docs/10-URETIM-ENV-SABLONU.md` · `app/Console/Commands/CheckEnvTemplate.php` (yeni) ·
`composer.json` (`check` zincirine `env:template --check`) · `app/Support/ProductionMailCheck.php` (yeni) ·
`app/Providers/AppServiceProvider.php` (dinleyici + `about` satırı) · `docs/rehber/app/Console/Commands/CheckEnvTemplate.md` (yeni) ·
`docs/rehber/app/Support/ProductionMailCheck.md` (yeni) · `tests/Feature/ProductionConfigTest.php` (yeni).

**Testler** (`ProductionConfigTest`, 6): `the_env_template_lists_every_key_config_reads` · `the_check_fails_when_the_template_misses_a_key_config_reads` ·
`the_check_fails_for_a_key_config_does_not_read` · `the_log_mailer_is_reported_as_critical_in_production` ·
`the_log_mailer_is_quiet_outside_production` · `about_command_shows_the_mailer_warning_in_production`.
**Mutantlar (2):** M1 şablondan `AWS_URL` silindi → komut `missing in template: AWS_URL` çıkış 1, test kırmızı ·
M2 ortam kontrolü kaldırıldı → `the_log_mailer_is_quiet_outside_production` kırmızı. İkisinden sonra dosyalar geri kondu, 6/6 yeşil.

**B7 tablosu**

| Kontrol | Sonuç (görülen çıktı) |
|---|---|
| `pint --test` | geçti |
| `phpstan analyse` | 🔴 2 hata — `ResolvesRefundRequests.php:29` (`cast.string`, 14.6'dan; ARC-04'te de görüldü, dokunulmadı). Bu bulgunun dosyalarında hata yok |
| `errors:export --check` · `plans:export --check` · `env:template --check` | geçti · geçti · geçti ("in sync") |
| `php artisan test` (tam) | geçti — 1179 test, 4360 doğrulama |
| `composer audit` | geçti — açık yok |
| Frontend / e2e | koşulmadı (frontend'e dokunulmadı) |

**İsmail'e kalan elle işler.** (1) Sunucuda `cut -d= -f1 .env | sort` ile anahtar listesini şablonla karşılaştır; şablonda
olmayan bir anahtar varsa sor. (2) Sunucuda `php artisan about --only=davetkart` → `Mail mailer: ses`. (3) PHPStan kırmızısı için karar (ARC-04 kaydı).

---

## 19.7 — `OPS-03`: İzleme ve alarm yok; sağlık ucu yalnızca PHP'nin ayakta olduğunu söylüyor

> **Dallar:** backend `fix/2026-11-22-ops-03-health-monitoring` · docs `docs/2026-11-22-ops-03-health-monitoring` · **K:** K255–K256 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-07-OPS-03.md`

**Sorun.** `/api/ping` veritabanına dokunmadan 200 döner; PostgreSQL durur, kuyruk takılır, cron susar ya da disk dolar,
dış izleme yine "çalışıyor" der. Başarısız kuyruk işi sessizdi.

**Kararlar.** §A-1…§A-3 **varsayılan (İsmail cevaplamadı)**: araç seçilmedi (karşılaştırma `IZLEME.md` §3); DSN'i İsmail
girer; eşikler 10 dk · 5 dk · %90. D1 `/api/ping` aynen. D2 (K255) `GET /api/health`: dört kontrol (`database`, `queue`,
`scheduler`, `disk`), her biri `HealthCheck` sınıfı, `ReadinessProbe` (Composite, fırlatan = başarısız), gövde yalnızca
`status` + başarısız adlar, `no-store`, `throttle:60,1`; eşikler `config/health.php`; disk `DiskUsage` arayüzü arkasında.
Kalp atışı `routes/console.php`'de her dakika (`withoutOverlapping` + `onOneServer` + `sentryMonitor`, `MaintenanceTest`
şartı). D3 (K256) `ReportFailedJob`: `Log::critical` (Sentry kanalının eşiği), yalnızca beş güvenli alan. D4 `IZLEME.md`.
**Plandan sapma.** (1) D3 "`Log::error`" diyordu: Sentry log kanalı `critical` eşikli (10.55b), `error` oraya ulaşmaz.
(2) Kalp atışı önce `MonitoringServiceProvider::boot()`'taydı; `routes/console.php`'ye taşındı (öteki dört işin yanı).
(3) `MaintenanceTest`'in Sentry izleyici listesine `'' => 'health-heartbeat'` eklendi (callback işinin komut metni yok).

**Değişen dosyalar.** Backend: `app/Support/Health/*` (7) · `app/Contracts/HealthCheck.php`, `DiskUsage.php` ·
`ReadinessController.php` · `MonitoringServiceProvider.php` · `ReportFailedJob.php` · `config/health.php` · `routes/api.php` ·
`routes/console.php` · `bootstrap/providers.php` · `tests/Feature/ReadinessTest.php` · `MaintenanceTest.php` (1 satır) · 5 kılavuz.
Docs: `operations/deployment/IZLEME.md` · `operations/README.md` · `HAT-C-ACIK-HATALAR.md`.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `--filter=ReadinessTest` (10 test: tamam, veritabanı, kuyruk 9/11 dk, gecikmeli/ayrılmış iş, zamanlayıcı yok/6 dk, disk %89,9/%90, fırlatan kontrol, sızıntı, kalp atışı kaydı, başarısız iş) | ✅ 10/10, 29 assertion (son hâl) |
| M1 | `DatabaseCheck` listeden çıkarıldı | 🔴 `readiness_fails_when_the_database_is_unreachable`: 503 beklendi, 200 geldi |
| M2 | Dinleyici istisna metnini loga yazar | 🔴 `a_failed_job_is_reported` |
| 2 | Tam test takımı (kalp atışı provider'dayken) | ilk koşu 🔴 2 (`MaintenanceTest`: kalp atışında `withoutOverlapping`/`sentryMonitor` yok) → düzeltildi → ✅ 1216/1216 |
| 3 | Kalp atışı `routes/console.php`'ye taşındıktan sonra `--filter=MaintenanceTest` · `--filter=ReadinessTest` | ✅ 29/29 · ✅ 10/10 |
| 3b | Tam test takımı, son hâl | ✅ 1216/1216, 4434 assertion |
| 4 | `composer check` | ❌ pint ✅ · phpstan: Larastan iç hatası `MaintenanceTest` (aşağıda) |

**B7.** Uç, kontroller, dinleyici ✅ görüldü (1, M1, M2, 2, 3). `composer check` ❌, bu daldan bağımsız neden (aşağıda).
Tam takım son hâlde ✅ 1216/1216 (3b). Sunucu/izleyici adımları koşmadı.

**Kapı kırmızı (kapsam dışı).** `Internal error: Class mixed was not found … while analysing tests/Feature/MaintenanceTest.php`.
Benim izlenen dosyalarım HEAD hâline getirildiğinde de çıkıyor: yeni `vendor` ile mevcut, `MaintenanceTest` değişmedikçe sonuç
önbelleği gizliyordu. Çöktüğü koşuda başka hatalar eksik/yanlış raporlanıyor (bir koşuda tip notları "yok" sayıldı).
19.3'teki `cast.string` (×2) de duruyor. → `davetkart-docs: phases/faz-19/HAT-C-ACIK-HATALAR.md` #1, #11.

**İsmail'e kalan.** Sentry DSN + Crons + uyarı kuralı · dış izleyici seçimi ve iki monitör · yedek kalp atışı adresleri ·
`IZLEME.md` §5 elle doğrulama · PHPStan kırmızısı (#1, #11).

---

## 19.8 — `OPS-05`: Tek hata noktaları: alan adı yenilemesi, posta ve kredi bitiş tarihleri

> **Dallar:** docs `docs/2026-11-22-ops-05-expiry-calendar` · **K:** — · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-08-OPS-05.md`

**Sorun.** Bitiş tarihleri yalnızca `YAYIN-PLANI.md` §8'deki bir tabloda duruyordu; hatırlatıcı yoktu. Alan adının
otomatik yenilemesi 4 Ekim'de KAPALI'ydı (site, API, medya, SSL ve posta aynı gün düşer); posta barındırma
11 Ocak 2027'de, AWS kredisi 25 Temmuz 2027'de bitiyor.

**Kararlar.** §A-1…§A-4 **varsayılan (İsmail cevaplamadı)**. D1: `operations/deployment/SURE-TAKVIMI.md` (tek ev):
kalem · bitiş · ne durur · sahibi · yenileme yolu · 30/7 gün hatırlatıcı · durum; en üstte "otomatik yenilemeyi aç"
(25 Kasım). Plan §8'e bağlantı notu (tablo silinmedi). D2: `davetkart-sure-takvimi.ics` (7 VEVENT + VALARM, 09:00).
D3: posta seçenekleri tablosu (Natro · Zoho · Google Workspace · SES gelen posta), K yok. D4: SSL `certbot.timer`
plan kaydından doğrulandı (Y7.2b, `renew --dry-run` ✅); dış izleme yok → ⚠️ (Y11.7 / `OPS-03`). Plan kararı gereği
SSL için sabit günlü hatırlatıcı yok. ACM (`media.`) kendini yeniler; şartı doğrulama CNAME'i.
**Plandan sapma.** (1) `operations/deployment/README.md` yok; satırlar `operations/README.md`'ye. (2) Posta "30 gün"
hatırlatıcısı 12 Aralık değil, planın ve prompt'un karar günü **11 Aralık**. (3) `.gitattributes`'a `*.ics text eol=crlf`:
`* text=auto eol=lf` commit'te CRLF'yi silerdi (RFC 5545 CRLF ister). (4) `IZLEME.md` (19.7 `OPS-03`) henüz yok.

**Değişen dosyalar (docs).** `operations/deployment/SURE-TAKVIMI.md` · `davetkart-sure-takvimi.ics` (yeni) ·
`operations/README.md` · `operations/deployment/YAYIN-PLANI.md` (§8 notu) · `.gitattributes` · `phases/faz-19/HAT-C-ACIK-HATALAR.md`.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `.ics` denetimi (CRLF, ≤75 bayt, BEGIN/END, VEVENT'te UID/DTSTAMP/DTSTART/VALARM) | ilk koşu 🔴 3 satır >75 bayt → katlandı; ✅ `geçerli: 7 VEVENT` |
| 2 | Tarih karşılaştırması (plan §8 + Y1.3 ↔ takvim ↔ `.ics` = bitiş − 30/7) | ilk koşu 🔴 posta 12 Aralık beklendi (sapma 2) → plan karar günü okunur; ✅ 3/3 |
| M1 | `.ics`'ten kredi-30 `DTSTART` silindi | 🔴 `VEVENT #4: eksik ['DTSTART']` + karşılaştırma `eksik ['20270625']`; geri alındı, ✅ |
| 3 | `git check-attr eol` `.ics` | `crlf` |

Denetim betikleri oturumun geçici klasöründeydi (depoya girmedi).

**B7.** Takvim ve `.ics` ✅ görüldü (1–3, M1). İçe aktarma (Google/Outlook) koşmadı (İsmail'de). Panel işleri koşmadı (kapsam dışı).

**İsmail'e kalan.** (1) 🔴 Natro: `davetkart.com` otomatik yenilemesi + kart. (2) Posta kararı 11 Aralık'a kadar;
karar verilince K + `SURE-TAKVIMI.md` §3. (3) Kredi sonrası aylık tahmin + kart (§4). (4) `.ics`'i takvime aktar.

---

## 19.9 — `SEC-05`: Zayıf parolalar kabul ediliyor

> **Dallar:** backend ve frontend `fix/2026-11-23-sec-05-password-policy` · docs `docs/2026-11-23-sec-05-password-policy` · **K:** K258 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-09-SEC-05.md`

**Sorun.** Kayıt, sıfırlama ve değiştirme `12345678`, `password`, `aaaaaaaa` kabul ediyordu; kural üç istekte ayrı ayrı
`min:8, max:255` olarak yazılıydı.

**Kararlar.** §A-1 **İsmail: en az 8** (bulgu ve varsayılan 12 diyordu; ASVS 2.1.1). §A-2 ve §A-3 **varsayılan (İsmail
cevaplamadı)**: depoda yaygın parola listesi (dış istek yok); mevcut hesaplar zorlanmaz. D1 (K258) tek kaynak
`App\Rules\PasswordPolicy::rules()` = `min:8`, `max:255`, `uncommon_password`; üç istek de kullanır. D2 liste
`resources/security/common-passwords.txt` (SecLists xato-net 10.000, MIT; küçük harf, tekrarsız 9.916 satır), süreç
başına bir kez belleğe; küçük harfe çevrilmiş tam eşleşme; ayrıca tek karakter tekrarı reddedilir. D3 yeni hata kodu yok:
`VALIDATION_FAILED` + alan kuralı `uncommon_password` (tr/en çeviri, `verify:errors`). D4 formlar 8'i zaten gösteriyordu;
yalnızca yorumlar güncellendi. Hash Argon2id: bcrypt 72 bayt sınırı yok (kılavuzda not).
**Plandan sapma.** (1) Kural nesnesi değil, `Validator::extend` ile dize adlı kural: kural nesnesi sınıf adıyla raporlanır,
ad sözleşmeye sızar (D6, `RegisterRequest.md`). Kayıt yeni `ValidationServiceProvider`'da (`AppServiceProvider` Hat A'nın).
(2) Liste `10k-most-common.txt` değil xato-net: bulgunun `qwerty123456`'sı öbüründe yok. (3) `aaaaaaaaaaaa` hiçbir
listede yok → tek karakter tekrarı denetimi eklendi. (4) Mutant "kuralı `min:8`'e geri al" uzunluk 8 kaldığı için
"`uncommon_password`'ı çıkar" oldu. (5) Backend `19.9.3` (kayıt dosyası) docs deposunda. (6) Değiştirme formuna "yaygın parolalar kabul edilmez"
ipucu eklendi, `verify:i18n` (gömülü Türkçe metin tabanı 5 → 6) reddetti; geri alındı, ipucu `t()` işi kapsam dışı.
(7) `PasswordChangeTest::the_same_password_is_refused` fabrika parolasını (`password`) kullanıyordu; artık ilk hata
`uncommon_password`. Test, aynı-parola amacını koruyarak yaygın olmayan bir parolaya geçirildi (kural gevşetilmedi).

**Değişen dosyalar.** Backend: `app/Rules/PasswordPolicy.php` · `app/Providers/ValidationServiceProvider.php` ·
`bootstrap/providers.php` · üç istek sınıfı · `resources/security/common-passwords.txt` + `.LICENSE` ·
`tests/Feature/PasswordPolicyTest.php` · `PasswordChangeTest.php` (1 test) · kılavuzlar (`PasswordPolicy.md`, `PasswordPolicyTest.md`, `RegisterRequest.md` notu).
Frontend: `src/locales/{tr,en}/errors.json` · `scripts/verify-error-contract.ts` · `RegisterPage.tsx`, `ResetPasswordPage.tsx`
(yorum).

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `--filter=PasswordPolicyTest` (3 uç × 5 parola, parola cümlesi regresyonu, 7/8 karakter sınırı) | ✅ 17/17, 43 assertion |
| M1 | `rules()`'tan `uncommon_password` çıkarıldı | 🔴 15 |
| M2 | `ChangePasswordRequest` eski `'min:8', 'max:255'` | 🔴 5 (yalnızca değiştirme) |
| 2 | Tam backend takımı | ilk koşu 🔴 1 (`PasswordChangeTest::the_same_password_is_refused`, sapma 7) → düzeltildi → ✅ 1242/1242, 4503 assertion |
| 3 | `composer check` | pint ✅ (ilk koşuda `PasswordPolicy.php` import düzeni, düzeltildi) · phpstan ❌ Larastan iç hatası (`TemplateCatalogueTest`, #11) |
| 4 | Frontend `npm run check` | ilk koşu 🔴 `@playwright/test` yok (eski `node_modules`, 19.22'nin bağımlılığı) → `npm ci` → 🔴 `verify:i18n` (sapma 6) → ✅ çıkış 0 (`verify:errors` 28 kod, iki dil) |

**B7.** Kural, üç uç, mutantlar, tam takım, frontend `check` ✅ görüldü (1, M1, M2, 2, 4). PHPStan yeni dosyaları
analiz **edemedi** (iç hata, bu daldan bağımsız) → `davetkart-docs: phases/faz-19/HAT-C-ACIK-HATALAR.md` #1, #11. Tarayıcıda form denenmedi.

**İsmail'e kalan.** Uzunluk kararını gizlilik/güvenlik metninde anmak isterse (`SEC-11`); mevcut zayıf parolalı hesaplar
için ayrı karar (§A-3, kapsam dışı).

---

## 19.10 — `SEC-06`: Genel hız sınırı IP anahtarlı: misafir trafiği davetiye sahibini de kilitliyor

> **Dallar:** backend `fix/2026-11-22-sec-06-rate-limit-keys` · docs `docs/2026-11-22-sec-06-rate-limit-keys` · **K:** K186 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-10-SEC-06.md`

**Sorun.** Genel `api` kovası yalnızca IP'ye bağlıydı (60/dk). Düğün salonunda yüz misafir tek dış IP'de:
61. istek 429 ve aynı Wi-Fi'daki girişli davetiye sahibi de 429 (denetim P85/P86). Misafir medya IP kovası
(15/dk) bütün salon için dardı.

**Kararlar.** K186 (aşağıda). §A boş → dört satır da **varsayılan (İsmail cevaplamadı)**: girişli 120/dk kullanıcı
anahtarlı · misafir okuma ayrı kova 300/dk IP · misafir medya IP 60/dk (davetiye 150/saat aynen) · Nginx mikro-cache yok.
- D1: `apiLimits` kimlikliyse `api|user|<id>`, değilse `api|<IpBucket>`. **Ara katman sırası doğrulandı:** Laravel'in
  `middlewarePriority` listesi `AuthenticatesRequests`'i `ThrottleRequests`'ten önce sıralar; `auth:sanctum` uçlarında
  limiter'daki `$request->user()` dolu. Eski kılavuz/yorum ("limiter auth'tan önce, user() null") yanlıştı; düzeltildi, testle kanıtlı.
- D2: `GET /public/invitations/{id}` → `withoutMiddleware('throttle:api')` + `throttle:public-read`.
- D3: `media.rate_limit.guest_per_ip_per_minute` 15 → 60.
- D4: sayılar `config/davetkart.php` → `api.rate_limit` (yeni bölüm, `cache`'ten önce).
- **Plandan sapma 1:** 19.10.3 (D3) ayrı commit değil 19.10.1'in içinde: D1/D4 ile aynı dosya (`config/davetkart.php`).
- **Plandan sapma 2:** dal `fix/2026-11-22-ops-04-env-template`'ten açıldı (İsmail'in ARC-04'teki kararı; `AppServiceProvider.php` ortak). Birleştirme sırası: ARC-04 → OPS-04 → SEC-06.
- **Plandan sapma 3:** testlerde sayılar `Config::set` ile küçültüldü (61/301 istek yerine 2–4); gerçek değerler `RateLimitTest::weddingLimits`'te sabit.

**Değişen dosyalar (backend).** `app/Providers/AppServiceProvider.php` · `config/davetkart.php` · `routes/api.php` ·
`tests/Feature/RateLimitTest.php` · `tests/Feature/ApiRateLimitTest.php` (yeni) · `docs/rehber/app/Providers/AppServiceProvider.md` ·
`docs/rehber/config/davetkart.md`.

**Testler** (`ApiRateLimitTest`, 4 + `RateLimitTest` veri setine 3 satır): `an_authenticated_user_is_not_throttled_by_guests_on_the_same_ip` ·
`two_users_behind_one_ip_have_separate_buckets` · `the_public_invitation_read_has_its_own_bucket` · `an_anonymous_client_is_still_limited_by_ip`.
**Mutantlar (2):** M1 kimlik dalı kapatıldı (kova yalnızca IP) → ilk iki test kırmızı (200 yerine 429) ·
M2 `withoutMiddleware('throttle:api')` silindi → `the_public_invitation_read_has_its_own_bucket` kırmızı. Geri konduktan sonra 4/4 yeşil.

**B7 tablosu**

| Kontrol | Sonuç (görülen çıktı) |
|---|---|
| `pint --test` | geçti |
| `phpstan analyse` | 🔴 2 hata — `ResolvesRefundRequests.php:29` (`cast.string`, 14.6'dan; ARC-04/OPS-04'te de görüldü, dokunulmadı). Bu bulgunun dosyalarında hata yok |
| `errors:export --check` · `plans:export --check` · `env:template --check` | geçti · geçti · geçti |
| `php artisan test` (tam) | geçti — 1186 test, 4379 doğrulama |
| `composer audit` | geçti — açık yok |
| Frontend / e2e | koşulmadı (frontend'e dokunulmadı) |

**Gördüm, dokunmadım.** `auth:sanctum` uçlarına geçersiz token'la gelen istek `Authenticate`'te 401 alır ve hiçbir kovaya
ulaşmaz (K186'dan önce de böyle; giriş uçları `SEC-16`).

**İsmail'e kalan elle işler.** Yok (Nginx mikro-cache §A-4 gereği yapılmadı). PHPStan kararı ARC-04 kaydında.

---

## 19.11 — `TST-02`: Kilitlerin hiçbiri testle korunmuyor

> **Dallar:** backend `fix/2026-11-22-tst-02-lock-tests` · docs `docs/2026-11-22-tst-02-lock-tests` · **K:** K187–K188 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-11-TST-02.md`

**Sorun.** Denetimde dört kilit silindi (M14 hediye kodu, M47 ödeme bildirimi, M48 LCV kotası, M49 serbest hak);
1052 testin hiçbiri kırmızı olmadı. Tek süreçli paket kilidin engellediği durumu hiç üretmiyordu.
Ayrıca Faz 18.4'ün "gördüm, dokunmadım" yarışı (donmuş hak eşzamanlı yayında bağlanabiliyor) açıktı.

**Kararlar.** K187, K188 (aşağıda). §A yok.
- D1: `tests/Support/LockAssertions` (`assertLocksRowOf`: `DB::listen` + `for update`) ve 17 `lockForUpdate()` çağrısının
  15'i için `LockInventoryTest`; `StartCheckoutAction` zaten `FirstPublishCheckoutTest`'te (18.3). Envanter tablosu
  kılavuzda (dosya · değişmez · test). Denetimde 17 deniyordu; bugün de 17 (16 dosya, `ResolveRefundRequestAction`'da 2).
- D2: `assertWaitsForRowLock`: ikinci bağlantı (`pgsql` kopyası, ad `davetkart_test(_<hat>)` değilse durur) satırı kilitler,
  Action `SET LOCAL lock_timeout = '200ms'` ile `55P03` almalı. M14, M47, M48, M49 için dört test. `RefreshDatabase` yerine
  `DatabaseTruncation` + test sonunda yeniden boşaltma (gerekçe kılavuzda). `config/database.php` ve `phpunit.xml` değişmedi.
- D3: yarış önce **kırmızı** gösterildi (alt süreç: sipariş kilidi + iade talebi + 800 ms + COMMIT; sahiplenme bekledi ve
  donmuş hakkı bağladı). Düzeltme yerel (`ClaimReleasedOrderAction`: kilitten sonra aynı koşullar yeni sorguyla
  yeniden sınanır); durum makinesine dokunulmadı → **yeşil**.
- D4: `LockRaceTest` `#[Group('concurrency')]`; `composer check` tam paketi koştuğu için grup da koşar. Süre: grup ~4,5 sn,
  `LockInventoryTest` ~3 sn. Tam paket 1186 test/~120 sn (SEC-06) → 1206 test/~126–132 sn (ölçümler arası oynama ±20 sn).
- **Plandan sapma 1:** SQL testleri mevcut Action test dosyalarına değil tek `LockInventoryTest`'e yazıldı (paralel hatların
  aynı test dosyalarında çakışmaması; envanter tek yerde).
- **Plandan sapma 2:** 19.11.3 (D2) ve 19.11.4 (D3) tek commit: yarış testi ile düzeltme aynı test dosyasını paylaşıyor.
- **Plandan sapma 3:** dal `fix/2026-11-22-sec-06-rate-limit-keys`'ten (zincir ARC-04 → OPS-04 → SEC-06 → TST-02).
- Alt süreç için ext-pgsql değil Laravel `Process` + PDO (ext-pgsql `composer.json`'da zorunlu değil; yeni bağımlılık yok).

**Değişen dosyalar (backend).** `tests/Support/LockAssertions.php` · `tests/Support/hold-row-lock.php` · `tests/Feature/LockInventoryTest.php` ·
`tests/Feature/LockRaceTest.php` · `app/Actions/Payment/ClaimReleasedOrderAction.php` · `docs/rehber/tests/Support/LockAssertions.md` ·
`docs/rehber/app/Actions/Payment/ClaimReleasedOrderAction.md`.

**Mutantlar (17/17 öldü; `php.exe` ile koşturuldu, her biri geri alındı).** Hedef: `LockInventoryTest|LockRaceTest|FirstPublishCheckoutTest` (41 test).

| Mutant (kilit silindi) | Sonuç | Öldüren test(ler) |
|---|---|---|
| M14 `ReserveGiftCodeAction` | ÖLDÜ | SQL + `…_waits_for_the_code_row_lock` |
| M47 `HandlePaymentCallbackAction` | ÖLDÜ | yalnızca SQL (bekleme testi yeşil kaldı: sonraki `UPDATE` de bekliyor) |
| M48 `EnsureRsvpQuotaAction` | ÖLDÜ | SQL + `…_waits_for_the_invitation_row_lock` |
| M49 `ClaimReleasedOrderAction` | ÖLDÜ | SQL + D3 yarışı (bekleme testi yeşil kaldı, M47 ile aynı neden) |
| Diğer 13 (e-posta, davetiye ×4, galeri ×2, görsel işi, checkout, iade ×4) | 13/13 ÖLDÜ | her biri kendi SQL testi |

✅ Ortak düzeltme (A-E8): M47 ve M49'a gerçek araya girme testi eklendi (`LockRaceTest`); iki mutant da artık SQL testinden
bağımsız olarak davranışla ölüyor. Ayrıntı: `docs/rehber/tests/Support/LockAssertions.md` "Sık hata".

İlk mutant turu **geçersizdi** (`php.exe` yolu bulunamadı, testler koşmadı); sonuçlar atılıp yeniden koşuldu.

**B7 tablosu**

| Kontrol | Sonuç (görülen çıktı) |
|---|---|
| `pint --test` | geçti |
| `phpstan analyse` | 🔴 yalnızca `ResolvesRefundRequests.php:29` (2, 14.6'dan; dokunulmadı). Bu bulgunun dosyalarında hata yok (4 tip hatası düzeltildi) |
| `errors:export --check` · `plans:export --check` · `env:template --check` | geçti · geçti · geçti |
| `php artisan test` (tam, son düzenlemeden sonra) | geçti — 1206 test, 4403 doğrulama |
| `--group concurrency` | geçti — 5 test |
| `composer audit` | geçti — açık yok |
| Frontend / e2e | koşulmadı |

**İsmail'e kalan elle işler.** Yok. PHPStan kararı ARC-04 kaydında.

---

## 19.12 — `FE-51`: Misafir fotoğraf/video yüklemesine arayüzden ulaşılamıyor

> **Dallar:** backend ve frontend `fix/2026-11-23-fe-51-guest-media-upload` · docs `docs/2026-11-23-fe-51-guest-media-upload` · **K:** K189 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-12-FE-51.md`

**Sorun.** Dosya alanı yalnızca `RsvpModal`'daydı ve onu açan `onRsvpClick`'i hiçbir şablon çağırmıyordu.
Buna karşın `POST /api/public/invitations/{id}/media` açık ve kimliksizdi; depolama, kota ve temizleme işi de çalışıyordu.
Yani risk vardı, fayda yoktu.

**Kararlar.** §A boş → varsayılan (İsmail cevaplamadı): §A-1 **hayır** → **D1** + **D3**. §A-2/§A-3 bu kolda kullanılmadı.
- Backend: `davetkart.guest_uploads.enabled` (`DAVETKART_GUEST_UPLOADS`, varsayılan `false`). `StoreGuestMediaAction` ilk satırda
  bayrağa bakar; kapalıyken **404**. LCV modülü kapalıyken de aynı 404 dönüyor (C6), böylece taramaya "uç var ama kapalı" bilgisi gitmez.
  Dürüst arayüz bayrağı D3'ten zaten öğreniyor. `media:prune-orphans` değişmedi.
- D3: `PublicInvitationResource` LCV açıkken `guestUploads: bool` yayınlar. Frontend bunu `PublicInvitationView.guestUploads`'a ayırır.
  `Invitation` modeline eklenmedi, çünkü sunucu ayarı tasarım verisi değil.
- Frontend: `RsvpModal.tsx` silindi. `useUIStore` modal durumu, `onRsvpClick` (`TemplateRenderer`, `types.ts`, simülatör, misafir sayfası),
  `useRsvpStore.attachDraftMedia` ve taslağın medya alanları, `mediaService.uploadAsGuest` kaldırıldı.
  `RsvpCreatePayload.photoMediaId/videoMediaId` sözleşmede kaldı.
- Env şablonu: `DAVETKART_GUEST_UPLOADS=false` (`env:template --check` yeşil).
- **Plandan sapma:** backend dalı `fix/2026-11-22-tst-02-lock-tests`'ten (hat zinciri), frontend dalı `faz-19-audit-fixes`'ten açıldı.
  Docs dalı SEC-10 docs commit'inden sonra açılacak (commit komutları dosyası); SEC-10 docs henüz commit'lenmemişti.
- **Plandan sapma:** "paket grafiğinde yok" kontrolü `src/` metin taramasıyla yapıldı, Vite'ın grafiğiyle değil (verify-render rehberi).
- **Bilinen gecikme:** davetiye yanıtı önbellekte en çok 6 saat kalıyor. Bayrak değişince uç hemen uyar, ama `guestUploads` alanı
  TTL dolana kadar eski kalabilir. Bayrağı değiştirdikten sonra `php artisan cache:clear` (rehber).
  ✅ Ortak düzeltmede kapandı (A-E3): alan önbellekten sonra her istekte config'ten yazılıyor; `cache:clear` gerekmez.

**Testler / mutant.** Backend: `MediaTest::guest_upload_is_refused_when_disabled` + `guest_upload_works_when_enabled`,
`PublicInvitationTest::the_public_invitation_reports_the_guest_upload_flag` (kapalı/açık). Mevcut misafir yükleme testleri bayrağı açar.
Mutant 1: bayrak kontrolü devre dışı → `refused` kırmızı (öldü). Frontend `verify:render` `guestUploadsAreOff`.
Mutant 2: `RSVPForm`'a `<input type="file" />` → 2 kontrol kırmızı (öldü).

**B7 tablosu**

| Kontrol | Sonuç |
|---|---|
| Hedefli backend testleri (6 dosya) | 229/229 ✓ |
| Backend `composer check` | pint ✓ · **PHPStan ✗ 2 hata, yalnızca `ResolvesRefundRequests.php:29` (önceden var)** · errors/plans/env ✓ · test 1210/1210 ✓ (PHPStan'dan sonraki adımlar elle koşuldu) |
| Frontend `npm run check` | çıkış 0 (lint, build, verify:* + render) |
| Mutantlar | 2/2 öldü |

**İsmail'e kalan elle işler.** (1) Üretimde bayrak kapalı kalacaksa yapılacak bir şey yok (varsayılan). (2) Özellik istenirse §A-1'i
"evet" yapıp 19.12'yi D2 ile yeniden çalıştır. (3) Bayrak değişirse `php artisan cache:clear`.

---

## 19.13 — `SEC-08`: Kimliksiz misafir davetiye başına 5 GB yükleyebilir; yetim dosya kotayı 24 saat tutar

> **Dallar:** backend `fix/2026-11-23-sec-08-guest-upload-budget` · docs `docs/2026-11-23-sec-08-guest-upload-budget` · **K:** K190 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-13-SEC-08.md`

**Sorun.** Misafir kotası yalnızca adet sayıyordu (≈ 5 GB) ve iliştirilmemiş (yetim) dosyaları da sayıyordu.
Yetimler 24 saat yaşadığı için kimliksiz biri kotayı yetimlerle doldurup gerçek misafirleri bir gün engelleyebiliyordu.

**Kararlar.** §A boş → varsayılan (İsmail cevaplamadı):
1 GB tek bütçe (plan bazlı değil) · bekleme 1 saat · video 20 MB kalır · bekleyen kota 50 dosya / 200 MB.
- **D1:** `media.guest_budget.attached_bytes_per_invitation` = 1 GB. Sayılan: LCV'ye **iliştirilmiş** misafir baytları + gelen dosya.
  `size_bytes` kolonu zaten vardı, göç gerekmedi (19.13.1 atlandı). Kesin kontrol davetiye satırı `lockForUpdate` ile kilitliyken.
  Boyut diskten bir kez okunur; kontrolde ve satırda aynı değer kullanılır.
- **D2:** Tür başına adet sınırı (foto 200, video 100) artık yalnızca iliştirilmişleri sayıyor. İliştirilmemişler ayrı kotaya giriyor:
  `pending_files_per_invitation` 50, `pending_bytes_per_invitation` 200 MB.
  `orphan_grace_hours` 24 → 1. `media:prune-orphans` günlük 03:15 → **saatlik :15**.
  "İliştirilmiş/bekleyen" tanımı `Media::scopeAttachedToRsvp`/`scopeAwaitingRsvp`'ta tek yerde; temizlik işi de bunu kullanıyor.
- **D3:** seçilmedi (tek değer) → `contracts/plan-rules.json` değişmedi, `plans:export --check` yeşil.
- **D4:** mevcut `MEDIA_QUOTA_EXCEEDED` (403, `forGuest()`, params boş, H9). Yeni kod yok.
- **Bilinen sınır:** bekleyen bir dosya sonradan iliştirilirse bütçe en çok 200 MB aşılabilir.
  Aşımın üst sınırı var ve kasıtlı: yetimleri bütçeye katmak DoS'u geri getirirdi.
- **Plandan sapma:** 19.13.1 (göç) gerekmedi. Prompt'un "kilit testi" maddesi, toplamların kilitten sonra okunduğunu SQL sırasıyla
  doğrulayan bir test olarak yazıldı (`LockInventoryTest`).

**Testler / mutant.** `MediaTest::orphaned_guest_uploads_do_not_consume_the_attached_media_quota` · `guest_uploads_stop_at_the_byte_budget` ·
`the_pending_upload_quota_is_enforced` · `LockInventoryTest::the_guest_byte_budget_is_read_under_the_invitation_lock`.
Testler 19.12 bayrağı açıkken koşuyor (`MediaTest::setUp`). `MaintenanceTest` cron beklentisi `15 * * * *`.
Mutant 1: adet sorgusuna yetimleri geri katmak → `orphaned…` kırmızı (öldü).
Mutant 2: bekleyen adet kontrolünü kaldırmak → `the_pending…` kırmızı (öldü).

**B7 tablosu**

| Kontrol | Sonuç |
|---|---|
| Hedefli testler (6 dosya) | 205/205 ✓ |
| `composer check` | pint ✓ · **PHPStan ✗ 3 hata:** `ResolvesRefundRequests.php:29` (2, önceden var) + `ReadinessTest.php:175` (1, Hat C 19.7.1'den entegrasyona geldi; bu dal dokunmadı) · errors/plans/env ✓ · test 1229/1229 ✓ (PHPStan'dan sonraki adımlar elle) |
| Mutantlar | 2/2 öldü |

**İsmail'e kalan elle işler.** (1) Sunucuda zamanlayıcı (`schedule:run`) her dakika koşuyorsa yapılacak bir şey yok. Doğrulama:
`php artisan schedule:list` → `media:prune-orphans` `15 * * * *`. (2) Plan bazlı bütçe istenirse §A-1'e yaz, 19.13'ü D3 ile yeniden çalıştır.

---

## 19.14 — `SEC-09`: E-posta doğrulaması yok

> **Dallar:** backend ve frontend `fix/2026-11-23-sec-09-email-verification` · docs `docs/2026-11-23-sec-09-email-verification` · **K:** K191 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-14-SEC-09.md`

**Sorun.** Kayıt herhangi bir adresle anında tam yetkili oturum açıyordu. Sonuçları: ilk yayın indirimi (hesap başına)
her davetiyede yeni bir uydurma hesapla tekrar alınabiliyordu, postalar doğrulanmamış adreslere gidiyordu ve başkasının
adresiyle hesap açılabiliyordu.

**Kararlar.** §A boş → varsayılan (İsmail cevaplamadı): giriş/taslak/önizleme serbest, checkout ve yayın `403 EMAIL_NOT_VERIFIED`;
indirim yalnızca doğrulanmış hesaba; bağlantı 24 saat, yeniden gönderme saatte 3; mevcut hesaplara göç yok (kılavuzda `tinker`).
✅ Ortak düzeltme (A-E1, **K260**, İsmail'in kararı): "göç yok" geçersiz; `2026_11_25_100000_mark_existing_accounts_as_verified`
eski hesaplara `email_verified_at = created_at` yazar.
- **D1:** kayıtta `VerifyEmailNotification` (kuyruk) commit'ten sonra gönderilir. Bağlantı `EmailVerificationLink`:
  `EmailChangeLink` deseni (imzalı, 24 saat, `user` + adresin HMAC parmak izi), `/eposta-dogrula` → `POST /api/public/email-verification/confirm`.
  Tek kullanım: doğrulanmış hesapta reddedilir. Adrese bağlı: adres değişince reddedilir.
  Bozuk/süresi dolmuş/kullanılmış bağlantı tek kod: `422 EMAIL_VERIFICATION_INVALID` (H6).
- **D2:** `VerifiedEmailGuard` (tek yer), `StartCheckoutAction` ve `PublishInvitationAction`'ın ilk satırı → `403 EMAIL_NOT_VERIFIED`. Sözleşme ve tr/en çeviri eklendi.
- **D3:** `FirstPublishEligibility::isEligible()` doğrulanmamışa `false`. Önizleme ve checkout aynı servisi okur (K163).
- **D4:** `POST /api/auth/email-verification` (kimlikli, `throttle:email-verification` kullanıcı başına saatte 3; doğrulanmışsa posta gitmez, 204).
  E-posta değişikliği zaten `email_verified_at = now()` yazıyor (yeni adres o bağlantıyla kanıtlanmış); değiştirilmedi.
- **D5:** `UserResource.emailVerified`. Frontend: `AppLayout`'ta `EmailVerificationBanner` (yeniden gönder), `/eposta-dogrula` sayfası,
  `accountService.resendEmailVerification/confirmEmailVerification`, 403 mesajı `errors.json` üzerinden (checkout `toDisplayError` kullanıyor).
- **E2E:** Hat B'nin `TST-04`'ü birleşmişti. `e2e/support/flows.ts` → `verifyEmailIfRequired` kancası dolduruldu
  (log posta sürücüsünden bağlantıyı okuyup açar). **Koşulmadı:** E2E Hat B'nin portunu (8020) ve `davetkart_e2e` veritabanını kullanıyor.
- **Plandan sapma:** "rastgele token, veritabanında karması" yerine `EmailChangeLink`'in gerçek deseni (imzalı bağlantı, hiçbir sır saklanmaz) kullanıldı.
  Göç yok, 19.14.1 atlandı. `the_token_is_stored_hashed` testi `the_link_stores_no_secret_and_carries_no_raw_address` oldu.
- **Plandan sapma:** D1 ve D4 tek backend commit'inde (`routes/api.php` ikisini birlikte taşıyor); D2 ve D3 tek commit'te.
- **Plandan sapma:** frontend worktree'sinde `npm ci` koşuldu; TST-04'ün eklediği `@playwright/test` kurulu değildi, lint düşüyordu.
  `package.json` ve `package-lock.json` değişmedi.

**Testler / mutant.** `EmailVerificationTest` (9): kayıt postası, `a_verification_link_works_once_and_expires`,
`the_link_stores_no_secret_and_carries_no_raw_address`, adres değişmiş/kurcalanmış bağlantı, `resending_is_rate_limited`,
doğrulanmışa posta yok, `an_unverified_account_cannot_start_a_checkout`, `an_unverified_account_cannot_publish`,
`an_unverified_account_gets_no_first_publish_discount` (önizleme + servis). Ayrıca `InvitationTest::an_unverified_account_can_still_create_a_draft`;
`ProfileUpdateTest` beklenen JSON'a `emailVerified` eklendi.
Mutant 1: `StartCheckoutAction`'dan kapı kaldırıldı → checkout testi kırmızı (öldü).
Mutant 2: `FirstPublishEligibility`'den doğrulama kontrolü kaldırıldı → indirim testi kırmızı (öldü).

**B7 tablosu**

| Kontrol | Sonuç |
|---|---|
| Backend `composer check` | pint ✓ · **PHPStan ✗ 3:** `ResolvesRefundRequests.php:29` (2) + `ReadinessTest.php:175` (1); ikisi de önceden var · errors/plans/env ✓ · test 1256/1256 ✓ (PHPStan'dan sonrası elle) |
| Frontend `npm run check` | çıkış 0 (`verify:errors` yeni kodlar, `verify:endpoints` iki yeni uç ✓) |
| `npm run e2e` | koşulmadı (Hat B'nin kaynakları) |
| Mutantlar | 2/2 öldü |

**İsmail'e kalan elle işler.** (1) Yerel deneme hesaplarını doğrula: `docs/rehber/app/Support/VerifiedEmailGuard.md`'deki `tinker` komutu.
(2) `npm run e2e`'yi Hat B ortamında koş; yolculuk 1 doğrulama adımını log'dan geçmeli. (3) Canlıda SES gönderimi açık olmalı (OPS-04, K185).

---

## 19.15 — `SEC-10`: Shopier'e uydurma TCKN ve telefon gönderiliyor

> **Dallar:** docs `docs/2026-11-22-sec-10-shopier-buyer-fields` · **K:** — · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-15-SEC-10.md`

**Sorun.** Ödeme formunda her alıcı için aynı yer tutucular gidiyor (`config/payment.php` → `buyer_placeholders`:
`buyer_id_nr=11111111110`, `buyer_phone=05000000000`, sabit adres; `ShopierGateway::buyerFields()`). Shopier'in bunu
kabul edip etmediği yazılı olarak bilinmiyor (K125 ⚠️).

**Kararlar.** §A-1 (Shopier'in yazılı cevabı) **boş** → prompt'un **D4** kolu: kod değişmedi, backend dalı açılmadı, yeni K yok.
§A-2 ve §A-3 bu kolda kullanılmadı.
- Shopier'e gönderilecek soru metni: `notes/04-SHOPIER-ALICI-ALANLARI-SORUSU.md` (+ `notes/README.md` satırı).
- **Plandan sapma:** "değişecek dosyaların listesi `FAZ-19.md`'ye" yerine aşağıya yazıldı (`FAZ-19.md` paylaşılan dosya;
  `BAGLAM.md` §2 gereği 19.90 bu kayıttan üretir).
- **Plandan sapma:** docs dalı `docs/2026-11-22-tst-02-lock-tests`'ten açıldı (hattın zinciri).

**Cevap gelince değişecek dosyalar (backend)**

| Dosya | D1 (zorunlu değil) | D2 (telefon zorunlu) | D3 (her kol) |
|---|---|---|---|
| `config/payment.php` → `buyer_placeholders` | kalkar | `id_nr`/adres cevaba göre | yer tutucular kalkar |
| `app/Services/Payment/ShopierGateway.php` → `buyerFields()` | alanlar silinir ya da boş | telefon siparişten | üretimde yer tutucuyla form üretmez (fail-closed, SEC-03 deseni) |
| Checkout isteği (`StartCheckoutAction` + FormRequest) | — | `phone` alanı, TR mobil biçimi | — |
| `orders` göçü (telefon yalnızca siparişte, KVKK en az veri) | — | yeni kolon | — |
| `tests/Feature/ShopierGatewayTest.php` | `the_shopier_form_carries_no_placeholder_identity` | + `checkout_requires_a_valid_phone_when_shopier_needs_it` | `production_refuses_placeholder_buyer_fields` |
| `docs/rehber/app/Services/Payment/ShopierGateway.md` · `docs/rehber/config/payment.md` · `docs/10-URETIM-ENV-SABLONU.md` | güncellenir | güncellenir | güncellenir |
| Frontend ödeme sayfası + tr/en çeviri | — | telefon alanı | — |

İmza (`ShopierSignature::forPayment`) bu alanları kapsamıyor; hiçbir kolda imza değişmez.

**Testler / mutant.** Kod değişmediği için yok. `composer check` koşulmadı (değişen kod yok; hat zincirinin son kapısı TST-02 kaydında).

**B7 tablosu**

| Kontrol | Sonuç |
|---|---|
| Backend / frontend kapıları | koşulmadı (yalnızca docs değişti) |
| Shopier'e istek | yapılmadı (yasak) |

**İsmail'e kalan elle işler.** (1) `notes/04-SHOPIER-ALICI-ALANLARI-SORUSU.md`'deki metni Shopier desteğine gönder.
(2) Cevabı (tarih, kanal, metin) `prompts/faz-19-agent/19-15-SEC-10.md` §A-1'e yaz; §A-2/§A-3'ü doldur; 19.15'i yeniden çalıştır.
(3) Cevap TCKN'yi zorunlu tutarsa: KVKK ve `SEC-11` için ayrı karar.

---

## 19.16 — `SEC-13`: `data:purge` tarihi olmayan davetiyelerin misafir verisini hiç silmiyor

> **Dallar:** backend `fix/2026-11-23-sec-13-purge-undated` · docs `docs/2026-11-23-sec-13-purge-undated` · **K:** K257 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-16-SEC-13.md`

**Sorun.** `purgeGuestData()` yalnızca `event_at < şimdi − 6 ay` arıyordu. Etkinlik tarihi isteğe bağlı; `NULL < tarih`
SQL'de hiçbir zaman doğru olmadığı için tarihsiz davetiyenin LCV'leri ve misafir dosyaları süresiz kalıyordu (K98 sözü tutulmuyordu).

**Kararlar.** §A-1, §A-2 **varsayılan (İsmail cevaplamadı)**: yedek kural `published_at` + 12 ay, yayınlanmamışsa
`created_at` + 12 ay; silmeden önce sahibe bildirim yok. D1 (K257) `COALESCE(event_at + 6 ay, published_at + 12 ay,
created_at + 12 ay) < şimdi`, kodda iki açık dal (`undatedPastFallback()`); 12 ay komutta sabit (`config/davetkart.php` Hat A'nın).
D2 komut tarihsiz davetiye sayısını ayrı satırda yazar (`--dry-run` ve gerçek koşu). D3 K98 genişletildi, daraltılmadı.
**Plandan sapma.** (1) Prompt'taki backend `19.16.2` (kayıt dosyası) docs deposunda; backend'de tek commit (19.16.1).
(2) Beşinci test eklendi: D2'nin `--dry-run` satırı.

**Değişen dosyalar.** Backend: `app/Console/Commands/PurgeExpiredData.php` · `tests/Feature/MaintenanceTest.php` (+5 test, 1 yorum) ·
`docs/rehber/app/Console/Commands/PurgeExpiredData.md` (§2 tablo, §3.2). Docs: bu kayıt + commit komutları.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `--filter=MaintenanceTest` (yeni: yedek süre dolmuş/dolmamış, `created_at` dalı, K98 regresyonu, `--dry-run` satırı) | ✅ 34/34, 113 assertion |
| M1 | Yedek dal (`orWhere(undatedPastFallback)`) kaldırıldı | 🔴 2: `guest_data_of_an_undated_invitation_is_purged_after_the_fallback_period`, `an_unpublished_undated_invitation_uses_created_at` |
| M2 | Yedek dalda `whereNull('event_at')` kaldırıldı | 🔴 `dated_invitations_still_follow_k98` |
| 2 | Geri alındıktan sonra `--filter=MaintenanceTest` | ✅ 34/34 |
| 3 | `composer check` | ❌ pint ilk koşuda `phpdoc_separation` → düzeltildi ✅ · phpstan: Larastan iç hatası (`TemplateCatalogueTest`; tek dosya koşusunda `PurgeExpiredData.php`'nin HEAD hâlinde de) |
| 4 | Tam test takımı, son hâl | ✅ 1221/1221, 4451 assertion |

**B7.** Komut, testler, mutantlar ✅ görüldü (1, M1, M2, 2, 4). PHPStan bu dosyayı analiz **edemedi** (iç hata,
bu daldan bağımsız) → `davetkart-docs: phases/faz-19/HAT-C-ACIK-HATALAR.md` #1, #11. Yayında komut koşmadı.

**İsmail'e kalan.** Yayında ilk koşudan önce `php artisan data:purge --dry-run` (K84): tarihsiz satır ilk gece birikmiş
veriyi bir kerede siler. Gizlilik metninde 12 aylık yedek süre (`SEC-11`, kapsam dışı).

---

## 19.17 — `SEC-12`: Her sayfa Google Fonts'u Google'dan yüklüyor

> **Dallar:** backend ve frontend `fix/2026-11-23-sec-12-self-hosted-fonts` · docs `docs/2026-11-23-sec-12-self-hosted-fonts` · **K:** K226 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-17-SEC-12.md`

**Sorun.** `index.css`'in ilk satırı ve beş şablon yardımcısı (`harita`/`kitap`/`sergi`/`paper` `fontlar.ts`, `sinema/useTemplateFont`, 21 şablon) Google Fonts'tan CSS + dosya çekiyordu: ziyaretçi IP'si Google'a, CSP iki dış alan adına açık.

**§A.** İsmail yanıtlamadı; varsayılanlar: `public/fonts/` + `@font-face`, yeni npm bağımlılığı yok (fontsource paketleri `npm pack` ile yalnızca dosya kaynağı olarak kullanıldı); yalnızca `latin` + `latin-ext`.

**Karar K226 (D1–D4).**
- **D1:** 34 ailenin envanteri (3 site geneli + 31 şablon). 95 woff2 + 34 `LICENSE` (33 OFL-1.1, Special Elite Apache-2.0: ikisi de kendi sunucunda barındırmaya izin verir) `public/fonts/<id>/`. Değişken ailelerde tek dosya (`opsz` gereken 4 ailede `opsz` ekseni). `font-display: swap`.
- **Plandan sapma (D1 "tek CSS"):** iki dosya: `src/styles/fonts.css` (site geneli 3 aile, ana CSS'e gömülü) + `public/fonts/templates.css` (31 şablon ailesi, 36 KB, yalnızca şablon açılınca). Eski tasarımın "şablon aileleri ana sayfayı yüklemesin" kuralı korunsun diye.
- **D2:** `src/utils/templateFonts.ts` → `loadTemplateFonts()` (tek `<link>`, `load`/`error`'da çözülür). Beş yardımcı Google `<link>`'i yerine bunu çağırır; `useKagitFontlari` parametresiz. `useTemplateFont(family)` imzası kaldı: sorgu artık belge, `verify:fonts` denetler.
- **D3:** `verify:fonts` (`npm run verify` zincirinde): `dist/` + `src/` içinde Google adresi yok, her `url()` var, her klasörde `LICENSE`, kodun istediği 33 ailenin hepsi tanımlı.
- **D4:** CSP (Report-Only) `style-src`/`font-src`'den Google çıktı. `location ^~ /fonts/` 30 gün (adlar hash'siz, `immutable` değil), `= /fonts/templates.css` `no-cache`; ikisi de güvenlik başlıkları snippet'ini `include` eder (K173). Duman testi: CSP'de Google varsa hata + iki yeni yol.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `npm run verify:fonts` | ✅ 7/7 (istenen 33 aile, 34 tanımlı) |
| M1 | `index.css`'e Google `@import` geri + build + `verify:fonts` | 🔴 2 hata: `dist/assets/index-*.css`, `src/index.css`; geri alındı (sha1 `b3c14497…` eşit) |
| 2 | Tarayıcı (Playwright, `vite preview` :4181): ana sayfa + `templates.css` ile Newsreader, Pinyon Script, Libre Caslon Display, Bodoni Moda, Fredoka | ✅ Google'a **0** istek; Inter/Playfair `check` true; her yüz 2 dosya (latin + latin-ext) yerelden · ekran: `D:\Projects\davetkart-hat-b\.playwright-mcp\sec12-home.png` |
| 3 | `bash -n deploy/smoke/check-security-headers.sh` | ✅ sözdizimi |
| 4 | `npm run check` | ✅ exit 0 |

**B7.**

| İddia | Durum |
|---|---|
| Build ve kaynakta Google Fonts yok | ✅ görüldü (verify + mutant) |
| Tarayıcı Google'a istek atmıyor, fontlar yerelden | ✅ görüldü (ana sayfa + 5 şablon ailesi yüklemesi; beş şablon davetiyesi tek tek açılmadı) |
| Yerel Nginx: daraltılmış CSP ile konsolda font ihlali yok, `/fonts/` başlıkları | ⏳ koşmadı (Herd nginx var; bütçe) |
| `composer check` | ⏳ koşmadı: PHP değişmedi; dalda phpstan `ResolvesRefundRequests.php:29` önceden kırmızı (TST-04 kaydı) |

**Gördüm, dokunmadım.** Font denetimi için `index.md`, `oniki-etkilesimli-sablon.md`, `premium-videolu.md`, `tasarim/*-KONSEPTI.md` kılavuzları Google sorgusunu anlatmaya devam ediyor (tarihçe; yalnızca dört `fontlar.md`'ye not düşüldü).

**İsmail'e kalan.** Herd Nginx ile duman testi: `bash deploy/smoke/check-security-headers.sh -k https://davetkart.test`; tarayıcıda bir sinema şablonu açıp ağ sekmesinde `/fonts/` dışında font isteği olmadığını görmek.

---

## 19.18 — `SEC-11`: Gizlilik metni "hemen silinir" diyor; yedekler tutuyor; üçüncü taraflar adıyla yok

> **Dallar:** frontend `fix/2026-11-24-sec-11-privacy-text` · docs `docs/2026-11-24-sec-11-privacy-text` · **K:** K231 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-18-SEC-11.md`

**Sorun.** Gizlilik metni hesap silinince verinin "derhal" silindiğini söylüyordu; doğru olan yalnızca canlı sistem.
Gece dökümü (S3, 30 + 7 gün), Lightsail disk görüntüsü (7 gün) ve medya bucket'ının eski sürümleri (7 gün) silinen
veriyi bir süre daha taşıyor. Veri işleyenler adıyla yoktu. Ayrıca tarihsiz davetiye cümlesi K257'den (19.16) geride kalmıştı.

**Önkoşullar.** SEC-09 ve SEC-13 entegrasyonda. SEC-10 yalnızca D4 (hazırlık, kod değişmedi) olarak birleşmiş:
metin Shopier'e **bugün** giden alanları yazar (ad, soyad, e-posta, tutar, hesap yaşı); TCKN/telefon/adres yer tutucu, kişisel veri değil.

**§A.** İsmail yanıtlamadı; varsayılanlar: (1) veri sorumlusu metni aynen; (2) AWS (Frankfurt), SES, Shopier, Natro;
Sentry ve Gemini koşullu; (3) "yedeklerden en geç 40 gün".

**Karar K231.**
- **D1:** K97 cümlesi "canlı sistemden derhal silinir; yedeklerdeki kopyaları … süre içinde". Yeni "Yedekler" maddesi:
  döküm, disk görüntüsü, eski medya sürümü; "en geç `BACKUP_PURGE_DAYS` gün içinde kendiliğinden". Tarihsiz davetiye: K257 cümlesi.
- **D2:** yeni bölüm `veri-isleyenler` (ad · amaç · yer · veri): AWS (Lightsail, S3; Frankfurt), Amazon CloudFront
  (dünya geneli önbellek; §A-2'de ayrı yoktu, medya Y3.2 ile onun üstünden sunuluyor), Amazon SES (Frankfurt), Shopier
  (Türkiye), Natro (DNS + gelen posta), Sentry (etkinleştirildiğinde; `send_default_pii=false`), Google Gemini (asistan
  etkinleştirildiğinde; yalnızca mesaj). Google Fonts yok: SEC-12 (K226) ile fontlar kendi sunucumuzda; bunu not satırı söyler.
- **D3:** `src/legal/retention.ts` → `BACKUP_PURGE_DAYS = 40` (K152 ~37 + S3 günlük gecikme + pay). Metin sabiti kullanır;
  `verify-render` metni sabitle karşılaştırır, işleyen adlarını kendi listesinden arar.
- **D4:** yasal metin yalnızca tr (en sürümü yok). Hukuki çerçeve (KVKK m. 5/8/9/11, alıcı grupları) aynen; yalnızca teknik gerçek eklendi.
- **Plandan sapma:** `verify:i18n` tabanında `PrivacyPage` 76 → 88. Yasal metin bilerek tr; `t()`'ye taşımak kapsam dışı.
  `--update` aynı zamanda bayat `total` alanını (797) dosya toplamına (781) düzeltti; başka dosya değişmedi.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `npm run verify:render` | ✅ K97 · K231 (40 gün, sabitle aynı) · K257 · 7 sağlayıcı |
| M1 | metinde `${BACKUP_PURGE_DAYS}` → elle "45" | 🔴 `yedeklerden en geç 40 gün, sabitle aynı`; geri alındı (sha1 `094662cbaf81` aynı) |
| M2 | Shopier maddesi silindi | 🔴 `eksik: Shopier`; geri alındı |
| 2 | `npm run check` | ilk koşu 🔴 `verify:i18n` (PrivacyPage 76 → 88) → taban güncellendi → ✅ exit 0 |
| 3 | Mobil (390 px, `vite preview`, `/privacy`) | ✅ iki ekran görüntüsü; yatay taşma yok |

**B7.**

| İddia | Durum |
|---|---|
| Metin sabitle ve K257 ile aynı | ✅ görüldü (`verify-render` + mutantlar) |
| Yedek bucket'ı ömür kuralı gerçekten 30 + 7 gün | ⏳ AWS paneli doğrulanmadı (K152 ⚠️ bucket henüz açılmadı) |
| Lightsail görüntüsü 7 gün, medya eski sürümü 7 gün | ⏳ yayın planı kaydından; panelde bakılmadı |
| Natro posta verisinin Türkiye'de tutulduğu | ⏳ şirket Türkiye'de; sunucu yeri doğrulanmadı |
| Metnin hukuki uygunluğu | ⏳ kapsam dışı; hukukçuya okutulmalı |

**Gördüm, dokunmadım.** `CookiesPage` yerel depolama listesinde LCV düzenleme kodu (`davetkart_rsvp_receipt:*`) yok (kılavuzda
zaten açık). Bir yedek geri yüklenirse silinmiş veri canlıya geri dönebilir; geri yükleme kitabında "silme günlüğünü yeniden uygula"
adımı yok. 10 yıl sonra `orders` silen iş yok (kılavuzda açık).

**İsmail'e kalan.** AWS'de yedek bucket'ı açılınca ömür kuralını (30 + 7) ve Lightsail görüntü saklamasını (7) panelde doğrula;
farklıysa `BACKUP_PURGE_DAYS`'i güncelle. Metni yayından önce bir hukukçuya okut. Sentry/Gemini açılırsa bölgelerini metne işle.

---

## 19.19 — `FE-52`: Fiyat sayfası SSS'si backend'de olmayan "yayın süresi" ve ücretli uzatmayı anlatıyor

> **Dallar:** frontend `fix/2026-11-24-fe-52-pricing-faq` · docs `docs/2026-11-24-fe-52-pricing-faq` · **K:** K232 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-19-FE-52.md`

**Sorun.** `PricingPage` SSS'si "paketinizin yayın süresi boyunca", "süre sonunda erişime kapanır", "küçük bir ücretle
uzatabilirsiniz" diyordu. Backend'de yayın süresi, kapanma tarihi ya da uzatma siparişi yok; davetiyeyi yayından
kaldıran tek yol iade (`HandlePaymentCallbackAction`). Tek zaman kuralı misafir verisinin silinmesi (K98, `data:purge`).

**Önkoşul.** 19.18 `SEC-11` entegrasyonda (frontend `b62a22e`, docs `2193e2a`).

**§A.** İsmail yanıtlamadı; varsayılan: yayın süresi **yok** → D1.

**Karar K232.**
- **D1:** ilk cevap "Bir kez ödersiniz; davetiyeniz siz silene kadar yayında kalır". Üçüncü soru "Davetiyem ne kadar süre
  yayında kalır?": süre sınırı ve ek ücret yok; misafir yanıtları ve misafir dosyaları etkinlikten
  `GUEST_DATA_MONTHS_AFTER_EVENT` ay sonra silinir. "Ücretli uzatma" kalktı, yerine "yakında" da yazılmadı.
- **Sabit:** `src/legal/retention.ts` (19.18'in dosyası) → `GUEST_DATA_MONTHS_AFTER_EVENT = 6`. Gizlilik metninin 6 ay
  cümlesi de bu sabiti kullanır (iki yerde sayı yazılmaz).
- **D3:** `STATIC_FAQS` dışa aktarıldı; `verify-render` `pricingFaq()`: hiçbir SSS'de `/yayın süre|uzat/i` yok ve
  6 ay cümlesi sabitle aynı. Gizlilik metnindeki 6 ay denetimi de sabite bağlandı.
- SSS yalnızca tr (çeviri anahtarı yok); en dosyasına dokunulmadı. İade cevabı (K166) değişmedi.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `npm run verify:render` | ✅ K232 vaat yok · 6 ay sabitle aynı (SSS + gizlilik) |
| M1 | ilk cevaba "Dilerseniz küçük bir ücretle yayın sürenizi uzatabilirsiniz." geri kondu | 🔴 `geçen soru: Ödeme tek seferlik mi, abonelik mi?`; geri alındı (sha1 `3fd6e61de305` aynı) |
| 2 | `npm run check` | ✅ exit 0 (`verify:i18n` tabanı değişmedi) |

**B7.**

| İddia | Durum |
|---|---|
| SSS yayın süresi/uzatma vaat etmiyor | ✅ görüldü (`verify-render` + mutant) |
| Backend'de yayın süresi yok | ✅ bulgu kanıtı + `routes/api.php`'de yalnızca `publish`; yayından kaldırma yalnızca iade yolunda (salt okuma) |
| Sayfanın tarayıcıda görünümü | ⏳ bakılmadı (yalnızca metin değişti) |

**Gördüm, dokunmadım.** Tarihsiz davetiyede misafir verisi 12 ay kuralı (K257) SSS'de yok; gizlilik metninde var.
Diğer pazarlama metinleri kapsam dışı.

**İsmail'e kalan.** Yayın süresi bir gün ürüne girerse önce backend kuralı ve yeni K, sonra `pricingFaq()` denetimi, en son metin.

---

## 19.20 — `FE-01`: Dil seçici on dil sunuyor, arayüzün büyük kısmı yalnızca Türkçe

> **Dallar:** frontend `fix/2026-11-20-fe-01-language-scope` · docs `docs/2026-11-20-fe-01-language-scope` · **K:** K220–K221 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-20-FE-01.md`

**Sorun.** Seçici on dil sunuyordu, çevrilmiş olan yalnızca 72 anahtar (üst menü, ana sayfa). Almanca seçen ödeme adımında Türkçe görüyor, Arapça sayfayı RTL çevirip metni Türkçe bırakıyordu. Çekirdek `.tsx`'lerde ~800 gömülü Türkçe metin parçası vardı ve büyümesini durduran bir denetim yoktu.

**Kararlar.** §A boş: iki satır da **varsayılan (İsmail cevaplamadı)**.
- **K220 (D1):** `SUPPORTED_LANGUAGES` = `tr`, `en`; seçici, `supportedLngs`, `isSupportedLanguage`, `<html dir>` ondan okur. Sekiz dilin dosyaları silinmedi, içe aktarılmıyor. Özel `storedPreference` algılayıcısı kayıtlı desteklenmeyen kodu (`de`) **tarayıcı diline değil** `tr`'ye çözer; önbellek kaydı `tr` ile ezer. `fallbackLng` `en` → `DEFAULT_LANGUAGE = 'tr'` (eski kayıt, desteklenmeyen tarayıcı ve eksik anahtar aynı yere düşsün; D1'in doğal uzantısı).
- **K221 (D2, D3):** `verify:i18n` mandalı: `src/**/*.tsx` (şablonlar hariç) Türkçe harfli JSX metni + dizgi sabiti, dosya dosya `scripts/i18n-baseline.json` ile **tam eşit** olmalı (artış ve azalış kırmızı; azalışta `npm run verify:i18n -- --update`). Taban: 797 parça, 66 dosya. Ölçü denetimin `fe-d1-metrics.mjs` §2b ifadesi.
- **Plandan sapma:** D2 "artarsa kırmızı" diyor; azalış da kırmızı yapıldı, yoksa açılan boşluk başka metinle sessizce dolar. Ayrıca toplam yerine dosya başına taban.

**Değişen dosyalar (frontend).** 19.20.1: `src/i18n.ts` · `src/components/LanguageSwitcher.tsx` · `scripts/render-env.ts` (`window.localStorage`) · `scripts/verify-render.tsx` (`languageScope`) · `docs/rehber/src/i18n.md` (yeni) · `docs/rehber/scripts/verify-render.md`. 19.20.2: `scripts/verify-i18n.ts` · `scripts/i18n-baseline.json` · `package.json` · `docs/rehber/scripts/verify-i18n.md`.

**Testler + mutantlar.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `npm run verify:render` → *Dil kapsamı* (7 kontrol) | ✅ 7/7 |
| 2 | `npm run verify:i18n` | ✅ 797 parça, 66 dosya |
| M1 | `PricingPage.tsx:187` `suffix={t('pricing.oneTime')}` → `suffix="Tek ödeme"` | 🔴 `15 → 16`, exit 1 |
| M2 | `storedPreference` süzmeden `return stored;` | 🔴 2 hata: dil `en`, kayıt `en-US` |
| 3 | `npm run check` (lint + build + verify zinciri) | ✅ exit 0 |

Mutantlar sonrası dosyalar yedekten geri kondu (sha1 eşit).

**B7.**

| İddia | Durum |
|---|---|
| Seçici yalnızca tr/en çiziyor | ✅ görüldü (verify:render) |
| Kayıtlı `de` → sayfa `tr`, kayıt `tr` | ✅ görüldü (verify:render) |
| Gömülü metin artışı kırmızı | ✅ görüldü (M1) |
| `npm run check` yeşil | ✅ görüldü |
| Tarayıcıda elle deneme | ⏳ yapılmadı (İsmail) |
| `npm run e2e` | ⏳ koşulmadı |

**İsmail'e kalan.** Tarayıcıda `localStorage.setItem('davetkart-language','de')` → yenile → Türkçe, seçicide iki dil. Çekirdek akışın İngilizce çevirisi ayrı faz.

---

## 19.21 — `FE-02`: Hata sınırı yok: render hatası ve yüklenemeyen chunk ham hata ekranı veriyor

> **Dallar:** frontend `fix/2026-11-20-fe-02-error-boundary` · docs `docs/2026-11-20-fe-02-error-boundary` · **K:** K222–K223 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-21-FE-02.md`

**Sorun.** Hiçbir rotada `errorElement` yoktu. Bir sayfa düşünce ya da tembel bir chunk yüklenemeyince React Router'ın İngilizce, markasız "Unexpected Application Error!" ekranı çıkıyordu; editörde kaydedilmemiş değişiklik kayboluyordu.

**Kararlar.**
- **K222 (D1, D3, D4):** Rota tablosu `src/routes.tsx`'e taşındı (`routes` dışa aktarılır; `App.tsx` yalnızca `createBrowserRouter(routes)`). Katmanlı sınırlar: layout rotası (tam sayfa), yolsuz iç rota (sayfa düşerse başlık/alt bilgi kalır), `/create` (`scope="editor"`), `/invite/:id` (`scope="guest"`, site bağlantısı yok). `RouteError`: `t('routeError.*')` (tr/en), *Tekrar dene* (aynı adrese `navigate`, yenilemesiz: bellekteki taslak kalır), *Sayfayı yenile*, *Ana sayfa* (düz `<a>`). Editör notu canlı `saveState`'i okur (sökülen editörün otomatik kaydetmesi bekleyen kaydı `flush` eder): `local`/`saving`/`failed`/`saved`. Ayrıntı yalnızca `DEV`'de. Frontend'de hata raporlama aracı yok; bağımlılık eklenmedi.
- **K223 (D2):** `vite:preloadError` → `recoverFromPreloadError`: `sessionStorage['davetkart-preload-reload']`'a zaman damgası, 60 s içinde ikinci hata yenilemez (hata `RouteError`'a düşer, "Sayfa yüklenemedi"); depolama yoksa yenileme yok.
- **Plandan sapma:** `verify:render` vakası çizim hatası yerine hata fırlatan `loader` kullanıyor: sunucu çiziminde React hata sınırları çalışmaz; yükleyici hatası yönlendirici durumuna yazılır ve aynı `errorElement` çizilir. Test, gerçek rota tablosunu kullanır.

**Değişen dosyalar (frontend).** 19.21.1: `src/routes.tsx` (yeni) · `src/App.tsx` · `src/components/errors/RouteError.tsx` (yeni) · `src/locales/{tr,en}/translation.json` (`routeError`, `nav`'ın altında) · `docs/rehber/src/routes.md` · `docs/rehber/src/components/errors/RouteError.md`. 19.21.2: `src/utils/preloadRecovery.ts` (yeni) · `src/main.tsx` · `docs/rehber/src/utils/preloadRecovery.md`. 19.21.3: `scripts/verify-render.tsx` · `docs/rehber/scripts/verify-render.md`.

**Testler + mutantlar.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `verify:render` → *Hata sınırı* (9) + *Chunk yenilemesi* (5) | ✅ 14/14 |
| M1 | `/create`'in `errorElement` satırı silindi | 🔴 2 hata: sınır `app`, not yok |
| M2 | `preloadRecovery` bayrak kontrolü silindi | 🔴 3 hata: yenileme 2, 3, 3 |
| 2 | `npm run check` (tsc + build + verify zinciri, `verify:i18n` 797 değişmedi) | ✅ exit 0 |
| 3 | Tarayıcı: `vite preview` :4173, `PricingPage-*.js` geçici olarak adı değişik, `/pricing` | ✅ gezinme türü `reload` (tek yenileme), bayrak yazıldı, `data-route-error="app"`, "Sayfa yüklenemedi", `<footer>` yerinde · ekran: `D:\Projects\davetkart-hat-b\.playwright-mcp\fe02-chunk-error.png` |

Mutantlardan sonra dosyalar yedekten geri kondu (sha1 eşit); chunk adı geri alındı.

**B7.**

| İddia | Durum |
|---|---|
| Sayfa/editör/misafir hatası markalı `RouteError` | ✅ görüldü (verify:render) |
| Chunk hatası: tek yenileme, sonra `RouteError` | ✅ görüldü (birim + tarayıcı) |
| Editör çökünce bekleyen kayıt `flush` ediliyor | ⏳ koddan çıkarım (`useInvitationAutoSave` temizliği); tarayıcıda denenmedi |
| `npm run check` yeşil | ✅ görüldü |
| `npm run e2e` | ⏳ koşulmadı |

**İsmail'e kalan.** Oturumlu editörde bir hata tetikleyip kaydetme notunun `saving → saved` geçişini gözle görmek.

---

## 19.22 — `TST-04`: Gerçek frontend + gerçek backend'i koşturan otomatik test yok

> **Dallar:** backend ve frontend `fix/2026-11-22-tst-04-e2e` · docs `docs/2026-11-22-tst-04-e2e` · **K:** K224–K225 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-22-TST-04.md`

**Sorun.** Backend testleri kendi gövdeleriyle, frontend `verify:*` betikleri sahte taşıma katmanıyla yeşil; iki tarafı birlikte koşturan kapı yoktu.

**§A.** İsmail yanıtlamadı; varsayılanlar uygulandı: yerel `npm run e2e` (`npm run check`'e girmez, CI yalnızca taslak), her koşuda sıfırdan kurulan ayrı `davetkart_e2e` veritabanı.

**Kararlar.**
- **K224 (D1, D2, D5):** `@playwright/test` 1.61.1 (tam sürüm, dev), yalnızca Chromium. `playwright.config.ts`: `publish` projesi → `after-publish` (`guest-rsvp`, `paywall-after-publish`) ona bağımlı, `workers: 1`, iz yalnızca hatada. Üç yolculuk rol/etiket seçicileriyle, adımlar `e2e/support/flows.ts`'te. `vite.config.ts` proxy hedefi `DAVETKART_API_PROXY` (yoksa 8000). CI taslağı `.github/workflows/e2e.yml.example` (çalışmaz).
- **K225 (D3):** `scripts/e2e/backend.ps1`: ad denetimi (`^davetkart_e2e(_…)?$`, aksi hâlde durur), config önbelleği varsa durur, `.env`'den yalnızca bağlantı (`PGPASSWORD` ekrana yazılmaz), `DROP … WITH (FORCE)` + `CREATE`, süreç ortamıyla `fake` ödeme / `sync` kuyruk / `log` posta, `migrate`, `E2EHEDIYE100` (%100, tüm planlar), `artisan serve` 8020.
- **D4:** SEC-09 (e-posta doğrulama) backend'de henüz yok; yardımcı rota **eklenmedi**. Kanca: `flows.ts` → `verifyEmailIfRequired` (şimdilik boş).

**Plandan sapmalar.**
- Mutant varsayımı tutmadı: `orderId` → `id` backend testlerince de yakalanıyor (aşağıda). Mutant sınırı (2) ve bütçe nedeniyle "yalnızca e2e yakalar" ikinci bir mutant denenmedi.
- `composer check` bu dalda mutantsız da kırmızı: phpstan, `app/Console/Commands/Concerns/ResolvesRefundRequests.php:29` `cast.string` ×2 (14.6'dan kalma, TST-04 ile ilgisiz; dokunulmadı).

**Değişen dosyalar (frontend).** 19.22.1: `package.json` · `package-lock.json` · `playwright.config.ts` (yeni) · `vite.config.ts` · `.gitignore` · `docs/rehber/playwright.config.md`. 19.22.2: `scripts/e2e/backend.ps1` · `docs/rehber/scripts/e2e/backend.md`. 19.22.3: `e2e/support/{state,flows}.ts` · `e2e/{publish-with-gift-code,guest-rsvp,paywall-after-publish}.spec.ts` · `docs/rehber/e2e/README.md`. 19.22.4: `.github/workflows/e2e.yml.example`.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `npm run e2e` ×3 art arda (her biri DB sıfırdan + build) | ✅ 3/3, 3/3, 3/3 · 1.1 dk / 55.5 s / 58.3 s |
| M1 | backend `OrderResource:93` `'orderId'` → `'id'` · `npm run e2e` | 🔴 `publish`: `/odeme?tier=gold&invitation=…`'de kaldı, `/odeme/basarili?order=` gelmedi; 2 yolculuk "did not run" |
| M1 | aynı mutant · `npm run check` | ✅ exit 0 (beklenen: frontend kapısı görmez) |
| M1 | aynı mutant · `php artisan test` Order/Paywall/RefundRequest | 🔴 4 kırmızı / 116 (`OrderTest` ×3, `PaywallTest::the_package_checkout_creates_an_order_without_an_invitation`): prompttaki "composer check yeşil" varsayımı yanlış |
| 2 | `npm run check` (tsc e2e + config dâhil) | ✅ exit 0 |

Mutant geri alındı: sha1 `85f409ee…` eşit, backend `git status` temiz.

**Yol boyunca bulunan test hataları (ürün değil).** Plan duvarı başlığı `h2` ("Davetiyenize uygun paketi seçin"); otomatik kaydetme POST'u tıklamadan önce gelebiliyor (bekleyiş formdan önce başlar); ödeme onay kutusu görsel olarak gizli, etiketinde bağlantı var (klavyeyle işaretlenir); `isVisible()` beklemediği için zarf ve "Tasarımını Düzenle" adımları ara ara atlanıyordu (`waitFor`/`click`).

**B7.**

| İddia | Durum |
|---|---|
| Üç yolculuk yeşil, 3 koşu kararlı | ✅ görüldü |
| Mutant e2e'yi kırar, `npm run check`'i kırmaz | ✅ görüldü |
| Mutant `composer check`'i kırmaz | ❌ yanlış: backend testleri yakalıyor (görüldü) |
| `composer check` tümüyle | ⏳ phpstan'da (önceden var olan) durdu; tam test paketi koşmadı |
| CI taslağı çalışır | ⏳ koşulmadı (§A-1) |

**Gördüm, dokunmadım.**
- Plan duvarında `role="dialog"`/`aria-modal` yok (ekran okuyucu kipin değiştiğini bilmez).
- Misafir LCV formu zarf açıldıktan sonra bir kez yeniden çizilebiliyor; o arada yazılan ad siliniyor (`RSVPForm` yerel durumu). Gerçek misafirde de olabilir.
- phpstan `ResolvesRefundRequests.php:29` (yukarıda).

**İsmail'e kalan.** Boş backend dalı `fix/2026-11-22-tst-04-e2e` silinebilir. CI isteniyorsa taslağı `e2e.yml` yapıp backend deposu erişimini ayarlamak.

---

## 19.23 — `FE-04`: Davetiye bağlantısının paylaşım önizlemesi yok (Open Graph)

> **Dallar:** backend ve frontend `fix/2026-11-23-fe-04-share-preview` · docs `docs/2026-11-23-fe-04-share-preview` · **K:** K230 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-23-FE-04.md`

**Sorun.** `/invite/{id}` WhatsApp'ta paylaşılınca önizleme boş: SPA'nın `index.html`'inde OG etiketi yok, botlar JS çalıştırmaz.

**§A.** İsmail yanıtlamadı; varsayılanlar: sağlam kol (Nginx bot eşlemesi → Laravel Blade), görsel davetiye kapağı → şablon kapağı → site geneli, Elit'te `og:site_name` boş.

**Karar K230.**
- **D1:** `index.html`: `description`, `og:type/site_name/title/description/url/image/locale`, `twitter:card`; mutlak `https://davetkart.com` (botlar göreli `og:image` çözmez).
- **D2:** `GET /invite/{id}` (`whereUlid`) → `SharePreviewController` (5 satır) → `BuildSharePreviewAction` → Blade `share-preview`. **Yalnızca yayındaki** davetiyeden ad, Türkçe tarih, mekân (`ResolvePublicInvitationAction` kapsamı); taslak/silinmiş/olmayan → aynı genel önizleme (H7). Her değer `{{ }}` kaçışlı, betik yok, `noindex`. `og:url`/görsel `frontend.url`'den (SEC-07 dersi: istek başlığından değil). Elit → `og:site_name` yok (K102).
- **§A-2 sapması:** şablon kapağı kullanılamadı: kapaklar frontend derlemesinde hash'li adla (`assets/`), backend bilemez. Sıra: galeri açıksa ilk görsel → `share_preview.image_path` (`/videos/hero-bg-poster.jpg`).
- **Rota yükleme:** uygulama "saf API" (`web` grubu yok); `routes/web.php` `bootstrap/app.php` `then:` ile **grupsuz** yüklendi (Session/CSRF/çerez yok). Ölü `welcome` rotası dosyadan çıktı (zaten yüklenmiyordu).
- **D3:** `map $http_user_agent $davetkart_preview_bot` (15 desen, kaynaklar backend kılavuzunda; `Googlebot` bilerek yok), `location ^~ /invite/` (snippet `include`, K173) → bot ise `return 418` → `@share_preview` (fastcgi), değilse SPA. UA sahtelenebilir: tek savunma D2'deki yayın kapsamı. `Vary: User-Agent`.
- **D4:** önbellekli misafir yükü `PublicInvitationPayloadAction`'a taşındı; API ve önizleme **aynı anahtar** (`Invitation::publicCacheKey`) → `ClearInvitationCache` ikisini birden tazeler. HTTP: `Cache-Control: public, max-age=300`.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `SharePreviewTest` (5) + `PublicInvitationTest` (39) | ✅ 44/44 |
| M1 | `ResolvePublicInvitationAction`'dan yayın koşulu silindi | 🔴 `an_unpublished_invitation_gets_the_generic_preview` (taslak adı sızdı); geri alındı (sha1 `73ccaaf6…`) |
| 2 | `php artisan test` (tam) | ✅ 1264/1264 |
| 3 | phpstan (proje yapılandırması) | 3 hata, **hepsi önceden** (`ResolvesRefundRequests` ×2, `ReadinessTest`); yeni dosyalar temiz. `composer check` bu yüzden phpstan'da duruyor |
| 4 | pint · `errors:export --check` · `plans:export --check` | ✅ / exit 0 / exit 0 |
| 5 | `bash -n deploy/smoke/check-share-preview.sh` | ✅ sözdizimi |
| 6 | Yerel Nginx (Herd) `curl -A WhatsApp` / tarayıcı | ⏳ **koşmadı** (bütçe) |
| 7 | `npm run check` | ✅ exit 0; `dist/index.html`'de 7 `og:` |

**B7.**

| İddia | Durum |
|---|---|
| Bot yayındaki davetiyede adları görür, taslakta görmez | ✅ görüldü (Feature test + mutant) |
| Nginx botu Laravel'e, insanı SPA'ya yollar | ⏳ yapılandırma yazıldı, Nginx'te koşulmadı |
| UA listesi platform belgeleriyle aynı | ⏳ bağlantılar kılavuzda, bu fazda açılıp doğrulanmadı |
| WhatsApp'ta gerçek önizleme | ⏳ canlı ortam gerekir |

**Gördüm, dokunmadım.** phpstan'ın önceden kalan 3 hatası (14.6 ve `ReadinessTest`). Davetiyeye özel kapak alanı yok (önizleme görseli bu yüzden genel).

**İsmail'e kalan.** Herd Nginx ile `bash deploy/smoke/check-share-preview.sh -k https://davetkart.test <id>`; canlıya çıkınca WhatsApp'ta bir davetiye bağlantısı paylaşıp önizlemeye bakmak; Facebook Sharing Debugger ile `og:image`.

---

## 19.24 — `FE-10`: Ana giriş paketi 1,64 MB: 299 şablon her sayfaya, misafir sayfasına bile yükleniyor

> **Dallar:** frontend `fix/2026-11-23-fe-10-bundle-split` · docs `docs/2026-11-23-fe-10-bundle-split` · **K:** K227 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-24-FE-10.md`

**Sorun.** `TemplateRenderer` dokuz kategori barrel'ını statik içe aktarıyordu: 307 şablon ana pakette, her sayfada ve misafir sayfasında.

**§A.** İsmail yanıtlamadı; varsayılan 400 kB ham bütçe **ulaşılamaz** çıktı (aşağıda), cırcır bütçe kondu.

**Karar K227.**
- **D1 + D5:** `THEME_PRESETS` 307 girdisi betikle `lazyTemplate(() => import('./<kategori>/<Ad>'), '<Ad>')`'e çevrildi (`lazyPresets.ts` deseni); barrel içe aktarımları kalktı. `import.meta.glob` kullanılmadı: `verify:*` betikleri esbuild ile derleniyor. Yükleme sırasında mevcut `TemplateFallback` (şablon renginde tam ekran), parça hatasında 19.21 `RouteError`.
- **D2:** `HomePage` → `PreviewSection` `React.lazy` + `Suspense` (`min-h-[100svh]` yer tutucu). **Sapma:** `DeferredSection` (CSS `content-visibility`) sarmalanmadı: `HomePage`'deki ölçümlü not, hero'dan hemen sonraki bölüm ertelenirse ilk kaydırmada ~590 ms görev çıktığını söylüyor.
- **D3:** `src/templateCatalog.ts` (kapaklar + `TEMPLATE_PRESETS` + iki arama fonksiyonu, 3 892 satır); `data.ts` 298 satır. 12 içe aktaran güncellendi. **Ölçümle düzeltilen varsayım:** kapaklar JS'de yalnızca URL; ağır olan katalog metni (103.6 kB). Ana pakete katalog iki yoldan giriyordu: ölü `useActivePreset` (silindi) ve `useSubscriptionStore.getRequiredTier` (artık backend sözleşmesi `PLAN_RULES.presetTiers`'ten okuyor; `verify:plans` (c) ve `verify:payment` yeşil).
- **D4:** `verify:bundle`: ilk yükleme ≤ 720 kB, misafir sayfası (şablon hariç) ≤ 900 kB, barrel statik içe aktarımı yok, 307/307 şablon tembel. CSS bölme yapılmadı (öneri).
- **Adım birleşmesi:** 19.24.3 (D3), 19.24.1'e katıldı: `TemplateRenderer.tsx` iki değişikliği de taşıyor, ayrı commit derlenmezdi.

**Önce / sonra (ham · gzip).**

| Ölçüm | Önce | Sonra |
|---|---|---|
| Ana giriş (= ilk yükleme, tek dosya) | 1 576.8 kB · 420.8 kB | 698.8 kB · 227.1 kB |
| Misafir sayfası (şablon hariç) | ≥ 1 576.8 kB (+ `InvitePage`) | 878.3 kB · 264.4 kB |
| JS dosya sayısı | 169 | ~555 (şablon başına parça) |

400 kB neden olmaz: ilk yüklemede kütüphaneler ~480 kB (react-dom 181, motion 144, react-router 94, i18next 80, axios 52).

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `verify:bundle` | ✅ 6/6 |
| M1 | `TemplateRenderer`'a `import { DugunSade } from './dugun'` + statik girdi | 🔴 3 hata (barrel kuralı, tembel girdi eksik, misafir 964.8 kB); geri alındı (sha1 `3c315d3b…` eşit) |
| 2 | `npm run check` (`verify:render` 373 şablon dahil) | ✅ exit 0 |
| 3 | `npm run e2e` (19.22) | ✅ 3/3 (1.4 dk) |

**B7.**

| İddia | Durum |
|---|---|
| Ana giriş 1.58 MB → 699 kB | ✅ görüldü (build + kaynak haritası) |
| Misafir yalnızca kendi şablonunu indirir | ✅ çıkarım: şablon başına parça + e2e misafir yolculuğu yeşil; ağ kaydıyla sayılmadı |
| Ana sayfada CLS yok | ⏳ ölçülmedi (yer tutucu `100svh`, hero altında) |

**Gördüm, dokunmadım.** Misafir sayfası kataloğu hâlâ indiriyor (`TemplateRenderer` renk haritası, `InvitePage` `resolvePresetId`): ~104 kB. `motion` ilk yüklemede (144 kB).

**İsmail'e kalan.** Bütçe kararı (cırcır 720/900 kB mı, yoksa hedef sayı mı); sonraki adımlar: misafirden kataloğu ayırmak, `motion`'ı ilk yüklemeden çıkarmak, CSS bölme.

---

## 19.25 — `FE-53`: Kısıtlı mobil koşulda LCP 3,9–5,7 sn; giriş ve fiyat sayfalarında CLS 0,33–0,63

> **Dallar:** frontend `fix/2026-11-23-fe-53-mobile-vitals` · docs `docs/2026-11-23-fe-53-mobile-vitals` · **K:** K228 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-25-FE-53.md`

**§A.** İsmail yanıtlamadı; varsayılanlar: misafir LCP < 3 sn, dört sayfada CLS < 0,1; ayrı `npm run perf`.

**Karar K228.**
- **D5 (önce):** `npm run perf` = `playwright.perf.config.ts` (e2e ortamı + `publish` yolculuğu) + `scripts/perf/server.mjs` (gzip'li `dist/`, Nginx kuralları) + `e2e/perf/vitals.perf.ts` (denetimin `perf-measure.mjs` koşulları, 3 koşu ortanca, LCP öğesi ve kayan öğeler teşhis için). `check`'e girmez.
- **D1 (ölçüm sırası):** 19.17 + 19.24 sonrası ilk ölçüm kaymaların kaynağını gösterdi: giriş/fiyatlarda **footer** (0,328 / 0,572), ana sayfada **hero metni** (`#hero-stats` itiliyor; font değişimi).
- **D2:** `Inter Fallback` (Arial) / `Playfair Display Fallback` (Georgia): `size-adjust` Chromium'da Türkçe metin genişlik oranı (Inter 16 px: 105,01 %, Playfair 101,56 %), ascent/descent fontların `hhea`/`OS/2` tablosundan. **Sapma:** font önyüklemesi eklenmedi (`index.html` her rotada; misafir LCP bütçeye 250 ms yakın; eşlemeyle CLS 0).
- **D3 (ölçümle değişti):** kart iskeleti yerine `AppLayout` `PageSpinner` en az `100svh`: tembel sayfa gelene dek footer görünür alanın dışında. Kalan kart kayması 0,014 (bütçe altında); iskelet eklenmedi.
- **D4:** hero videosu `preload="none"`, `<source>`'lar `window` `load`'dan sonra (`useAfterLoad`). LCP öğesi CSS arka planındaki poster; `fetchpriority` uygulanamaz (img değil), poster önyüklemesi her rotaya bindiği için eklenmedi.
- **Ön koşul onarımı:** SEC-09 (Hat A) doğrulama kancası `laravel.log` okuyor ama `.env` `daily` kanal: `backend.ps1` `LOG_CHANNEL=single`, kanca dosya yokken bekler. Bu olmadan `npm run e2e`/`perf` ilk yolculukta düşüyordu. Hat-B backend çalışma ağacı dal açılmadan `faz-19-audit-fixes`'e (`--detach`) alındı.

**Ölçüm (kısıtlı mobil, 3 koşu ortanca).**

| Sayfa | LCP önce → sonra | CLS önce → sonra | Bütçe |
|---|---|---|---|
| Ana sayfa | 4604 → 4752 ms | 0,176 → 0 | CLS ✅ |
| Misafir | 2808 → 2752 ms | 0 → 0 | LCP ✅ CLS ✅ |
| Giriş | 2924 → 3640 ms | 0,329 → 0 | CLS ✅ |
| Fiyatlar | 4088 → 3924 ms | 0,626 → 0,014 | CLS ✅ |

Denetim değerleri (15 Kasım): ana sayfa LCP 5680 / CLS 0,182; misafir LCP 4452. LCP koşular arasında ±400 ms oynuyor; giriş LCP'sindeki artış bu aralıkta (mutant koşusunda 2616 ms).

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `npm run perf` | ✅ bütçe içinde (yukarıdaki tablo) |
| M1 | `PageSpinner`'dan `min-h-[100svh]` kaldırıldı | 🔴 giriş CLS 0,328, fiyatlar 0,587; geri alındı (sha1 `ac33c39b…` eşit) |
| 2 | `npm run check` | ✅ exit 0 (`verify:bundle` ilk yükleme 709,0 kB / 720) |
| 3 | `npm run e2e` | ✅ 3/3 (SEC-09 doğrulama dahil) |

**B7.**

| İddia | Durum |
|---|---|
| Dört sayfada CLS < 0,1, misafir LCP < 3 sn | ✅ görüldü (laboratuvar, yerel) |
| Gerçek cihazda aynı | ⏳ ölçülmedi (RUM kapsam dışı) |
| Ana sayfa LCP iyileşti | ❌ hayır (4,6–4,8 sn; bütçe yok). LCP JS'e bağlı: hero React'le çiziliyor |

**Gördüm, dokunmadım.** `verify:bundle` ilk yükleme 709/720 kB (SEC-09 +10 kB): pay azaldı. Ana sayfa LCP'si için sıradaki adım ilk yüklemeyi küçültmek (`motion`, CSS bölme, FE-10 notu).

**İsmail'e kalan.** Ana sayfa LCP için bütçe isteniyor mu; `npm run perf`'i yayından önce elle koşmak.

---

## 19.26 — `FE-50`: Sihirbaz her ziyarette sunucuda boş taslak açıyor; yenilemede editör kayboluyor

> **Dallar:** backend ve frontend `fix/2026-11-23-fe-50-draft-lifecycle` · docs `docs/2026-11-23-fe-50-draft-lifecycle` · **K:** K192 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-26-FE-50.md`

**Sorun.** Otomatik kaydetme kategori/tema tıklamasını düzenleme sayıp `POST /invitations` ile adsız taslak açıyordu.
Her göz atma panele boş bir "Düğün" bırakıyordu. Adres `/create` kimlik taşımadığı için yenileme editörü 1. adıma döndürüyordu.
Backend'de taslak sınırı da yoktu.

**Kararlar.** §A boş → varsayılan (İsmail cevaplamadı): taslak tavanı 50 (yayındakiler sayılmaz); mevcut boş taslaklara dokunulmadı.
- **D1:** `utils/draftContent.ts` → `shouldAutoSave`: kayıt yokken otomatik kaydetme yalnızca gerçek içerikte (isimler, tarih, mekân).
  Kategori/tema seçimi yerel kalır. "Davetiyeni Oluştur" (`DetailsFormStep`) açık bir `saveInvitation()` çağırır. Kayıt açıldıktan sonra her düzenleme kaydedilir.
- **D2:** `/create/:id` rotası. Kayıt açılınca `navigate('/create/<id>', { replace: true })`. `/create/:id` store'da o kayıt yoksa
  `GET /invitations/{id}` → `loadRecord` + `resumeEditor`; hata (başkasının kimliği 404, H7) → mesaj + `/dashboard`.
  Panelin "Düzenlemeye Devam Et"i doğrudan `/create/<id>`'ye gider. Store sıfırlanırsa (çıkış) sayfa takılmaz, yeniden yükler.
- **D3:** `CreateInvitationAction` kullanıcı satırını `lockForUpdate` ile kilitleyip yayınlanmamışları sayar.
  `davetkart.invitations.max_drafts_per_user` = 50 dolunca **403 `DRAFT_LIMIT_REACHED`** (`params.limit`). Sözleşme, frontend kopyası ve tr/en çeviri
  ("panelinizden kullanmadığınız taslakları silin") eklendi.
- **E2E:** yolculuk 1'e `draftSurvivesReload` (adres `/create/<ULID>`, yenile, isim ekranda) eklendi. **Koşulmadı** (Hat B'nin portu ve veritabanı).
- **Plandan sapma:** `a_user_cannot_open_someone_elses_draft_by_url` mevcut `owner_cannot_read_another_users_invitation`'ın taslak versiyonu; ikisi de duruyor.
  ✅ Ortak düzeltmede kopya silindi (A-E9); `owner_cannot_read_another_users_invitation` taslakla kuruluyor, aynı şeyi korur.

**Testler / mutant.** Backend: `InvitationTest::a_user_cannot_keep_more_than_the_draft_limit` · `the_draft_limit_is_counted_under_the_user_lock` ·
`a_user_cannot_open_someone_elses_draft_by_url`. Frontend `verify:state` → `autosaveWaitsForRealContent` (3 kontrol).
Mutant 1 (backend): tavan koşulu kaldırıldı → `a_user_cannot_keep_more…` kırmızı (öldü).
Mutant 2 (frontend): `shouldAutoSave` her zaman `true` → "yalnızca kategori ve tema seçimi kayıt isteği üretmez" kırmızı (öldü).

**B7 tablosu**

| Kontrol | Sonuç |
|---|---|
| Backend `composer check` | pint ✓ · **PHPStan ✗ 3** (`ResolvesRefundRequests.php:29` ×2, `ReadinessTest.php:175`; önceden var) · errors/plans/env ✓ · test 1259/1259 ✓ (PHPStan'dan sonrası elle) |
| Frontend `npm run check` | çıkış 0; bağımlılık düzeltmesinden sonra lint/build/`verify:render` yeniden ✓ |
| `npm run e2e` | koşulmadı |
| Mutantlar | 2/2 öldü |

**İsmail'e kalan elle işler.** (1) `npm run e2e`'yi Hat B ortamında koş (yolculuk 1: doğrulama + yenileme adımları). (2) Boş taslakların otomatik temizliği ayrı karar (§A-2).

---

## 19.27 — `FE-54`: Hata ve başarı mesajları ekran okuyucuya duyurulmuyor; kontrast yetersiz

> **Dallar:** frontend `fix/2026-11-23-fe-54-accessibility` · docs `docs/2026-11-23-fe-54-accessibility` · **K:** K229 · **Kayıt:** `davetkart-docs: phases/faz-19/kayit/19-27-FE-54.md`

**§A.** İsmail yanıtlamadı; varsayılan: `@axe-core/playwright` taraması `npm run e2e`'de, 14 sayfada `color-contrast` + `aria`/`landmark` sıfır.

**Karar K229.**
- **D1:** `Toaster` iki kalıcı canlı bölge: `role="status" aria-live="polite"` (başarı/bilgi), `role="alert" aria-live="assertive"` (hata). `verify:render` denetler.
- **D2:** kontrast tablosu frontend `docs/rehber/e2e/a11y.md`'de (önce/sonra, axe oranları). Promptun saydıklarına (alt bilgi, alt şerit, fiyat kartı) ek olarak ölçüm şunları buldu: panel "sil" düğmesi, başlık gezinmesi (gizlilik zemini), yasal sayfa numaraları (yeni `--color-gold-ink: #7f6220`), zarf ipucu (`theme.heading` + opaklık tabanı 0,75; bütün zarflı şablonlar). Başlıktaki "Kart" logotip: WCAG 1.4.3 muafiyeti, `data-logotype` ile taramadan hariç.
- **D3 (sapma):** `<main>` 300 şablonun ortak kabuğuna değil misafir sayfasına (`InvitePage`): aynı şablonlar önizlemede uygulamanın kendi `<main>`'i içinde çiziliyor; kabukta olsaydı iç içe `main` olurdu.
- **D4:** `e2e/a11y.spec.ts` (`after-publish` projesi; sahibin oturumu ve yayındaki davetiye 19.22'den). Ek: masaüstü başlık `<nav aria-label>` (`nav.mainMenu`, tr/en) — `landmark-unique`.
- Denetimdeki `landmark-complementary-is-top-level` ve `landmark-one-main` (misafir) bugünkü taramada çıkmadı/çözüldü; `heading-order` (best-practice) kapsam dışı, kaldı.

**Testler + mutantlar.**

| # | Koşu | Sonuç |
|---|---|---|
| 0 | İlk tarama (düzeltmeden önce) | 🔴 13/14 sayfada `color-contrast` + misafirde `region` |
| 1 | `npm run e2e` (a11y dahil 4 test) | ✅ 4/4, 14 sayfada ihlal 0 |
| M1 | Toast `role="alert" aria-live="assertive"` silindi → `verify:render` | 🔴 "hata: role=alert … baştan var" ✗; geri alındı (sha1 `aaec047c…`) |
| M2 | Alt bilgi `/70` → `/50` → `npm run e2e` | 🔴 11 sayfada kontrast; geri alındı (sha1 `a209876c…`) |
| 2 | `npm run check` (`verify:i18n` 769 parça değişmedi) | ✅ exit 0 |

**B7.**

| İddia | Durum |
|---|---|
| 14 sayfada kontrast/yer işareti ihlali 0 | ✅ görüldü (axe) |
| Bölgeler baştan DOM'da | ✅ görüldü (verify:render) |
| Narrator yanlış parolayı duyuruyor | ⏳ koşmadı (elle; ajan ekran okuyucu çalıştıramaz) |
| Bütün şablonlarda zarf ipucu ≥ 4,5 | ⏳ yalnızca e2e'nin şablonunda ölçüldü |

**Gördüm, dokunmadım.** `heading-order` 12 sayfada (best-practice); `Features.tsx`/`AboutPage.tsx`'te aynı `text-gold` küçük numara deseni (taranan sayfalarda ihlal vermedi). Form hata mesajlarının `aria-describedby` bağı taranmadı.

**İsmail'e kalan.** Narrator ile giriş sayfasında yanlış parola: hata duyuruluyor mu. Klavye gezinmesi denetimi (öneri).

---

## 19.28 — `ARC-03`: K18 dosyaların %92'sinde çiğneniyor; 79 dosyanın kılavuzu yok

> **Dallar:** backend `fix/2026-11-24-arc-03-k18-ratchet` · docs `docs/2026-11-24-arc-03-k18-ratchet` · **K:** K259 · **Hat:** C

**Sorun.** K18 (kısa yorum, ayrıntı `docs/rehber/<aynı-yol>.md`) ölçülmüyordu: denetimde 287 üretim dosyasının 265'inde uzun
blok, 362 kod dosyasının 79'unun kılavuzu yoktu. `routes/api.php`'de rotalar 300 satır açıklamanın arasındaydı.

**Kararlar.** §A boş → üçü de **varsayılan (İsmail cevaplamadı)**: cırcır, iki dosya taşınsın, Faz 13–14 kılavuzları yazılsın.
K259: `php artisan k18:check` (`app/Support/K18Inspector.php` ölçer) tabandan (`0743874`) sonra değişen `app/`, `routes/`,
`config/`, `database/` PHP dosyalarında 3 içerik satırından uzun blok ya da eksik kılavuz → çıkış 1. Belirteç tabanlı
(`token_get_all`); `/**`, `*/` ve tip etiketleri (`@param`, `@return`, `@template`, `@phpstan-*` …) sayılmaz. `composer
check`'e girdi; CI `fetch-depth: 0` (sığ klonda taban yok → kapalı başarısızlık). D2: `routes/api.php` 21 blok,
`AppServiceProvider.php` 13 blok kılavuzlarına **aynen** taşındı (`T1…T21`, `P1…P13`); kodda tek cümle + numara.
D3: 28 kılavuz (`Actions/Pricing` 5, `Actions/Account` 6, `Requests/Account` 5, `Notifications` 5, `Services/Payment` 7) +
`ValidationServiceProvider` (19.9'da atlanmıştı). D4: frontend için öneri.

**Plandan sapma.** (1) Komut adı `k18:check`, taban sabit commit (`CheckK18::BASELINE`), `faz-19-audit-fixes` adı değil:
dal ilerledikçe taban kaymasın diye. (2) "3 satır" içerik satırı sayılır (denetim betiği `/**` ve `*/`'yi de sayıyordu);
bu yüzden denetimin `awk` ölçüsü taşınan dosyalarda düşmedi (aşağıda). (3) `.github/workflows/ci.yml` değişti
(`fetch-depth: 0`); prompt CI dosyasını saymıyordu. (4) Taşıma bir betikle yapıldı; özetler kesik kalan 10 blokta elle yazıldı.

**Testler + mutant.**

| # | Koşu | Sonuç |
|---|---|---|
| 1 | `K18CheckTest` (uzun blok + kılavuzsuz, 3 satır sınırı, tip etiketleri, blok bölme, kökler, komut, bilinmeyen taban) | ✅ 7/7 |
| M1 | `close()`'da `>` → `>=` | 🔴 1 |
| M2 | kılavuz denetimi kaldırıldı | 🔴 2 |
| 2 | `route:list --json` önce/sonra (api.php ve provider taşımasından sonra) | ✅ birebir aynı |
| 3 | `k18:check routes/api.php` / `AppServiceProvider.php` | 21 + 14 ihlal → 0 + 0 |
| 4 | Denetim ölçüsü (betiğin mantığı, taban → dal) | kod dosyası 418 → 421 · kılavuzsuz 110 → 81 · uzun bloklu 296/325 → 298/327 (ölçü `/**` `*/`'yi saydığı için) |
| 5 | Kapanış kapıları | yukarıdaki özet tablosu |
| 6 | **Gerçek envanter, `K18Inspector` ile** (Faz 19 ortak düzeltme, 25 Kas; `fix/2026-11-25-hat-c-open-issues` @ `64f43a3`, OPS-03'ün 9 kılavuzu dahil) | `app/` 272 · `config/` 19 · `database/` 44 · `routes/` 3 = **338** dosya · kılavuzsuz **45** (`app/` 38, `database/` 6, `routes/` 1) · uzun bloklu **271** dosya, **568** blok · iki kurala da uyan **44** |

**B7.** Komut, mutantlar, rota listesi, tam takım, audit, frontend kapıları görüldü. PHPStan yeni dosyaları analiz **edemedi**
(tek dosya kipinde mevcut dosyalar da aynı "tip yok" hatalarını veriyor; tam koşu #11'de çöküyor). Denetimin 79/265 sayıları
denetim anlık görüntüsüne (`d810410`) ait; buradaki taban `0743874`.

**İsmail'e kalan.** `main`'e birleştirme ve `v1.2.0` (PHPStan yeşil olmadan etiket yok): docs `phases/faz-19/FAZ-19-ARC-03-COMMIT-KOMUTLARI.md`.
Kalan 81 kılavuzsuz dosya ve uzun bloklar cırcırla, dokunuldukça. (Satır 6: aynı kurala göre gerçek sayı 45 kılavuzsuz
dosya; `awk` ölçüsü `tests/` ve `bootstrap/`'ı da sayıyordu, `K18Inspector` yalnızca dört kökü.)
