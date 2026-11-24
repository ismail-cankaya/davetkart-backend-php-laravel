# `app/Services/Payment/Shopier/ShopierCallback.php`

> **Faz:** 12 (K127) · **Ana kılavuz:** `ShopierGateway.md` §4 · **Test:** `tests/Feature/ShopierCallbackTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

İmzası doğrulanmış tarayıcı callback'i. İki ayrı bilgi taşır: nereye yönlendirilecek (`orderId`, `succeeded`; her kipte) ve durum ilerletilsin mi (`notification`; yalnızca `callback` kipinde).

## Neden böyle

`notification === null` "bu callback hiçbir siparişi değiştiremez" demek; `osb` kipinde üretilemez. Controller unutsa bile ilerletecek bir şey yoktur.

## Kurallar

- 🔴 `succeeded` imzasız: yalnızca hangi sayfanın gösterileceğini seçer.
- Üretimde `callback` kipi reddedilir (K170).

## Sık hata

| Hata | Sonuç |
|---|---|
| `succeeded`'e göre siparişi `paid` yapmak | Tarayıcıdaki değeri değiştiren ödeme yapmadan yayınlar |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
