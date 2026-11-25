# `app/Enums/DeletionSubject.php` — Kılavuz

> **Faz:** 19 ortak düzeltme (B-E1) · **Karar:** K233

Silme günlüğündeki kayıt türleri ve geri yüklemeden sonra nasıl yeniden silindikleri (`data:reapply-deletions`):

| Değer | Model | Yeniden uygulama |
|---|---|---|
| `account` | `User` | `DeleteAccountAction` (davetiyeler, dosyalar, token'lar; sipariş anonimleşir) |
| `invitation` | `Invitation` | `DeleteInvitationAction` (çöp kutusu + sipariş hakkı kuralları); `deleted_at` günlükteki ana çekilir |
| `rsvp` | `Rsvp` | Satır silinir |
| `media` | `Media` | Galeri öğesiyse `DeleteGalleryMediaAction` (davetiyenin dizisinden de çıkar), değilse dosya + satır |

Yeni bir tür eklemek: `RecordDeletion`'daki `match` + `ReapplyDeletions`'daki `match` (PHPStan eksik kolu yakalar).
