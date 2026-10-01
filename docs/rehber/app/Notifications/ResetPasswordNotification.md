# `app/Notifications/ResetPasswordNotification.php`

> **Faz:** 10 — Dilim D, adım 10.34 · **Kararlar:** K95 (sağlayıcıdan bağımsız) · K96 (Türkçe)
> **Bağlantılı:** [`config/mail.md`](../../config/mail.md) · [`lang/tr.json.md`](../../lang/tr.json.md) · `User::sendPasswordResetNotification()`

## 1. Projenin ilk bildirimi (Notification)

Laravel bir parola sıfırlama bildirimiyle geliyor (`Illuminate\Auth\Notifications\ResetPassword`).
Bizim için üç yerde yanlıştı:

| Laravel'in hâli | Sorun | Bizim hâlimiz |
|---|---|---|
| İngilizce metin | Kullanıcılar Türk | Türkçe (K96), `davetkart.mail.locale` |
| `route('password.reset')` | Böyle bir rota yok: backend saf API, sayfa frontend'de | `FRONTEND_URL` + `/sifre-sifirla?token=…&email=…` |
| Senkron | 15 sn kuralı · zaman farkıyla hesap tarama | `ShouldQueue` |

`User::sendPasswordResetNotification()` Laravel'in bildirimi yerine bunu gönderiyor;
parola aracı token üretince o metodu çağırıyor.

## 2. Genişletme noktaları

`ResetPassword`'ün iki korumalı metodu eziliyor, gerisi Laravel'in:

```php
protected function buildMailMessage($url): MailMessage   // metin
protected function resetUrl($notifiable): string          // bağlantı
```

**Neden `ResetPassword::createUrlUsing()` değil?** Plan (10.34) `AppServiceProvider`'da
statik bir geri çağrı öneriyordu. O yol global bir statik durum bırakır ve bağlantı ile
mail metni iki ayrı dosyada yaşar. Ezilen metotla ikisi aynı sınıfta; kimse global bir
şeyi hatırlamak zorunda değil.

## 3. 🔴 Bağlantı istekten değil config'ten (password reset poisoning)

```php
return Config::string('davetkart.frontend.url')
    .Config::string('davetkart.frontend.password_reset_path')
    .'?'.http_build_query(['token' => $this->token, 'email' => …]);
```

`url('/')` kullanılsaydı adres isteğin `Host` başlığından gelirdi. O başlık
istemcinin elinde:

```
Saldırgan:  POST /api/auth/forgot-password   Host: saldirgan.example
            { "email": "ayse@…" }
Ayşe'nin gelen kutusu:  "Parolamı Sıfırla" → https://saldirgan.example/sifre-sifirla?token=…
```

Ayşe gerçek bir DavetKart mailine tıklar ve **token'ı saldırgana teslim eder**. Test
aynı isteği sahte bir `Host` ile gönderiyor ve maildeki bağlantıda o adın geçmediğini
iddia ediyor (`the_link_ignores_the_request_host_header`).

## 4. Dil: bildirimin kendi locale'i

```php
$this->locale(Config::string('davetkart.mail.locale'));   // 'tr'
```

Uygulamanın dili `en` (K21: API tek dil). Bildirim gönderilirken Laravel geçici olarak
bildirimin diline geçer; şablonun hazır metinleri (alt not, *"All rights reserved."*)
o anda `lang/tr.json`'dan çevrilir.

Mail metni `siz` diliyle yazıldı: arayüzün geri kalanıyla aynı (*"Hesabınız yok mu?"*).

## 5. Kuyruk yoksa ne olur?

Üretimde `QUEUE_CONNECTION=database`: bildirim `jobs` tablosuna yazılır,
`queue:work` gönderir. İşçi çalışmıyorsa mail **hiç gitmez** ve kimse fark etmez,
çünkü uç yine 202 döner. `docs/10` → *Posta* ve *Kuyruk* bunu uyarıyor.
