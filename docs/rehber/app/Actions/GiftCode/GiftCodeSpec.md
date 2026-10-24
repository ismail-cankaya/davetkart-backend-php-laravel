# `app/Actions/GiftCode/GiftCodeSpec.php` — kodun özellikleri

> **Faz:** 11, adım 11.9
> **Test:** `GiftCodeCreateCommandsTest` (23 geçersiz birleşim + 6 tutar biçimi + tarih)

---

## 1. Neden ayrı bir sınıf?

İki komut (`create`, `generate`) aynı seçenekleri alır: `--benefit`, `--tier`, `--percent`,
`--amount`, `--expires`, `--label`. Seçenekleri ayrıştırma ve birleşim kuralları iki komutta
kopyalansaydı biri güncellenip diğeri unutulurdu (C3).

`GiftCodeSpec::fromOptions()` ham metinleri alır ve ya **geçerli** bir nesne döner ya da
operatöre gösterilecek Türkçe bir mesajla `InvalidArgumentException` fırlatır. Kurucu
`private`: geçersiz bir spesifikasyon nesne olarak **var olamaz** (A2 ailesi).

## 2. Birleşim kuralları

| `--benefit` | Zorunlu | Yasak |
|---|---|---|
| `free` | `--tier` | `--percent`, `--amount` |
| `percent` | `--percent` (1–100, tam sayı) | `--amount` |
| `fixed` | `--amount` (TL) | `--percent` |

`--tier` `percent` ve `fixed`'de isteğe bağlı (boş = bütün planlar). Veritabanı CHECK'leri aynı
kuralı ikinci kez korur. Komut ise operatöre **neyin** yanlış olduğunu söyler, veritabanı yalnızca
"kısıt ihlali" der.

## 3. 🔴 `--amount` float'sız kuruşa

```php
// "150.50" → ['150', '50'] → 150 × 100 + 50 = 15050
preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $amount, $parts);
$kurus = (int) str_pad($parts[2] ?? '0', 2, '0');   // "5" → "50"
```

`(float) '150.50' * 100` yazılsaydı ne olurdu? Bu örnekte doğru sonucu verir, ama
`(float) '0.29' * 100` → `28.999999999999996` ve `(int)` ile **28** olur. Bir kuruş
kaybolur. Tam ve kesir kısımları ayrı ayrı tam sayıya çevrilince bu hata sınıfı yapısal olarak
imkânsız (CLAUDE.md §3).

`str_pad` neden? `150.5` "150 lira 5 kuruş" değil "150 lira **50** kuruş" demek. Dolgu
olmasaydı 15005 çıkardı. Mutasyon testi tam olarak bunu yakalıyor (`kuruş dolgusu yok` → kırmızı).

Kabul edilenler: `150`, `150.50`, `150,50` (Türkçe klavyede virgül). Reddedilenler: `1.005` (3
ondalık), `-5`, `1e3`, `0`. Üst sınır 100.000 TL: bir yazım hatası (`15000` yerine `150000`)
fark edilmeden kampanyaya dönmesin.

## 4. `--expires`: günün sonu, İstanbul'da

```php
CarbonImmutable::createFromFormat('!Y-m-d', '2026-12-31', 'Europe/Istanbul')
    ->endOfDay()   // 2026-12-31 23:59:59.999999 İstanbul
    ->utc();       // 2026-12-31 20:59:59.999999 UTC → saklanır
```

"31 Aralık'a kadar geçerli" diyen operatör İstanbul'daki 31 Aralık'ın **sonunu** kastediyor.
UTC'de yorumlansaydı kod İstanbul'da 31 Aralık saat 03:00'te (UTC gece yarısı) ölürdü. Saat
dilimi `davetkart.default_timezone`'dan gelir (`contact:list --since` ile aynı).

- `!` öneki saat kısmını sıfırlar; olmasaydı "şu anki saat" eklenirdi.
- `2027-02-31` gibi olmayan bir gün PHP'de 3 Mart'a taşınır. Geri yazınca aynı mı diye
  bakılarak reddedilir.
- Geçmiş tarih reddedilir: doğduğu anda ölü bir kod operatör hatasıdır.

## 5. `singleUse()`

Toplu üretim her kodu tek kullanımlık yapar (K115). `GenerateGiftCodeBatchAction` gelen
spesifikasyonu `singleUse()` ile kopyalar. Komut `--max-uses` seçeneğini zaten sunmuyor, ama
Action başka bir yerden çağrılsa bile kural Action'da korunuyor.

## 6. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| `(int) ((float) $amount * 100)` | `0.29` → 28 kuruş |
| `Carbon::parse('2026-12-31')` | UTC gece yarısı = İstanbul 03:00'te biter |
| `free` için `--tier` kontrolünü veritabanına bırakmak | Operatör `SQLSTATE[23514]` görür, neyin yanlış olduğunu anlamaz |
