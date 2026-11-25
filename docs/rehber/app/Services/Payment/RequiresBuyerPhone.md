# `app/Services/Payment/RequiresBuyerPhone.php`

> **Faz:** 19 ortak düzeltme — Hat A `E5` (SEC-10 D2) · **Karar:** K262

## Neden

SEC-10: Shopier formuna her alıcı için aynı yer tutucu telefon (`05000000000`) gidiyordu. İsmail'in kararı (25 Kasım 2026):
Shopier'in cevabını beklemeden telefon gerçek alınsın (D2); TCKN ve adres cevaba kadar yer tutucu kalır.

## Ne

Boş bir işaret arayüzü. Uygulayan sürücü (bugün yalnızca `ShopierGateway`) alıcının gerçek cep telefonunu ister.
`StartCheckoutAction::buyerPhoneFor`:

| Sürücü | Telefon gelmezse | Gelirse |
|---|---|---|
| `RequiresBuyerPhone` (Shopier) | 422 `VALIDATION_FAILED`, `fields.phone[0].rule = required`; sipariş açılmaz | `orders.buyer_phone`'a yazılır, forma o gider |
| Diğerleri (Fake) | sorun yok | **saklanmaz** (en az veri, KVKK) |

Hediye kodunun tamamen karşıladığı siparişte sağlayıcıya gidilmez; telefon istenmez ve saklanmaz.

**Neden `PaymentGateway`'e metot değil?** Arayüze metot eklemek testlerdeki üç anonim sürücüyü ve her yeni sürücüyü
etkilerdi; ihtiyaç bir sürücü özelliği, sözleşmenin parçası değil. `instanceof` tek yerde sorulur.

## İlgili

- İstek: `StoreCheckoutRequest` (`phone`, `regex:/\A05\d{9}\z/`; `+90`, boşluk, tire, parantez önce normalleştirilir).
- Göç: `database/migrations/2026_11_25_110000_add_buyer_phone_to_orders_table.md`.
- Sürücü: `ShopierGateway::buyerFields` — telefon yoksa form üretmez (yer tutucuya geri düşmez).
- Test: `tests/Feature/CheckoutBuyerPhoneTest.php`, `ShopierGatewayTest::a_shopier_form_is_never_built_without_the_buyer_phone`.
