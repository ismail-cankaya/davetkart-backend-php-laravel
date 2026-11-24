# `app/Actions/Pricing/StopPriceCampaignAction.php`

> **Faz:** 13 · **Ana kılavuz:** `StartPriceCampaignAction.md` §4 · **Test:** `tests/Feature/PriceCampaignCommandsTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

`pricing:campaign:stop <id>` komutunun eylemi: kampanyayı hemen durdurur (`stopped_at = now()`). Satır silinmez.

## Neden böyle

Silinen kampanya geçmiş siparişlerin hangi indirimle alındığını açıklayamaz (muhasebe izi). İdempotent: zaten durmuş bir kampanyayı tekrar durdurmak ilk durdurma anını değiştirmez (`DisableGiftCodeAction` kalıbı).

## Kurallar

- Kimlik küçük harfe çevrilir (ULID); yoksa `InvalidArgumentException` (komut Türkçe mesaj gösterir).
- Yalnızca ilk durdurmada `Log::info`.

## Sık hata

| Hata | Sonuç |
|---|---|
| Kampanyayı `delete()` ile kaldırmak | Geçmiş siparişlerin indirimi açıklanamaz |
| Her çağrıda `stopped_at`'i yeniden yazmak | İlk durdurma anı kaybolur |
