# `tests/Feature/OrderConsumptionTest.php`

> **Faz:** 14, adım 14.1 · **Karar:** K137

| Test | Kanıtladığı |
|---|---|
| `deleting_after_the_window_stamps_the_order_as_consumed` | Pencere kapalıyken silme damga yazar, bağ yerinde kalır |
| `deleting_within_the_window_does_not_consume` | Pencere açıkken hak serbest kalır, damga yok |
| `a_pending_order_is_never_stamped` | Parası alınmamış sipariş yanmaz |
| `a_consumed_order_is_not_reborn_when_the_trash_is_purged` | 🔴 Purge sonrası bağ NULL olsa da yayın siparişi bağlamaz (402) |
| `the_database_refuses_a_consumed_order_without_payment` | CHECK kısıtı ödenmemiş damgayı reddeder |

**Mutasyon:** `ClaimReleasedOrderAction`'dan `->unconsumed()` silinirse dördüncü test
kırılır (yayın 200 döner ve sipariş yeni davetiyeye bağlanır).
