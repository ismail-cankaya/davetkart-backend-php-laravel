# `tests/Feature/TemplateCatalogueTest.php`

> **Faz 15** · [FAZ-15](../../fazlar/FAZ-15.md)

Backend şablon listesi tutmaz; bu test o sözleşmenin frontend'in 2026-11'de eklediği 25 kimlik için
de geçerli olduğunu kilitler. Kimlikler bilerek **sabit yazıldı** (10.49'un dersi): liste bir ürün
vaadi, frontend `src/data.ts` → `TEMPLATE_PRESETS` ile aynı.

| Test | Söz |
|---|---|
| `a_new_preset_is_stored_and_read_back_unchanged` | `POST /api/invitations` kimliği reddetmez, yanıtta ve `invitations.preset_id`'de aynen durur; `categoryId` kimliğin önekinden (`dogum-gunu-…` → `dogum-gunu`). |
| `a_new_preset_reaches_the_guest_page` | Yayındaki davetiyenin misafir ucu (`/api/public/invitations/{id}`) kimliği döndürür — frontend `TemplateRenderer` buna göre çizer. |
| `a_new_preset_needs_no_premium_plan` | Modülsüz davetiyede `TierResolver` Standart ister: yeni şablon tek başına planı yükseltmez. |
| `no_new_preset_is_listed_as_premium` | `preset_tiers`'e yeni bir kimlik eklenirse test kırılır ve bilinçli bir karar ister (FAZ-15 §4). |

## Faz 17 — on bir şablon (2026-11-05)

Frontend'in Faz 17 turu on bir şablon ekledi (`docs/rehber/src/components/templates/onbir-premium-sablon.md`,
frontend deposu). Dokuzu **premium** (Gold): dördü kodla üretilmiş videolu (`kurumsal-lansman`,
`kina-bugulu-ayna`, `sunnet-ucan-hali`, `parti-lazer`), beşi canlı WebGL / sensörlü etkileşimli
(`dugun-kelebek`, `dugun-karanlik-oda`, `nisan-koi`, `parti-kokteyl`, `mezuniyet-yillik`). İkisi
(`dogum-gunu-surpriz-makinesi`, `baby-kazi-kazan`) her planda yayınlanır.

> 🔴 **K102'nin genişlemesi:** "premium = arka planı video olan tema" kuralı bu turda İsmail'in onayıyla
> "premium = videolu **ya da** canlı etkileşimli premium tema" oldu. Liste yine `config/davetkart.php`
> → `preset_tiers`'te tek yerde; `PaywallTest::the_premium_themes_are_the_video_themes` 32 kimliği kilitler.

| Test | Söz |
|---|---|
| `a_faz17_preset_is_stored_and_reaches_the_guest_page` | Kimlik kaydedilir, aynen geri okunur ve misafir ucuna ulaşır (kategori öneki: `dogum-gunu-…`, `baby-…` → `baby-shower`). |
| `a_faz17_preset_requires_its_plan` | Modülsüz davetiyede gereken plan yalnızca temadan gelir: dokuzu Gold, ikisi Standart. |

**Çalıştırılmadı:** bulut çalışma alanında PHP 8.3 var, proje `^8.5` istiyor (`php -l` geçti).
