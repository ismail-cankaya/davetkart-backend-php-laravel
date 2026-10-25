# FAZ 13 — Elle Doğrulama Betiği

> **Tarih:** 25 Ekim 2026 · **Adım sayısı:** 10 (+ Adım 0)
> **Ortam:** Yerel (Herd + Vite) · gerçek ödeme YOK
> Her adımın sonucunu yaz. Gözlenmeyen bir şeye ✅ konmaz (B7).

---

## Adım 0 — Kapı

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
git switch faz-13-payment-settings
php artisan migrate
composer check          # beklenen: 865 test
cd ..\davetkart-frontent
git switch faz-13-payment-settings
npm run check
```

Backend `.env`'ine ekle: `PAYMENT_METHODS=gift_code` (sonra `php artisan config:clear`).

| # | Adım | Beklenen | Gözlenen |
|---|---|---|---|
| 1 | Çıkış yapmış hâlde `/pricing` | Standart / Gold / Elit, 249 / 399 / 549 ₺; "%60" bandı; uydurma paket (Zarif…) YOK | |
| 2 | `php artisan pricing:campaign:start 20 --until=<gelecek bir gün>` → `/pricing` yenile | Üstü çizili liste fiyatı, 199,20 / 319,20 / 439,20 ₺, "%20 kampanya · <tarih> tarihine kadar" | |
| 3 | Yeni hesap aç → `/pricing` | 99,60 / 159,60 / 219,60 ₺, "İlk yayına %60" (kampanyadan büyük olan) | |
| 4 | Davetiye tasarla → Yayınla → paywall | Aynı fiyatlar; not "yalnızca hediye koduyla" | |
| 5 | Ödeme sayfası (`/odeme?tier=gold`) | Kart / havale düğmesi YOK; "Şu an yalnızca hediye kodu" açıklaması; özet: 399 − 239,40 = 159,60 | |
| 6 | `php artisan gift-codes:create YARI50 --benefit=percent --percent=50 --max-uses=5` → kodu uygula | Özet: −239,40 ve −79,80, toplam 79,80; açıklama "kalan ₺79,80 için açık bir yöntem yok" | |
| 7 | `gift-codes:create BEDAVA1 --benefit=free --tier=gold` → kodu uygula → Ücretsiz etkinleştir | Sipariş `paid`; ödeme dönüş sayfasında "Ödenen tutar: ₺0" | |
| 8 | Aynı kullanıcıyla `/pricing` | Artık liste fiyatı (ya da kampanya). İlk yayın bitti | |
| 9 | `.env`: `PAYMENT_METHODS=card,gift_code` + `config:clear` → ödeme sayfasını yenile | Kart düğmesi geri geldi, havale "Şu an kullanılamıyor" | |
| 10 | `.env`: `PAYMENT_METHODS=card,gift_cod` (yanlış) → `/pricing` | "Fiyat şu an gösterilemiyor"; `/api/ping` çalışıyor. Sonra düzelt | |

Son olarak `php artisan pricing:campaign:list` → adım 2'deki kampanyayı gör ve
`pricing:campaign:stop <kimlik>` ile durdur.
