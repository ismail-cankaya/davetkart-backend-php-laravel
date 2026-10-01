# `lang/tr.json`

> **Faz:** 10 — Dilim D, adım 10.34 · **Karar:** K96 (mail dili Türkçe)
> **Kullanan:** [`ResetPasswordNotification.md`](../app/Notifications/ResetPasswordNotification.md)

## Neden var?

API metin döndürmüyor ve uygulamanın dili `en` (K21). Bu dosya **yalnızca
maillerin** çerçevesi için: Laravel'in bildirim şablonu (`notifications::email`)
birkaç hazır metni `@lang(...)` ile basıyor. Bildirim `tr` diliyle gönderildiğinde bu
metinler buradan çevriliyor:

| Anahtar (Laravel'in metni) | Türkçesi |
|---|---|
| `Hello!` | Merhaba! |
| `Whoops!` | Bir sorun oluştu! |
| `Regards,` | Sevgiler, |
| `All rights reserved.` | Tüm hakları saklıdır. |
| Düğme çalışmazsa çıkan alt not | *"`:actionText` düğmesi çalışmazsa aşağıdaki bağlantıyı…"* |

Bizim bildirimimiz selamlamayı ve kapanışı kendisi yazıyor (`greeting()`,
`salutation()`). İlk üç anahtar yine de burada: yarın yazılacak bir bildirim onları
ezmezse İngilizce sızmasın.

## JSON neden, PHP dosyası değil?

Laravel çeviriyi iki biçimde okur: `lang/tr/*.php` (anahtar: `dosya.anahtar`) ve
`lang/tr.json` (anahtar: **İngilizce metnin kendisi**). Şablon `@lang('Hello!')` diye
metnin kendisini sorduğu için JSON biçimi gerekiyor.

## Hangi test korur?

`PasswordResetTest::the_reset_mail_is_turkish_and_links_to_the_frontend`: mail gerçekten
oluşturuluyor ve HTML'de Türkçe alt not aranıyor. Mutasyon: alt notun çevirisi silinince
test kırılıyor (M9).
