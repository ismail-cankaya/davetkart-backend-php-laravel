# FAZ 16 — Premium sinematik koleksiyon (10 Gold şablon)

> **Tarih:** 4–5 Kasım 2026 · **Dal:** `faz15-desing/claude-davetiye` (backend) · `design/claude-davetiye` (frontend)
> **Durum:** kod ✅ · `composer check` yazıldığı ortamda **koşmadı**; yeşil: Faz 18.1 (K150) · elle doğrulama bekliyor
> **Önceki:** [`FAZ-15.md`](FAZ-15.md) · **Sonraki:** [`FAZ-17.md`](FAZ-17.md) · **Elle doğrulama:** [`FAZ-16-ELLE-DOGRULAMA.md`](FAZ-16-ELLE-DOGRULAMA.md)
> **Frontend eşi:** `docs/rehber/src/components/templates/premium-videolu.md` (video boru hattı: `docs/rehber/scripts/video.md`)
> Bu dosya Faz 19.3'te (`ARC-02`, 20 Kasım 2026) commit'lerden ve koddan geriye dönük yazıldı.

---

## 1. Fazın amacı

Fiyat kartındaki "Premium tema koleksiyonu" vaadine yeni içerik: kodla üretilmiş videolu on şablon.
Backend'de yalnızca plan kuralı değişti; şablon listesi frontend'in malı (Faz 15 §1).

## 2. Şablon kimlikleri

| Kimlik | Tür | Plan |
|---|---|---|
| `dugun-saray-kapisi` | video (iki klip) | Gold |
| `dugun-yasemin` | video | Gold |
| `dugun-kristal-balo` | video (kaydırmayla) | Gold |
| `nisan-iplik` | video | Gold |
| `kina-al-duvak` | video | Gold |
| `sunnet-sehzade` | video | Gold |
| `dogum-gunu-havai-fisek` | video | Gold |
| `mezuniyet-kep` | video (kaydırmayla) | Gold |
| `baby-ay-besigi` | video | Gold |
| `parti-havuz` | video → WebGL | Gold |

## 3. Yazılan dosyalar

| Dosya | Ne |
|---|---|
| `config/davetkart.php` → `preset_tiers` | on kimlik `gold` ("Faz 16" yorumu) |
| `tests/Feature/PaywallTest.php` | `the_premium_themes_are_the_video_themes` listeyi 23 kimliğe sabitledi |
| `contracts/plan-rules.json` | Faz 18.1'den beri `plans:export` üretir (K146); bu fazda dosya yoktu |

Commit: `82c7312` (16.1). Frontend: `ced4f2a` … `bd65665` (16.2–16.12).

## 4. Kararlar

| K | Karar |
|---|---|
| K102 | Premium = arka planı video olan tema (1 Ekim 2026). Bu fazın on şablonu bu kuralın uygulaması; yeni karar yok |
| K252 | Faz 16 ve 17'nin 19 Gold şablonu kayda geçti (Faz 19.3); ayrıntı [`FAZ-17.md`](FAZ-17.md) §4 |

## 5. Doğrulama durumu

Yazıldığı ortamda koşmadı (bulut çalışma alanında PHP 8.3; proje ^8.5 ister). İlk koşu
`AUD-2026-11-12` (kırmızı, `TST-01`: Pint); yeşil: Faz 18.1 (K150, `composer check` 1074 test) →
[`FAZ-18.md`](FAZ-18.md). Kimlik listesi `contracts/plan-rules.json` `presetTiers` ile karşılaştırıldı (Faz 19.3).
