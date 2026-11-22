# `app/Support/ProductionMailCheck.php`

> **Faz:** 19.6 (`OPS-04`, K185) · **Bağlantı:** `AppServiceProvider::configureMailCheck()` ve `configureAboutCommand()` · **Test:** `tests/Feature/ProductionConfigTest.php`

## Neden

`MAIL_MAILER=log` maili hata vermeden `laravel.log`'a yazar. Üretimde parola sıfırlama maili hiç gitmez,
kullanıcı "mail gelmedi" der, sunucuda hata yoktur. Ödeme ve medya diskinden farklı olarak burada
fail-closed **değil**, görünür: posta gitmemesi giriş, davetiye ve LCV'yi durdurmamalı (OPS-04 §A-2 varsayılanı).

## Desen

- Kural tek yerde: `isSilent()` = `app()->isProduction() && config('mail.default') === 'log'`.
- `MessageSending` olayında `reportIfSilent()` → `Log::critical('Mail not delivered: production mailer is log')`.
  `LOG_STACK=daily,sentry` olduğu için Sentry'ye de düşer. Bağlamda alıcı **yok** (KVKK), yalnızca sürücü adı.
- `php artisan about --only=davetkart` → **Mail mailer** satırı: `ses` ya da `log (MAIL IS NOT DELIVERED …)`.

## Neden açılışta değil gönderim anında

PHP-FPM her istekte, zamanlayıcı her dakika uygulamayı açar. Açılışta `Log::critical` her isteği bir
Sentry olayına çevirirdi. Gönderim anındaki alarm gerçek kayba bağlıdır (K183 ile aynı gerekçe, plandan sapma).

## Sık hata

- Kontrolü `config/mail.php`'de yapmak: config önbelleğe alınır, ortam sorusu orada sorulmaz.
- Testte gerçek maili `Log::spy()` ile göndermek: `log` taşıyıcısı spy'dan logger alamaz ve çöker.
  Test olayı doğrudan tetikler (`event(new MessageSending(...))`).

## Nasıl denenir

```bash
php artisan test --compact --filter=ProductionConfigTest
php artisan about --only=davetkart
```

Mutant: ortam kontrolünü kaldır → `the_log_mailer_is_quiet_outside_production` kırmızı.
