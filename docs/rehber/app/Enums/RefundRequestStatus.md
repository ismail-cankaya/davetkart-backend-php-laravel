# `app/Enums/RefundRequestStatus.php` — iade talebinin durumları

> **Faz:** 14, adım 14.2 · **Karar:** K138 · **Test:** `tests/Unit/RefundRequestStatusTest.php`

## 1. Durumlar

```
requested ──► approved   (yönetici parayı geri gönderdi → sipariş refunded)
          ├─► rejected   (yönetici reddetti → hak kullanıcıya döner)
          └─► withdrawn  (kullanıcı vazgeçti → hak döner)
```

Kapanan talep bir daha açılmaz. Kullanıcı yeniden isterse **yeni bir satır** açılır; böylece
"önce reddedildi, sonra onaylandı" geçmişi kaybolmaz.

## 2. 🔴 Neden `OrderStatus`'a `refund_requested` eklenmedi?

`OrderStatus` bir **ödeme** durum makinesi: sağlayıcının söylediklerini kaydeder (`paid`,
`refunded`). "Kullanıcı iade istedi" ise bir **iş akışı** durumu. İkisini karıştırsaydık:

| Sorun | Sonuç |
|---|---|
| Shopier'den gelen iade bildirimi `paid → refunded` bekler | `refund_requested → refunded` geçişini de bilmek zorunda kalırdı |
| `grantsPublishRight()` | "talep açık ama ret gelirse hak geri gelir" mantığı enum'a sızardı |
| Talep geçmişi | Tek kolon: "iki kez istedi, bir kez reddedildi" bilgisi yok olurdu |

Ayrı tablo + ayrı enum: **her durum makinesi tek bir soruyu cevaplar.**

## 3. `isOpen()`

Açık talep **hakkı dondurur**: hem yayında kullanılamaz (`ClaimReleasedOrderAction`) hem de
ikinci bir talep açılamaz (kısmi UNIQUE indeks). Kural tek yerde; `RefundRequest::scopeOpen`
onu SQL'e çevirir (K50 deseni).
