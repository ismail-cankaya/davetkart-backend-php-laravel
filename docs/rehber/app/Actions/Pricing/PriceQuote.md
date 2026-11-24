# `app/Actions/Pricing/PriceQuote.php`

> **Faz:** 13 (K129, K133) · **Ana kılavuz:** `QuotePriceAction.md` · **Test:** `tests/Feature/QuotePriceActionTest.php`, `GiftCodeCheckoutTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Bir planın bu kullanıcıya fiyat dökümü: liste fiyatı → otomatik indirim (kampanya ya da ilk yayın) → hediye kodu → ödenecek. Bütün tutarlar kuruş (`int`). Fiyat uçları, hediye kodu önizlemesi ve checkout aynı nesneyi kullanır.

## Neden böyle

Ekranda gösterilen sayı ile karttan çekilen sayı **aynı hesaptan** çıkmalı. Hesap tek sınıfta, yan etkisiz; sipariş `discount_minor` kolonu `discountMinor()`'dan yazılır.

## Kurallar

- K133: hediye kodu **kalan** fiyata uygulanır (`afterPromotionMinor()`), liste fiyatına değil.
- `withGiftCode()` kodun aynı plan ve aynı indirimli taban üzerinden hesaplandığını sınar; değilse `LogicException` (programlama hatası).
- `coversEverything()` = ödenecek 0: sipariş sağlayıcıya gitmeden hediye siparişi olarak açılır.
- Hiçbir tutar eksi olamaz; tam sayı aritmetiği, float yok.

## Sık hata

| Hata | Sonuç |
|---|---|
| Kodu liste fiyatı üzerinden hesaplamak | İndirimler üst üste biner, toplam eksiye düşebilir |
| Toplamı controller'da yeniden hesaplamak | Ekrandaki ve tahsil edilen tutar ayrışır |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
- **Kuruş (`*_minor`):** para en küçük birimde tam sayı; 149,90 ₺ = 14990.
