# `StartPriceCampaignAction` · `StopPriceCampaignAction` · `PriceCampaignSpec`

> **Faz:** 13, adım 13.3 · **Karar:** K132
> **Test:** `PriceCampaignCommandsTest`

---

## 1. Akış

```
php artisan pricing:campaign:start 20 --until=2027-01-07
        │
        ▼
StartPriceCampaign (komut, ince)  → PriceCampaignSpec::fromOptions()  (doğrula)
        │
        ▼
StartPriceCampaignAction::handle($spec)  → satırı yaz + log
```

Komut controller gibi davranır: girdiyi okur, işi Action'a verir (K3). Hediye kodu
komutlarının (`gift-codes:create` → `GiftCodeSpec` → `CreateGiftCodeAction`) birebir aynısı.

## 2. `PriceCampaignSpec`: doğrulama kuralları

| Seçenek | Kural | Hata mesajı (özet) |
|---|---|---|
| `percent` | 1–90 tam sayı | "Ücretsiz yayın için hediye kodu kullan" |
| `--tier` | `standart`·`gold`·`elit` ya da boş | — |
| `--from` | `YYYY-MM-DD`, en erken bugün, günün **başı** | "geçmişte kalıyor" |
| `--until` | `YYYY-MM-DD`, günün **sonu**, gelecekte, `--from`'dan sonra | — |
| `--label` | ≤ 120 karakter | — |

- **Tarihler `davetkart.default_timezone` (İstanbul) saatine göre** okunur ve UTC olarak
  saklanır. `--until=2027-01-07` yazdığında kampanya İstanbul saatiyle 7 Ocak 23:59:59'da
  biter, UTC'ye göre değil.
- `2027-02-31` gibi bir tarihi PHP sessizce 3 Mart'a çevirir. Spec bunu, tarihi geri yazıp
  karşılaştırarak yakalar (`GiftCodeSpec` ile aynı yöntem).
- Yüzde sınırı veritabanındaki CHECK ile aynı (`MAX_PERCENT = 90`). Kısıt son savunma hattı,
  spec ise insana okunur bir hata mesajı verir.

## 3. Neden çakışma kontrolü yok?

"Gold'a %30" ve "bütün planlara %10" aynı anda açık olabilir. Fiyat hesabı her plan için
uygun kampanyaların **en büyüğünü** seçer (`QuotePriceAction`). Kontrol koysaydık ikincisini
açmak için birincisini durdurmak zorunda kalırdın.

## 4. Durdurma

`StopPriceCampaignAction` satırı **silmez**, `stopped_at` yazar. Kampanyadan indirim almış
siparişler `price_campaign_id` ile ona bağlı (FK `restrict`). İdempotent: ikinci durdurma
ilk anı değiştirmez. Kimlik büyük/küçük harf duyarsız (`strtolower`), çünkü ULID tabloda
küçük harfle saklanır.

## 5. Log

Açma ve durdurma `info` log'u yazar: kimlik, yüzde, plan, pencere. Fiyat değişikliği bir iş
olayıdır. "Geçen hafta neden herkes %20 indirim aldı?" sorusunun cevabı log'da durmalı.
