# `tests/Feature/PasswordResetTest.php`

> **Faz:** 10 — Dilim D, adım 10.36 · **12 test**
> **Test edilen:** `ForgotPasswordRequest` · `ResetPasswordRequest` · `SendPasswordResetLinkAction` ·
> `ResetPasswordAction` · `ResetPasswordNotification` · `lang/tr.json` · iki rota
> **Kurallar:** A2 · H6 · T6 · T11 · T13 · T14 · T16

## 1. Testler

| Grup | Test | Ne kanıtlar |
|---|---|---|
| İsteme | 🔴 `a_registered_and_an_unknown_email_get_the_same_answer` | İki istek de 202, **ham gövde** aynı (boş). Mail yalnızca kayıtlı olana |
| | `the_email_is_normalized_like_at_login` | `İsmail.Cankaya@…` ile istenen sıfırlama `ismail.cankaya@…` hesabına gider |
| | `the_reset_mail_is_turkish_and_links_to_the_frontend` | Mail **gerçekten** oluşuyor: Türkçe konu, düğme, alt not; İngilizce iz yok; bağlantı `FRONTEND_URL/sifre-sifirla?token=…&email=…` |
| | 🔴 `the_link_ignores_the_request_host_header` | Sahte `Host: saldirgan.example` maildeki bağlantıya girmiyor |
| | `the_reset_mail_goes_through_the_queue` | `ShouldQueue` · dil `tr` |
| | `reset_link_requests_are_rate_limited` | 6. istek 429 |
| Sıfırlama | 🔴 `a_valid_link_sets_the_new_password_and_ends_every_session` | Yeni parola geçerli, eskisi değil · **iki** token da 401 · yeni parolayla giriş 200 · `PasswordReset` olayı |
| | `a_reset_link_works_only_once` | İkinci deneme 422, parola ikinci kez değişmedi |
| | `an_expired_link_is_rejected` | 61. dakika 422 |
| | `a_token_issued_for_another_account_is_rejected` | Mehmet'in token'ı Ayşe'nin parolasını değiştiremez |
| | 🔴 `a_wrong_token_and_an_unknown_email_are_indistinguishable` | İkisi de 422 `PASSWORD_RESET_INVALID`, `fields` yok, ham gövde aynı |
| | `a_too_short_password_is_a_field_error` | T6'nın varlık yarısı: biçim hatası alan alan konuşur |

## 2. Maili gerçekten göndermek

Çoğu test `Notification::fake()` kullanıyor: *"gönderildi mi?"* sorusu için yeterli.
Ama üç iddia sahte bir bildirimle **kanıtlanamaz**:

- Dil gerçekten Türkçeye geçiyor mu? (Bildirimin `locale`'i ancak gönderimde uygulanır)
- `lang/tr.json` gerçekten okunuyor mu?
- Bağlantı `Host` başlığını gerçekten yok sayıyor mu?

Bu yüzden iki test maili **gerçekten** gönderiyor. `phpunit.xml`'de
`MAIL_MAILER=array` (mail bellekte tutulur, kimseye gitmez) ve
`QUEUE_CONNECTION=sync` (kuyruktaki iş anında çalışır). Test, `array` sürücüsünün
taşıyıcısından mesajı alıp HTML'ini okuyor:

```php
$transport = app('mailer')->getSymfonyTransport();   // ArrayTransport
$email = $transport->messages()->first()->getOriginalMessage();
```

## 3. `Password::broker()->createToken($user)`

Sıfırlama testleri gerçek bir token'a ihtiyaç duyuyor. Token maildeki bağlantının
içinde, ama mail sahteyken onu okumak mümkün değil. Aracın kendi `createToken()`
metodu aynı token'ı üretip veritabanına (hash'lenmiş hâliyle) yazıyor.

## 4. Mutasyon kanıtı (1 Ekim 2026, İsmail'in makinesi, PHP 8.5)

| # | Mutasyon | Kırılan |
|---|---|---|
| M1 | Token iptali kaldırıldı | `a_valid_link_…` |
| M2 | Kayıtsız e-posta 404 alsın | `a_registered_and_an_unknown_…` (+ hız sınırı testi) |
| M3 | Bağlantı `url('/')` ile (Host'tan) | Türkçe mail testi · Host testi |
| M4 | Bildirim dili kaldırıldı | Türkçe mail testi · kuyruk/dil testi |
| M5 | Forgot isteğinde `EmailNormalizer` yok | `the_email_is_normalized_…` |
| M6 | `ShouldQueue` kaldırıldı | `the_reset_mail_goes_through_the_queue` |
| M7 | `forgot-password` `throttle:auth` grubunun **dışına** taşındı | `reset_link_requests_are_rate_limited` |
| M8 | Araç reddedince istisna yok (sessiz 204) | dört ret testi |
| M9 | `lang/tr.json`'da alt not İngilizce | Türkçe mail testi |

M7'nin ilk denemesi yanlış kuruldu: rota grubun dışına taşınmak yerine **silindi**
ve beş test 404'ten kırıldı. Bu, sınanmak istenen şey değildi. Doğru mutasyon (rota
var ama hız sınırı yok) yalnızca hız sınırı testini kırdı.

## 5. Çalıştırma

```powershell
php artisan test --filter=PasswordResetTest
# 12 passed
```
