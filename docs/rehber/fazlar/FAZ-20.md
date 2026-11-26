# Faz 20 — Faz 19 sonrası kritik hatalar (`AUD-2026-11-26`)

> **Başlangıç:** 26 Kasım 2026 · **Entegrasyon dalı:** `faz-20-critical-fixes` (`faz-19-fix` @ `7f589e7`'den)
> **Kaynak:** docs deposu `audits/2026-11-26-post-faz-19-scan/REPORT.md` · takip tablosu `phases/faz-20/README.md`
> **Kararlar:** docs deposu `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-20.md` (K263, K264)
> **Önceki:** [`FAZ-19.md`](FAZ-19.md) · Commit'leri bu fazda ajan attı (İsmail'in isteği).

## Fazın amacı

İsmail Faz 19'dan sonra Hesap Ayarları'nın üç sayfasında (Genel Bakış, Planlarım, Gizlilik ve Hesap) "Bir aksaklık
oldu" gördü. Kök neden kodda değil ortamdaydı: yerel veritabanı 26 Ekim'den beri göç almamıştı ve `vendor/`,
`node_modules` kilitlerin gerisindeydi. Faz 20 ortamı eşitledi, aynı durumun **sessizce** tekrarlanmasını
görünür kıldı ve taramada çıkan güvenlik yamasını uyguladı.

## Öğrenme hedefleri

| Soru | Nerede |
|---|---|
| Kapılar yeşilken uygulama neden 500 verir? | Bu dosya §20.1 "Sorun"; `RefreshDatabase` test veritabanını her koşuda sıfırdan kurar, geliştirme veritabanını değil |
| Katı kip (strict mode) canlıda neden kapalı ve bu neyi gizler? | [`../app/Support/Health/SchemaCheck.md`](../app/Support/Health/SchemaCheck.md) |
| Hazır olma ucu neden "bağlantı var mı" ile "şema güncel mi"yi ayırır? | aynı dosya "Neden `DatabaseCheck`'in içinde değil" |
| Güvenlik yaması nasıl yalnızca kilidi değiştirir? | [`../composer.md`](../composer.md) §9 (K179 deseni) |
| Çekince/dal değiştirince ne koşulur? | [`../composer.md`](../composer.md) §10 (`composer sync`) |

## 20.0 — Ortam (kod dışı, 26 Kasım)

| Adım | Görülen |
|---|---|
| Kanıt | `laravel-2026-11-25.log` / `-26.log`: 58 × `MissingAttributeException: [first_published_at] … [App\Models\Order]`, yığın `AccountController::entitlements` → `SummarizeEntitlementsAction` → `RefundEligibility::usageBlockerFor` → `Order::hasPublished()` |
| Durum | `migrate:status --pending`: 4 göç (`2026_11_16_100000`, `2026_11_17_100000`, `2026_11_25_100000`, `2026_11_25_110000`); son batch 11 (`2026_10_26_120000`) |
| Neden yalnızca bir kullanıcı | `usageBlockerFor()` bağlı/yanmış hakta `InUse` ile kısa devre yapar; yalnızca bağsız hakkı olan kullanıcıda `hasPublished()` çağrılır |
| Düzeltme | `pg_dump -Fc` yedeği → `php artisan migrate` (4 × DONE) → `composer install` (55 paket, Laravel 13.35.0 → 13.43.0) |
| Sonrası | 5 kullanıcının "Planlarım" özeti OK; geçici token'larla 112 oturumlu/açık GET, 0 hata; başsız Chromium ile 18 rota, 17 temiz (`/eposta-dogrula` sorgusuz 422: beklenen) |

## 20.1 — `OPS-10`: kod şemanın önünde, kimse fark etmiyor

### Sorun

Kod bir sütunu okumaya başlayıp göçü veritabanına ulaşmadığında: yerelde katı kip istisna fırlatır (500), canlıda
(`shouldBeStrict(! isProduction())`) sütun `null` okunur ve `hasPublished()` gibi bir savunma **sessizce** kapanır.
`/api/ping` ve `database` kontrolü bunu görmez: bağlantı çalışıyor.

### Karar

