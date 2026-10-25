# `app/Models/RefundRequest.php` · `database/factories/RefundRequestFactory.php`

> **Faz:** 14, adım 14.2 · **Karar:** K138

## 1. Model

- `#[Fillable([])]` (E7): hiçbir alan istemciden gelmez. `reason` bile Action'da açıkça atanır.
- `scopeOpen()`: kural `RefundRequestStatus::isOpen()`'da, kapsam yalnızca çevirir.
- İlişkiler: `order()`, `user()`.

`Order` tarafına üç şey eklendi: `refundRequests()` (geçmiş), `openRefundRequest()` (tek açık
talep; kısmi UNIQUE indeks birden fazla olmasını engeller) ve `scopeWithoutOpenRefundRequest()`.

## 2. Fabrika

Varsayılan: **ödenmiş** bir siparişte açık talep. Sahip siparişten kopyalanır (`user_id`
closure'ı); başkasının siparişine talep açmak üretimde olmayan bir durum, fabrika da onu
üretmemeli. `approved()/rejected()/withdrawn()` durum ile `resolved_at`'i **birlikte** yazar:
CHECK kısıtı ikisini bağlıyor.
