# FAZ 17 — Elle Doğrulama Betiği (kısa)

> **Tarih:** 20 Kasım 2026 (Faz 19.3, `ARC-02`) · **Adım sayısı:** 4 (+ Adım 0)
> **Ortam:** Yerel (Herd + Vite) ve bir telefon (jiroskop için gerçek cihaz) · gerçek ödeme YOK
> Her adımın sonucunu yaz. Gözlenmeyen bir şeye ✅ konmaz (B7). Şablon listesi: [`FAZ-17.md`](FAZ-17.md) §2.

## Adım 0 — Kapı

[`FAZ-16-ELLE-DOGRULAMA.md`](FAZ-16-ELLE-DOGRULAMA.md) Adım 0 ile aynı (bir kez koşmak yeter).

## Adım 1 — Her şablon mobilde açılır

| Kimlik | Açıldı | Video / WebGL / sensör | Not |
|---|---|---|---|
| `kurumsal-lansman` | | | |
| `kina-bugulu-ayna` | | | |
| `sunnet-ucan-hali` | | | |
| `parti-lazer` | | | |
| `dugun-kelebek` | | | |
| `dugun-karanlik-oda` | | | |
| `nisan-koi` | | | |
| `parti-kokteyl` | | | |
| `mezuniyet-yillik` | | | |
| `dogum-gunu-surpriz-makinesi` | | | |
| `baby-kazi-kazan` | | | |

iOS'ta sensörlü şablonlar jiroskop iznini sorar (frontend 15.2); izin verilmezse şablon yine okunur.

## Adım 2 — Plan duvarı (Gold)

Standart planlı davetiyede `nisan-koi` → plan duvarı, yayın olmaz; Gold ile yayınlanır.

## Adım 3 — Standart kalan iki şablon

Standart planlı davetiyede `dogum-gunu-surpriz-makinesi` ve `baby-kazi-kazan` duvarsız yayınlanır.

## Adım 4 — Misafir sayfası

Adım 2 ve 3'ün davetiyeleri gizli pencerede açılır; şablonlar görünür.
