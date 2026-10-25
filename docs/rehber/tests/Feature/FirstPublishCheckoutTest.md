# `tests/Feature/FirstPublishCheckoutTest.php`

> **Faz:** 13, adım 13.5

Checkout'un fiyat dökümünü siparişe doğru yazdığını kanıtlar (T14: yanıta değil satıra bakılır).

| Test | Kanıt |
|---|---|
| `a_new_users_first_order_is_opened_at_sixty_percent_off` | 399 → 159,60; kaynak `first_publish` |
| `once_an_order_is_paid_the_next_one_is_at_list_price` | İlk yayın bir kez |
| `an_abandoned_checkout_keeps_the_discount_for_the_next_try` | Bekleyen sipariş indirimi bitirmez |
| `a_campaign_order_points_at_its_campaign` | `price_campaign_id` dolu |
| `a_percent_code_is_applied_to_what_is_left` | Kod kalan fiyata; kullanım satırı yalnızca kodun payı |
| `a_free_code_on_a_first_order_is_a_paid_gift_order_that_keeps_the_breakdown` | Bedava siparişte de döküm duruyor |
| `the_body_cannot_lower_the_price` | Gövdedeki fiyat/indirim alanları yok sayılır |
