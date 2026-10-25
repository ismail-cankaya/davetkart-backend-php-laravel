# `2026_10_26_110000_create_refund_requests_table` — iade talepleri

> **Faz:** 14, adım 14.2 · **Karar:** K138 · **Test:** `tests/Feature/RefundRequestConstraintsTest.php`

## 1. Kolonlar

| Kolon | Neden |
|---|---|
| `id` (ULID) | Diğer ticari tablolar gibi tahmin edilemez kimlik |
| `order_id` → `restrictOnDelete` | Sipariş muhasebe kaydı, hiç silinmez; talep onunla yaşar |
| `user_id` → `nullOnDelete` | Hesap silinse de talep yöneticinin listesinde kalır (K97) |
| `status` | `RefundRequestStatus`, CHECK kısıtıyla |
| `reason` (500) | Kullanıcının isteğe bağlı açıklaması |
| `admin_note` (500) | Ret gerekçesi; kullanıcıya mailde gider |
| `resolved_at` | Karar anı |

## 2. Üç kısıt

1. **`status IN (...)`** — değerler enum'dan (K39).
2. **`(status = 'requested') = (resolved_at IS NULL)`** — "açık" ile "kararı yok" tek olgu.
   Eşitlik iki yönü birden bağlar: açık talepte karar anı olamaz, kapanmışta olmak zorunda.
3. **Kısmi UNIQUE: `(order_id) WHERE status = 'requested'`** — 🔴 bir siparişte en fazla bir
   açık talep. İki sekmeden aynı anda "İptal et"e basılırsa ikinci INSERT burada kırılır.
   Kapanmış satırlar indekse girmez: reddedilen talep yeniden açılabilir.

## 3. Neden paranın kendisi burada değil?

Shopier'in iade API'si entegrasyonda yok; iade, Shopier panelinden **elle** yapılıyor. Bu
tablo bir **iş listesi**: yönetici `refunds:list` ile görür, parayı gönderir, `refunds:approve`
ile siparişi `refunded` yapar (14.6). Otomatik iade gelirse yalnızca onay adımı değişir.
