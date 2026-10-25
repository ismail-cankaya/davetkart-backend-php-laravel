# `app/Models/PriceCampaign.php` — manuel fiyat kampanyası

> **Faz:** 13, adım 13.3 · **Karar:** K132
> **Test:** `PriceCampaignCommandsTest`, `QuotePriceActionTest`

---

## 1. Ne tutar?

"20 Aralık ile 7 Ocak arasında bütün planlarda %20" gibi bir kararı. Tablo:
[`price_campaigns`](../../database/migrations/2026_10_25_100000_create_price_campaigns_table.md).

## 2. "Şu an geçerli mi?" kuralı tek yerde

```php
PriceCampaign::query()->activeAt(now())->applicableTo(SubscriptionTier::Gold)->max('percent');
```

| Koşul | Neden |
|---|---|
| `stopped_at IS NULL` | Elle durdurulmamış |
| `starts_at <= now` | Başlamış |
| `ends_at IS NULL OR ends_at > now` | Bitmemiş (`>`: bitiş anı hariç) |

`isActiveAt()` aynı kuralın PHP ikizi. Listeleme komutu "GECERLI/bitti/başlamadı" yazarken
onu kullanıyor. İkisi yan yana durduğu için biri değişirse diğeri de görünür.

🔴 Zamanlanmış görev (cron) **yok**. Kampanya `ends_at` geldiğinde kimse onu "kapatmaz":
bir sonraki istekte `now() < ends_at` koşulu yanlış olur ve kampanya kendiliğinden
uygulanmaz. Durum bir kolonda değil, **zamanın kendisinde**.

## 3. `applicableTo()`

`tier IS NULL` (bütün planlar) **veya** `tier = 'gold'`. Gift code'daki `isApplicableTo()`
ile aynı anlam.

## 4. Neden `#[Fillable([])]`?

Satırı yalnızca `StartPriceCampaignAction` yazar ve alanları tek tek atar (E7). İstemciden
gelen hiçbir veri bu tabloya ulaşmaz. Liste yine de boş, çünkü bir gün bir yönetim paneli
yazılırsa toplu atama bilinçli bir karar olsun.

## 5. Fabrika

`PriceCampaign::factory()` varsayılanı: şu an geçerli, bütün planlarda %20, süresiz.
Durumlar: `percent(n)`, `forTier(tier)`, `upcoming()`, `ended()`, `stopped()`.
