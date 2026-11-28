# `database/migrations/2026_11_28_120000_add_pending_changes_to_invitations_table.php`

Faz 27.3 (K323). `invitations.pending_changes jsonb null`. Biçim: `app/Actions/Invitation/PendingChanges.md`.
Sorgulanmaz, misafir yanıtına girmez (`PublicInvitationResource` okumaz). Fillable değil: yalnızca Action'lar yazar.
