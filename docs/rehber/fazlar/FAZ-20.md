# Faz 20 — Faz 19 sonrası kritik hatalar (`AUD-2026-11-26`)

> **Başlangıç:** 26 Kasım 2026 · **Entegrasyon dalı:** `faz-20-critical-fixes` (`faz-19-fix` @ `7f589e7`'den)
> **Kaynak:** docs deposu `audits/2026-11-26-post-faz-19-scan/REPORT.md` · takip tablosu `phases/faz-20/README.md`
> **Kararlar:** docs deposu `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-20.md` (K263)
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

## 20.6 — `OPS-13`: `composer setup` anahtarı ezmez

`setup` yalnızca `APP_KEY` boşsa `key:generate` çalıştırır ([`../composer.md`](../composer.md) §11). Koruma ayrı bir
klasörde beş `.env` biçimiyle denendi (boş LF, boş CRLF, yalnızca boşluk → üretir; dolu, tırnaklı → dokunmaz).

## Yapılmayanlar

| Madde | Neden |
|---|---|
| 20.4 `OPS-06` ↑ (deploy sırasında kod şemanın önünde) | Karar İsmail'in: bakım kipi mi, sürüm klasörü mü (`phases/faz-20/README.md` §20.4). `deploy.md` §5 "`artisan down` bilerek eklenmedi" diyor; bu karar değişmeden betiğe dokunulmadı |
| 20.5 `FE-59` (`/eposta-dogrula` sorgusuz 422) | Değişiklik gerekmiyor: sunucu doğru kodu (`EMAIL_VERIFICATION_INVALID`) döndürüyor, ekran doğru mesajı gösteriyor; frontend'de imza kontrolü kuralın ikinci kopyası olurdu |

## Doğrulama durumu (B7)

| Kapı | Sonuç |
|---|---|
| `composer check` (`faz-20-critical-fixes` @ `73a8462`, 26 Kasım) | ✅ pint · PHPStan 0 · errors/plans/env:template · `k18: 32 dosya, 0 ihlal` · **1304/1304** (4760 doğrulama) |
| `composer audit` | ✅ "No security vulnerability advisories found." |
| `composer install --dry-run` · `migrate:status --pending` (yerel) | ✅ "Nothing to install, update or remove" · "No pending migrations" |
| Frontend `npm run check` (`faz-19-fix` @ `5c68df3`, `node_modules` onarıldıktan sonra) | ✅ tsc · build · 15 `verify:*` · ilk yükleme 712,0 / 720 kB · misafir 790,2 / 800 kB |
| Frontend `npm run e2e` (frontend `5c68df3` + bu backend ucu, `davetkart_e2e`) | ✅ **6/6** (yayın + hediye kodu, a11y 15 sayfa, hatalı form a11y, editör çıkışta kayıt, misafir LCV, Elit plan duvarı), 2,6 dk |
| Canlı sunucu: `/api/health`, OPcache ayarı | koşmadı |

## Elle doğrulama (İsmail)

1. Tarayıcında Hesap Ayarları → Genel Bakış, Planlarım, Gizlilik ve Hesap: hata toast'ı yok.
2. `curl -i http://127.0.0.1:8000/api/health` → `failed` listesinde `schema` yok (yerelde `queue`, `scheduler`
   beklenir: işçi ve zamanlayıcı çalışmıyor).
3. Bundan sonra `git pull` / dal değişiminden sonra: backend `composer sync`, frontend Vite'ı durdurup `npm ci`.
