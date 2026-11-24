# `app/Notifications/PasswordChangedNotification.php`

> **Faz:** 14 (14.8) · **Ana kılavuz:** `ChangePasswordAction.md` · **Test:** `tests/Feature/PasswordChangeTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

"Parolanız değiştirildi, diğer oturumlar kapatıldı" uyarısı.

## Neden böyle

Hesabı ele geçiren parolayı değiştirirse asıl sahibin haberi olmalı. Bağlantı sıfırlama **sayfasına** gider, token taşımaz: postayı ele geçiren onunla hiçbir şey yapamaz.

## Kurallar

- `ShouldQueue`: kuyruktan gider (15 saniye kuralı; yanıt süresi adresin varlığını sızdırmaz).
- Dil `config('davetkart.mail.locale')`; bağlantılar `davetkart.frontend.url`'den, isteğin Host başlığından değil.

## Sık hata

| Hata | Sonuç |
|---|---|
| Postaya sıfırlama token'ı koymak | Posta kutusu ele geçirilirse parola da ele geçer |
