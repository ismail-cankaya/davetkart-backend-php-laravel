# Faz 21 — Ertelenmiş düşük bulgular ve açık kararlar

> **Başlangıç:** 27 Kasım 2026 · **Entegrasyon dalı:** `faz-21-backlog` (`faz-20-critical-fixes` @ `70edfcf`'den)
> **Kaynak:** docs deposu `audits/2026-11-12-full-system-audit/` (düşük 🟢 ve öneri 💡 bulgular) + `audits/2026-11-26-post-faz-19-scan/REPORT.md` §4b
> **Takip tablosu:** docs deposu `phases/faz-21/README.md` §1 · **Kararlar:** `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-21.md`
> **Önceki:** [`FAZ-20.md`](FAZ-20.md) · Tek oturum, onaysız; her madde ayrı commit, commit'leri ajan attı (İsmail'in isteği).

## Fazın amacı

`AUD-2026-11-12` denetiminin ertelenen düşük ve öneri bulgularını ve ikinci taramanın (`AUD-2026-11-26`) açık karar
sorularını kapatmak. Karar isteyen maddelerde İsmail'in "Cevabın" sütunu boş kaldıysa plandaki varsayılan uygulandı
ve K kaydına "varsayılan (İsmail cevaplamadı)" yazıldı.

## 21.0 — Hazırlık (27 Kasım)

| Adım | Görülen |
|---|---|
| Depo durumu | Üç depoda `git status --short` boş |
| Dallar | backend/frontend `faz-21-backlog` (`faz-20-critical-fixes` ucundan), docs `docs/2026-11-27-faz-21-backlog` (plan dalı `docs/2026-11-26-faz-21-plan` ucundan) |
| Ortam | `composer sync`: kurulacak paket yok, "Nothing to migrate"; `migrate:status --pending` "No pending migrations"; `composer install --dry-run` "Nothing to install, update or remove". Frontend'de Vite (`npm run dev`) açıktı, `npm ci` yerine `npm install` koşuldu: "found 0 vulnerabilities" |

### Taban kapıları

| Kapı | Sonuç (görülen çıktı) |
|---|---|
| `composer check` | pint passed · phpstan 0 hata · `errors:export --check`, `plans:export --check`, `env:template --check` temiz · `k18: 32 dosya, 0 ihlal` · phpunit **1306/1306**, 4765 assertion, 121,9 sn · çıkış 0 |
| `composer audit` | "No security vulnerability advisories found." |
| `npm run check` | çıkış 0 · `verify:errors` 31 kod · `verify:i18n` 781 parça, 65 dosya |
| `npm run e2e` | **6/6** (1,9 dk) |

## 21.1 — `SEC-16`: hesap bazlı (IP'den bağımsız) giriş freni

### Sorun

Giriş ucunun iki kovası (e-posta + IP 5/dk, IP 20/dk) anahtarında IP taşıyordu. Bir hesaba çok sayıda IP'den yapılan
denemeler hiçbir kovada birikmiyordu: 1000 IP, dakikada 5000 deneme.

### Karar (K265, §A boş → varsayılan)

- Üçüncü kova: hesap başına, IP'den bağımsız **saatte 30 başarısız** giriş (`AccountLoginThrottle`, anahtar
  `login-fail|HMAC(normalize(e-posta))`). Yalnızca başarısızlık sayılır; kayıtsız adres de sayılır (429 kaydı ele vermez).
- Aşılınca `429 RATE_LIMITED` + `retryAfter`; parola **hiç denenmez**, doğru parola da 429 alır. Kalıcı kilit yok;
  başarılı parola sıfırlama kovayı boşaltır (sahibin yolu). Açık oturumlar etkilenmez.
- Kova ilk dolduğunda hesap sahibine kuyruklu, Türkçe, IP'siz "çok sayıda hatalı giriş" postası; ayrı kovayla saatte
  en çok bir.
- Mevcut iki kova ve sırası değişmedi (`authLimits()` dokunulmadı).

### Değişen dosyalar

| Dosya | Ne | Kılavuz |
|---|---|---|
| `app/Support/AccountLoginThrottle.php` | yeni: kova, uyarı kovası, `clear()` | [`AccountLoginThrottle.md`](../app/Support/AccountLoginThrottle.md) |
| `app/Exceptions/AccountLoginThrottledException.php` | yeni: 429 `RATE_LIMITED` + `retryAfter` | [`AccountLoginThrottledException.md`](../app/Exceptions/AccountLoginThrottledException.md) |
| `app/Notifications/SuspiciousLoginAttemptsNotification.php` | yeni: uyarı postası | [`SuspiciousLoginAttemptsNotification.md`](../app/Notifications/SuspiciousLoginAttemptsNotification.md) |
| `app/Actions/Auth/LoginUserAction.php` | en başta `ensureOpen()`, başarısızlıkta `recordFailure()` + posta; başlık yorumu kısaldı (K18) | [`LoginUserAction.md`](../app/Actions/Auth/LoginUserAction.md) "Faz 21" |
| `app/Actions/Auth/ResetPasswordAction.php` | başarıda `clear()`; 9 satırlık yorum kılavuza taşındı (K18) | [`ResetPasswordAction.md`](../app/Actions/Auth/ResetPasswordAction.md) "Faz 21" |
| `tests/Feature/AccountLoginThrottleTest.php` | 12 test | [`AccountLoginThrottleTest.md`](../tests/Feature/AccountLoginThrottleTest.md) |
| `phpunit.xml` | `<ini name="memory_limit" value="256M"/>` (aşağıda) | [`phpunit.md`](../phpunit.md) §5 |
| `docs/08-HATA-SOZLESMESI.md` §4.1, `AppServiceProvider.md` | girişte `RATE_LIMITED`'ın üç kaynağı | — |

