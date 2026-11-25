# `ResolvesRefundRequests` — Kılavuz

> **Kod dosyası:** `app/Console/Commands/Concerns/ResolvesRefundRequests.php`
> **Faz:** 14.6 (iade komutları) · tip daraltması Faz 19 ortak düzeltme (B-H1)
> **Kullananlar:** `refunds:approve`, `refunds:reject` — ayrıntı: [`../RefundCommands.md`](../RefundCommands.md)

## Ne yapar?

İki komutun ortak adımı: `id` argümanını ve `--note` seçeneğini okur, notu kırpar (en çok 500 karakter),
`ResolveRefundRequestAction`'ı istenen kararla (`approved` / `rejected`) çağırır, sonucu yazar.
Action'ın `RuntimeException`'ı (talep yok, zaten karara bağlanmış…) hata satırı + çıkış 1 olur.

## Neden trait, neden kalıtım değil?

İki komut birbirinin özel hâli değil; aynı adımı paylaşan iki ayrı komut. Biri diğerinden türeseydi
onayın "Shopier'den gönderdiniz mi?" sorusu ret komutuna da miras kalırdı.

## Neden `(string) $id` değil, `is_string`?

`Command::argument()` PHPStan'e göre `array|bool|float|int|string|null` döner. `(string)` dönüşümü bir
dizi geldiğinde sessizce `"Array"` üretir ve PHPStan (level 8) bunu `cast.string` hatası sayar.
`id` imzada zorunlu olduğu için pratikte hep string gelir; yine de tip **daraltılır**, gelmezse komut
kapalı başarısız olur (`Talep kimligi gerekli.`, çıkış 1). `ignoreErrors` ya da cast ile susturulmaz.

## Nasıl denenir?

```powershell
php artisan test --compact tests/Feature/RefundCommandsTest.php
$env:PHPSTAN_TURBO='0'; vendor\bin\phpstan analyse app/Console/Commands/Concerns/ResolvesRefundRequests.php
```
