# `app/Actions/Invitation/DiscardPendingChangesAction.php`

Faz 27.3 (K323). `DELETE /invitations/{id}/pending`, "Vazgeç": `pending_changes = null`. Olaysız ve zaman damgasız
yazım (`SavePendingChangesAction` ile aynı neden). Canlı davetiye değişmez.
