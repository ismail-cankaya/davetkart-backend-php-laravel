# `tests/Feature/ShopierOsbTest.php`

> **Faz:** 12 — adım 12.7 · **Sınadığı:** `POST /api/public/payments/shopier/osb`

## 🔴 Önce bir uyarı

OSB gövdesinin alanları (`orderid`, `price`, `currency`, `istest`) topluluk örneklerinden.
`platform_order_id` alanının OSB'de bulunduğu bir **varsayım** (`matchOsbToOrder`).
Testler bu varsayımın **kodunu** sınıyor, Shopier'in gerçekte ne gönderdiğini değil. O
ancak elle doğrulama adım 7'de görülür.

## Testler

| Test | Kanıtladığı |
|---|---|
| `a_signed_osb_pays_the_order_and_acknowledges` | `paid`, `provider_payment_id`, gövde `success` |
| `a_repeated_osb_is_applied_once` | `paid_at` ikinci bildirimde değişmiyor |
| `a_wrong_hash_is_a_silent_404` | K69; satır değişmiyor |
| `an_osb_with_a_different_amount_does_not_pay` | 🔴 K126; `critical` log, yine `success` |
| `a_discounted_order_is_paid_with_its_discounted_amount` | Faz 11 indirimi: beklenen tutar `amount_minor` |
| `a_test_notification_is_refused_in_production` / `…accepted_outside_production` | `istest` |
| `an_unmatched_osb_is_acknowledged_and_logged` | Başka bir satışın bildirimi satır yaratmaz |
| `a_late_osb_still_pays_an_expired_order` | K89 |
| `without_osb_credentials_the_endpoint_refuses` | `callback` modunda OSB kurulmamışsa 404 |

## Üretim ortamı nasıl taklit ediliyor?

`$this->app->detectEnvironment(fn () => 'production')`. `App::isProduction()` bu değeri
okur. Test kendi uygulama örneğinde çalıştığı için sonraki testlere sızmaz. Mutasyon:
`istest` kontrolünü kapat → test kırmızı (denendi).
