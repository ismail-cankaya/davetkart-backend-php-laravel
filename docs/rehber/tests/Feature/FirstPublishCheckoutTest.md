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

---

## 🆕 Faz 18.3 (`SEC-01`) — tek indirim, tek açık sipariş

Dosya 7 testten 21'e çıktı. Yeni testler dört soruyu cevaplar:

| Soru | Testler |
|---|---|
| İndirim gerçekten **bir** siparişe mi veriliyor? | `the_first_publish_discount_is_granted_to_one_order_only` · `the_quote_and_the_checkout_agree_on_the_first_publish_discount` |
| Aynı niyet yeni satır açıyor mu? (K160) | `a_second_checkout_for_the_same_target_reuses_the_pending_order` · `…same_package…` · `the_same_gift_code_for_the_same_target_reuses_the_order` · `a_provider_failure_on_a_reused_order_leaves_it_pending` · `a_closed_card_method_refuses_the_reuse_too` |
| Farklı niyet eskisini doğru kapatıyor mu? (K161) | `a_different_plan_…closes_the_old_order_and_takes_the_discount` · `another_plan_at_the_same_price_is_not_the_same_intent` · `a_price_that_changed_…` · `a_list_price_that_changed_…` · `dropping_the_gift_code_…` · `an_invalid_gift_code_leaves_the_open_order_untouched` |
| Kilit yerinde mi? (K158) | `the_user_row_is_locked_before_the_checkout_looks_at_orders` |

**Değişen test:** `an_abandoned_checkout_keeps_the_discount_for_the_next_try` eskiden art arda iki
checkout'un iki indirimli sipariş açtığını doğruluyordu; bu, `SEC-01`'in ta kendisiydi. Sözü
(terk edilen ödeme indirimi yakmaz, K131) aynı; artık pencereyi `travel()` ile geçirip eski
siparişin `expired` olduğunu ve yenisinin indirimi aldığını sınıyor.

**Kilit testi nasıl çalışıyor?** `DB::listen` koşan her SQL'i toplar; test iki şeye bakar:
(1) `from "users" … for update` geçen bir deyim var mı, (2) o deyim `"orders"` geçen ilk deyimden
**önce** mi. Tek süreçli bir test yarışı üretemez; ama kilidin silinmesini ya da yanlış yere
taşınmasını yakalar. Gerçek iki bağlantılı yarış testi `TST-02`'nin işi.

**T14 hâlâ geçerli:** yanıta değil satıra bakılır (`promotion`, `amount_minor`, `status`).
