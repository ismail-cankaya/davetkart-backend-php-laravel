# `app/Http/Resources/PricingResource.php` — fiyat kataloğu yanıtı

> **Faz:** 13, adım 13.6 · **Sözleşme:** frontend `types.ts` → `PricingCatalog`

```json
{
  "data": {
    "currency": "TRY",
    "personalized": true,
    "firstPublishPercent": 60,
    "plans": [
      { "tier": "standart", "priceMinor": 24900, "promotion": { "kind": "first_publish", "percent": 60, "discountMinor": 14940, "endsAt": null }, "discountMinor": 14940, "totalMinor": 9960 },
      { "tier": "gold", "priceMinor": 39900, "promotion": { "...": "..." }, "discountMinor": 23940, "totalMinor": 15960 },
      { "tier": "elit", "priceMinor": 54900, "promotion": { "...": "..." }, "discountMinor": 32940, "totalMinor": 21960 }
    ],
    "paymentMethods": ["gift_code"],
    "bankTransfer": null
  }
}
```

- `plans` sırası her zaman rank sırası (`SubscriptionTier::cases()`).
- `paymentMethods` sırası her zaman kart → havale → kod (`PaymentMethod::cases()`).
- `bankTransfer` her zaman var, havale kapalıyken `null` (C7).
- `personalized` kontrol amaçlı. Frontend misafir kataloğunu yanlışlıkla kişisel sanıp
  "senin fiyatın" yazmasın.
