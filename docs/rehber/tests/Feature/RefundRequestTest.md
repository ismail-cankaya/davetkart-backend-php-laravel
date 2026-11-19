# `tests/Feature/RefundRequestTest.php`

> **Faz:** 14, adım 14.5 · **Faz 18.4 (`SEC-02`):** son dört satır

| Test | Kanıtladığı |
|---|---|
| `a_guest_cannot_request_a_refund` | 401 |
| `the_owner_cancels_an_unused_right` | 201, açıklama kırpılır, hak `refund_requested` kovasına geçer |
| `an_empty_reason_is_stored_as_null` | Tek "yok" temsili (N4) |
| `a_too_long_reason_is_refused` | 500 karakter |
| `another_users_order_is_not_found` | 🔴 H7: 404, satır açılmaz |
| `a_right_in_use_cannot_be_cancelled` · `a_gift_right_…` · `…past_the_window…` · `an_unpaid_order…` | 409 + doğru `reason` |
| `a_second_request_is_refused` | `already_requested` |
| `a_frozen_right_is_not_claimed_by_a_publish` | 🔴 İptal + yayın = bedava yayın olmaz |
| `withdrawing_unfreezes_the_right` | Vazgeçince hak yeniden yayın açar |
| `withdrawing_without_an_open_request_is_404` · `another_user_cannot_withdraw` | Geri çekme sahiplik ve varlık kontrolü |
| `a_right_that_published_an_invitation_cannot_be_refunded_after_release` | 🔴 `SEC-02`: öde → yayınla → sil (pencere açık) → iade talebi 409 `used`; hak listesi de `available` + `eligible: false` + `blocker: used` der. Mutant: `hasPublished()` kolunu sil → 201 |
| `a_released_right_that_published_can_still_publish_again` | K82 aynen duruyor: iade edilemeyen hak yeni bir davetiyeyi yayınlar |
| `an_unpublished_right_is_still_refundable` | K139 bozulmadı: taslak silinince hak iade edilebilir |
| `a_right_in_a_published_invitation_reports_in_use_before_used` | Sebep sırası: şu an bağlıysa `in_use`. Mutant: iki kolun yerini değiştir |

Faz 18.4'ün ilk testi akışı **uçlardan** kurar (yayın ve silme istekleri), fabrikadan değil: damgayı
gerçekten `StampFirstPublishAction`'ın bastığı ve silmenin onu bırakmadığı da aynı testte sınanır.
