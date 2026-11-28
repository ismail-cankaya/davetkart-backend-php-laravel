# `app/Actions/Invitation/BuildInvitationPreviewAction.php`

Faz 29.4 (K328). Önizlemenin modeli: canlı davetiye + `pending_changes`, **kaydedilmeden** (modelin kopyası `fill()`
edilir; taslak program kaydedilmemiş `TimelineEvent` örnekleriyle `timelineEvents` ilişkisine konur). Taslak yoksa canlı
model. Durum sorulmaz: taslak (yayında olmayan) davetiye de önizlenir; erişimi imza belirler.
