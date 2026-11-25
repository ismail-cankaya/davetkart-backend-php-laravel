# `app/Observers/RecordDeletion.php` — Kılavuz

> **Faz:** 19 ortak düzeltme (B-E1) · **Karar:** K233 · **Kayıt:** `AppServiceProvider::configureDeletionLog()`

## Ne yapar

`User`, `Invitation`, `Rsvp`, `Media` modellerinin `deleted` olayında [`DeletionLog`](../Support/DeletionLog.md)'a
tür + kimlik yazar. Kullanıcının başlattığı silmelerin hepsi model üzerinden geçer: hesap silme
(`DeleteAccountAction`: davetiyeler `forceDelete`, kullanıcı `delete`), davetiyeyi çöp kutusuna atma
(`DeleteInvitationAction`; soft delete da `deleted` olayıdır), galeri öğesi (`DeleteGalleryMediaAction`),
sahibin LCV silmesi (`RsvpController::destroy`).

## Neden observer, neden Action'larda değil

- Dört Action yerine tek yer; yeni bir silme yolu model üzerinden geçtikçe kendiliğinden yazılır.
- `ShouldHandleEventsAfterCommit`: olay transaction **commit olduktan sonra** işlenir. Geri alınan bir silme
  günlüğe girseydi, geri yüklemeden sonra silinmemiş bir şey silinirdi (test: `a_rolled_back_deletion_is_not_recorded`).

## Bilinen sınırlar

- Toplu silmeler (`->delete()` sorgu üzerinde: `data:purge`'ün LCV ve mesaj silmesi, `PruneEmptyDrafts`) olay üretmez.
  Gerek de yok: bunlar **kural tabanlı**dır; geri yüklemeden sonra `data:purge` aynı kuralla yeniden siler
  (`data:reapply-deletions` sonunda onu çağırır).
- Kural tabanlı model silmeleri (`data:purge`'ün davetiye ve misafir medyası silmesi) de günlüğe girer; zararsız.
- Günlük yazılamazsa silme geri alınmaz; `Log::critical('Deletion log write failed', …)` yazılır. Kitap §7.3
  geri yüklemeden sonra bu satırların da aranmasını söyler.
