# FAZ 16 — Elle Doğrulama Betiği (kısa)

> **Tarih:** 20 Kasım 2026 (Faz 19.3, `ARC-02`) · **Adım sayısı:** 3 (+ Adım 0)
> **Ortam:** Yerel (Herd + Vite) ve bir telefon · gerçek ödeme YOK
> Her adımın sonucunu yaz. Gözlenmeyen bir şeye ✅ konmaz (B7). Şablon listesi: [`FAZ-16.md`](FAZ-16.md) §2.

## Adım 0 — Kapı

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
composer check
php artisan plans:export --check
cd ..\davetkart-frontent
npm run check
npm run verify:render
```

## Adım 1 — Her şablon mobilde açılır

Telefonda (ya da DevTools 390×844) on şablonun her biri için: önizleme açılır, video oynar
(ya da sessiz başlar), metin okunur, yatay kaydırma yok.

| Kimlik | Açıldı | Video | Not |
|---|---|---|---|
| `dugun-saray-kapisi` | | | |
| `dugun-yasemin` | | | |
| `dugun-kristal-balo` | | | |
| `nisan-iplik` | | | |
| `kina-al-duvak` | | | |
| `sunnet-sehzade` | | | |
| `dogum-gunu-havai-fisek` | | | |
| `mezuniyet-kep` | | | |
| `baby-ay-besigi` | | | |
| `parti-havuz` | | | |

## Adım 2 — Plan duvarı

Standart planlı bir davetiyede `dugun-yasemin` seçilip yayınlanmak istenir → plan duvarı (Gold ister),
yayın olmaz. Aynı davetiye Gold hakkıyla yayınlanır.

## Adım 3 — Misafir sayfası

Gold ile yayınlanan davetiye gizli pencerede açılır; şablon ve video görünür.
