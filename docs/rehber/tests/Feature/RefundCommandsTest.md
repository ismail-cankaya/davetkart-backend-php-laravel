# `tests/Feature/RefundCommandsTest.php`

> **Faz:** 14, adım 14.6

| Test | Kanıtladığı |
|---|---|
| `the_list_shows_only_open_requests_by_default` | Varsayılan liste + `--all` + kontrol karakteri kaçışı |
| `an_empty_queue_says_so` | Boş kuyruk |
| `approving_refunds_the_order_and_mails_the_user` | Talep, sipariş, hak sayacı ve mail birlikte |
| `approving_without_confirmation_changes_nothing` | 🔴 "Hayır" hiçbir şeyi değiştirmez |
| `approving_an_already_refunded_order_only_closes_the_request` | Shopier bildirimi önce gelmiş |
| `rejecting_gives_the_right_back` | Ret: hak yeniden kullanılabilir, not mailde |
| `a_closed_request_cannot_be_resolved_again` · `an_unknown_request_fails` | Hata yolları |
| `an_anonymous_request_is_resolved_without_mail` | Silinmiş hesap |
| `the_mail_reads_in_turkish_with_the_amount` | Tutar kuruştan, float'suz |
