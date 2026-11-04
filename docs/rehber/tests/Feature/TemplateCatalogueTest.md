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
