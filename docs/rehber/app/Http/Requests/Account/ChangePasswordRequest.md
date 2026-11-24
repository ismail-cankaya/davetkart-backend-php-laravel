# `app/Http/Requests/Account/ChangePasswordRequest.php`

> **Faz:** 14 (14.8) · 19.9 (`SEC-05`, K258) · **Ana kılavuz:** `ChangePasswordAction.md`, `app/Rules/PasswordPolicy.md` · **Test:** `tests/Feature/PasswordChangeTest.php`, `PasswordPolicyTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

`PUT /api/auth/password` gövdesi: mevcut parola + yeni parola.

## Neden böyle

🔴 Token tek başına yetmez: açık kalmış bir oturumu bulan biri parolayı değiştirip asıl sahibi dışarıda bırakamamalı (`current_password:sanctum`, yanlışsa 422 alan hatası).

## Kurallar

- Yeni parolanın kuralı kayıt ve sıfırlamayla aynı tek kaynaktan: `PasswordPolicy::rules()` (en az 8, en çok 255, yaygın parola değil).
- `different:currentPassword`: aynı parolayı "değiştirmek" hiçbir şeyi korumaz; büyük ihtimalle yanlış alana yazılmıştır.
- Uç `throttle:auth` kovasında: mevcut parolayı istediği için tahmin hedefi.

## Sık hata

| Hata | Sonuç |
|---|---|
| Parola kuralını burada ayrıca yazmak | Üç uç ayrışır (SEC-05 tam olarak buydu) |
| Kural nesnesi kullanmak | Hata sözleşmesine sınıf adı sızar; dize adlı kural (`uncommon_password`) |
