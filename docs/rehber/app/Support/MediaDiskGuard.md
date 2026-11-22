# `app/Support/MediaDiskGuard.php`

> **Kod dosyaları:** `MediaDiskGuard.php` · `app/Exceptions/MediaStorageUnavailableException.php` · çağrı yeri `app/Actions/Media/StoreUploadedMediaAction.php` · `about` satırı `AppServiceProvider::configureAboutCommand()`
> **Faz:** 19.5 (`ARC-04`, K183) · **Test:** `tests/Feature/MediaDiskGuardTest.php`

## Neden

`DAVETKART_MEDIA_DISK` varsayılanı `public` (yerel geliştirme için doğru). Üretim `.env`'inde değişken
unutulursa misafir fotoğrafları sessizce sunucunun kendi diskine, web kökünün altına yazılırdı:
sunucu yok edilebilir olmaktan çıkar (yayın planı: "medya ve yedek S3'te") ve 5 GB/davetiye kota
tek diski doldurabilir. K70/K123 ilkesi depolamaya da uygulanır: yapılandırma hatası **gürültülü** olmalı.

## Desen

- Kural tek yerde: `refusesUploads()` = `app()->isProduction() && disk !== 's3'`. Allowlist; yalnızca
  `public`'i yasaklamak `local` gibi başka bir yanlış değeri geçirirdi.
- Çağrı `StoreUploadedMediaAction::handle()`'ın **ilk satırında**: sahip ve misafir yüklemesi aynı
  Action'dan geçer, kontrol diske tek bayt yazılmadan önce yapılır.
- Ret: `Log::critical` + `MediaStorageUnavailableException` → `PROVIDER_UNAVAILABLE` (503, `retryAfter`).
  Yeni hata kodu yok; ödeme sürücüsündeki "bu serviste yapılandırılmamış" ile aynı anlam.
- Okuma uçları etkilenmez: `media.disk` satırda saklanır (F4), eski `public` dosyalar sunulmaya devam eder.
- `php artisan about` → **DavetKart / Media disk** satırı: `s3` ya da `public (UPLOADS REFUSED …)`.

## Neden açılışta değil ret anında log

PHP-FPM her istekte uygulamayı yeniden açar; açılışta `Log::critical` her isteği (sağlık sondası dahil)
bir alarma çevirirdi. Ret anında log, alarmı gerçek zarar girişimine bağlar; açılış görünürlüğü
`about` satırıyla sağlanır. (Plandan sapma, K183 kaydında.)

## Hangi hataya karşı

Unutulmuş ortam değişkeni → sessiz yerel disk. Yanlış yazılmış disk adı (`S3`, `local`) → aynı sonuç.

## Sık hata

- Kontrolü controller'a koymak: misafir ucu (`PublicMediaController`) atlanır.
- Kontrolü `config/davetkart.php` içinde yapmak: config önbelleğe alınır, ortam sorusu orada sorulmaz.
- Testte `detectEnvironment` yerine `Config::set('app.env')`: `isProduction()` onu okumaz.

## Nasıl denenir

```bash
php artisan test --compact --filter=MediaDiskGuardTest
php artisan about --only=davetkart
```

Mutantlar: ortam kontrolünü kaldır → `local_environment_still_uses_the_public_disk` kırmızı;
Action'daki çağrıyı kaldır → `production_refuses_the_public_media_disk_for_uploads` kırmızı.
