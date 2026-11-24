# `app/Actions/Account/EntitlementRight.php`

> **Faz:** 14 (14.3) · **Ana kılavuz:** `SummarizeEntitlementsAction` (Planlarım) · **Test:** `tests/Feature/SummarizeEntitlementsActionTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

"Planlarım" listesinin bir satırı: sipariş, hakkın durumu (`EntitlementState`), iade engeli (`RefundBlocker`) ve iade penceresinin sonu.

## Neden böyle

Arayüzün "iade iste" düğmesini gösterip göstermeyeceği backend'de karar verilir; `isRefundable()` = engel yok.

## Kurallar

- `order` ilişkileri (`invitation` çöptekiler dahil, `openRefundRequest`) **yüklü** gelir: Resource tembel sorgu açmaz.

## Sık hata

| Hata | Sonuç |
|---|---|
| İade uygunluğunu frontend'de hesaplamak | İki kural ayrışır; sunucu yine reddeder ama ekran yanlış söz verir |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
