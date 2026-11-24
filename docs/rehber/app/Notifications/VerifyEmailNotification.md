# `app/Notifications/VerifyEmailNotification.php`

> **Faz:** 19.14 (`SEC-09`, K191) · **Ana kılavuz:** `SendEmailVerificationAction.md` · **Test:** `tests/Feature/EmailVerificationTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Kayıt sonrası doğrulama postası; "Adresimi Doğrula" düğmesi imzalı bağlantıyı açar.

## Neden böyle

Doğrulanmamış hesap yayınlayamaz ve ödeme başlatamaz; posta bunun nedenini ve süresini söyler.

## Kurallar

- `ShouldQueue`: kuyruktan gider (15 saniye kuralı; yanıt süresi adresin varlığını sızdırmaz).
- Dil `config('davetkart.mail.locale')`; bağlantılar `davetkart.frontend.url`'den, isteğin Host başlığından değil.
- Süre `davetkart.auth.email_verification_expire_minutes`'tan saate çevrilir (24 saat).

## Sık hata

| Hata | Sonuç |
|---|---|
| Süreyi metne sabit yazmak | Config değişince posta yalan söyler |
