# `app/Console/Commands/CreateGiftCode.php` — `gift-codes:create`

> **Faz:** 11, adım 11.9
> **Test:** `tests/Feature/GiftCodeCreateCommandsTest.php`

---

## 1. Kullanım

```powershell
# %20, bütün planlar, 50 kişi, 31 Ocak sonuna kadar
php artisan gift-codes:create DUGUN2026 --benefit=percent --percent=20 --max-uses=50 --expires=2027-01-31 --label="Instagram cekilisi"

# Gold'u bedava açar, tek kişilik
php artisan gift-codes:create HEDIYEGOLD --benefit=free --tier=gold

# 150,50 TL indirim, yalnızca Elit
php artisan gift-codes:create ELIT150 --benefit=fixed --amount=150.50 --tier=elit --max-uses=10
```

| Seçenek | Varsayılan | Not |
|---|---|---|
| `code` | — | 4–32 karakter, `A-Z0-9`; boşluk ve tire atılır, küçük harf büyür |
| `--benefit` | — | `free` · `percent` · `fixed` |
| `--tier` | boş | `free` için zorunlu; diğerlerinde boş = bütün planlar |
| `--percent` | — | 1–100, tam sayı |
| `--amount` | — | TL: `150`, `150.50`, `150,50` |
| `--max-uses` | 1 | Toplam kullanım; kişi başı her zaman 1 |
| `--expires` | süresiz | `YYYY-MM-DD`, o günün **sonuna** kadar, İstanbul saatiyle |
| `--label` | boş | En fazla 120 karakter |

Çıkış kodları: başarı `0`, geçersiz seçenek ya da var olan kod `2` (`Command::INVALID`).

## 2. İnce komut

Komut controller gibi davranır: seçenekleri okur, `GiftCodeSpec::fromOptions()` ile
doğrular, `CreateGiftCodeAction`'ı çağırır, sonucu tablo olarak basar. İş kuralı yok.
Hataların hepsi `InvalidArgumentException` olarak gelir ve kırmızı bir satırla gösterilir:

```
 ERROR --benefit=free hangi plani actigini bilmeli: --tier zorunlu.
```

## 3. Çıktı

```
 INFO Kod olusturuldu.
+----------------------------+-------+---------+-------+-----+------------------+
| Kimlik                     | Ipucu | Fayda   | Plan  | Max | Bitis            |
| 01m5m333m57b7mnh6cegg6e0bm | …2026 | percent | hepsi | 50  | 2027-01-31 23:59 |
```

Kod yeniden basılmaz. Kimlik `gift-codes:disable` için gerekli.

## 4. Testte doğrulananlar

- Düz kod (`DUGUN2026` ve `dugun-2026`) veritabanı satırında yok
- `150.50` / `150,50` / `150.5` → 15050, `0.30` → 30 (float hatası yok)
- `--expires=2026-12-31` → UTC `2026-12-31 20:59:59`; bir saniye önce geçerli, sonra değil
- 23 geçersiz birleşim exit 2 ile reddedilir ve satır yazılmaz
- `dugun 2026`, `DUGUN2026` var iken "zaten var" der

## 5. Canlıda

```bash
ssh deploy@sunucu
cd /var/www/davetkart/backend
sudo -u www-data php artisan gift-codes:create DUGUN2026 --benefit=percent --percent=20 --max-uses=50
```
