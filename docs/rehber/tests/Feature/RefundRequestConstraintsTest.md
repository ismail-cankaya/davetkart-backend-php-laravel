# `tests/Feature/RefundRequestConstraintsTest.php` · `tests/Unit/RefundRequestStatusTest.php`

> **Faz:** 14, adım 14.2

| Test | Kanıtladığı |
|---|---|
| `an_order_cannot_have_two_open_requests` | 🔴 Kısmi UNIQUE: eşzamanlı iki talep imkânsız |
| `a_closed_request_does_not_block_a_new_one` | Reddedilen/geri çekilen talep yenisini engellemez |
| `an_open_request_cannot_carry_a_decision_time` · `a_closed_request_must_carry_a_decision_time` | `resolved_check` iki yönü de bağlıyor |
| `an_unknown_status_is_refused` | CHECK, Eloquent'i atlayan yazımı da durdurur |
| `deleting_the_user_anonymizes_the_request` | Hesap silme talebi silmez (K97) |
| `the_factory_copies_the_owner_from_the_order` | Fabrika gerçekçi sahiplik üretir |

Unit testi durum makinesini sınar: yalnızca `requested` açık, kapanmış talep hiçbir yere gitmez.
