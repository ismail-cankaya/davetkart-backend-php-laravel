# `tests/Feature/DeletionLogTest.php` — Kılavuz

> **Faz:** 19 ortak düzeltme (B-E1) · **Karar:** K233

| Test | Neyi kilitler |
|---|---|
| `user_initiated_deletions_are_recorded_with_only_type_id_and_time` | Davetiye, LCV, hesap silmesi sırayla yazılır; dosyada yalnızca `subject`, `id`, `at` |
| `a_rolled_back_deletion_is_not_recorded` | Geri alınan transaction günlüğe girmez (`ShouldHandleEventsAfterCommit`) |
| `deletions_since_the_backup_are_reapplied_after_a_restore` | Geri yükleme benzetimi: satırlar durur, günlükte yazılı; dry-run sayar, gerçek koşu siler; çöp kutusu zamanı günlükteki |
| `entries_before_the_backup_are_ignored` | `--since`'ten eski kayıt uygulanmaz |
| `a_missing_or_invalid_since_fails_closed` | `--since` yok ya da bozuk → çıkış 1 |
| `a_gallery_item_leaves_the_invitation_gallery_too` | Galeri öğesi davetiyenin dizisinden de çıkar |
| `the_purge_drops_log_days_older_than_the_backup_window` | `data:purge` 40 günden eski gün klasörlerini siler |

Günlük diski `TestCase`'te sahte (`deletion-log-test`); zaman `travelTo` ile sabit.