### Testler ve mutantlar

Önce kırmızı: yeni dosya 11 testle koşuldu, 8 başarısız + 1 hata (sınıf yok); iki "yokluk" testi doğal olarak geçti ve
mutantla sınandı. Mutantlar `mutate.py` ile tek tek uygulanıp geri alındı:

| Mutant | Sonuç | Öldüren test |
|---|---|---|
| M1 `ensureOpen()` çağrısı yok | öldü | 7 test (ör. `login_attempts_from_many_ips_share_an_account_bucket`) |
| M2 başarılı giriş de sayılır | öldü | `successful_logins_are_not_counted` |
| M3 anahtar e-postadan bağımsız (tek kova) | öldü | `the_bucket_belongs_to_one_account_only` |
| M4 yalnızca kayıtlı hesapta sayılır | öldü | `an_unknown_address_fills_its_bucket_the_same_way` |
| M5 anahtarda normalizasyon yok | önce **yaşadı** (HTTP yolunda `LoginRequest` zaten normalize ediyor) → `the_throttle_normalizes_the_address_itself` eklendi → öldü | o test |
| M6 sıfırlama kovayı boşaltmaz | öldü | `a_password_reset_empties_the_bucket` |
| M7 uyarı kovası yok | öldü | `at_most_one_warning_per_hour` |
| M8 uyarı bir deneme erken | öldü | `the_owner_is_warned_once_when_the_bucket_fills` |
| M9 pencere iki saat | öldü | `the_bucket_empties_after_an_hour` |
| M10 sınır 31 | öldü | `login_attempts_from_many_ips_share_an_account_bucket` |
| M11 posta kuyruksuz | öldü | `the_warning_is_queued_turkish_and_carries_no_ip` |

### Kapı sırasında çıkan: test sürecinin belleği

