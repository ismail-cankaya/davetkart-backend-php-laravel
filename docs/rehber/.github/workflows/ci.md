# `.github/workflows/ci.yml` — backend CI

> Bu kılavuz Faz 21.11'de açıldı (`TST-03`, K273); iş akışının geri kalanı dosyanın kendi yorumlarında anlatılıyor.

## Adımlar

| Adım | Ne | Kapı mı? |
|---|---|---|
| `composer check` | pint, PHPStan, `errors:export`/`plans:export`/`env:template --check`, `k18:check`, testler (sıralı) | evet |
| **Satır kapsamı (pcov, paralel)** | `php artisan test --parallel --coverage --min=92.9 --coverage-clover=build/coverage/clover.xml` | evet: kapsam %92,9'un altına düşerse kırmızı |
| Kapsam raporunu sakla | `build/coverage/clover.xml` → `coverage-clover` artefaktı (`if: always()`) | hayır |
| `composer audit` | bilinen güvenlik açığı | evet |

## Satır kapsamı (Faz 21.11)

**Neden?** "Bu satır hiç koşmadı" bilgisi hiçbir yerde üretilmiyordu; yeni bir Action testsiz eklense kapı görmezdi.
Kapsam testlerin iyi olduğunu söylemez (bunu mutantlar söyler), ama nereye bakılacağını söyler.

**İlk ölçüm (27 Kasım):** **%95,9** satır kapsamı, 1338/1338 test. Yerelde Herd'in xdebug'ı (3.5.0) yalnızca bu ölçüm
için ortam değişkenleriyle yüklendi, kurulmadı:

```powershell
$d = "$env:TEMP\xdebug-scan"; New-Item -ItemType Directory -Force $d | Out-Null
Set-Content "$d\zz-xdebug.ini" 'zend_extension="C:\Program Files\Herd\resources\app.asar.unpacked\resources\bin\xdebug\xdebug-8.5.dll"' -Encoding ascii
$env:PHP_INI_SCAN_DIR = $d; $env:XDEBUG_MODE = 'coverage'
php artisan test --parallel --coverage          # ~110 sn (20 çekirdek)
Remove-Item Env:\PHP_INI_SCAN_DIR; Remove-Item Env:\XDEBUG_MODE
```

`php -d zend_extension=…` işe yaramaz: `php artisan test` PHPUnit'i ayrı süreçte başlatır, `-d` alt sürece geçmez; ortam
değişkeni geçer. Kalıcı kurulum Herd arayüzünden (Herd Pro: "Debug → Xdebug") yapılır; bu fazda yapılmadı.

**Eşik:** K273 kuralı "ilk ölçümün 3 puan altı": 95,9 − 3 = **92,9**. Ölçüm xdebug'la, CI pcov'la; ikisi de PHP'nin aynı
"çalıştırılabilir satır" bilgisini kullanır, fark beklenmez ama ilk CI koşusunda bakılmalı.

**Neden ayrı adım, `composer check` içinde değil?** Plan `check`'in değişmemesini istiyor; kapsam yalnızca kapsam
sürücüsü olan ortamda (CI) çalışır. Adım `--parallel` koşar, yani testleri ikinci kez koşmanın bedeli düşük (yerelde
~110 sn xdebug ile; pcov daha hızlı).

**En düşük sınıflar (ilk ölçüm):** `ExportErrorCodes` %0 (komut testlerde değil `composer check`'te koşuyor),
`EmailChangeConfirmationNotification` %18,2, `GiftCodeQuote` %33,3, `EmailChangedNotification` %46,2, `NullProvider` %50.
Birkaç enum %0 görünüyor: çalıştırılabilir satırları yok ya da yalnızca sabit taşıyor.

## İsmail'e kalan

1. Push sonrası ilk CI koşusunda "Satır kapsamı" adımının yüzdesine bakmak (pcov). %92,9'un altındaysa ölçüm farkıdır:
   eşik K273'e not düşülerek CI değerine göre yeniden belirlenir.
2. Kalıcı yerel kapsam isteniyorsa Herd'den xdebug'ı açmak.
