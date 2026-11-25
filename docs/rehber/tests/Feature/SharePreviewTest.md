# `tests/Feature/SharePreviewTest.php`

> **Faz:** 19.23 — `FE-04` · **Karar:** K230

| Test | Kanıtladığı |
|---|---|
| `a_bot_gets_the_couples_names_in_og_title` | Yayındaki davetiye: `og:title` adlar, açıklama Türkçe tarih · mekân, `og:site_name`, mutlak `og:url`, `Cache-Control` |
| `an_unpublished_invitation_gets_the_generic_preview` | Taslak ve olmayan kimlik aynı genel önizlemeyi alır; ad ve mekân sızmaz (UA sahtelenebilir) |
| `preview_values_are_html_escaped` | Adda/mekânda `<script>`, `"` ve `<img onerror>` kaçışlı |
| `an_elite_invitation_has_no_site_name` | K102: Elit siparişli davetiyede `og:site_name` yok |
| `the_preview_shares_the_guest_cache_entry` | D4: misafir okumasıyla aynı önbellek anahtarı |

**Mutant (23 Kasım 2026):** `ResolvePublicInvitationAction`'dan yayın koşulu silindi → `an_unpublished…` kırmızı (taslak adı sızdı).

- `an_invitation_without_a_gallery_shows_its_template_cover` (B-E4, K235): galerisiz davetiyede kapak dizinindeki yol;
  dizinde olmayan preset ve beklenmeyen (başka alan adı) değer → genel poster.
