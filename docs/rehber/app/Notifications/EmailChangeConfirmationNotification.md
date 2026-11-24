# `app/Notifications/EmailChangeConfirmationNotification.php`

> **Faz:** 14 (14.10, K144) · **Ana kılavuz:** `RequestEmailChangeAction.md` · **Test:** `tests/Feature/EmailChangeTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Yeni adrese giden onay postası; "Adresimi Onayla" düğmesi imzalı bağlantıyı açar.

## Neden böyle

Bağlantı yapılandırmadaki frontend adresinden kurulur (K180 ilkesi): `ResetPasswordNotification`'daki "reset poisoning" savunması.

## Kurallar

- `ShouldQueue`: kuyruktan gider (15 saniye kuralı; yanıt süresi adresin varlığını sızdırmaz).
- Dil `config('davetkart.mail.locale')`; bağlantılar `davetkart.frontend.url`'den, isteğin Host başlığından değil.
- Geçerlilik süresi `davetkart.auth.email_change_expire_minutes`; onaylanana kadar eski adres kullanılır.

## Sık hata

| Hata | Sonuç |
|---|---|
| Bağlantıyı `url()` ile kurmak | Saldırgan Host başlığıyla onay bağlantısını kendi alanına yönlendirir |