İlk `composer check` PHPStan'de düştü (test yardımcısında `TestResponse<Response>` jeneriği eksikti; düzeltildi). İkincisi
tam takımda `OptimizeUploadedImageTest::a_landscape_photo_is_reduced_to_the_longest_edge`'de `Allowed memory size of
134217728 bytes exhausted` ile düştü; iki dosya birlikte ve ayrı ayrı yeşildi. Ölçüm (`tests/TestCase.php`'ye geçici
`memory_get_usage()` satırı, sonra geri alındı): görsel testinin başında süreç GC sonrası 93,3 MB taşıyordu, 3000×2000 GD
tuvali payı aştı; 21.1 testleri ~5 MB kalıcı sınıf yükü ekledi. Düzeltme `phpunit.xml`'de test süreci için `256M`
(gerekçe ve seçenekler [`phpunit.md`](../phpunit.md) §5). Üçüncü koşu yeşil.

### B7 tablosu

| İddia | Kanıt (görülen çıktı) |
|---|---|
| Yeni testler önce kırmızıydı | `tests=11, passed=2, failed=8, errors=1` |
| Yeni testler yeşil | `tests=12, passed=12, assertions=394` |
| 11 mutantın hepsi öldü | `mutate.py` çıktısı: M1–M4, M6–M11 KILLED; M5 test eklendikten sonra KILLED |
| `composer check` | pint passed · phpstan 0 · `k18: 37 dosya, 0 ihlal` · phpunit **1318/1318**, 5159 assertion · çıkış 0 |
| `npm run e2e` | 6/6 (kayıt ve giriş akışı bu backend koduyla) |

### Elle doğrulama

Koşmadı (tarayıcıyla ya da gerçek posta sunucusuyla). Testler aynı yolu HTTP çekirdeği üzerinden koşuyor. İsmail için:
yerelde `MAIL_MAILER=log` iken bir hesaba 30 kez yanlış parola (`curl` ile farklı `X-Forwarded-For` işe yaramaz, güvenilen
vekil yok; tek IP'den denenirse önce 5/dk kovası dolar) → en kolay yol `php artisan tinker` içinde
`app(App\Support\AccountLoginThrottle::class)->recordFailure('<adres>')` × 29, sonra giriş sayfasında bir kez yanlış
parola (30.; posta kuyruğa girer, `queue:work` sonrası `storage/logs`'ta metni okunur), ardından doğru parolayla "çok
fazla deneme" görmek. Geri almak için parola sıfırlama ya da `php artisan cache:clear`.

### Gördüm, dokunmadım

- `config/davetkart.php` `auth.login_rate_limit_per_minute = 5` hiçbir yerde okunmuyor; `authLimits()` 5'i sabit yazıyor.
  Yeni kovanın sayısı da sınıfta sabit (`config/davetkart.php`'ye dokunmak dosyanın 17 uzun yorum bloğunu K18'e sokardı).

## 21.2 — `SEC-15`: kayıt ucu adresin kayıtlı olduğunu söylüyor

### Sorun

Var olan adresle kayıt `422 REGISTRATION_FAILED`, yeni adresle `201`. `fields` yok ama durum kodu farkı adresin kayıtlı
olduğunu söylüyor.

### Karar (K266, §A boş → varsayılan B)

Sözleşme aynı (K11). Risk kabul edildi; frenler yazıldı ve testle kilitlendi. Tam kapatma (kayıt her zaman `202
check_email`, oturum doğrulamadan sonra) K11'i ve frontend akışını değiştirir, ayrı iş.

**Plandaki adımların karşılığı:**

- "Kayıt ucuna e-posta + IP ve IP kovası ekle": kovalar **zaten vardı**. Kayıt rotası `throttle:auth` grubunda (adres + IP
  5/dk, IP 20/dk) ve kayıtlı adresle deneme de sayılıyor (`Limit::by` her isteği sayar). Kod eklenmedi; üç testle kilitlendi.
- "Çakışma yolunda `Hash::make` atlanıyor mu?": **atlanmıyor**. `User::create()` `hashed` cast'iyle parolayı INSERT'ten
  önce hash'liyor; çakışma INSERT'te `UniqueConstraintViolationException` olarak çıkıyor. Yapı testle kilitlendi; süre
  test edilmedi (gerekçe [`RegisterUserAction.md`](../app/Actions/Auth/RegisterUserAction.md) "Faz 21").

### Değişen dosyalar

| Dosya | Ne | Kılavuz |
|---|---|---|
| `tests/Feature/RegistrationEnumerationBrakesTest.php` | 3 test | [`RegistrationEnumerationBrakesTest.md`](../tests/Feature/RegistrationEnumerationBrakesTest.md) |
| `tests/Support/CountingHasher.php` | `make()` sayan devredici | aynı kılavuz, son bölüm |
| `docs/08-HATA-SOZLESMESI.md` §3.1 | "kabul edilen risk" notu | — |
| `docs/rehber/app/Actions/Auth/RegisterUserAction.md` | Faz 21 bölümü (frenler tablosu, süre, B6) | — |

`app/` değişmedi.

### Testler ve mutantlar

Davranış değişmediği için testler ilk koşuda yeşildi (3/3). Koruma mutantla gösterildi:

| Mutant | Sonuç | Öldüren test |
|---|---|---|
| M1 kayıt rotası `throttle:auth` dışında | öldü | iki kova testi |
| M2 IP kovası yok | öldü | `scanning_many_addresses_from_one_ip_is_capped` |
| M3 adres + IP kovası adresi taşımaz (yalnızca IP) | önce **yaşadı** → "aynı IP'den başka adres 201" adımı eklendi → öldü | `attempts_on_a_taken_address_fill_the_address_and_ip_bucket` |
| M4 kayıtlı adreste hash'siz kısa yol | öldü | `the_password_is_hashed_even_when_the_address_is_taken` |

`Hash::partialMock()` ilk denemede `Call to a member function get() on null` verdi (kurucusuz taklit); yerine
`CountingHasher` yazıldı.

### B7 tablosu

| İddia | Kanıt (görülen çıktı) |
|---|---|
| Yeni testler yeşil | `tests=3, passed=3, assertions=40` |
| 4 mutant öldü | `mutate.py`: M1, M2, M4 KILLED; M3 test güçlendirildikten sonra KILLED |
| `npm run e2e` | 6/6 (1,9 dk) |
| `composer check` | pint passed · phpstan 0 · `k18: 37 dosya, 0 ihlal` · phpunit **1321/1321**, 5199 assertion · çıkış 0 |

### Elle doğrulama

Gerekmiyor: davranış değişmedi.

## 21.3 — `FE-58`: ödeme dönüşünde onaysız yayın (frontend; kısa kayıt)

Backend değişmedi. Karar **K267** (varsayılan): otomatik yayın kalır, K67 daraltıldı: ödeme **sunucuda** yayınlamaz
(webhook yalnızca `paid` yapar), frontend yayın niyetli ödemenin dönüşünde sekme başına bir kez `POST
/invitations/{id}/publish` ister; yetki ve plan kontrolü (402) yine burada. Frontend `fe ea9d8b1`: karar
`src/utils/autoPublish.ts`'e taşındı, yenilemede ikinci istek gitmiyor (panelden kaldırılan davetiye sorulmadan yeniden
yayınlanıyordu). `npm run check` 0, e2e 6/6, `verify:payment` 19 yeni denetim, 7 mutant öldü. Ayrıntı frontend
`docs/rehber/src/utils/autoPublish.md`. Backend'e kalan (ayrı iş): `GET /orders/{id}` yanıtında
`orders.first_published_at` olursa başka sekmede de yeniden yayın kapanır.

## 21.4 — `OPS-08`: istek kimliği

### Sorun

Günlük satırları ve kuyruk işleri onları doğuran isteğe bağlanamıyordu.

### Karar (K268, §A boş → varsayılan)

- Her yanıtta `X-Request-Id` (ULID). Hata zarfı **değişmedi** (§A-1).
- Gelen `X-Request-Id` yalnızca ULID ya da UUID ise kullanılır; değilse yenisi üretilir (§A-2; biçim ≤64'ü zaten sağlar).
- Kimlik Laravel `Context`'ine (`request_id`): günlük satırının `extra`'sına ve kuyruk işinin yüküne kendiliğinden gider.
- Ara katman global zincirin **en başında** (`prepend`).
- Nginx önerisi (koşulmadı): erişim günlüğüne `$upstream_http_x_request_id`.

### Değişen dosyalar

| Dosya | Ne | Kılavuz |
|---|---|---|
| `app/Http/Middleware/AssignRequestId.php` | yeni | [`AssignRequestId.md`](../app/Http/Middleware/AssignRequestId.md) |
| `bootstrap/app.php` | `$middleware->prepend(AssignRequestId::class)` | [`bootstrap/app.md`](../bootstrap/app.md) "Faz 21.4" |
| `tests/Feature/RequestIdTest.php` | 7 test (14 vaka) | [`RequestIdTest.md`](../tests/Feature/RequestIdTest.md) |
| `docs/08-HATA-SOZLESMESI.md` §4.1 | başlık notu, zarf aynı | — |

### Testler ve mutantlar

Önce kırmızı: 13 vakadan 12'si başarısız (zarf testi yokluk testi, geçti). Sonra 413 testi eklendi.

| Mutant | Sonuç | Öldüren test |
|---|---|---|
| R1 ara katman kayıtlı değil | öldü | 13 vaka |
| R2 gelen her kimlik kabul | öldü | `a_malformed_incoming_id_is_replaced` (6 vaka) |
| R3 gelen kimlik hiç kabul edilmez | öldü | `a_well_formed_incoming_id_is_kept`, günlük, kuyruk |
| R4 Context'e yazılmaz | öldü | günlük ve kuyruk testleri |
| R5 `append` (zincirin sonu) | öldü | `an_early_response_from_the_global_stack_carries_it_too` |
| R6 uzunluk sınırı yok | **yaşadı**: eşdeğer (ULID 26, UUID 36) → kontrol koddan kaldırıldı | — |
| R7 UUID kabul edilmez | öldü | `a_well_formed_incoming_id_is_kept` (UUID) |

### B7 tablosu

| İddia | Kanıt (görülen çıktı) |
|---|---|
| Önce kırmızı | `tests=13, passed=1, failed=12` |
| Yeşil | `tests=14, passed=14, assertions=40` |
| Gerçek günlük satırı | yerelde `tinker` ile tek satır: `local.INFO: faz21-request-id-deneme  {"request_id":"01JDQZ3K8G4T6M2N5P7R9S1V3W"}` |
| `composer check` | ilk koşu PHPStan'de düştü (`Log::channel()` → `LoggerInterface::getLogger()`; testte `Illuminate\Log\Logger` doğrulandı); ikinci: pint passed · phpstan 0 · `k18: 38 dosya, 0 ihlal` · phpunit **1335/1335**, 5240 assertion |
| `npm run e2e` | 6/6 |

### Elle doğrulama

`curl -si http://127.0.0.1:8000/api/ping | findstr X-Request-Id` koşulmadı (İsmail'in dev sunucusuna dokunulmadı); aynı
yol testte üç uçla sınandı. Sunucuda nginx `log_format` adımı İsmail'in (kılavuz §6).

