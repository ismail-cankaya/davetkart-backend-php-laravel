# Profil güncelleme: `PATCH /api/auth/me`

> **Faz:** 14, adım 14.7
> **Dosyalar:** `UpdateProfileRequest`, `UpdateProfileAction`, `ProfileController`
> **Test:** `tests/Feature/ProfileUpdateTest.php`

## 1. Ne değişir?

Yalnızca `firstName` ve `lastName`. Yanıt `GET /auth/me` ile aynı biçim (`{data: user}`, K11):
frontend dönen kullanıcıyı doğrudan oturuma yazar.

## 2. 🔴 E-posta neden burada yok?

E-posta hesabın **kimliği**: giriş onunla, parola sıfırlama ona gider. Doğrulamasız
değiştirilebilseydi, açık kalmış bir oturumu bulan biri adresi kendi adresiyle değiştirip
"şifremi unuttum" ile hesabı tamamen ele geçirirdi. E-posta ayrı, doğrulamalı bir akış
(14.10). Bu uçta `email` gelirse kurallarda olmadığı için `validated()`'a girmez.

## 3. Neden `fill()` değil?

`User`'ın `#[Fillable]` listesinde `email` ve `password` da var (kayıt onlara ihtiyaç duyuyor).
Action `fill($array)` kullansaydı, yarın biri bu Action'a `validated()` yerine `all()`
verdiğinde e-posta ve parola sessizce değişirdi. İki kolon açıkça atanıyor: Action'ın
yapabileceği şey imzasında yazılı.

## 4. Testler

| Test | Kanıtladığı |
|---|---|
| `a_guest_cannot_update_a_profile` | 401 |
| `the_name_is_trimmed_and_saved` | Kırpma + `assertExactJson` |
| `the_email_and_password_cannot_change_here` | 🔴 Kimlik bu uçtan değişmez |
| `blank_names_are_refused` | Boşluk `required`'a takılır, 60 sınırı |
