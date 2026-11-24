# `app/Actions/Pricing/PriceCampaignSpec.php`

> **Faz:** 13 (fiyat kampanyası) · **Ana kılavuz:** `StartPriceCampaignAction.md` §2 · **Test:** `tests/Feature/PriceCampaignCommandsTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

`pricing:campaign:start` komutunun ham seçeneklerini (`--percent`, `--tier`, `--from`, `--until`, `--label`) tipli ve sınırlı bir değere çevirir. Komut metni verir; `fromOptions()` ya geçerli bir `PriceCampaignSpec` döner ya da operatörün okuyacağı Türkçe bir `InvalidArgumentException` atar.

## Neden böyle

`GiftCodeSpec` kalıbı: doğrulama komutta dağınık `if`'ler olmasın, tek yerde ve test edilebilir olsun. Kampanya fiyatı doğrudan değiştirdiği için yanlış bir yüzde ya da geçmiş tarih sessizce kabul edilmemeli.

## Kurallar

- Yüzde 1–`MAX_PERCENT` (90) arası tam sayı; 90 veritabanındaki `price_campaigns_percent_check` ile aynı. %100 = hediye kodu işi.
- `--from` o günün başı, `--until` o günün sonu; ikisi de `config('app.timezone')` ile yorumlanır, UTC saklanır.
- `--from` bugünden önce olamaz; `--until` geçmişte ya da `--from`'dan önce olamaz.
- Tarih `YYYY-MM-DD`; `2026-02-31` gibi taşan tarihler geri yazma karşılaştırmasıyla reddedilir.
- `--label` en çok 120 karakter; boş = `null`.

## Sık hata

| Hata | Sonuç |
|---|---|
| Yüzdeyi `(int)` ile doğrudan çevirmek | `"12abc"` 12 olur; düzenli ifade bunu engeller |
| `createFromFormat` sonucuna güvenmek | 31 Şubat sessizce 3 Mart olur |
| Sınırı yalnızca kodda tutmak | Veritabanı kısıtıyla ayrışır; iki yerde aynı sayı |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
- **Değer nesnesi:** kimliği olmayan, yalnızca taşıdığı değerle anlamlı nesne.
