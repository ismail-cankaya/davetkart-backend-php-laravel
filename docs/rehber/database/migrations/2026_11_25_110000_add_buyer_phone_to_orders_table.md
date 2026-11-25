# `2026_11_25_110000_add_buyer_phone_to_orders_table`

> **Faz:** 19 ortak düzeltme — Hat A `E5` (SEC-10 D2) · **Karar:** K262

`orders.buyer_phone` — `varchar(11)`, nullable. Biçim her zaman `05XXXXXXXXX` (istek normalleştirir ve doğrular).

- **Yalnızca siparişte**, hesapta değil (19.15 prompt'unun D2 varsayılanı, KVKK en az veri): telefon bir ödemenin parçasıdır,
  profil bilgisi değil. Her checkout yeniden sorar; aynı niyetle yeniden açılan sipariş (K160) düzeltilmiş telefonu alır.
- Boş kalır: hediye siparişi, telefon istemeyen sürücü (Fake), bu göçten önceki siparişler.
- Saklama: siparişin kendisiyle aynı (gizlilik metni, SEC-11). Hesap silinince siparişler muhasebe için kalır (K97); telefon
  da onlarla kalır — gizlilik metnindeki Shopier satırı bunu söyler.
- İndeks yok: telefonla arama yapılmaz.
