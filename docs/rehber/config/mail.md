# `config/mail.php` — Kılavuz

E-postanın nasıl gönderileceğini tanımlar.

## Mailer'lar

| Mailer | Davranış | Ne zaman |
|---|---|---|
| `log` | Göndermez, `laravel.log`'a yazar | Geliştirme |
| `array` | Hafızada tutar | Testlerde (`Mail::fake()`) |
| `smtp` | Gerçek SMTP sunucusu | Üretim |
| `ses`, `postmark`, `resend` | Sağlayıcı API'si | Üretim (yüksek hacim) |

`.env` şu an `MAIL_MAILER=log` — yerelde e-posta gönderilmiyor, log'a düşüyor.
Geliştirme için doğru ayar.

## `from` — gönderen kimliği

```php
'from' => [
    'address' => env('MAIL_FROM_ADDRESS'),
    'name'    => env('MAIL_FROM_NAME'),
],
```

`MAIL_FROM_NAME="${APP_NAME}"` yazıyor; `APP_NAME` hâlâ `Laravel`. Düzeltilmeli,
yoksa kullanıcıya "Laravel" adından mail gider.

## DavetKart'ta hangi mailler var?

| Mail | Tetikleyen | Adım |
|---|---|---|
| Yeni LCV bildirimi | Misafir yanıt gönderdi | 10 |
| Ödeme başarılı | Webhook `paid` işaretledi | 12 |
| Şifre sıfırlama | Kullanıcı talebi | (ileride) |

Hepsi **kuyruğa** gider (`ShouldQueue`). SMTP sunucusu yavaş cevap verebilir;
15 saniye kuralı gereği HTTP isteği beklememeli.

## Teslim edilebilirlik (deliverability)

Gerçek gönderime geçince, mailin spam'e düşmemesi için domain tarafında
**SPF**, **DKIM** ve **DMARC** kayıtları gerekir. Bu bir kod meselesi değil,
DNS ayarıdır — ama atlanırsa "mailler gitmiyor" diye günlerce kod aranır.

## Dikkat

- Testlerde `Mail::fake()` kullanılır; gerçek gönderim yapılmaz, gönderim
  iddiası `Mail::assertQueued()` ile doğrulanır.
- Mail şablonlarına kullanıcı girdisi basılırken `{{ }}` (escape'li) kullanılır;
  `{!! !!}` XSS açar.
