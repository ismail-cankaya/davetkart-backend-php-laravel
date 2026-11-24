# `app/Actions/Account/ConfirmEmailVerificationAction.php`

> **Faz:** 19.14 (`SEC-09`, K191) · **Ana kılavuz:** `SendEmailVerificationAction`, `EmailVerificationTest` · **Test:** `tests/Feature/EmailVerificationTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

İmzası doğrulanmış doğrulama bağlantısıyla hesabın adresini doğrular (`email_verified_at`).

## Neden böyle

Doğrulanmamış hesap ödeme başlatamaz ve yayınlayamaz (K191). Bağlantı tek kullanımlık olmalı: doğrulanmış hesapta ya da adres değişmişse reddedilir.

## Kurallar

- Satır kilitli: aynı bağlantının iki eşzamanlı tıklaması tek doğrulama yapar.
- Hata türleri (`user not found`, `address changed`, `already used`) yalnızca log içindir; istemci hep aynı 422 `EMAIL_VERIFICATION_INVALID`'i görür.

## Sık hata

| Hata | Sonuç |
|---|---|
| Adres parmak izini sınamamak | Adres değiştikten sonra eski bağlantı yeni adresi doğrular |
| Hata nedenini yanıtta söylemek | Bağlantıyı kurcalayan hangi parçanın yanlış olduğunu öğrenir |
