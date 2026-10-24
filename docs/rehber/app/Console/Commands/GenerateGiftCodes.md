# `app/Console/Commands/GenerateGiftCodes.php` — `gift-codes:generate`

> **Faz:** 11, adım 11.9 · **Karar:** K115 (D9)
> **Test:** `GiftCodeCreateCommandsTest` → *gift-codes:generate* bölümü

---

## 1. Kullanım

```powershell
php artisan gift-codes:generate --count=50 --benefit=free --tier=elit --label="Fuar 2026"
php artisan gift-codes:generate --count=200 --benefit=percent --percent=30 --expires=2026-12-31
```

Seçenekler `gift-codes:create` ile aynı, iki farkla:

- `code` argümanı yok: kodlar rastgele üretilir.
- `--max-uses` yok: her kod **tek kullanımlık** (K115). Seçenek sunulsaydı biri
  `--max-uses=100` ile 1000 kod üretip 100.000 kullanımlık bir kampanya açabilirdi.

`--count` 1–1000.

## 2. 🔴 Kodlar konsola basılmaz

```
 INFO 50 tek kullanimlik kod uretildi.
+----------------------------+------+-------+------+---------+
| Batch                      | Adet | Fayda | Plan | Bitis   |
| 01m5m334nn84fn0cscsvrhscbv | 50   | free  | elit | suresiz |
CSV: /var/www/davetkart/backend/storage/app/private/gift-codes/01m5m334nn84fn0cscsvrhscbv.csv
 WARN Kodlar yalnizca bu dosyada. Indirip guvenli bir yere koyun; veritabanindan geri okunamaz.
```

Konsol çıktısı; terminal geçmişine, ekran kaydına, `tmux` tamponuna, bir CI log'una düşebilir.
50 tane `free/elit` kodu 27.450 TL eder. Kodlar yalnızca CSV'ye yazılır. Test:
`doesntExpectOutputToContain('DK-')`.

## 3. Canlıda: üret, indir, sil

```bash
# Sunucuda, 'deploy' kullanıcısıyla. artisan HER ZAMAN web kullanıcısıyla koşar
# (deploy.sh ile aynı kural): storage/ altındaki dosyaların sahibi karışmasın.
cd /var/www/davetkart/backend
sudo -u www-data php artisan gift-codes:generate --count=50 --benefit=free --tier=elit --label="Fuar 2026"
```

```powershell
# Kendi bilgisayarında
scp deploy@sunucu:/var/www/davetkart/backend/storage/app/private/gift-codes/<batch>.csv .
```

```bash
# Sunucuda: indirdikten sonra
sudo -u www-data rm /var/www/davetkart/backend/storage/app/private/gift-codes/<batch>.csv
```

> ⚠️ `scp` 'deploy' kullanıcısıyla bağlanır, dosyanın sahibi `www-data`. Okuma izni yoksa
> önce sunucuda `sudo cp <csv> ~/ && sudo chown deploy ~/<batch>.csv` ile ev dizinine
> kopyalayıp oradan indir, sonra iki kopyayı da sil.
