# `app/Actions/Account/SendEmailVerificationAction.php`

> **Faz:** 19.14 (`SEC-09`, K191) · **Ana kılavuz:** `ConfirmEmailVerificationAction.md` · **Test:** `tests/Feature/EmailVerificationTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Doğrulama postasını kuyruğa koyar. Kayıt ve "yeniden gönder" ucu (`POST /api/auth/email-verification`) çağırır.

## Neden böyle

Hesap zaten doğrulanmışsa hiçbir şey gönderilmez ama uç yine 204 döner: yanıt hesabın durumunu sızdırmaz. Bağlantı `EmailVerificationLink::for()`'dan (imzalı, 24 saat).

## Kurallar

- Yeniden gönderme kullanıcı başına saatte 3 (`throttle:email-verification`).
- Posta kuyruktan (15 saniye kuralı).

## Sık hata

| Hata | Sonuç |
|---|---|
| Doğrulanmış hesaba da göndermek | Gereksiz posta; mail bombalama yüzeyi |
