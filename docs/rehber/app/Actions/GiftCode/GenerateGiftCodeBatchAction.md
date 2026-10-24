# `app/Actions/GiftCode/GenerateGiftCodeBatchAction.php` — toplu tek kullanımlık kodlar

> **Faz:** 11, adım 11.9 · **Karar:** K115 (D9) · **Komut:** `gift-codes:generate`

---

## 1. Ne yapar?

1. `count` (1–1000) adet `DK-XXXX-XXXX-XXXX` kodu üretir (bellek içinde tekrarsız)
2. Hepsine aynı `batch_id`'yi (ULID, küçük harf) verir, `max_redemptions = 1`
3. Satırları ve CSV'yi **aynı transaction'da** yazar
4. `['batchId', 'path', 'count']` döner. Kodları **döndürmez**

CSV: `storage/app/private/gift-codes/<batch_id>.csv`

```csv
code,benefit,tier,percent_off,amount_off_minor,expires_at_utc,label,batch_id
DK-7KQ2-M9XA-P4TR,free,elit,,,,Fuar 2026,01m5m3…
```

## 2. 🔴 Kodlar bir kez gösterilir (K115)

Veritabanında yalnızca özet var (K109). CSV yazıldıktan sonra kodları **hiçbir yerden** geri
okumak mümkün değil. CSV kaybolursa:

```powershell
php artisan gift-codes:list --batch=<batch_id>      # hangi kodlar
php artisan gift-codes:disable <id>                 # her birini kapat
php artisan gift-codes:generate --count=...         # yeniden üret
```

> ⚠️ Toplu kapatma (`--batch` ile) bu fazda yok. 1000 kodluk bir batch'i kapatmak bugün
> 1000 komut demek. İhtiyaç doğarsa `gift-codes:disable --batch=` eklenebilir.

## 3. 🔴 Yazım sırası: satırlar ve CSV birlikte

İki dosya sisteminin (veritabanı + disk) **atomik** yazılması mümkün değil. Hangi yarım
durumun daha kötü olduğuna bakıldı:

| Yarım durum | Sonuç | Ne kadar kötü |
|---|---|---|
| Satırlar var, CSV yok | Kimsenin bilmediği kodlar: kimse kullanamaz | Zararsız ama kirli |
| CSV var, satırlar yok | **Çalışmayan kodlar dağıtılır**: müşteri "geçersiz kod" görür | Kötü |

Çözüm:

```php
try {
    DB::transaction(function () {
        // 1. satırları yaz
        // 2. CSV'yi yaz; yazılamazsa fırlat → satırlar geri alınır
    });                                     // 3. commit
} catch (Throwable $e) {
    Storage::disk('local')->delete($path);  // commit patladıysa CSV de silinir
    throw $e;
}
```

- CSV yazılamazsa (disk dolu, izin yok) transaction geri alınır. Test:
  `a_failed_csv_write_rolls_the_codes_back`.
- Commit patlarsa CSV silinir.

> **Mutasyon notu:** `catch (Throwable)` → `catch (LogicException)` yapıldığında testler
> yeşil kaldı. Commit'in CSV yazıldıktan **sonra** patladığı yol testle sınanmadı (PHPUnit'te
> commit'i patlatmanın temiz bir yolu yok). Kod yolu basit, ama bu bir boşluk.

`Storage::disk('local')->put()` `false` döndürebilir: disk ayarında `'throw' => false` var,
yani hata fırlatmaz, sessizce `false` döner. Dönüş değeri bu yüzden kontrol ediliyor.

## 4. Neden `storage/app/private`?

`local` diski web kökünün (`public/`) dışında. CSV'yi tarayıcıdan indirmenin yolu yok.
Canlıda `scp` ile indirilir (`FAZ-11-ELLE-DOGRULAMA.md`). İndirildikten sonra sunucudan
silinmesi önerilir: sunucu sızarsa CSV de sızar.

## 5. CSV injection

Excel `=`, `+`, `-`, `@` ile başlayan hücreyi **formül** sayar.
`--label='=HYPERLINK("http://kotu.site")'` gibi bir etiket CSV'yi açan kişinin ekranında
tıklanabilir bir bağlantıya dönerdi. `neutralize()` bu karakterle başlayan hücrenin başına `'`
koyar (`a_spreadsheet_formula_in_the_label_is_neutralized`). Etiketi operatör yazıyor, risk
düşük, ama savunmanın maliyeti tek satır.

## 6. Çakışma olasılığı

Bellek içinde `$codes[$kod] = true` ile tekrar engellenir. Veritabanında var olan bir kodla
çakışma olasılığı yaklaşık `n / 30¹²`, yani 1000 kod için ~2·10⁻¹⁵. Olursa UNIQUE kısıt
yakalar, bütün batch geri alınır ve komut yeniden koşulur.

## 7. Performans

1000 kod = 1000 `INSERT` + bir CSV. Yerel denemede bir saniyenin altında. Toplu `insert()`
kullanılmadı: `fill()` ile aynı atama yolundan geçmek, modelin cast'lerini (enum → string,
tarih → UTC) atlamamak demek.
