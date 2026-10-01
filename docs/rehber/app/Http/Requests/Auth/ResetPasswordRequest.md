# `app/Http/Requests/Auth/ResetPasswordRequest.php`

> **Faz:** 10 — Dilim D, adım 10.33 · **Uç:** `POST /api/auth/reset-password`
> **Önce oku:** [`ForgotPasswordRequest.md`](ForgotPasswordRequest.md) · [`RegisterRequest.md`](RegisterRequest.md)

## 1. Kurallar

| Alan | Kural | Neden |
|---|---|---|
| `token` | `required`, `string`, `max:255` | Laravel'in token'ı 64 karakterlik onaltılık bir dizgi. Sınır biçimi **doğrulamaz** (o parola aracının işi); yalnızca saçma uzunlukta bir gövdeyi keser |
| `email` | `required`, `string`, `email:rfc`, `max:255` | Token tek başına yetmez: araç token'ı **o e-postanın** satırında arar |
| `password` | `required`, `string`, `min:8`, `max:255` | **Kayıtla aynı kural.** Sıfırlama, kayıtta reddedilecek bir parolaya kapı açmamalı |

`password_confirmation` yok, çünkü kayıtta da yok. *"Tekrar yazın"* kutusu
frontend'in sunum kararı; backend tek bir parola alır.

## 2. Normalizasyon

`ForgotPasswordRequest` ile aynı (`EmailNormalizer`). Maildeki bağlantı adresi
normalize edilmiş hâliyle taşıyor, ama kullanıcı adresi elle de yazabilir.

## 3. Hata ayrımı

| Durum | Yanıt |
|---|---|
| Biçim hatası (kısa parola, eksik alan) | 422 `VALIDATION_FAILED` + `fields` |
| Token geçersiz / süresi dolmuş / e-posta kayıtsız | 422 `PASSWORD_RESET_INVALID`, `fields` **yok** (`ResetPasswordAction`) |
