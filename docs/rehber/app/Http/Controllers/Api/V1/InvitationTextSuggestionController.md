# `app/Http/Controllers/Api/V1/InvitationTextSuggestionController.php`

Faz 25 (K310). `POST /api/invitations/{invitation}/text-suggestions` → `{ data: { suggestions: string[] } }`.
Sahiplik `Gate::authorize('update', $invitation)` (başkasının davetiyesi 404, H7). Resource yok: ortada model yok,
`AssistantController` ile aynı gerekçe.
