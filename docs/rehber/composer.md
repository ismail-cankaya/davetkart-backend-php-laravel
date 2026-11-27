# `composer.json` · `composer.lock` — Kılavuz: güvenlik güncellemesi ve `composer audit`

> **Kod dosyaları:** `composer.lock` (19.1'de ve 20.2'de değişti) · `composer.json` (20.3'te yalnızca `sync` betiği eklendi)
> **Faz:** 19.1 — `SEC-07` (`AUD-2026-11-12`) · **Kararlar:** **K179** (güncellemenin kapsamı) ·
> **K181** (yayın adayı = `composer check` ✅ + `composer audit` ✅; K150'yi tamamlar)
> **İlgili:** [`app/Services/Payment/ShopierGateway.md`](app/Services/Payment/ShopierGateway.md) §12 (K180,
> aynı bulgunun kod tarafı) · faz kaydı [`fazlar/FAZ-19.md`](fazlar/FAZ-19.md) §19.1
> **Faz 20.2 (`SEC-51`, 26 Kasım 2026):** aynı yolla ikinci yama → [§9](#9-faz-202-sec-51-symfony-8112) · **Faz 20.3:** `composer sync` → [§10](#10-composer-sync-dal-değiştirince-ya-da-çekince-faz-203)

---

## 1. İki dosya, iki soru

| Dosya | Sorusu | Örnek |
|---|---|---|
| `composer.json` | Hangi sürüm **aralıklarını** kabul ediyorum? | `"laravel/framework": "^13.8"` = 13.8 ≤ sürüm < 14 |
| `composer.lock` | Bugün **tam olarak** hangi sürümü (ve commit'i) kuruyorum? | `laravel/framework v13.43.0` |

Sunucu `composer install` çalıştırır (`deploy/deploy.sh`): lock'u okur, aralıklara bakmaz. Bu yüzden
güvenlik yaması **lock'ta** yaşar. `composer.json`'daki `^13.8` zaten 13.43'ü kapsıyordu; kısıtı
yükseltmek gereksizdi. Lock'taki `content-hash` `composer.json`'un özetidir. 19.1'den önce ve sonra
aynı (`62b132be…`): bu, `composer.json`'un değişmediğinin kanıtı.

## 2. `composer audit` ne yapar, ne yapmaz?

Kurulu her paketin sürümünü Packagist'in güvenlik tavsiyesi veritabanıyla karşılaştırır
(FriendsOfPHP/security-advisories + GitHub Advisory Database). Çıkış `0` = bilinen açık yok, `1` =
en az bir tavsiye var.

- **Ağ ister** (Packagist API). Kodu çalıştırmaz.
- **"Sömürülebilir mi?" sorusunu cevaplamaz.** Yalnızca "bu sürüm etkilenen aralıkta" der. Etki
  değerlendirmesi bizim işimiz (§5). Ama değerlendirme "güncellememek" için gerekçe değildir:
  kırmızı kapı okunmaz hâle gelir (§6).
- **Zamana bağlıdır.** Aynı lock dün yeşil, bugün kırmızı olabilir: denetim günü (13 Kasım) 8 açık
  vardı; aynı gün akşam Laravel'in tavsiyesi yayımlandı ve sayı 9 oldu.
- Composer 2.9 `update` sırasında tavsiyeli sürümleri **seçmez** (`policy.advisories.block`). Çözüm
  hatasında "*… were not loaded, because they are affected by security advisories*" iletisi bu demek;
  listelenen sürümlerin hepsi tavsiyeli değildir (§3'teki tuzak).

## 3. 19.1'de ne yapıldı (`SEC-07`)

```powershell
composer update "symfony/*" laravel/framework --with-all-dependencies `
  --with "guzzlehttp/psr7:^2.13" --with "guzzlehttp/uri-template:^1.0.10" --with "brick/math:^0.18"
```

| Parça | Neden |
|---|---|
| `"symfony/*" laravel/framework` | Açık taşıyan altı paket bunların içinde. Raporun önerisi (§A-1 boş kaldı, varsayılan uygulandı). |
| `--with-all-dependencies` (`-W`) | Onsuz çözülmüyor: Laravel 13.40+ `laravel/prompts ^0.3.25` istiyor, lock'ta 0.3.21 vardı (*"fixed to v0.3.21 (lock file version) by a partial update"*). |
| `--with …` (üç geçici kısıt) | `-W` tek başına dolaylı bağımlılıklarda **majör** atlatıyordu: `guzzlehttp/guzzle` 7.15.2 → 8.5.2, `psr7` 2.13 → 3.5, `promises` 2.5 → 3.1, `uri-template` 1.0.10 → 2.0.1, `brick/math` 0.18 → 1.0 (+ `ralouphie/getallheaders` kalkıyordu). Güvenlik yaması majör getirmez (**K179**). `--with` kısıtı `composer.json`'a yazılmaz; yalnızca bu çözüm için geçerlidir. |

Neden üç kısıt yetti? Guzzle 8'i çeken `psr7 3`; `psr7 ^2.13` deyince Guzzle 7 hattında kaldı
(7.15.4) ve `promises` 2.x'te (2.6.1). `uri-template` ve `brick/math` ayrı ayrı majör atlıyordu.

🔴 **Tuzak 1, düşürme:** ilk denemede `uri-template:^1.0` yazıldı ve çözücü paketi 1.0.10'dan
**1.0.0'a düşürdü** (Packagist'te bu paket için tavsiye yok; sebep tavsiye değil, çözücünün tercihi).
Alt sınırı bugünkü sürüm yap: `^1.0.10`. Kuru koşuda `Downgrading` satırı var mı, bak.

🔴 **Tuzak 2, yanıltıcı ileti:** `--with "guzzlehttp/guzzle:^7.15"` çözülmedi ve ileti bütün Guzzle
sürümlerini "tavsiyeli" diye listeledi. Gerçekte tavsiyeli olanlar `< 7.15.2` (Packagist API'si 20
Kasım'da sorgulandı); 7.15.4 temiz ve `psr7` kısıtıyla seçildi.

⚠️ **Bir sonraki düz `composer update` bu majörleri getirir.** Guzzle 8 / PSR-7 3 / `brick/math` 1.0
geçişi ayrı bir iş (açık karar, [`fazlar/FAZ-19.md`](fazlar/FAZ-19.md) §19.1). Etkilenecek yerler:
Laravel HTTP istemcisi (`app/Services/Ai/GeminiProvider.php`, `Http::`), AWS SDK (S3 medya diski),
Sentry (`psr7`), Laravel'in `Number` yardımcısı (`brick/math`).

## 4. Değişen sürümler (55 paket; `composer.json` ve dev paketleri değişmedi)

| Paket | Önce | Sonra |
|---|---|---|
| `carbonphp/carbon-doctrine-types` | 3.2.0 | 3.2.1 |
| `doctrine/inflector` | 2.1.0 | 2.1.1 |
| `doctrine/lexer` | 3.0.1 | 3.0.3 |
| `dragonmantank/cron-expression` | v3.6.0 | v3.6.1 |
| `graham-campbell/result-type` | v1.1.4 | v1.2.0 |
| `guzzlehttp/guzzle` | 7.15.2 | 7.15.4 |
| `guzzlehttp/promises` | 2.5.1 | 2.6.1 |
| **`laravel/framework`** | **v13.35.0** | **v13.43.0** |
| `laravel/prompts` | v0.3.21 | v0.3.27 |
| `laravel/serializable-closure` | v2.0.15 | v2.2.0 |
| `league/commonmark` | 2.10.3 | 2.10.7 |
| `league/flysystem-local` | 3.31.0 | 3.35.3 |
| `league/uri` | 7.8.1 | 7.10.0 |
| `league/uri-interfaces` | 7.8.1 | 7.10.0 |
| `monolog/monolog` | 3.10.0 | 3.13.0 |
| `nesbot/carbon` | 3.13.1 | 3.15.1 |
| `nette/schema` | v1.3.5 | v1.4.0 |
| `nette/utils` | v4.1.5 | v4.1.6 |
| `nunomaduro/termwind` | v2.4.0 | v2.4.1 |
| `phpoption/phpoption` | 1.9.5 | 1.10.0 |
| `ramsey/uuid` | 4.9.3 | 4.10.0 |
| `symfony/clock` | v8.1.0 | v8.1.9 |
| `symfony/console` | v8.1.1 | v8.1.11 |
| `symfony/css-selector` | v8.1.0 | v8.1.10 |
| `symfony/deprecation-contracts` | v3.7.1 | v3.8.1 |
| `symfony/error-handler` | v8.1.0 | v8.1.10 |
| `symfony/event-dispatcher` | v8.1.1 | v8.1.5 |
| `symfony/event-dispatcher-contracts` | v3.7.1 | v3.8.1 |
| **`symfony/filesystem`** | **v8.1.9** | **v8.1.11** |
| `symfony/finder` | v8.1.1 | v8.1.11 |
| **`symfony/http-foundation`** | **v8.1.9** | **v8.1.11** |
| **`symfony/http-kernel`** | **v8.1.1** | **v8.1.11** |
| `symfony/mailer` | v8.1.1 | v8.1.10 |
| **`symfony/mime`** | **v8.1.0** | **v8.1.10** |
| `symfony/options-resolver` | v8.1.0 | v8.1.10 |
| `symfony/polyfill-intl-grapheme` | v1.41.0 | v1.43.3 |
| `symfony/polyfill-intl-idn` | v1.38.1 | v1.43.0 |
| `symfony/polyfill-intl-normalizer` | v1.38.0 | v1.43.0 |
| `symfony/polyfill-mbstring` | v1.38.2 | v1.43.3 |
| `symfony/polyfill-php80` | v1.37.0 | v1.43.0 |
| `symfony/polyfill-php84` | v1.38.1 | v1.43.0 |
| `symfony/polyfill-php85` | v1.41.0 | v1.44.0 |
| `symfony/polyfill-php86` | v1.41.0 | v1.43.0 |
| `symfony/polyfill-time` | — (yeni) | v1.42.0 |
| `symfony/polyfill-uuid` | v1.37.0 | v1.43.0 |
| `symfony/process` | v8.1.0 | v8.1.10 |
| `symfony/psr-http-message-bridge` | v8.1.0 | v8.1.9 |
| **`symfony/routing`** | **v8.1.0** | **v8.1.11** |
| `symfony/service-contracts` | v3.7.1 | v3.8.1 |
| `symfony/string` | v8.1.0 | v8.1.10 |
| `symfony/translation` | v8.1.1 | v8.1.11 |
| `symfony/translation-contracts` | v3.7.1 | v3.8.1 |
| `symfony/uid` | v8.1.0 | v8.1.9 |
| `symfony/var-dumper` | v8.1.1 | v8.1.10 |
| `vlucas/phpdotenv` | v5.6.4 | v5.8.0 |

Kalın satırlar açık taşıyan altı paket. Hepsi küçük sürüm ya da yama; majör yok.

## 5. Dokuz açık ve bizdeki karşılığı

"Bizde" sütunu **kod okumasıdır** (19.1'de `grep`), sömürü denemesi değil.

| Paket | Tavsiye | Düzeldiği sürüm | Bizde |
|---|---|---|---|
| `laravel/framework` | CVE-2026-128083 `Concurrency` süreç çıktısı enjeksiyonu (düşük) | 13.40.0 | `Concurrency` kullanılmıyor (yalnızca çerçevenin kendi sağlayıcı kaydı) |
| `symfony/http-foundation` | Üretilen URL'ler istemcinin seçtiği host'u gösterebilir | 8.1.11 | 🔴 `ShopierGateway` callback'i `url()` ile üretiyordu → **K180** ([§12](app/Services/Payment/ShopierGateway.md)) |
| `symfony/http-foundation` | `private_ranges` güvenilen vekil kısayolu IPv4-mapped / 6to4 / Teredo / NAT64 istemcilere güveniyor | 8.1.11 | `TRUSTED_PROXIES` boş, `private_ranges` kullanılmıyor |
| `symfony/http-foundation` | CVE-2026-118514 HEAD istekleri `access_control` `methods` kuralını atlatıyor | 8.1.11 | Symfony Security bileşeni yok (Laravel) |
| `symfony/http-foundation` | PHP önündeki yol kuralları betik adına yol eklenerek atlatılabilir | 8.1.11 | Nginx yalnızca `index.php`'yi çalıştırıyor (denetimin değerlendirmesi) |
| `symfony/http-kernel` | CVE-2026-123339 inline fragment'ler iletilen başlıklarla host/şema belirliyor | 8.1.11 | Fragment (ESI / inline) kullanılmıyor |
| `symfony/routing` | CVE-2026-118516 host yer tutucusu yolun başıyla eşleşebiliyor | 8.1.11 | Rotalarda host kısıtı (`->domain()`) yok |
| `symfony/mime` | CVE-2026-45070 parametre adlarında e-posta başlık enjeksiyonu (orta) | 8.1.10 | Postalar Symfony Mailer ile gidiyor; parametreli başlığı biz kurmuyoruz |
| `symfony/filesystem` | `://` öncesi her önek kök sayılıyor; yol taban dizinden kaçabiliyor | 8.1.11 | `Symfony\Component\Filesystem` doğrudan kullanılmıyor |

Dokuzundan biri bizim kodumuza dokunuyordu ve o satır ayrıca düzeltildi (K180). Paket güncellemesi
Symfony'nin açığını kapatır, ama `url()`'nin isteğin host'unu kullanması bir açık değil, tasarımdır.

## 6. Kapı tanımı: yayın adayı = `composer check` ✅ **ve** `composer audit` ✅ (K181)

K150 "kalite kapısı kırmızı olan dal yayın adayı değildir" diyordu; K181 kapıyı ikiye ayırır ve
ikisini de şart koşar. `composer audit` **`composer check` zincirine girmez**:

| Neden ayrı? | |
|---|---|
| Ağ ister | Ağ yoksa `check` kırmızı olur ve "kod bozuk" ile "Packagist'e ulaşılamıyor" karışır. 19.1'de GitHub bu makineden 40 dakikadan uzun erişilemedi (12:40–13:22); `check` yine koşabildi (eski `vendor` ile). |
| Zamana bağlı | `check` deterministiktir: aynı commit → aynı sonuç. `audit` değildir: yeni tavsiye yayımlanınca aynı commit kırmızıya döner. İkisini birleştirmek "dün geçen commit bugün neden kırmızı?" sorusunu kalite kapısına taşır. |
| CI'da zaten ayrı | `.github/workflows/ci.yml` → "Composer güvenlik taraması" adımı; özet ekranında hangisinin patladığı tek bakışta görünür. |
| Yayında ikisi de şart | Docs `operations/deployment/YAYIN-SIRASI.md` §2: 1. madde `composer check`, 2. madde `composer audit`. |

🔴 Kırmızıya alışma: `policy.advisories.ignore` ile bir tavsiyeyi susturmak ancak **yazılı bir
gerekçeyle** (K kaydı, bitiş tarihi) kabul edilebilir. "Bizi etkilemiyor" tek başına gerekçe değil;
§5'teki tablo bile dokuz satırdan birinin bize dokunduğunu gösterdi.

## 7. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Güvenlik yaması için argümansız `composer update` | Her paket güncellenir; majörler (Guzzle 8) sessizce gelir |
| Kuru koşu (`--dry-run`) yapmadan güncellemek | Majör ve `Downgrading` satırları ancak sonradan görülür |
| `composer.json`'da kısıtı yükseltmek | Gereksiz; aralık zaten kapsıyor. Lock yeter |
| Lock'u güncelleyip `vendor`'u güncellememek | Testler eski kodla koşar. 19.1'de ağ kesilince oldu: lock yazıldı, indirmeler yarıda kaldı. `composer install --dry-run` "Nothing to install" demeli |
| `composer audit`'i `composer check`'e eklemek | Kapı ağa ve zamana bağlanır (§6) |
| Sunucudaki `composer audit --no-dev` yeşilse yerel de yeşil sanmak | `--no-dev` dev paketlerini saymaz; ikisi farklı listeler |

## 8. Kendin dene

```powershell
composer audit                                   # çıkış 0: "No security vulnerability advisories found."
composer show laravel/framework | Select-String '^versions'
composer install --dry-run                       # lock = vendor mi? "Nothing to install, update or remove"
composer update "symfony/*" laravel/framework -W --dry-run   # hiçbir şeyi değiştirmez; majörleri görürsün
```

`--locked` kurulu paketlere değil **lock dosyasına** bakar: `vendor` güncellenmeden de yeni lock'un
temiz olup olmadığını söyler (19.1'de ağ kesikken böyle görüldü). Kapının kırmızıyı gerçekten
yakaladığını gör, eski lock'a karşı, kurulu paketlere dokunmadan:

```powershell
$d = Join-Path $env:TEMP 'eski-lock'; New-Item -ItemType Directory -Force $d | Out-Null
Copy-Item composer.json $d
# cmd'nin '>'u baytları olduğu gibi yazar; PowerShell 5.1'in boru hattı dosyayı yeniden kodlar (BOM)
cmd /c "git show faz-18-audit-fixes:composer.lock > `"$d\composer.lock`""
composer audit --locked -d $d                    # çıkış 1: "Found 9 security vulnerability advisories affecting 6 packages"
composer audit --locked                          # bu dalın lock'u: çıkış 0
Remove-Item -Recurse -Force $d
```

## 9. Faz 20.2 (`SEC-51`): Symfony 8.1.12

26 Kasım 2026'da `composer audit` yeniden kırmızıydı: 25 Kasım'da yayımlanan **5 bildirim, 3 paket**. Kilit 19.1'den
beri aynıydı; değişen, açık listesiydi. "Dün yeşildi" bugün yeşil demek değil: kapı (K181) her yayından önce koşar.

| Paket | CVE | Konu |
|---|---|---|
| `symfony/http-kernel` | CVE-2026-133743 | Satır içi fragment'ta güvenilmeyen `Forwarded` başlıkları `asset()`/`absolute_url()` adresini değiştirir (önceki yamanın eksiği) |
| `symfony/mailer` | CVE-2026-133751 · CVE-2026-133755 | `require_tls` araya giren biri tarafından atlatılabilir · STARTTLS'ten hemen sonra enjekte edilen yanıta güvenilir |
| `symfony/routing` | CVE-2026-133746 · CVE-2026-133754 | `UrlGenerator` başka bir host'a adres üretebilir (önceki yamanın eksiği; host yer tutucusu) |

Komut K179'un aynısı, kuru koşudan sonra: `composer update "symfony/*" -W`. Sonuç **yalnızca `composer.lock`**:
14 paket yama sürümü (Symfony 8.1.9–8.1.11 → 8.1.12, `egulias/email-validator` 4.0.4 → 4.0.5); majör yok,
`composer.json` değişmedi. Sonra `composer audit` → "No security vulnerability advisories found.", `composer check` yeşil.

## 10. `composer sync`: dal değiştirince ya da çekince (Faz 20.3)

```powershell
composer sync      # composer install + php artisan migrate (yerel veritabanı)
```

Neden ayrı bir komut: 26 Kasım'da (`AUD-2026-11-26`, `OPS-10`/`OPS-11`) ana klasörün `vendor/`'ı kilidin 55 paket,
veritabanı 4 göç gerisindeydi. Kapılar test veritabanında koştuğu için yeşildi; Hesap Ayarları'nın üç sayfası ise 500
veriyordu. `git pull`, `git switch` ya da birleştirmeden sonra bu tek komut ikisini birden eşitler.

- **Canlıda kullanılmaz:** canlının sırası `deploy/deploy.sh`'tedir (`--no-dev`, önbellek, `--force`). `APP_ENV=production`
  iken `migrate` onay ister ve `--no-interaction` altında durur.
- Göç durursa (ör. `2026_11_16_100000` çakışan veri bulursa) komut kırmızı biter; mesajdaki kılavuza bak, veriyi elle karar ver.
- **Frontend için karşılığı:** Vite'ı durdur (`Ctrl+C`), sonra `npm ci`. Vite açıkken `npm ci` Windows'ta kilitli bir
  `.node` dosyasında `EPERM` ile yarıda kalır ve `node_modules`'ü yarım bırakır (26 Kasım'da oldu; `npm install` onardı).
- Eşit mi? `composer install --dry-run` → "Nothing to install, update or remove" · `php artisan migrate:status --pending`
  → "No pending migrations" · `/api/health` yanıtında `schema` yok (K263).


## 11. `composer setup` mevcut kurulumda anahtarı ezmez (Faz 20.6, `OPS-13`)

Laravel iskeletinin `setup` betiği `php artisan key:generate`'i her koşuda çalıştırıyordu. Komut yalnızca
`APP_ENV=production`'da onay ister; yerelde **sorusuz** yeni `APP_KEY` yazar. Anahtara bağlı olanlar bir anda
geçersiz olur: `IpHasher` hash'leri (hız sınırı ve tekilleştirme kovaları), imzalı e-posta değişikliği ve doğrulama
bağlantıları, sahte ödeme imzaları (`FakeGateway`), şifreli çerezler.

Şimdi betik önce `.env`'e bakar: `APP_KEY=` satırında boşluk olmayan bir değer varsa (tırnaklı dahil) hiçbir şey
yapmaz; boşsa ya da yalnızca boşluksa `key:generate --ansi` çalışır. İlk kurulumda (`.env.example`'dan kopya,
anahtar boş) davranış aynı. Desende `\S` yerine `[^[:space:]]` var: `composer.json` içinde ters bölüye gerek kalmasın
ve CRLF'li `.env`'de `\r` "dolu" sayılmasın.

| Hata | Neden yanlış |
|---|---|
| "Ortamı eşitlemek" için `composer setup` | Eşitleme `composer sync`'tir (§10); `setup` ilk kurulum içindir |
| Anahtarı elle değiştirip "oturumlar düştü" diye şaşırmak | Anahtar değişince imzalı ve şifreli her şey geçersizdir; bilerek yapılır (sızıntıdan sonra döndürme) |

## 12. `composer test:parallel` (Faz 21.10, `TST-06`)

```powershell
composer test:parallel      # config:clear + php artisan test --parallel (paratest, çekirdek başına süreç)
```

| | Sıralı (`composer test`, `check` içindeki adım) | Paralel (`composer test:parallel`) |
|---|---|---|
| Süre (27 Kasım, 20 çekirdek, 1338 test) | ~140 sn | ~32–35 sn |
| Veritabanı | `davetkart_test` | süreç başına `davetkart_test_test_<n>` |
| Kapı | **evet** (`composer check`) | hayır: yalnızca hızlı geri bildirim |

`brianium/paratest` (v7.20, PHPUnit 12) dev bağımlılığı; `composer audit` temiz. `check`'e alınması İsmail'in kararı:
paralel koşu 11 kez yeşil (beşi doğrudan `php artisan test --parallel`, altısı `composer test:parallel`, 30–35 sn); bir kez,
ilk `composer test:parallel` denemesinde, Composer'ın 300 sn süre sınırına takıldı ve yeniden üretilemedi. Ayrıntı: `fazlar/FAZ-21.md` §21.10.
