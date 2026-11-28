# `app/Actions/Invitation/PendingChanges.php`

Faz 27.3 (K323). `invitations.pending_changes` kolonunun tipli okuması.

```json
{ "attributes": { "title": "Yeni başlık", "text_overrides": { "tr": { "rsvp.submit": "Geliyorum!" } } },
  "timelineEvents": [ { "id": "12", "time": "19:00", "title": "Karşılama", "description": "" } ] }
```

- `attributes`: **kolon adlarıyla** (`InvitationRequest::invitationAttributes()` çıktısı), yalnızca canlıdan farklı olanlar.
- `timelineEvents`: istekteki biçimle; yoksa program değişmedi. Galeri yok: yükleme, silme ve sıra medya uçlarında
  anında yazılır.
- Boşsa kolon `null` (rozet ve `hasPendingChanges` buna bakar).
- `without($columns, $timeline)`: canlıya yazılan alanlar taslaktan düşer (K324).
