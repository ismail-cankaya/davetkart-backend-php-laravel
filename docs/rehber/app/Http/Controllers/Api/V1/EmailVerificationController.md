# `app/Http/Controllers/Api/V1/EmailVerificationController.php`

> **Faz 19 (19.14 · K191, SEC-09)**

| Metot | Uç | Kimlik | Hız sınırı | Yanıt |
|---|---|---|---|---|
| `resend` | `POST /api/auth/email-verification` | `auth:sanctum` | `throttle:email-verification` (kullanıcı başına saatte 3) | 204; doğrulanmışsa posta gitmez, yine 204 |
| `confirm` | `POST /api/public/email-verification/confirm?user=…&address=…&expires=…&signature=…` | yok; kanıt imza | `throttle:auth` | 204 · 422 `EMAIL_VERIFICATION_INVALID` |

İki metot tek denetleyicide: ikisi aynı kaynağın (doğrulama postası) iki yüzü ve her biri tek bir Action'a yönlendirir.
`resend` doğrulanmış hesapta da 204 döner; istemci durumu `user.emailVerified`'dan zaten biliyor.
`confirm` her bozuklukta aynı 422'yi döner; sebep yalnızca istisna mesajında (logda) kalır (H6).
