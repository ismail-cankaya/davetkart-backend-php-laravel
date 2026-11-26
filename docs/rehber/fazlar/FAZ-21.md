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
