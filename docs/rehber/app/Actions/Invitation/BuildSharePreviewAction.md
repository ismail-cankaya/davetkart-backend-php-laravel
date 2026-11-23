# `BuildSharePreviewAction`

> **Faz:** 19.23 — `FE-04` · **Karar:** K230

Bot önizlemesinin değerleri: `url` (`frontend.url` + `/invite/<id>`, istek başlığından değil — SEC-07 dersi), `title`
(adlar; tek taraflı `&` ayracı temizlenir), `description` (`j F Y, H:i` Türkçe · mekân), `image` (galeri açıksa ilk
görsel, yoksa `share_preview.image_path`), `siteName` (Elit/logosuz → boş, K102).

**Yalnızca yayındaki davetiye** (`PublicInvitationPayloadAction` → `ResolvePublicInvitationAction`); taslak, silinmiş,
olmayan → aynı genel önizleme (H7). Şablon kapağı kullanılmadı: kapaklar frontend derlemesinde hash'li adla, backend bilemez.
