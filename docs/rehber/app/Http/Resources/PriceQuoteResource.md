# `app/Http/Resources/PriceQuoteResource.php` — bir planın fiyat dökümü

> **Faz:** 13, adım 13.5 · **Karar:** K128
> **Kullananlar:** `GiftCodeQuoteResource` (önizleme), `PricingResource` (fiyat uçları, 13.6)

---

```json
{
  "tier": "gold",
  "priceMinor": 39900,
  "promotion": { "kind": "campaign", "percent": 20, "discountMinor": 7980, "endsAt": "2027-01-07T20:59:59+00:00" },
  "discountMinor": 7980,
  "totalMinor": 31920
}
```

| Alan | Frontend'de nerede? |
|---|---|
| `priceMinor` | Üstü çizili fiyat (yalnızca indirim varsa) |
| `totalMinor` | Büyük yazılan fiyat |
| `promotion.percent` | Rozet: "%60 ilk yayın indirimi" / "%20 kampanya" |
| `promotion.endsAt` | "7 Ocak'a kadar" (yalnızca kampanyada dolu) |

## Kararlar

- **Frontend hesap yapmaz.** `totalMinor`'ı kendisi `priceMinor × (1 − percent)` diye
  hesaplasaydı yuvarlama farkı ekranla kartı ayırırdı. Bütün sayılar hazır gelir.
- **`promotion` her zaman var, `null` olabilir.** Her fiyatın bir alanı olduğu için
  (`OrderResource.paidAt` gibi). Bağlama göre var olan bir alan değil (C7).
- **Beyaz liste (C1):** kampanya kimliği ve notu (`label`) gitmez. İç bilgi.
- `endsAt` UTC ISO 8601. Gösterim biçimi frontend'in işi (kullanıcının dili ve saat dilimi).
