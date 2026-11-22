# `tests/Support/LockAssertions.php` — kilitlerin testle korunması

> **Faz:** 19.11 (`TST-02`, K187) · **Testler:** `tests/Feature/LockInventoryTest.php` (SQL) · `tests/Feature/LockRaceTest.php` (`#[Group('concurrency')]`) · alt süreç `tests/Support/hold-row-lock.php`

## Neden

Tek süreçli paket **sıralı** doğruluğu kanıtlar; kilit, sıralı koşuda görünmez bir satırdır. Denetimde
dört kilit silindi (M14, M47, M48, M49), 1052 testin hiçbiri kırmızı olmadı.

## Üç katman

| Yardımcı | Ne kanıtlar | Bedel |
|---|---|---|
| `assertLocksRowOf($table, fn)` | `DB::listen`: `from "<table>" … for update` yürüdü. İş kuralı hatası kilitten sonra gelir, yutulur ve mesajda gösterilir | ~ms, RefreshDatabase ile |
| `assertWaitsForRowLock($table, $id, fn)` | İkinci bağlantı satırı tutarken Action bekler: `SET LOCAL lock_timeout = '200ms'` → `55P03` | ~200 ms |
| `holdRowLockInAnotherProcess($sql, $holdMs)` | Alt süreç kilidi alır, bekler, COMMIT eder; ana süreç arada bloklanır. Gerçek araya girme | ~1 sn |

**İkinci bağlantı yalnızca test veritabanı:** `pgsql` yapılandırması kopyalanır; ad
`davetkart_test(_<hat>)` değilse `LogicException` (K182 deseniyle aynı, fail-closed). `config/database.php` değişmedi.

**RefreshDatabase neden yarış testinde yok?** Testi tek bir transaction'a sarar; içindeki satırları ikinci
bağlantı göremez. `LockRaceTest` `DatabaseTruncation` kullanır ve test sonunda tabloları **yeniden** boşaltır
(trait yalnızca öncesinde boşaltır; sonraki RefreshDatabase testlerine veri sızmasın). Migration'lar veri eklemiyor.

## Envanter (17 `lockForUpdate()`, hepsi mutantla denendi: sil → kırmızı)

| Dosya | Koruduğu değişmez | Test |
|---|---|---|
| `Account/ConfirmEmailChangeAction` | e-posta iki kez değişmez | `confirming_an_email_change_locks_the_user_row` |
| `GiftCode/ReserveGiftCodeAction` (M14) | tek kullanımlık kod tek siparişe | `reserving_a_gift_code_locks_the_code_row` + `…_waits_for_the_code_row_lock` |
| `Invitation/DeleteInvitationAction` | silme ile yayın birbirini görür | `deleting_an_invitation_locks_the_invitation_row` |
| `Invitation/PublishInvitationAction` | iki kez yayın yok (E9) | `publishing_an_invitation_locks_the_invitation_row` |
| `Invitation/UpdateInvitationAction` | modül/plan kontrolü taze satırda | `updating_an_invitation_locks_the_invitation_row` |
| `Invitation/WithdrawUncoveredInvitationAction` | hakkı biten yayın tek karar | `withdrawing_an_uncovered_invitation_locks_the_invitation_row` |
| `Media/DeleteGalleryMediaAction` | galeri dizisi ezilmez | `deleting_a_gallery_photo_locks_the_invitation_row` |
| `Media/StoreUploadedMediaAction` | kota + galeri sırası (E9) | `storing_an_upload_locks_the_invitation_row` |
| `Jobs/OptimizeUploadedImage` (`swapFile`) | dosya değişimi silmeyle yarışmaz | `swapping_an_optimized_image_locks_the_invitation_row` |
| `Payment/ClaimReleasedOrderAction` (M49) | serbest hak tek davetiyeye | `claiming_a_released_order_locks_the_order_rows` + `a_refund_requested_during_a_claim_keeps_the_right_frozen` |
| `Payment/HandlePaymentCallbackAction` (M47) | bildirim iki kez ilerlemez | `a_payment_notification_locks_the_order_row` |
| `Payment/StartCheckoutAction` | ilk yayın indirimi tek sipariş (18.3) | `FirstPublishCheckoutTest::the_user_row_is_locked_before_the_checkout_looks_at_orders` |
| `Refund/RequestRefundAction` | iptal ile yayın sıraya girer | `requesting_a_refund_locks_the_order_row` |
| `Refund/ResolveRefundRequestAction` ×2 | talep tek karar; sipariş taze | `resolving_a_refund_request_locks_the_request_and_the_order` |
| `Refund/WithdrawRefundRequestAction` | geri çekme ile karar yarışmaz | `withdrawing_a_refund_request_locks_the_request_row` |
| `Rsvp/EnsureRsvpQuotaAction` (M48) | LCV kotası aşılmaz | `checking_the_rsvp_quota_locks_the_invitation_row` + `…_waits_for_the_invitation_row_lock` |

## Sık hata

- Bekleme testinin her mutantı öldüreceğini sanmak: kilit silinince Action'ın sonraki `UPDATE`'i de aynı
  satırı bekler (M47, M49'da böyle). Kilidin **okuma anını** sıralayan tek kanıt SQL testi ya da gerçek araya girmedir.
- Yeni `lockForUpdate()` ekleyip envantere satır ve test eklememek.

## Nasıl denenir

`php artisan test --compact --group concurrency` · `--filter LockInventoryTest`
