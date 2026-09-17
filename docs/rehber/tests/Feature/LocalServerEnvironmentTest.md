# `tests/Feature/LocalServerEnvironmentTest.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `tests/Feature/LocalServerEnvironmentTest.php`
> **Bağlantılı:** [`AppServiceProvider.md`](../../app/Providers/AppServiceProvider.md) — `configureLocalServer()`

---

## 0. Bir dakikalık özet

Windows'ta `php artisan serve` altında her dosya yüklemesi PHP'nin geçici
klasör hatasıyla düşüyordu. Düzeltme, `TEMP`/`TMP`/`TMPDIR` değişkenlerini
serve komutunun izin listesine ekler. Bu test, düzeltmenin **yerinde
durduğunu** sınar.

| Test | Soru |
|---|---|
| `artisan_serve_passes_the_temp_directory_to_the_php_server` | Geçici klasör değişkenleri listede mi? |
| `the_framework_defaults_are_kept` | Laravel'in kendi listesi (`APP_ENV`, `PATH`, `SYSTEMROOT`) korunuyor mu, tekrar eden değer var mı? |

---

## 1. 🔴 Neden davranış değil yapılandırma sınanıyor?

Projenin kuralı "metin değil **davranış** doğrulanır" (T5). Burada istisna
yapıldı, çünkü davranış bir testte **görünmez**:

- `$this->post(..., ['file' => UploadedFile::fake()->image(...)])` dosyayı
  PHP'nin yükleme katmanından geçirmez. Dosya doğrudan isteğe konur, geçici
  klasör hiç kullanılmaz.
- Hatayı yeniden üretmek için gerçek bir `php artisan serve` süreci başlatıp
  ona çok parçalı (multipart) bir istek göndermek gerekir. Bu yavaş, işletim
  sistemine bağlı ve kararsız bir testtir.

Bu yüzden hatanın **nedeni** (liste) sınanır. Uçtan uca doğrulama bir kez elle
yapıldı ve `AppServiceProvider.md`'de kayıtlıdır.

## 2. İkinci test neden var?

`ServeCommand::$passthroughVariables` **statik** bir özelliktir ve PHP süreci
boyunca yaşar. Test paketi uygulamayı her testte yeniden kurar, yani
`configureLocalServer()` tek süreçte yüzlerce kez çalışır. `array_unique`
olmasaydı liste her testte büyürdü. Test bunu ve listenin **ezilmediğini**
(yalnızca eklendiğini) sınar.
