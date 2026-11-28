# `2026_11_28_100000_add_text_customization_to_invitations_table.php`

Faz 24. `invitations`'a üç kolon:

| Kolon | Tür | Karar | Not |
|---|---|---|---|
| `locale` | `varchar(8)` not null, `'tr'` | K302 | Davetiyenin dili; arayüz dilinden bağımsız. Geçerli değerler sözleşmenin `locales`'i (v2.0: yalnızca `tr`) |
| `text_overrides` | `jsonb` null | K303 | Yalnızca değişen metinler, dile göre gruplu. Sorgulanmaz (K6: küçük içerik jsonb) |
| `has_custom_texts` | `boolean` not null, `false` | K305 | Paket kuralının girdisi (`module_tiers.has_custom_texts = elit`). Yalnızca model mutator'ı yazar |

Var olan satırlar `locale = 'tr'`, `text_overrides = null`, `has_custom_texts = false` olur: hiçbir davetiyenin
görünümü ve gereken paketi değişmez.

`has_custom_texts` neden generated column değil: `UpdateInvitationAction` paket kontrolünü `save()`'den önce bellekteki
modelde yapar; veritabanının hesapladığı kolon orada bayat kalırdı (yayındaki davetiyede Elit kapısı atlanırdı).
