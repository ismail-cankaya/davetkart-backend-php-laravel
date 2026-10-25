# `tests/Feature/AccountEntitlementsTest.php`

> **Faz:** 14, adım 14.4

| Test | Kanıtladığı |
|---|---|
| `a_guest_gets_401` | Uç auth arkasında |
| `the_counts_come_per_tier_in_an_envelope` | `{data: …}` zarfı, plan sırası, sayılar |
| `a_right_carries_its_invitation_and_refund_status` | `assertExactJson`: sözleşmenin TAMAMI, fazladan alan yok |
| `provider_identifiers_never_leave_the_server` | 🔴 C1 beyaz listesi |
| `another_users_orders_never_appear` | Sahiplik |
| `an_unchanged_summary_answers_304` | `SetEtag` |
