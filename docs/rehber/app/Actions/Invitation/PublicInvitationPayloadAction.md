# `PublicInvitationPayloadAction`

> **Faz:** 19.23 — `FE-04` · **Karar:** K230 · Eski yeri: `PublicInvitationController::show` (4.2b, K7)

Misafir yükü (`PublicInvitationResource` + imza kararı), `Invitation::publicCacheKey($id)` altında önbellekli.
İki çağıran: API (`GET /api/public/invitations/{id}`) ve bot önizlemesi (`BuildSharePreviewAction`). Aynı anahtar =
aynı yaşam döngüsü: `ClearInvitationCache` (yayın, güncelleme, ödeme) ikisini birden tazeler (FE-04 D4).
Bulunamadı/yayında değil → `ModelNotFoundException` (önbelleğe yazılmaz).

## Faz 19 ortak düzeltme (A-E3) — canlı bayrak

Önbellekteki gövde davetiyenin verisidir; `guestUploads` ise ortam ayarıdır (`DAVETKART_GUEST_UPLOADS`). `withLiveGuestUploadFlag`
önbellekten (ya da yeni üretilen) yükte alan varsa onu her istekte `Config::boolean('davetkart.guest_uploads.enabled')`
ile yeniden yazar. Alan yoksa (LCV kapalı) eklemez. Eskiden bayrak değişince gövde 6 saate kadar eski değeri taşıyordu
ve `php artisan cache:clear` gerekiyordu. Test: `PublicInvitationTest::the_guest_upload_flag_is_not_frozen_in_the_cached_payload`
(mutant: yeniden yazma kapalı → kırmızı).
