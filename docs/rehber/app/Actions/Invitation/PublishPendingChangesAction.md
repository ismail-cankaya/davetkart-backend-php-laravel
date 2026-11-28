# `app/Actions/Invitation/PublishPendingChangesAction.php`

Faz 27.3 (K323). `POST /invitations/{id}/pending/publish`, "Değişiklikleri yayınla".

- Taslak `UpdateInvitationAction`'a verilir: aynı kilit, aynı paket farkı kontrolü (`ensureRaisedTierIsOwned`), aynı 402
  kodları. Ayrı bir paket yolu yok; taslakla Elit kapısı atlatılamaz (test: Gold + özel metin → 402).
- `UpdateInvitationAction` yazdığı alanları taslaktan düşürdüğü için başarıda taslak kendiliğinden boşalır. 402'de işlem
  geri alınır, taslak olduğu gibi kalır (kullanıcı yükseltip yeniden dener ya da metinleri sıfırlar, K312).
- Taslak boşsa hiçbir şey yazılmaz.
