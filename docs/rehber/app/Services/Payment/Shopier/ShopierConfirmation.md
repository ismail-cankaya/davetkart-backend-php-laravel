# `app/Services/Payment/Shopier/ShopierConfirmation.php`

> **Faz:** 12 (K117) · 18.5 (K170) · **Ana kılavuz:** `ShopierGateway.md` §3 · **Test:** `tests/Feature/PaymentDriverResolutionTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Siparişi kim `paid` yapar: `Osb` (varsayılan; yalnızca imzalı sunucu bildirimi) ya da `Callback` (imzalı tarayıcı callback'i de).

## Neden böyle

Enum `app/Enums/` altında değil: bir alan kavramı değil, tek bir sürücünün ayarı; sürücünün yanında durur (B6).

## Kurallar

- Değer `config('payment.providers.shopier.confirmation')`.
- 🔴 `Callback` yalnızca yerel/test; üretimde sürücü kurulmaz (K170, 503).

## Sık hata

| Hata | Sonuç |
|---|---|
| Üretimde `callback`'e geçmek | Sürücü kurulmaz; ödemeler 503 (bilerek) |
