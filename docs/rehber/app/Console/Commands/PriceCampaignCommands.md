# `pricing:campaign:start` · `pricing:campaign:list` · `pricing:campaign:stop`

> **Faz:** 13, adım 13.3 · **Dosyalar:** `StartPriceCampaign.php`, `ListPriceCampaigns.php`, `StopPriceCampaign.php`
> **Test:** `PriceCampaignCommandsTest`

---

## 1. Kullanım

```powershell
# Hemen başla, 7 Ocak'ın sonuna kadar, bütün planlarda %20
php artisan pricing:campaign:start 20 --until=2027-01-07 --label="Yilbasi"

# 1 Kasım'da başla, yalnızca Gold, durdurulana kadar %30
php artisan pricing:campaign:start 30 --tier=gold --from=2026-11-01

# Listele (en yeni üstte); yalnızca şu an geçerli olanlar
php artisan pricing:campaign:list
php artisan pricing:campaign:list --active

# Hemen durdur
php artisan pricing:campaign:stop 01jb2k...
```

## 2. `list` çıktısı

| Sütun | Anlamı |
|---|---|
| Durum | `GECERLI` · `baslamadi` · `bitti` · `durduruldu` |
| Ödenen sipariş | Bu kampanyadan indirim alıp **ödenmiş** sipariş sayısı (bekleyenler sayılmaz) |

Tablonun altında ilk yayın indiriminin o anki değeri yazar. İki kaynak birlikte düşünülmeli,
çünkü toplanmazlar (K133).

Sayım tek alt sorguda yapılır (`withCount`, N+1 yok). Not alanındaki kontrol karakterleri
terminale ham basılmaz, `ListGiftCodes` ile aynı şekilde kaçırılır.

## 3. Uyarı

`start`, ilk yayın indirimi açıksa şunu yazar: "İlk yayın indirimi (%60) ile toplanmaz;
ikisi birden uygunsa büyük olan uygulanır." Yani %20'lik bir kampanya yeni üyeye bir şey
kazandırmaz; onlar zaten %60 alıyor.
