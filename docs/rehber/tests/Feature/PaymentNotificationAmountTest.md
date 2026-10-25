# `tests/Feature/PaymentNotificationAmountTest.php`

> **Faz:** 12 — adım 12.4 · **Sınadığı:** `HandlePaymentCallbackAction` (K121, K126)

## Neden Action'ı doğrudan çağırıyor?

HTTP üzerinden sınamak için bir sürücü, bir imza ve bir uç gerekirdi. Bu testin
sorusu daha dar: *"doğrulanmış bir bildirim tutar taşıyorsa sipariş ne olur?"* İmza
ve ayrıştırma sürücü testlerinin işi (`ShopierGatewayTest`, `ShopierOsbTest`). Bir
test bir soruyu sorar.

## Testler

| Test | Kanıtladığı |
|---|---|
| `a_matching_amount_…` | Tutar tutuyor → `paid`, `paid_at` ve `provider_payment_id` yazıldı |
| `a_different_amount_is_rejected_loudly` | 1 kuruş fark → `pending` kaldı + `critical` log, bağlamında beklenen ve gelen tutar |
| `a_different_currency_is_rejected` | Tutar aynı, birim farklı → reddedildi |
| `a_notification_without_an_amount_…` | `null` tutar karşılaştırılmaz (Fake ve callback kırılmadı) |
| `the_first_payment_id_is_kept` | İade bildirimi farklı bir kimlik getirse de ilk kimlik kalır |

**T14:** hiçbiri yalnızca dönüş değerine bakmıyor; her biri `refresh()` ile
veritabanındaki satırı okuyor.
