# Hakkı iptal et: `RequestRefundAction` · `WithdrawRefundRequestAction`

> **Faz:** 14, adım 14.5 · **Karar:** K138, K139
> **Uçlar:** `POST /api/orders/{order}/refund-request` (201) · `DELETE …/refund-request` (204)
> **Dosyalar:** `RequestRefundAction`, `WithdrawRefundRequestAction`, `RefundNotAllowedException`,
> `StoreRefundRequestRequest`, `RefundRequestResource`, `RefundRequestController`, `ErrorCode::RefundNotAllowed`
> **Test:** `tests/Feature/RefundRequestTest.php`

---

## 1. Akış

```
Kullanıcı "İptal et"  ─► talep açılır (requested), hak DONAR
                          │
Yönetici Shopier'den      ├─► refunds:approve  → sipariş refunded, talep approved (14.6)
parayı gönderir           ├─► refunds:reject   → talep rejected, hak geri döner
                          └─ kullanıcı "Vazgeç" → talep withdrawn, hak geri döner
```

## 2. 🔴 "Donmuş hak" ne demek?

Talep açıkken hak **kullanılamaz**: `ClaimReleasedOrderAction` sorgusuna
`withoutOpenRefundRequest()` eklendi. Olmasaydı:

1. Kullanıcı Gold hakkını iptal eder (talep açık).
2. Aynı hakla bir davetiye yayınlar.
3. Yönetici talebi görür, parayı geri gönderir.

Sonuç: para iade, davetiye yayında. Test: `a_frozen_right_is_not_claimed_by_a_publish`.

## 3. Yarış: iptal ile yayın aynı anda

İki istek aynı **sipariş satırını** kilitliyor (`lockForUpdate`): iptal burada, yayın
`ClaimReleasedOrderAction`'da. Biri diğerini bekler; ikinci gelen birincinin sonucunu görür:

| Önce iptal | Önce yayın |
|---|---|
| Yayın kilidi bekler, açık talebi görür, siparişi bağlamaz → 402 | İptal kilidi bekler, `invitation_id` dolu görür → 409 `in_use` |

Kısmi UNIQUE indeks (14.2) son kalkan: aynı anda iki iptal isteğinin ikincisi indekse
çarpar; Action bunu `already_requested`'a çevirir.

## 4. Hata sözleşmesi: `409 REFUND_NOT_ALLOWED`

`params.reason` = `RefundBlocker` değeri: `already_requested`, `not_paid`, `in_use`, `free`,
`window_closed`. **Neden 409, 422 değil?** İstek biçimsel olarak doğru; engel siparişin şu
anki **durumu**. Durum değişince (davetiye silinip hak serbest kalınca) aynı istek geçer.
`INVITATION_ALREADY_PUBLISHED` ile aynı aile.

**Neden sebep söyleniyor (H6 ihlali değil mi)?** H6 *kimliği bilinmeyen* birine bir şeyin
varlığını sızdırmamaktır. Burada kullanıcı kendi siparişini soruyor; başkasının siparişi
zaten 404 (Policy, H7) — sebep oraya hiç ulaşmaz.

## 5. Vazgeç

`WithdrawRefundRequestAction` açık talebi **kilitleyerek** bulur. Yönetici aynı anda onaylarsa
ikisinden biri bekler; ikincisi talebi kapalı görür ve 404 alır (vazgeç) ya da "zaten kapalı"
uyarısı (komut). Açık talep yoksa 404: geri çekilecek bir kaynak yok.

## 6. Talep URL'sinde neden talep kimliği yok?

Bir siparişte en fazla bir açık talep var. `/orders/{order}/refund-request` **tekil kaynak**:
"bu siparişin iade talebi". Talep kimliği URL'de olsaydı istemci onu önce öğrenmek zorunda
kalırdı ve sahiplik iki kimlik üzerinden doğrulanırdı.
