# `app/Actions/Pricing/Promotion.php`

> **Faz:** 13 (K133) · **Ana kılavuz:** `QuotePriceAction.md` §3 · **Test:** `tests/Feature/QuotePriceActionTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Bir plana uygulanan otomatik indirim: tür (`PromotionKind::Campaign` ya da `FirstPublish`), yüzde ve kampanyaysa kampanya satırı. İki adlandırılmış kurucu: `firstPublish()` ve `campaign()`.

## Neden böyle

`endsAt()` müşteriye "şu tarihe kadar" demek için; ilk yayın indiriminin bitişi yok. `discountFor()` indirimi tek kuralla hesaplar.

## Kurallar

- 🔴 `discountFor()` tam sayı aritmetiği ve aşağı yuvarlama: kuruş farkı işletmenin lehine (`GiftCode::discountFor` ile aynı kural).
- Kurucu özel (`private`): geçersiz tür/kampanya bileşimi üretilemez.

## Sık hata

| Hata | Sonuç |
|---|---|
| Yüzdeyi `/ 100` ile float hesaplamak | Yuvarlama farkı; para float olmaz |
| Kampanya ve ilk yayın indirimini toplamak | K133: toplanmaz, büyük olan uygulanır; eşitlikte ilk yayın (QuotePriceAction) |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
- **Adlandırılmış kurucu:** `new` yerine anlamlı adlı statik metot; geçerli durumları sınırlar.
