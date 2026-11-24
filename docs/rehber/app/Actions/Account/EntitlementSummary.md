# `app/Actions/Account/EntitlementSummary.php`

> **Faz:** 14 (14.3) · **Ana kılavuz:** `SummarizeEntitlementsAction` (Planlarım) · **Test:** `tests/Feature/SummarizeEntitlementsActionTest.php`, `AccountEntitlementsTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

"Planlarım" ekranının tamamı: plan başına kova sayıları (`TierEntitlements`) + satır satır haklar (`EntitlementRight`).

## Neden böyle

Hesap silme uyarısı ve panel rozeti "kaç kullanılabilir hakkın var" sorusunu aynı yerden sorar: `totalAvailable()`.

## Kurallar

- `tiers` üç planı da rank sırasıyla içerir, sıfır olsa bile; `forTier()` olmayan plan için boş kova döner.
- `rights` en yeni sipariş üstte.

## Sık hata

| Hata | Sonuç |
|---|---|
| Sayıları ayrı sorguyla yeniden saymak | Ekrandaki liste ile sayı ayrışır |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
