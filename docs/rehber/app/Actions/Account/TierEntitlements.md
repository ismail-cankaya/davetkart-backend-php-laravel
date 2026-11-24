# `app/Actions/Account/TierEntitlements.php`

> **Faz:** 14 (14.3) · **Ana kılavuz:** `EntitlementSummary.md` · **Test:** `tests/Feature/SummarizeEntitlementsActionTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Tek bir planın kova sayıları: `EntitlementState` değeri → adet.

## Neden böyle

Her kova her zaman vardır; `count()` olmayan kova için 0 döner, çağıran `isset` yazmaz.

## Kurallar

- Değişmez; sayılar kurucuda verilir.

## Sık hata

| Hata | Sonuç |
|---|---|
| Diziye doğrudan erişmek | Eksik kova `Undefined array key` |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
