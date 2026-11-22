# FAZ 17 — Premium etkileşimli koleksiyon (9 Gold + 2 Standart şablon)

> **Tarih:** 5 Kasım 2026 · **Dal:** `ccr-9a346997-bu6gve` (backend ve frontend)
> **Durum:** kod ✅ · `composer check` yazıldığı ortamda **koşmadı**; yeşil: Faz 18.1 (K150) · elle doğrulama bekliyor
> **Önceki:** [`FAZ-16.md`](FAZ-16.md) · **Sonraki:** [`FAZ-18.md`](FAZ-18.md) · **Elle doğrulama:** [`FAZ-17-ELLE-DOGRULAMA.md`](FAZ-17-ELLE-DOGRULAMA.md)
> **Frontend eşi:** `docs/rehber/src/components/templates/onbir-premium-sablon.md`
> Bu dosya Faz 19.3'te (`ARC-02`, 20 Kasım 2026) commit'lerden ve koddan geriye dönük yazıldı.

---

## 1. Fazın amacı

On bir yeni şablon; dokuzu Gold. "Premium" tanımı videodan **premium etkileşime** genişledi:
canlı WebGL ya da sensörlü (jiroskop) şablon da videosuz olsa Gold olabilir.

## 2. Şablon kimlikleri

| Kimlik | Tür | Plan |
|---|---|---|
| `kurumsal-lansman` | video | Gold |
| `kina-bugulu-ayna` | video | Gold |
| `sunnet-ucan-hali` | video | Gold |
| `parti-lazer` | video | Gold |
| `dugun-kelebek` | canlı WebGL / sensör | Gold |
| `dugun-karanlik-oda` | canlı WebGL / sensör | Gold |
| `nisan-koi` | canlı WebGL / sensör | Gold |
| `parti-kokteyl` | canlı WebGL / sensör | Gold |
| `mezuniyet-yillik` | canlı WebGL / sensör | Gold |
| `dogum-gunu-surpriz-makinesi` | etkileşimli | Standart |
| `baby-kazi-kazan` | etkileşimli | Standart |

## 3. Yazılan dosyalar

| Dosya | Ne |
|---|---|
| `config/davetkart.php` → `preset_tiers` | dokuz kimlik `gold` ("Faz 17" yorumu); iki Standart kimlik listede yok |
| `tests/Feature/PaywallTest.php` | liste 32 kimliğe sabitlendi |
| `tests/Feature/TemplateCatalogueTest.php` | `faz17Presets`: on bir kimlik saklanır, misafire ulaşır, planını ister |

Commit: `d810410` (17.1). Frontend: `44caa49` (katalog, kapaklar, rehber).

## 4. Kararlar

| K | Karar |
|---|---|
| K102 | Premium = video teması → **genişletildi → K252** |
| K252 | Gold = videolu **ya da** premium etkileşimli (canlı WebGL / sensörlü) şablon; Faz 16'nın 10 ve Faz 17'nin 9 kimliği `preset_tiers`'te. ⚠️ Onay yalnızca kod yorumunda ve commit mesajında ("İsmail'in onayı, 2026-11-05"); bu kayıt İsmail'e onaylatılmadı |

Kaynak: `config/davetkart.php` `preset_tiers` · `contracts/plan-rules.json` `presetTiers` (K146).
Frontend `src/data.ts` `minimumTier: 'gold'` sayısı 32, backend ile aynı.

## 5. Doğrulama durumu

Yazıldığı ortamda koşmadı (PHP 8.3; yalnızca `php -l`). İlk koşu `AUD-2026-11-12` (kırmızı,
`TST-01`: `TemplateCatalogueTest` Pint `ordered_class_elements`); yeşil: Faz 18.1 (K150) →
[`FAZ-18.md`](FAZ-18.md). Kimlik listesi `presetTiers` ile karşılaştırıldı (Faz 19.3).