**K263:** `GET /api/health`'e beşinci kontrol `schema` (`app/Support/Health/SchemaCheck.php`): `migrate:status` ile
aynı yollardaki göç dosyalarından biri `migrations` tablosunda yoksa ya da tablo yoksa 503. Fazla kayıt (dosyası
olmayan) başarısızlık değil. Veritabanına ulaşılamazken `["database","schema"]`.

### Değişen dosyalar

| Dosya | Ne | Kılavuz |
|---|---|---|
| `app/Support/Health/SchemaCheck.php` | yeni kontrol | [`SchemaCheck.md`](../app/Support/Health/SchemaCheck.md) |
| `app/Providers/MonitoringServiceProvider.php` | `database`'ten hemen sonra kayıt | [`MonitoringServiceProvider.md`](../app/Providers/MonitoringServiceProvider.md) |
| `tests/Feature/ReadinessTest.php` | 3 yeni test, 1 beklenti (`database` düşünce `schema` da) | [`ReadinessTest.md`](../tests/Feature/ReadinessTest.md) |

### Testler ve öldürdükleri mutantlar

| Test | Öldürdüğü mutant |
|---|---|
| `readiness_fails_when_a_migration_has_not_run` | kontrol kayıtlı değil (önce kırmızı görüldü: 200) · `array_diff` yönü ters |
| `a_recorded_migration_without_a_file_does_not_fail_readiness` | `array_diff` yönü ters |
| `readiness_fails_when_the_migrations_table_is_missing` | tablo yokken `true` |

## 20.2 — `SEC-51`: Symfony 8.1.12

25 Kasım'da yayımlanan 5 bildirim (`http-kernel`, `mailer` ×2, `routing` ×2) `composer audit`'i kırmızıya çevirdi;
K181'e göre dal yayın adayı değildi. `composer update "symfony/*" -W` → yalnızca `composer.lock`, 14 yama sürümü.
Ayrıntı [`../composer.md`](../composer.md) §9.

## 20.3 — `OPS-11`: `composer sync`

`composer install` + `php artisan migrate` tek komut; `README.md`, [`../PROJE-KOMUTLARI.md`](../PROJE-KOMUTLARI.md) §5.2,
[`../composer.md`](../composer.md) §10. Frontend karşılığı Vite kapalıyken `npm ci`.

## 20.4 — `OPS-06` ↑: deploy bakım kipinde (K264, İsmail: seçenek A)

### Sorun

`deploy.sh` kodu `git pull` ile göçten önce yerine koyuyordu; OPcache varsayılanı yeni dosyayı ≤2 sn'de okur. Göç bitene
kadar (ya da göç yarıda kalırsa kalıcı olarak) yeni kod eski şemayla çalışır; canlıda katı kip kapalı olduğu için belirti
sessiz yanlış cevaptır. Kılavuz `set -e`'nin bunu önlediğini söylüyordu; önlemiyordu.

