# `PublicInvitationPayloadAction`

> **Faz:** 19.23 — `FE-04` · **Karar:** K230 · Eski yeri: `PublicInvitationController::show` (4.2b, K7)

Misafir yükü (`PublicInvitationResource` + imza kararı), `Invitation::publicCacheKey($id)` altında önbellekli.
İki çağıran: API (`GET /api/public/invitations/{id}`) ve bot önizlemesi (`BuildSharePreviewAction`). Aynı anahtar =
aynı yaşam döngüsü: `ClearInvitationCache` (yayın, güncelleme, ödeme) ikisini birden tazeler (FE-04 D4).
Bulunamadı/yayında değil → `ModelNotFoundException` (önbelleğe yazılmaz).
