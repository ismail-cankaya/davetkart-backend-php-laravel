# `app/Enums/PromotionKind.php` — otomatik indirimin kaynağı

> **Faz:** 13, adım 13.2 · **Karar:** K130–K133
> **Test:** `OrderPromotionConstraintsTest`, `QuotePriceActionTest`

---

## 1. İki kaynak

| Değer | Nereden gelir? | Kim açar/kapatır? |
|---|---|---|
| `first_publish` | `config/davetkart.php` → `pricing.first_publish_percent` (60) | Kod değişikliği + deploy |
| `campaign` | `price_campaigns` tablosu | Sen, `php artisan pricing:campaign:start/stop` |

İkisi **toplanmaz**: aynı anda geçerliyse büyük olan uygulanır (K133). Eşitlikte ilk yayın
kazanır. Böylece kampanyayı durdurduğunda yeni kullanıcının indirimi değişmez.

## 2. Hediye kodu neden bir `PromotionKind` değil?

Hediye kodu **kullanıcının yazdığı** bir şey ve otomatik indirimin **üstüne** uygulanıyor
(kalan fiyata). Kendi kullanım tablosu (`gift_code_redemptions`) ve kendi kuralları
(kişi başı bir, kullanım sınırı) var. Bir siparişte hem otomatik indirim hem hediye kodu
olabilir. Bu yüzden tek bir "indirim kaynağı" kolonuna sığmaz.

## 3. Veritabanında

`orders.promotion` kolonu, CHECK listesi `PromotionKind::values()`'tan (K39).
Kaynak yoksa kolon `NULL`.
