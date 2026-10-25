# `2026_10_24_100000_add_provider_payment_id_to_orders.php`

> **Faz:** 12 — adım 12.4 · **Karar:** **K121** (E5)

## Ne ekliyor?

`orders.provider_payment_id` — `varchar(64)`, nullable, index'li.

## İki kimlik, iki soru

| Kolon | Kimin kimliği | Sorduğu soru |
|---|---|---|
| `id` | Bizim (ULID) | "Hangi sipariş?" |
| `provider_ref` (UNIQUE) | Shopier'de **yine bizim** (`platform_order_id = orders.id`) | "Bu bildirim hangi siparişe ait?" İdempotansın anahtarı (M8) |
| `provider_payment_id` | **Shopier'in** `payment_id`'si | "Shopier panelinde bu ödeme hangisi?" Destek ve iade |

Shopier'de sipariş kimliğini **biz veriyoruz** (`platform_order_id`). Bu yüzden
`provider_ref` ile `id` aynı değer. Shopier'in kendi kimliği ise ödeme
gerçekleşince geliyor; bir müşteri "param çekildi" dediğinde panelde aranacak
değer odur.

## Neden UNIQUE değil?

UNIQUE bir **iddia**dır: "iki satır bu değeri paylaşamaz". Burada o iddiayı kurmak
için bir sebep yok, kurmanın ise riski var: Shopier'in aynı kimliği iki farklı
bildirimde (ödeme, iade) göndermesi normal. İdempotans zaten `provider_ref`'te.
Ayrıca UNIQUE ihlali PostgreSQL'de bütün transaction'ı zehirler (ders 65): bir
bilgi kolonu yüzünden bir ödemeyi reddetmek olmazdı.

## Neden index?

Destek senaryosu: "Shopier'de şu ödeme var, bizde hangi sipariş?" →
`WHERE provider_payment_id = ?`. Nadir ama elle yapılan bir sorgu; index ucuz.

## Geri alma

`down()` index'i ve kolonu siler. Veri kaybı: yalnızca Shopier kimlikleri. Siparişin
kendisi ve durumu etkilenmez.
