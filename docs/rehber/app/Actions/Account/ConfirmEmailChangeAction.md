# `app/Actions/Account/ConfirmEmailChangeAction.php`

> **Faz:** 14 (14.10, K144) · **Ana kılavuz:** `RequestEmailChangeAction.md` §4 · **Test:** `tests/Feature/EmailChangeTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

İmzası doğrulanmış onay bağlantısıyla e-posta adresini değiştirir. Başarıda adres değişir ve doğrulanmış sayılır, bütün oturumlar kapanır, eski adrese bekleyen sıfırlama bağlantısı silinir ve **eski** adrese "adresiniz değişti" postası gider.

## Neden böyle

Hesabın kimliği değişti: kullanıcı yeni adresle yeniden giriş yapmalı (parola sıfırlamayla aynı kural). Hesap ele geçirildiyse asıl sahibin haberi eski adresten olur.

## Kurallar

- Kullanıcı satırı `lockForUpdate` ile kilitlenir; bağlantı tek kullanımlık: eski adresin parmak izi tutmazsa `stale link`.
- 🔴 Yeni adres başka hesaptaysa veritabanının UNIQUE kısıtı yakalar ve **aynı** `EMAIL_CHANGE_INVALID` döner (H6). Önceden `exists()` sormak iki istek arasında yarış açardı.
- Posta işlemden **sonra**, kuyruktan gider.

## Sık hata

| Hata | Sonuç |
|---|---|
| Önce `exists()` ile adresi sormak | Yarış: iki onay aynı adresi alabilir |
| "Bu adres kullanımda" demek | Hesap tarayıcısı: hangi adreslerin kayıtlı olduğu sızar |
| Oturumları kapatmamak | Eski kimlikle açılmış token geçerli kalır |

## Terimler

- **Parmak izi:** adresin anahtarlı özeti (`EmailChangeLink::fingerprint`); adres değişince bağlantı geçersizleşir.
