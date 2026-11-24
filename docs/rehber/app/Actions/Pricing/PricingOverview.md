# `app/Actions/Pricing/PricingOverview.php`

> **Faz:** 13 · **Ana kılavuz:** `ShowPricingAction.md` · **Test:** `tests/Feature/PricingTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Fiyat uçlarının (`/api/public/plans`, `/api/pricing`) döndürdüğü bütün: plan başına `PriceQuote` listesi (rank sırasıyla), açık ödeme yöntemleri, varsa havale hesabı, ilk yayın yüzdesi ve yanıtın kişiye özel olup olmadığı.

## Neden böyle

Kişiye özel (`personalized = true`) yanıt ilk yayın indirimini hesaba katar; herkese açık katalog katmaz. Tek bir veri kabı iki ucun aynı biçimde yanıt vermesini sağlar; dönüşüm Resource'ta.

## Kurallar

- Saf veri; hesap yapmaz.
- `plans` her zaman rank sırasında.
- `bankTransfer` yalnızca havale açıkken dolu (K135).

## Sık hata

| Hata | Sonuç |
|---|---|
| Kişiye özel indirimi herkese açık uca koymak | Anonim kullanıcıya yanlış fiyat; K128 ihlali |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
