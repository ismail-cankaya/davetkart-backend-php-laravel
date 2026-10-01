# `tests/Feature/OrderTest.php`

> **Faz:** 10 — Dilim C, adım 10.25 · **11 metot, 13 vaka**
> **Test edilen:** [`OrderController.md`](../../app/Http/Controllers/Api/V1/OrderController.md) ·
> [`OrderPolicy.md`](../../app/Policies/OrderPolicy.md) · [`OrderResource.md`](../../app/Http/Resources/OrderResource.md) (Faz 10 eklemesi) ·
> [`routes/api.md`](../../routes/api.md) (Faz 10 eklemesi)
> **Kurallar:** T6 · T11 · T13 · T14 · T16 · P3 · H7 · C1

---

## 1. Testler

| Grup | Test | Ne kanıtlar |
|---|---|---|
| Kimlik | `guest_cannot_read_orders` | Token yok → 401 |
| Tek kayıt | `the_owner_reads_a_paid_order` | Donmuş zamanda **tam gövde** (`assertExactJson`): altı alan, fazlası yok |
| | `an_unpaid_order_reports_its_status_without_a_payment_time` (×3) | `pending`, `expired`, `failed` · `paidAt` anahtarı **var**, değeri `null` |
| | `a_package_order_has_no_invitation` | `invitationId: null` |
| Sahiplik 🔴 | `another_users_order_is_indistinguishable_from_a_missing_one` | 404 · **ham gövde** var olmayan kimlikle birebir aynı (T11) |
| | `the_list_contains_only_the_owners_orders_newest_first` | Kapsam (P3) **ve** sıra |
| | `orders_opened_in_the_same_second_keep_a_stable_order` | Eşitlik bozucu `id` |
| | `a_user_without_orders_gets_an_empty_list` | `{"data": []}`, hata değil |
| Sözleşme | `provider_internals_and_amounts_never_leak` | Ham gövdede `provider_ref` **değeri** ve `provider`/`amount`/`userId`/`expiresAt` yok, iki uçta da |
| | `a_malformed_order_id_is_a_plain_404` | `whereUlid` |
| | `orders_cannot_be_written_through_the_api` | `POST`/`PUT`/`DELETE` → 404 · durum değişmedi (T14) |

## 2. Öne çıkanlar

### 2.1 `assertExactJson`: beyaz listenin kanıtı

```php
->assertExactJson(['data' => [
    'orderId' => …, 'tier' => 'gold', 'status' => 'paid',
    'invitationId' => …, 'createdAt' => '2026-09-30T14:02:11+00:00', 'paidAt' => '…',
]]);
```

`assertJsonPath` yalnızca baktığı alanı görür. `assertExactJson` **fazlasını** da
görür: yarın biri Resource'a `providerRef` eklerse bu test de kırılır (10.25'te
denendi). Beyaz liste C1'in kanıtı ancak tam gövdeyle alınır.

### 2.2 🔴 Sıralama testi neden üç kayıtla ve karışık sırayla?

İlk yazım iki kayıtla yapılmıştı: *eski*, sonra *yeni* ekleniyor ve *yeni, eski*
bekleniyordu. Mutasyon denemesi bir zayıflığı ortaya çıkardı: `ORDER BY` **tamamen
silindiğinde test yeşil kaldı**. PostgreSQL sıralamasız bir sorguda sıra
**garanti etmez**. O koşuda satırları tesadüfen doğru sırada döndürdü.

İki kayıtla beklenen sıra ya ekleme sırasına ya da tersine denk gelir. Sıralamasız
sorgunun *"doğal"* sonucu da bu ikisinden biridir. Üç kayıt **karışık** eklenince
(orta, en eski, en yeni) beklenen sıra (yeni, orta, eski) ikisinden de farklı oluyor:

| Sıra | Değer |
|---|---|
| Ekleme | orta, eski, yeni |
| Ekleme tersi | yeni, eski, orta |
| **Beklenen** | **yeni, orta, eski** |

Aynı hile eşitlik bozucu testte de var: aynı saniyede üç sipariş, kimlikleri
elle verilmiş (`…b1`, `…a1`, `…c1` sırasıyla ekleniyor, `c1, b1, a1` bekleniyor).

> **Ders:** Sıralamayı sınayan bir test, sıralamasız sonucun **alabileceği her
> değerden** farklı bir beklenti kurmalı. Yoksa yeşil yanması bir şey kanıtlamaz.

### 2.3 Neden `YOK_OLAN_ULID` küçük harf?

`HasUlids` kimlikleri küçük harfle üretiyor (R6, `routes/api.md` §0.2). Büyük harfli
bir ULID de `whereUlid`'den geçer ama bulunamaz. Var olmayan kimliğin testteki
anlamı *"biçimi doğru ama kayıt yok"* olduğu için gerçek kimliklerle aynı biçimde
yazıldı.

## 3. Mutasyon kanıtı (1 Ekim 2026, İsmail'in makinesi, PHP 8.5)

| # | Mutasyon | Kırılan |
|---|---|---|
| M1 | `$user->orders()` → `Order::query()` (kapsam yok) | liste testi · boş liste testi |
| M2 | `OrderPolicy::view()` → `return true` | `another_users_order_…` |
| M3 | Resource'a `providerRef` eklendi | `the_owner_reads_a_paid_order` · `provider_internals_…` |
| M4 | Rotadan `only(['index', 'show'])` silindi | `orders_cannot_be_written_…` (metot yok → 500) |
| M5 | Sıralama tamamen silindi | liste testi · aynı saniye testi (**ilk yazımda: hiçbiri**, §2.2) |
| M6 | Eşitlik bozucu `orderByDesc('id')` silindi | aynı saniye testi |

## 4. Çalıştırma

```powershell
php artisan test --filter=OrderTest
# 13 passed
```