## 21.5 — `OPS-07`: PHP-FPM havuzu ve OPcache

### Sorun

Havuz dosyası depoda yoktu (Ubuntu varsayılanı `pm.max_children = 5`); OPcache ayarı ve bellek bütçesi yazılı değildi.

### Karar (K269, §A boş → varsayılan)

- `deploy/php-fpm/davetkart.conf`: `pm = dynamic`, `max_children 4`, `start 2`, `min_spare 1`, `max_spare 3`,
  `max_requests 500`; soket nginx ile aynı (`/run/php/php8.5-fpm.sock`, `www.conf` devre dışı); `request_terminate_timeout
  60s` (nginx `fastcgi_read_timeout` en çok 60s); slowlog 5s; durum sayfası yalnızca soketten.
- `deploy/php/davetkart.ini`: `opcache.memory_consumption=128`, `max_accelerated_files=20000`, `validate_timestamps=0`.
- Bellek tablosu kılavuzda: tavanlar toplamı ~2 270 MB (2 048'i ~220 MB aşar), tipik ~500–1 000 MB; takas dosyası ya da
  havuzda `memory_limit=192M` İsmail'e soru.

### Değişen dosyalar

| Dosya | Kılavuz |
|---|---|
| `deploy/php-fpm/davetkart.conf` (yeni) | [`deploy/php-fpm/davetkart.md`](../deploy/php-fpm/davetkart.md) |
| `deploy/php/davetkart.ini` (OPcache) | [`deploy/php/davetkart.md`](../deploy/php/davetkart.md) "OPcache" |
| — | [`deploy/deploy.md`](../deploy/deploy.md) Adım 5 notu: reload artık zorunlu |

### B7 tablosu

| İddia | Kanıt (görülen çıktı) |
|---|---|
| ini ayrıştırılıyor, OPcache değerleri yürürlükte | Herd PHP 8.5, `PHP_INI_SCAN_DIR` ile: `opcache.memory_consumption='128'`, `opcache.max_accelerated_files='20000'`, `opcache.validate_timestamps='0'`, `opcache yuklu: true` |
| Havuz sözdizimi (`php-fpm8.5 -t`) | **koşmadı**: Windows'ta PHP-FPM yok |
| Sunucu kurulumu | **koşmadı** (sunucuya istek yok); komutlar kılavuz §4, geri alma §5 |
| `composer check` | pint passed · phpstan 0 · `k18: 38 dosya, 0 ihlal` · phpunit **1335/1335** · çıkış 0 (PHP kodu değişmedi) |

### İsmail'e kalan (sunucu)

1. Kılavuz §4'teki kurulum (havuzu kopyala, `www.conf` → `www.conf.dist`, slowlog dosyası, `php-fpm8.5 -t`, reload, ping).
2. `deploy/php/davetkart.ini`'nin yeni hâli bir sonraki deploy'la sunucuya gelir; `phpenmod` bağlantısı olduğu için
   yalnızca `systemctl reload php8.5-fpm` (deploy bunu yapıyor). Sonra `php-fpm8.5 -i | grep opcache.validate_timestamps`.
3. Bellek payı kararı: takas dosyası mı, havuzda `memory_limit=192M` mi (kılavuz §3).

## 21.6 — `ARC-06`: hiç fırlatılmayan üç hata kodu

### Kanıt (önce)

| Kod | `app/` içinde `ErrorCode::<Case>` (katalog dışı) | Frontend `src/`, `scripts/`, `e2e/` (çeviri ve sözleşme kopyası dışı) |
|---|---|---|
| `TOKEN_EXPIRED` | 0 | 0 |
| `INVITATION_LOCKED` | 0 | 0 |
| `SLUG_TAKEN` | 0 | 0 |

### Karar (K270, §A boş → varsayılan)

Üçü de silindi. Katalog 31 → 28. Bir kod ancak onu fırlatan kodla birlikte eklenir (mimari test).

### Değişen dosyalar

| Dosya | Ne |
|---|---|
| `app/Enums/ErrorCode.php` | 3 case ve `status()` kolları silindi; K18 cırcırı: 9 uzun blok 1 satıra indi, metinler [`ErrorCode.md`](../app/Enums/ErrorCode.md) "Koddan taşınan notlar" E1–E9 |
| `contracts/error-codes.json` | `php artisan errors:export` → `count: 28` |
| `tests/Unit/ErrorCodeUsageTest.php` | 2 test ([`ErrorCodeUsageTest.md`](../tests/Unit/ErrorCodeUsageTest.md)) |
| `docs/08-HATA-SOZLESMESI.md` §4 | tablo satırları; "iş dili" örneği `INVITATION_ALREADY_PUBLISHED` |
| Frontend (ayrı commit) | `src/contracts/error-codes.json` kopyası (CRLF korundu), `src/locales/{tr,en}/errors.json` üçer anahtar |

### Testler ve mutantlar

Önce kırmızı: `every_error_code_is_used_somewhere_in_app` tam olarak üç kodu listeledi (`TOKEN_EXPIRED`, `INVITATION_LOCKED`,
`SLUG_TAKEN`); `the_removed_codes_stay_removed` kırmızı. Sonra yeşil (2/2).

| Mutant | Sonuç |
|---|---|
| A1 `SLUG_TAKEN` geri eklenir | öldü (iki test) |
| A2 kullanılmayan yeni kod (`ACCOUNT_LOCKED`) | öldü (iki test) |

### B7 tablosu

| İddia | Kanıt (görülen çıktı) |
|---|---|
| Katalog 28 | `INFO 28 hata kodu disari aktarildi: contracts/error-codes.json.` |
| `ErrorCode.php` K18 temiz | `k18: 1 dosya, 0 ihlal` |
| Frontend | `npm run check` çıkış 0, `[tr] 28 kod denetleniyor…`, `✓ Hata sözleşmesi eksiksiz: 28 kod`; `npm run e2e` 6/6 |
| `composer check` | pint passed · phpstan 0 · `errors:export --check` "Katalog guncel" · `k18: 39 dosya, 0 ihlal` · phpunit **1337/1337**, 5245 assertion · çıkış 0 |

### Gördüm, dokunmadım

- `InvitationController.php:107`, `MediaController.php:22`, `RsvpPolicy.php:52` yorumları "INVITATION_LOCKED kuralı
  geldiğinde" diyor. Silinen bir kodu anıyorlar; ama bu üç dosyaya dokunmak K18 cırcırına 10 uzun blok getirirdi
  (`k18:check` 4 + 2 + 4). Ayrı bir K18 temizliğinde düzeltilmeli.
- Eski kılavuzlarda (`FAZ-7.md`, `PublishInvitationAction.md`, `kavramlar/php-dili.md` …) üç kodun adı geçmişin kaydı olarak
  duruyor; yeniden yazılmadı.

## 21.7 — `FE-08`: konum araması (çoğu frontend)

Karar **K271** (varsayılan "kaldır"): backend'de hiç yazılmamış `GET /api/public/places/search` için frontend'de
bayrakla kapalı duran öneri araması silindi; "Ulaşım Bilgileri" alanı haritadan seçim ve bağlantı yapıştırma ile çalışır.
Frontend `fe 8263923`: `services/places.ts`, `hooks/usePlaceSearch.ts`, `PlaceSuggestion` tipi, `VITE_PLACES_SEARCH_ENABLED`
(`.env.example`, `vite-env.d.ts`), `docs/rehber/src/services/places.md`; `LocationSearchField.tsx`'ten yalnızca öneri kısmı
çıktı (dosya "haritadan seç" akışını taşıdığı için silinmedi). Bayrak kapalıyken kanca hep boş döndüğünden ekran değişmedi.
`verify:i18n` tabanı 781 → 780 (yalnızca azalış).

Backend: `deploy/deploy-frontend.sh` artık `VITE_PLACES_SEARCH_ENABLED=false` export etmiyor; kılavuz
[`deploy/deploy-frontend.md`](../deploy/deploy-frontend.md) §4 notu.

| Kapı | Görülen |
|---|---|
| `npm run check` | çıkış 0 · `verify:i18n` 780 parça, 65 dosya · `verify:endpoints` "Tüm uçlar backend sözleşmesiyle eşleşiyor" |
| `npm run e2e` | 6/6 (sihirbaz `createInvitation` "Davet Konumu"nu dolduruyor) |
| `composer check` | pint passed · phpstan 0 · `k18: 39 dosya, 0 ihlal` · phpunit **1337/1337** |

## 21.8 — `FE-03`: TypeScript `strict` (frontend; kısa kayıt)

Backend değişmedi. Frontend `fe a739642`: `tsconfig.json` `"strict": true`; `npx tsc --noEmit --strict`'in 20 hatası (15
`particleEngine.ts`, 2 `PreviewSection.tsx`, 1'er `EditorWorkspace.tsx`, `lazyPresets.ts`, `TemplateRenderer.tsx`) davranış
değişmeden kapandı (`as`/`!` yok). `noUncheckedIndexedAccess` denemesi 124 dosyada 719 hata, kapalı kaldı. `npm run check` 0,
`a11y:envelopes` 0 ihlal (342/373, 11 belirsiz, 20 kendi kapısı: Hat B ile aynı), e2e 6/6. Ayrıntı frontend
`docs/rehber/tsconfig.md`.

## 21.9 — `TST-05`: Node sürümü

Karar **K272** (varsayılan Node 24): frontend `fe 4a640dd` `.nvmrc` = `24`, `package.json` `engines` `>=24 <25` (kilit
kökü dahil), `ci.yml` ve `e2e.yml` `node-version-file`. Backend: `deploy/deploy-frontend.sh` `git pull`'dan sonra
`node --version`'ı `.nvmrc` ile karşılaştırır; uyuşmazsa UYARI, durdurmaz. Kılavuz
[`deploy/deploy-frontend.md`](../deploy/deploy-frontend.md) "Faz 21.9".

| Kapı | Görülen |
|---|---|
| Uyarı parçası | `bash -n` temiz; sahte `node` ile 9 durum (`.nvmrc` yokken çıkan yönlendirme hatası susturulduktan sonra 3'ü yeniden): `24`/`v24.22.1` sessiz, `24`/`v22.11.0`, `v25.0.0`, `v240.1.0`, Node yok → UYARI ve "devam"; `.nvmrc` yok → sessiz |
| `npm run check` | çıkış 0 |
| `composer check` | pint passed · phpstan 0 · `k18: 39 dosya, 0 ihlal` · phpunit **1337/1337** (203 sn; frontend kapısıyla eşzamanlı) · çıkış 0 |
| CI koşusu | **koşmadı** (push İsmail'in) |

İsmail'e: sunucuda `node --version` (beklenen `v24.x`; Y6.5c `v24.22.1`).

## 21.10 — `TST-06`: paralel testler

### Karar (K yok: §A yok)

`brianium/paratest` v7.20.0 (+ `fidry/cpu-core-counter` 1.4.1) dev bağımlılığı; `composer test:parallel` = `config:clear` +
`php artisan test --parallel`. `composer check`'in test adımı **değişmedi** (sıralı). `check`'e alınması İsmail'e soru.

### Ölçüm (27 Kasım, 20 çekirdek)

| Koşu | Sonuç | Süre (duvar) |
|---|---|---|
| Sıralı `php artisan test` | 1337/1337 | 140,4 sn |
| Paralel 1 (`--recreate-databases`) | 1337/1337 | 34,5 sn |
| Paralel 2–4 | 1337/1337 ×3 | 32,8 · 33,3 · 32,3 sn |
| İlk `composer test:parallel` | **Composer 300 sn süre sınırı** (`exceeded the timeout of 300 seconds`); yeniden üretilemedi | — |
| Composer'ın `-d` bayraklarıyla doğrudan | 1337/1338: yeni testin ilk hâli kırmızı (aşağıda), asılma yok | 31 sn |
| Paralel 5 (doğrudan), `composer test:parallel` cmd ve PowerShell yönlendirmesiyle | 1338/1338 ×3 | 31,8 · ~32 · ~30 sn |
| `composer test:parallel` ardışık 3 | 1338/1338 ×3 | 33,8 · 34,4 · 34,7 sn |
| Son hâliyle `composer test:parallel` | 1338/1338 | 33,8 sn |

Kazanç ~4,2×. Kilit testleri (`#[Group('concurrency')]`, alt süreçli `LockRaceTest`) paralelde de yeşil: ayrı sıralı grup
gerekmedi (alt süreç bağlantı adını config'ten okuyor).

### Test

`tests/Feature/ParallelTestDatabaseTest.php` ([kılavuz](../tests/Feature/ParallelTestDatabaseTest.md)): veritabanı trait'li
bir sınıfın paralelde `davetkart_test_test_<token>`'da, sıralıda `davetkart_test`'te koştuğunu config ve `current_database()`
ile sınar. İlk hâli trait'siz `TestSuiteIsolationTest`'e yazıldı ve paralelde kırmızı yandı (`'davetkart_test_test_19'`
beklenirken `'davetkart_test'`): Laravel yalnızca DB trait'li sınıfları ayırıyor. Bu, testin ortak veritabanını
yakaladığının kanıtı oldu; test trait'li ayrı sınıfa taşındı, `TestSuiteIsolationTest` değişmedi (deseni zaten
`davetkart_test(_[a-z0-9_]+)?`). Trait'siz üç sınıf (`HardeningTest`, `HealthTest`, `MaintenanceModeTest`) veritabanına
yazmıyor; bakım testi `array` önbelleğiyle süreç içinde.

### B7 tablosu

| İddia | Kanıt |
|---|---|
| `composer check` (sıralı) | ilk koşu Larastan `env()` kuralında düştü (test `Env::get`'e döndü), ikinci: pint passed · phpstan 0 · `k18: 39 dosya, 0 ihlal` · phpunit **1338/1338** (146 sn) |
| `composer audit` | "No security vulnerability advisories found." |
| `composer validate` | "./composer.json is valid" |

### Gördüm, dokunmadım

- Bir kez görülen 300 sn asılmanın nedeni bulunamadı; sonraki sekiz paralel koşunun hiçbiri asılmadı (biri yeni testin ilk
  hâli yüzünden kırmızı bitti, yedisi yeşil). Toplam yeşil paralel koşu: 11 (5 doğrudan, 6 Composer üzerinden). Composer süre sınırı
  koşuyu kapattığı için sonsuza dek asılmaz (kapalı başarısızlık).
- Bu maddenin ortasında İsmail'in `php artisan serve` ve Vite süreçleri artık çalışmıyordu (ajan durdurmadı; 21.0'da
  açıktılar).

## 21.11 — `TST-03`: satır kapsamı

### Karar (K273, §A boş → varsayılan "ilk ölçümün 3 puan altı")

- Yerelde `pcov`/`xdebug` yüklü değil (`php -m` boş). Herd'in `xdebug-8.5.dll`'i (3.5.0) **kurulmadan**, yalnızca ölçüm için
  `PHP_INI_SCAN_DIR` + `XDEBUG_MODE=coverage` ortam değişkenleriyle yüklendi.
- İlk ölçüm: **%95,9** (1338/1338, `php artisan test --parallel --coverage`, 107 sn). Eşik **92,9**.
- CI (`.github/workflows/ci.yml`): `setup-php` `coverage: pcov`; `composer check`'ten sonra ayrı adım
  `php artisan test --parallel --coverage --min=92.9 --coverage-clover=build/coverage/clover.xml`; clover artefakt olarak
  saklanır. `composer check` değişmedi.

### B7 tablosu

| İddia | Kanıt (görülen çıktı) |
|---|---|
| Ölçüm | `Total: 95.9 %`, paratest 1338/1338 |
| Eşik geçer | `--min=92.9` → çıkış 0; clover 478 KB, 285 dosya |
| Eşik keser | `--min=99` → çıkış 1, `FAIL Code coverage below expected: 95.9 %. Minimum: 99.0 %.` |
| YAML sözdizimi | **koşmadı** (yerelde ayrıştırıcı yok); fark gözle kontrol edildi (boşluk girintisi, sekme yok) |
| CI koşusu (pcov) | **koşmadı** (push İsmail'in) |
| `composer check` | pint passed · phpstan 0 · `k18: 39 dosya, 0 ihlal` · phpunit **1338/1338** |

Kılavuz: [`.github/workflows/ci.md`](../.github/workflows/ci.md) (yerel ölçüm komutu, en düşük sınıflar, İsmail'e kalan).

## 21.12 — `ARC-10`: `OptimizeUploadedImage` bölündü (saf yeniden düzenleme)

### Taban

`--filter "OptimizeUploadedImageTest|MediaTest|LockInventoryTest|DeleteReplaced"`: **90/90** (299 assertion).
`OptimizeUploadedImageTest` 19 test. AUD P54–P55 korumaları: "saklanan dosyada EXIF yok" vardı
(`the_exif_orientation_is_applied_and_the_metadata_is_dropped`, `a_file_with_exif_is_rewritten_even_when_it_does_not_shrink`);
"PHP kuyruğu yok" yoktu → `a_php_trailer_after_exif_is_dropped_with_the_rewrite` eklendi (bugünkü kodda yeşil, davranışı kilitler).

### Bölme

İş 664 → 111 satır, yalnızca sırayı yönetiyor. Yeni sınıflar (CLAUDE.md'ye göre: dış servis değil → `app/Support/Media/`;
veritabanı + dosya + olay + kuyruk birlikte → Action):

| Sınıf | Eski metot(lar) |
|---|---|
| `App\Support\Media\ImageDecoder` | `encodeOptimized()`'ın çözme kısmı |
| `App\Support\Media\ImageResizer` | `downscale()` |
| `App\Support\Media\ExifOrientation` | `applyOrientation()`, `readOrientation()`, `rotate()`, `flip()`, `hasExifBlock()` |
| `App\Support\Media\ImageEncoder` | `compress()`, `encode()`, `targetMimeType()`, `extensionFor()`, `targetBytes()` |
| `App\Support\Media\MemoryBudget` | `withMemoryLimit()` |
| `App\Support\Media\GdAlpha` | `keepAlpha()` |
| `App\Actions\Media\SwapOptimizedMediaFileAction` | `swapFile()`, `newPath()` |

K18: işin 264 satırlık yorumu sınıf kılavuzlarına **aynen** dağıtıldı ("Koddan taşınan notlar"); kodda 1–3 satır.
Log metinleri ve bağlamları (`['media' => id]`) aynı.

### Testler ve mutantlar

Sonra: aynı filtre **91/91** (90 + yeni koruma), yeni `tests/Feature/Media/ImagePipelineTest.php` 15/15. 12 mutant
(sınıf başına en az bir), hepsi öldü:

| Mutant | Öldüren |
|---|---|
| P1 `GdAlpha` karıştırma açık | `gd_alpha_keeps_a_transparent_pixel_through_encoding` |
| P2 palet truecolor'a çevrilmez | `the_decoder_turns_a_palette_png_into_truecolor` |
| P3 genişlik sınırı | birim + `a_portrait_photo_is_reduced_by_its_height` |
| P4 yön 6 ters | `orientation_six_turns_the_picture_ninety_degrees_clockwise` |
| P5 `hasBlock` biçime bakmaz | `only_a_jpeg_with_an_exif_header_counts_as_carrying_exif` |
| P6 PNG hedefi JPEG | birim + `a_png_becomes_a_webp_and_keeps_its_transparency` |
| P7 kalite düşürülmez | birim + `quality_is_lowered_until_the_file_fits_the_target` |
| P8 bellek geri yüklenmez | birim + `the_memory_limit_is_restored_after_the_job` |
| P9 sınırsız da düşürülür | `the_budget_never_lowers_an_unlimited_or_higher_limit` |
| P10 telafi silmesi yok | birim + `a_row_deleted_mid_flight_leaves_no_file_behind` |
| P11 eski dosya silme işi yok | birim + `the_replaced_file_is_scheduled_for_deletion` |
| P12 EXIF'li dosya yeniden yazılmaz | `a_file_with_exif_is_rewritten_even_when_it_does_not_shrink` + yeni P55 testi |

### B7 tablosu

| İddia | Kanıt |
|---|---|
| Uçtan uca aynen yeşil | `tests=91, passed=91, assertions=304` |
| Birim testleri | `tests=15, passed=15, assertions=51` |
| PHPStan (yeni dosyalar) | `phpstan passed, errors 0` |
| `composer check` | ilk koşu PHPStan'de düştü (test yardımcılarında `int<1, max>` ve closure dönüş tipi), ikinci: pint passed · phpstan 0 · `k18: 47 dosya, 0 ihlal` · phpunit **1354/1354**, 5303 assertion |

### Gördüm, dokunmadım

Hedefte, EXIF'siz küçük bir JPEG kısa yoldan dokunulmadan geçer; sonuna eklenmiş PHP kuyruğu dosyada kalır (zararsız:
uzantı sunucu seçimi, Nginx statik sunar). Kapatmak davranış değişikliği olurdu (kılavuz B6).

## 21.13–21.18 — docs ve frontend maddeleri (kısa kayıt)

| Madde | Ne | Nerede |
|---|---|---|
| 21.13 `SEC-18` | Token `localStorage` → `httpOnly` çerez **geçiş planı** (Sanctum SPA çerez kimliği, CSRF, K11 `{user}`, iki yollu geçiş, test planı, ön koşul K175 CSP zorlaması); kod yok. K274 (planlandı) | docs `phases/faz-21/README.md` Ek A |
| 21.14 `OPS-09` | Yatay ölçek eşikleri (FPM, bellek, p95, PostgreSQL, disk, kuyruk, CPU), ikinci sunucuda kırılacaklar (file cache sayaçları ve kilitleri, bakım kipi dosya sürücüsü …), geçiş sırası PostgreSQL → Redis → ikinci sunucu; kod yok | docs `operations/deployment/YAYIN-PLANI.md` §6.1, `IZLEME.md` §6 |
| 21.15 `SEC-17` | `archive/local-snapshots/` 16 dosya, 148 MB: açılmadan listelendi; `.env` içeren 3 arşivin yalnızca anahtar adları; silme ve döndürme İsmail'in | docs `phases/faz-21/README.md` Ek B |
| 21.16 `FE-07` | Frontend `README.md` yazıldı, `CLAUDE.md`'de GenAI satırı ve kurulum komutları koda göre düzeltildi | frontend `fe 2cc799f` |
| 21.17 `ARC-08` | Dal/worktree/etiket listesi ve İsmail'in komutları (hiçbiri koşulmadı) | docs `phases/faz-21/FAZ-21-ARC-08-KOMUTLARI.md` |
| 21.18 `ARC-07` | Frontend `.gitattributes` (`* text=auto eol=lf` + ikili kalıplar); indeks zaten LF, `--renormalize` 0 dosya. K275 | frontend `fe d3a91d1`, `e28b599` |

## Faz 21 kapanış özeti (27 Kasım 2026)

| Madde | Bulgu | Sonuç | K | Commit'ler |
|---|---|---|---|---|
| 21.0 | — | Hazırlık, taban kapıları | — | be 6ffb824 |
| 21.1 | `SEC-16` | Hesap başına başarısız giriş kovası (saatte 30), sahibe uyarı | K265 | be 245a71f |
| 21.2 | `SEC-15` | Kayıt sayım riski kabul; frenler testle kilitli | K266 | be ec850e0 |
| 21.3 | `FE-58` | Otomatik yayın kaldı, K67 daraltıldı, tekrar ziyarette istek yok | K267 | fe ea9d8b1 |
| 21.4 | `OPS-08` | `X-Request-Id` + günlük/kuyruk bağlamı | K268 | be 26943a9 |
| 21.5 | `OPS-07` | PHP-FPM havuzu + OPcache (sunucuda kurulmadı) | K269 | be a803fd3 |
| 21.6 | `ARC-06` | Üç ölü hata kodu silindi (31 → 28) | K270 | be bea73f2 · fe 07b64f4 |
| 21.7 | `FE-08` | Konum önerisi araması kaldırıldı | K271 | fe 8263923 · be 9393dcd |
| 21.8 | `FE-03` | TypeScript `strict` | — | fe a739642 |
| 21.9 | `TST-05` | Node 24 (`.nvmrc`) | K272 | fe 4a640dd · be 86445e4 |
| 21.10 | `TST-06` | `composer test:parallel` (~33 sn) | — | be 0d40881, e307e08 |
| 21.11 | `TST-03` | Satır kapsamı %95,9, CI kapısı 92,9 | K273 | be 5c68e03 |
| 21.12 | `ARC-10` | Görsel işi bölündü (664 → 111) | — | be 05c43be |
| 21.13 | `SEC-18` | plan | K274 | — |
| 21.14 | `OPS-09` | plan | — | — |
| 21.15 | `SEC-17` | liste | — | — |
| 21.16 | `FE-07` | README + CLAUDE.md | — | fe 2cc799f |
| 21.17 | `ARC-08` | liste | — | — |
| 21.18 | `ARC-07` | `.gitattributes` | K275 | fe d3a91d1, e28b599 |

**Bloke olan madde yok.** Kapı düzeltmeleri (her biri ≤3 deneme): 21.1 (PHPStan jenerik, test belleği → `phpunit.xml`
256M), 21.4 (PHPStan `Log::channel`), 21.10 (Larastan `env()`), 21.12 (PHPStan test tipleri).

### Son uçta kapılar (21.90)

| Kapı | Sonuç (görülen çıktı) |
|---|---|
| `composer check` | pint passed · phpstan 0 · `errors:export`, `plans:export`, `env:template` temiz · `k18: 47 dosya, 0 ihlal` · phpunit **1354/1354**, 5303 assertion |
| `composer audit` | "No security vulnerability advisories found." |
| Frontend `npm ci` · `npm run check` · `npm run e2e` | `added 160 packages … found 0 vulnerabilities` · çıkış 0 (28 kod, i18n 780/65) · **6/6** |
| Karar indeksi `--check` | `indeks 237 · tanım 237 · eksik 0 · tanımsız 0 · bekleyen 0` |

Taban → son: phpunit 1306 → 1354 (+48 test), hata kataloğu 31 → 28, i18n tabanı 781 → 780.
