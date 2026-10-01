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

---

## 🆕 Faz 10 eklemesi — kanal kararı ve ilk gerçek mail (10.30 · K95 · K96)

### K95: kod sağlayıcıdan bağımsız, sağlayıcı deploy'da

İsmail'in kararı (1 Ekim 2026): SES ile alan adının SMTP'si arasındaki seçim
**deploy'a** kaldı. Kod hiçbir sağlayıcıyı bilmiyor; `Notification` ve `MailMessage`
Laravel'in mail katmanına gidiyor, katman `MAIL_MAILER`'ın gösterdiği sürücüyü
kullanıyor.

| Ortam | `MAIL_MAILER` | Ne olur |
|---|---|---|
| Geliştirme | `log` | Mail `storage/logs/laravel.log`'a yazılır; sıfırlama bağlantısı oradan kopyalanır |
| Test | `array` (`phpunit.xml`) | Bellekte; `Notification::fake()` ile iddia edilir |
| Üretim | `ses` **ya da** `smtp` | Seçim ve gerekenler: `docs/10` → *Posta* |

SES seçilirse `composer require aws/aws-sdk-php` **o gün** yapılır. Bugün kurulmadı,
çünkü kullanılmayan bir paket bağımlılık ağacını büyütür (ders 26).

### K96: mail dili Türkçe

API tek dil konuşur ve metin döndürmez (K21). Mail ise kullanıcının **doğrudan
okuduğu** bir metin ve kullanıcının dil tercihi bugün saklanmıyor. K96 bu yüzden
K21'in bilinçli istisnası:

```php
// config/davetkart.php
'mail' => ['locale' => 'tr'],
```

Laravel'in mail şablonundaki hazır metinler (*"Hello!"*, *"Regards,"*, bağlantı
çalışmazsa çıkan alt not) `lang/tr.json`'dan çevrilir (10.34).

### İlk gerçek mail: parola sıfırlama

§*DavetKart'ta hangi mailler var?* tablosundaki *"Şifre sıfırlama (ileride)"* satırı
Faz 10'da doğdu (10.32–10.34). Kuyruğa gider (`ShouldQueue`). Üretimde `queue:work`
çalışmıyorsa mail de **gitmez** (`docs/10` → *Kuyruk*).

Bağlantı **mutlak** olmak zorunda: `config('davetkart.frontend.url')` +
`/sifre-sifirla?token=…&email=…`. Adres isteğin `Host` başlığından üretilmiyor,
çünkü o başlık istemcinin elinde: saldırgan kendi alan adını yazıp bir başkası adına
sıfırlama isterse, kurbanın gelen kutusuna **saldırganın sitesine** giden bir
bağlantı düşerdi (*password reset poisoning*).
