# `tests/Feature/SummarizeEntitlementsActionTest.php`

> **Faz:** 14, adım 14.3

| Test | Kanıtladığı |
|---|---|
| `two_gold_paid_and_one_refunded_leaves_one_available` | 🔴 İsmail'in örneği birebir |
| `every_tier_is_present_in_rank_order_even_when_empty` | Üç plan her zaman, sırayla |
| `an_order_on_a_live_invitation_is_in_use` | Bağlı hak sayılmaz |
| `a_burned_right_stays_consumed_before_and_after_the_purge` | 🔴 K137 + `withTrashed()` |
| `an_order_on_a_trashed_invitation_counts_as_consumed` | Damgasız eski satır |
| `a_pending_payment_is_shown_but_an_expired_one_is_not` | Süresi dolan bekleyen ödeme ve `failed` görünmez |
| `an_open_refund_request_freezes_the_right` | Talep açıkken hak kullanılabilir değil |
| `another_users_orders_are_never_counted` | Sahiplik sorguda |
| `the_newest_order_comes_first` | Liste sırası |
| `a_paid_unused_order_within_the_window_is_refundable` · `the_refund_blockers_name_the_first_reason` | K139 kuralının beş kolu |