Denerken ikinci bir hata çıktı: `trap … ERR` `set -E` olmadan fonksiyonların içine geçmez. `artisan()` içindeki her hata
(ör. `migrate`) betiği durduruyor ama "deploy yarıda kesildi, önceki sürüm: …" mesajını hiç basmıyordu (eski betik,
stub'lı deneme: çıkış 1, `HATA` satırı 0).

### Değişiklik

| Dosya | Ne | Kılavuz |
|---|---|---|
| `deploy/deploy.sh` | `set -Eeuo pipefail`; güvenlik kontrollerinden sonra `artisan down --retry=60`; `queue:restart`'tan sonra `artisan up`; hata mesajı bakım kipini söyler | [`../deploy/deploy.md`](../deploy/deploy.md) §1, adım 0b, §4, §5 |
| `tests/Feature/MaintenanceModeTest.php` | bakımda API `503 {"error":{"code":"PROVIDER_UNAVAILABLE"}}`, `up`'tan sonra 200 | [`../tests/Feature/MaintenanceModeTest.md`](../tests/Feature/MaintenanceModeTest.md) |
| frontend `src/locales/{tr,en}/errors.json` | `PROVIDER_UNAVAILABLE`: "Asistan…" → "Hizmet şu anda yanıt veremiyor" (ödeme/medya 503'lerinde de yanlıştı) | — |

### Deneme (stub'lı, sunucusuz)

`sudo`, `git`, `composer`, `curl` yerine çağrıyı kaydeden betikler PATH'in başında; `FAIL_ON` ile istenen adım düşürüldü:

| Senaryo | Çıkış | `artisan` sırası | Mesaj |
|---|---|---|---|
| başarı | 0 | `down` → `package:discover` → `config:cache` → `route:cache` → `migrate` → `queue:restart` → `up` | frontend betiği koştu |
| `migrate` düşer | 1 | … `migrate` (`up` yok) | `HATA … Onceki surum` + `Site BAKIM KIPINDE kaliyor` |
| `config:cache` düşer | 1 | … `config:cache` (`up` yok) | aynı |
| `up`'tan sonra `/api/ping` düşer | 22 | tam sıra | yalnızca `HATA` (bakım satırı yok: site açık); frontend betiği koşmadı |
| `down` düşer | 1 | `down` | artisan'ın kendi hatası (tuzak henüz kurulmadı; site bakımda değil) |

`MaintenanceModeTest` mutantı: `activate()`'ten sonra `deactivate()` → ilk test 200 görür, kırmızı.

## 20.6 — `OPS-13`: `composer setup` anahtarı ezmez

`setup` yalnızca `APP_KEY` boşsa `key:generate` çalıştırır ([`../composer.md`](../composer.md) §11). Koruma ayrı bir
klasörde beş `.env` biçimiyle denendi (boş LF, boş CRLF, yalnızca boşluk → üretir; dolu, tırnaklı → dokunmaz).

## Yapılmayanlar

| Madde | Neden |
|---|---|
| 20.5 `FE-59` (`/eposta-dogrula` sorgusuz 422) | Değişiklik gerekmiyor: sunucu doğru kodu (`EMAIL_VERIFICATION_INVALID`) döndürüyor, ekran doğru mesajı gösteriyor; frontend'de imza kontrolü kuralın ikinci kopyası olurdu |

## Doğrulama durumu (B7)

| Kapı | Sonuç |
|---|---|
| `composer check` (`faz-20-critical-fixes` @ `6b3b450`, 20.4 dahil, 26 Kasım) | ✅ pint · PHPStan 0 · errors/plans/env:template · `k18: 32 dosya, 0 ihlal` · **1306/1306** (4765 doğrulama); 20.4'ten önce `73a8462`'de 1304/1304 |
| `composer audit` | ✅ "No security vulnerability advisories found." |
| `composer install --dry-run` · `migrate:status --pending` (yerel) | ✅ "Nothing to install, update or remove" · "No pending migrations" |
| Frontend `npm run check` (`faz-19-fix` @ `5c68df3`, `node_modules` onarıldıktan sonra) | ✅ tsc · build · 15 `verify:*` · ilk yükleme 712,0 / 720 kB · misafir 790,2 / 800 kB |
| Frontend `npm run e2e` (`davetkart_e2e`) | ✅ **6/6** iki kez: frontend `5c68df3` + backend `73a8462` (2,6 dk) · frontend `faz-20-critical-fixes` @ `d10e7b2` + backend `6b3b450` (2,7 dk). Yayın + hediye kodu, a11y 15 sayfa, hatalı form a11y, editör çıkışta kayıt, misafir LCV, Elit plan duvarı |
| Frontend `npm run check` (`02836ec`, 20.4 metni) | ✅ `verify:errors` 31 kod iki dilde, `verify:i18n` 781 |
| Canlı sunucu: `/api/health`, OPcache ayarı | koşmadı |

## Elle doğrulama (İsmail)

1. Tarayıcında Hesap Ayarları → Genel Bakış, Planlarım, Gizlilik ve Hesap: hata toast'ı yok.
2. `curl -i http://127.0.0.1:8000/api/health` → `failed` listesinde `schema` yok (yerelde `queue`, `scheduler`
   beklenir: işçi ve zamanlayıcı çalışmıyor).
3. Bundan sonra `git pull` / dal değişiminden sonra: backend `composer sync`, frontend Vite'ı durdurup `npm ci`.
4. Canlıda ilk deploy (K264): eski betikle başlar, `down` o seferlik `pull`'dan sonra açılır. Deploy'dan önce izleyicide
   bakım penceresi aç; günlükte `Bakim kipi aciliyor` / `kapaniyor` satırlarını gör. Koşmadı (B7): gerçek sunucuda.
