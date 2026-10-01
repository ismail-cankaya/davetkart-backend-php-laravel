# `app/Exceptions/PasswordResetFailedException.php`

> **Kod dosyası:** `app/Exceptions/PasswordResetFailedException.php`
> **Faz:** 10 — Dilim D, adım 10.31
> **Kardeşi:** [`RegistrationFailedException.md`](RegistrationFailedException.md) (aynı H6 gerekçesi)
> **Kod:** `PASSWORD_RESET_INVALID` (422) — [`ErrorCode.md`](../Enums/ErrorCode.md) → *Faz 10 eklemesi*
> **Fırlatan:** [`ResetPasswordAction.md`](../Actions/Auth/ResetPasswordAction.md) (10.33)

---

## 1. Ne zaman?

`ResetPasswordAction`, Laravel'in parola aracından `Password::PASSWORD_RESET` dışında
herhangi bir sonuç alırsa:

| Araç sonucu | Anlamı | Dışarı giden |
|---|---|---|
| `passwords.token` | Token yanlış ya da süresi dolmuş | `PASSWORD_RESET_INVALID` |
| `passwords.user` | E-posta kayıtlı değil | `PASSWORD_RESET_INVALID` |
| `passwords.throttled` | Çok sık deneme | `PASSWORD_RESET_INVALID` |

## 2. `rejected(string $brokerStatus)`

```php
throw PasswordResetFailedException::rejected($status);
// Mesaj: "Password reset rejected by the broker: passwords.user."
```

Gerçek sebep **mesajda**, yani yalnızca log'da ve yerelde `debug` bloğunda görünür.
Yanıtta yalnızca kod var. 10.16'dan beri 4xx iş istisnaları raporlanmadığı için
(`dontReportWhen`) bu mesaj üretimde log'a da düşmüyor. Bilinçli: kullanıcının yanlış
bir bağlantıya tıklaması bir hata değil, sözleşmenin öngördüğü bir cevap.

## 3. `errorParams()` boş ve öyle kalmalı

`RegistrationFailedException` ile aynı gerekçe (H6). Sebep dışarı çıkarsa sıfırlama
formu bir hesap tarayıcısına döner.
