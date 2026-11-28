# `app/Http/Controllers/Api/V1/InvitationPendingController.php`

Faz 27.3 (K323). Üç uç, üç Action; yetki `InvitationPolicy::update` (başkasının davetiyesi 404, H7). Kaydetme
`UpdateInvitationRequest` ile doğrulanır: taslak normal güncellemeyle aynı kurallardan geçer. Yanıt `InvitationResource`
(`hasPendingChanges`, `pendingChanges`).
