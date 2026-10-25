# Migration: siparişe otomatik indirim kolonları

> **Faz:** 13, adım 13.2 · **Karar:** K133
> **Test:** `OrderPromotionConstraintsTest`

---

## 1. Yeni kolonlar

| Kolon | Anlamı |
|---|---|
| `promotion` | `first_publish` · `campaign` · NULL |
| `promotion_discount_minor` | Otomatik indirimin tutarı (kuruş) |
| `price_campaign_id` | Yalnızca `promotion = 'campaign'` iken dolu |

## 2. 🔴 `discount_minor`'ın anlamı genişledi

Faz 11'de `discount_minor` = hediye kodu indirimi. Faz 13'te **toplam indirim**:

```
liste fiyatı       = amount_minor + discount_minor
otomatik indirim   = promotion_discount_minor
hediye kodu kısmı  = discount_minor - promotion_discount_minor   (= redemption.discount_minor)
```

Liste fiyatı için ayrı bir kolon **yok** (E1: türetilebilen saklanmaz). `Order::listPriceMinor()`
onu toplamdan türetir. Saklasaydık bir gün `list_price ≠ amount + discount` olan bir satır
doğabilirdi. Toplamın kendisi zaten bir kısıt kadar güçlü bir değişmez.

Eski siparişler için geriye dönük bir düzeltme gerekmez: hepsinde `promotion = NULL`,
`promotion_discount_minor = 0`, yani `discount_minor` hâlâ yalnızca hediye kodu.

## 3. Üç kısıt, üç imkânsız satır (E2)

```sql
CHECK (promotion_discount_minor >= 0 AND promotion_discount_minor <= discount_minor)
CHECK ((promotion IS NULL) = (promotion_discount_minor = 0))
CHECK ((COALESCE(promotion, '') = 'campaign') = (price_campaign_id IS NOT NULL))
```

🔴 Üçüncüsündeki `COALESCE` bir tuzağı kapatıyor. SQL'de `NULL = 'campaign'` sonucu
`NULL`'dır (bilinmiyor) ve **CHECK, NULL sonucu geçirir**. Yani `COALESCE` olmasaydı
`promotion = NULL` ama `price_campaign_id` dolu bir satır kabul edilirdi. Faz 7'deki
`orders_paid_at_check` aynı nedenle iki tarafı da NOT NULL ifadelerle kurmuştu.
