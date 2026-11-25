# `app/Console/Commands/Concerns/ResolvesRefundRequests.php`

> **Faz:** 14, adım 14.6 (K141) · **Kullanan:** `ApproveRefundRequest`, `RejectRefundRequest` ·
> **Ana kılavuz:** [`RefundCommands.md`](../RefundCommands.md) · **Test:** `tests/Feature/RefundCommandsTest.php`

## Ne yapar

`refunds:approve` ve `refunds:reject`'in ortak adımı: `id` argümanını ve `--note`'u okur,
`ResolveRefundRequestAction`'ı çağırır, `RuntimeException`'ı (talep yok, kapalı, hak kullanılmış) hata satırı ve
çıkış 1 olarak yazar; başarıda talebin yeni durumunu basar.

## Neden trait, neden kalıtım değil

İki komut birbirinin özel hâli değil, aynı adımı paylaşan iki ayrı komut. Biri diğerinden türeseydi onayın
"Shopier'den gönderdiniz mi?" sorusu ret komutuna da miras kalırdı ([`RefundCommands.md` §3](../RefundCommands.md)).

## Girdi daraltma

- `argument('id')` `array|bool|string|null` döner. `is_string` ile daraltılır; değilse boş dize gider ve Action
  "bulunamadı" der. `(string)` cast'i PHPStan'de `cast.string` verip `composer check`'i durduruyordu (Faz 19 ortak düzeltme).
- `--note` kırpılır, boşsa `null`, en çok 500 karakter (kullanıcıya mailde gider).

## Sık hata

| Hata | Sonuç |
|---|---|
| Kimliği `(string)` ile çevirmek | PHPStan kırmızı; dizi gelirse `"Array"` aranır |
| Onay sorusunu bu trait'e taşımak | Ret komutu da para sorusu sorar |

Dene: `php artisan test --compact --filter=RefundCommandsTest`
