# `app/Http/Requests/Auth/ForgotPasswordRequest.php`

> **Faz:** 10 — Dilim D, adım 10.32 · **Uç:** `POST /api/auth/forgot-password`
> **Önce oku:** [`LoginRequest.md`](LoginRequest.md) (aynı normalizasyon, aynı `exists` yasağı)
> **Test:** [`PasswordResetTest.md`](../../../../tests/Feature/PasswordResetTest.md)

## 1. Kurallar

```php
'email' => ['required', 'string', 'email:rfc', 'max:255'],
```

Tek alan. **`exists:users,email` bilerek yok**: kural kayıtsız e-postada 422
`fields.email.0.rule = exists` döndürürdü ve form bir hesap tarayıcısına dönerdi
(`docs/08` §3.1). Uç her durumda aynı **202**'yi döner.

**Biçim hatası 422 alabilir mi?** Evet: `ayse@` gibi bir değer `VALIDATION_FAILED`
alır. Bu, hesabın varlığı hakkında bir şey söylemez; yalnızca yazılan şeyin bir
e-posta olmadığını söyler.

## 2. `prepareForValidation()`: kayıtla aynı normalizasyon

```php
$this->merge(['email' => EmailNormalizer::normalize($email)]);
```

10.13'ün dört yerine beşincisi. Olmasaydı `İsmail.Cankaya@…` ile kaydolan kullanıcı
(veritabanında `ismail.cankaya@…`) sıfırlama isterken `İ` yazdığında hesabı
bulunamaz ve **sessizce** mail almazdı. Uç yine 202 döndüğü için kullanıcı bunun bir
hata olduğunu hiç öğrenemezdi. Mutasyonla kanıtlandı (`PasswordResetTest`, M5).

## 3. `normalizedEmail()`

Doğrulanmış ve normalize edilmiş adres. Action yalnızca bir string alır (CLAUDE.md:
Action'a gelen veri saf ve güvenilir kabul edilir).
