# `app/Http/Controllers/Api/V1/InvitationPreviewController.php`

Faz 29.4 (K328).

| Uç | Yetki | Yanıt |
|---|---|---|
| `POST /invitations/{id}/preview-link` | sahibi (`InvitationPolicy::update`; başkası 404) | `{ data: { url, expiresAt } }` |
| `GET /public/invitations/{id}/preview?expires&signature` | `signed:relative` (bozuk, süresi dolmuş ya da başka davetiyenin imzası → 404, H7) | `PublicInvitationResource` biçimi; `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow`; ETag ve genel önbellek yok |
