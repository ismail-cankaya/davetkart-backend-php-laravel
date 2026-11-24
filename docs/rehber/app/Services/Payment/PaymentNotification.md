# `app/Services/Payment/PaymentNotification.php`

> **Faz:** 7 · 12 (K121, K126) · **Ana kılavuz:** `PaymentGateway.md` §5 · **Test:** `tests/Feature/PaymentNotificationAmountTest.php`, `ShopierOsbTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Sağlayıcıdan gelen, **imzası doğrulanmış** ödeme bildirimi: hangi sipariş (`providerRef`), ne oldu (`OrderStatus`), sağlayıcının ödeme kimliği ve varsa tahsil edilen tutar/para birimi.

## Neden böyle

🔴 Bu nesnenin var olması bir güvenlik iddiasıdır: onu üreten tek yer sürücünün `parseNotification()` metodu ve imza kontrolü o yolun üzerinde. Ham gövde bilerek taşınmaz; aşağı katmanlar ikinci bir yorum üretmesin.

## Kurallar

- Durum `OrderStatus` enum'u: sağlayıcının sözlüğünü (`SUCCESS`, `basarili`) çevirmek sürücünün işi.
- K126: tutar doluysa siparişle karşılaştırılır, uyuşmazsa geçiş reddedilir. `null` = tutar taşımıyor; 0 ile aynı değil.
- K171: üretimde tutar taşımayan `paid` reddedilir.

## Sık hata

| Hata | Sonuç |
|---|---|
| Bildirimi controller'da elle kurmak | İmzasız veri "doğrulanmış" görünür |
| `amountMinor` yokken 0 saymak | Tutar karşılaştırması yanlış sonuç verir |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
