# `app/Actions/Payment/HandlePaymentCallbackAction.php`

> **Kod dosyası:** `app/Actions/Payment/HandlePaymentCallbackAction.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.11
> **Ön koşullar:** [`OrderStatus.md §4`](../../Enums/OrderStatus.md) (iki katmanlı
> idempotans) · [`orders` migration §3`](../../../database/migrations/2026_09_03_100000_create_orders_table.md)

---

## 1. Tek soru: aynı bildirim iki kez gelirse ne olur?

Ödeme sağlayıcıları webhook'u **tekrarlar**. Bu bir istisna değil, **normal
işleyiştir**:

```
Sağlayıcı → POST /api/public/payments/webhook
          ← (yanıt ağda kayboldu)
Sağlayıcı → POST /api/public/payments/webhook   (aynı bildirim, 30 sn sonra)
Sağlayıcı → POST /api/public/payments/webhook   (aynı bildirim, 5 dk sonra)
```

**İdempotans:** aynı işlemi bir veya çok kez uygulamanın **sonucu
değiştirmemesi**. Bu Action'ın tamamı bu tek özelliği kurmak içindir.

---

## 2. 🔴 İki katman, iki farklı yarış

| Katman | Nerede | Neyi imkânsız kılar |
|---|---|---|
| `provider_ref` **UNIQUE** | Şema (7.2) | Aynı ödeme için **ikinci satır** |
| `lockForUpdate` + `canTransitionTo()` | Burada | Bir satırın **iki kez ilerlemesi** |

`docs/09`'un *"UNIQUE kısıtı idempotansın tek garantisi"* cümlesi yarım
doğrudur: UNIQUE kısıt bir `UPDATE`'i engellemez. **B6** gereği eksik açıkça
yazıldı.

---

## 3. 🔴 Kilit sorgunun içinde

```php
$order = Order::query()
    ->where('provider_ref', $notification->providerRef)
    ->lockForUpdate()
    ->first();
```

Neden `first()` sonra `lockForUpdate()` değil? Çünkü arada bir **boşluk**
kalırdı:

```
webhook A: oku  → status = pending
webhook B: oku  → status = pending     ← ikisi de "geçiş meşru" der
webhook A: yaz  → paid
webhook B: yaz  → paid (ikinci kez!)
```

Bu **check-then-act** yarışıdır ve Faz 2'nin **E2**, Faz 5'in **E9** kuralları
onu yasaklar.

`lockForUpdate()` SQL'e `SELECT … FOR UPDATE` ekler. PostgreSQL'in varsayılan
**READ COMMITTED** seviyesinde:

1. Webhook A satırı kilitler ve `pending` okur
2. Webhook B **aynı satırda bekler** (kilit serbest kalana kadar)
3. A commit eder → kilit düşer
4. B uyanır ve satırı **güncellenmiş** hâliyle (`paid`) yeniden okur
5. `paid → paid` geçişi yasak → B hiçbir şey yapmadan döner

Adım 4 kritik: READ COMMITTED'da kilit bekleyen sorgu, kilidi aldığında satırın
**en son hâlini** görür. Faz 5 ve 6'daki kota kilitleri de aynı mekanizmaya
dayanıyordu.

---

## 4. Geçiş kontrolü neden `if ($status === 'paid')` değil?

```php
if (! $order->status->canTransitionTo($notification->status)) {
    return $order;
}
```

Elle yazılmış bir kontrol **burada, çalıştığı yerde** dururdu ve ikinci bir
çağıran (iade ucu, admin paneli, bir kuyruk işi) onu **yeniden yazmak**
zorunda kalırdı — **C3**: aynı kuralı üreten iki yol zamanla ayrışır.

Kural enum'da olduğu için tek yerdedir ve bir tablo hâlinde okunabilir:

```
pending → paid | failed
paid    → refunded
failed / refunded → (hiçbir yere)
```

---

## 5. Bilinmeyen referans: sessizce yutulur

```php
if ($order === null) {
    Log::warning('Payment webhook for an unknown provider_ref', […]);
    return null;
}
```

İmza **geçerli** — yani gönderen gerçekten sağlayıcı. Ama bu referansla bir
siparişimiz yok. Sebepleri meşrudur: başka bir ortamın (staging) bildirimi,
elle iptal edilmiş bir kayıt, biz oluşturmadan önce gelen bir bildirim.

404 dönmek sağlayıcıyı **sonsuza kadar retry ettirir** ve kuyruğunu doldurur.
Controller bu durumda da `204` döner: webhook uçlarının evrensel kuralı
*"aldım, bir daha gönderme"*dir.

Log tek izdir — ve bir izleme alarmı için doğru yerdir: bu uyarının **artması**
bir yapılandırma hatasının işaretidir.

---

## 6. `paid_at` bir kez yazılır

```php
if ($notification->status->hasBeenPaid() && $order->paid_at === null) {
    $order->paid_at = now();
}
```

İki kural birden:

1. `orders_paid_at_check` **zorunlu kılar** — parası alınmış sipariş damga
   taşımak zorunda
2. `=== null` kontrolü, **iade** bildiriminin damgayı ezmesini önler

İade, ödemenin gerçekleştiği **anı** değiştirmez. `paid → refunded` geçişinde
`hasBeenPaid()` yine `true` döner (ikisi de "para alınmıştı" der) ama damga
zaten dolu olduğu için dokunulmaz.

---

## 7. Bu Action'ın YAPMADIKLARI (B6)

| Yapmaz | Nerede / Neden |
|---|---|
| İmza doğrulamak | `PaymentGateway::parseNotification()` — elindeki `PaymentNotification` zaten kanıt |
| HTTP yanıtı üretmek | Controller (K3) |
| Davetiyeyi yayınlamak | 🔴 Ödeme ≠ yayın. Yayın **ayrı bir kullanıcı eylemidir** (§8) |
| İadede yayını geri çekmek | Bugün iade akışı yok — açık karar (Faz 9) |
| Süresi dolmuş siparişleri kapatmak | `expires_at` yazılıyor, temizleyen iş yok (Faz 9 borcu) |

---

## 8. 🔴 Neden ödeme yayınlamıyor?

Cazip: *"ödeme geldi, davetiyeyi yayınla."* **Reddedildi**, üç gerekçeyle:

1. **Paket alımda hangi davetiye?** `invitation_id` NULL olabilir (K42);
   yayınlanacak kayıt belirsizdir.
2. **Kullanıcı hazır olmayabilir.** Ödeme ile yayın arasında son bir düzenleme
   yapmak isteyebilir.
3. **Sorumluluk sınırı.** Webhook bir **makine** bildirimidir; yayın bir
   **kullanıcı kararıdır**. İkisini birleştirmek, bir ağ tekrarını bir
   kullanıcı eylemine dönüştürürdü.

Akış bu yüzden iki adımdır:

```
POST /api/payments/checkout          → sipariş (pending)
POST /api/public/payments/webhook    → sipariş (paid)      ← burası
POST /api/invitations/{id}/publish   → yayın                ← kullanıcı
```

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `first()` sonra kilitlemek | Check-then-act yarışı; yan etki iki kez |
| 2 | Geçiş kontrolünü elle `if` ile yazmak | Kural dağılır (C3) |
| 3 | Bilinmeyen referansta 404 dönmek | Sağlayıcı sonsuza kadar retry eder |
| 4 | `paid_at`'ı her bildirimde yazmak | İade, ödeme anını siler |
| 5 | Webhook'ta davetiyeyi yayınlamak | Paket alımda hangi davetiye? + sorumluluk karışır |
| 6 | Bu Action'da imzayı yeniden doğrulamak | İkinci bir yorum kaynağı (C3) |
| 7 | Transaction'ı unutmak | Kilit, `save()`'i kapsamaz — yarış geri gelir |

---

## 10. Kendin dene

```php
// php artisan tinker
use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Services\Payment\PaymentNotification;
use App\Enums\OrderStatus;
use App\Models\Order;

$order = Order::factory()->create(['provider_ref' => 'ref-1']);
$action = app(HandlePaymentCallbackAction::class);

$action->handle(new PaymentNotification('ref-1', OrderStatus::Paid));
$order->refresh()->status;      // OrderStatus::Paid
$first = $order->paid_at;

// 🔴 Aynı bildirim ikinci kez
sleep(2);
$action->handle(new PaymentNotification('ref-1', OrderStatus::Paid));
$order->refresh()->paid_at->equalTo($first);   // true — damga DEĞİŞMEDİ

// Bilinmeyen referans
$action->handle(new PaymentNotification('yok', OrderStatus::Paid));   // null
```

**Mutasyon denemesi (kural 14):** `canTransitionTo()` kontrolünü sil.
`php artisan test --filter=PaywallTest` çalıştır.
`the_same_webhook_twice_does_not_move_paid_at` kırılmalı.

İkinci mutasyon: `->lockForUpdate()`'i sil — testler **yeşil kalır**.
🔴 Bu, tablonun kabul ettiği boşluktur: eşzamanlılık tek süreçli bir testte
kurulamaz (**T15**). Koruma yalnızca kod incelemesiyle korunur.

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **İdempotans** | Aynı işlemi bir/çok kez uygulamanın sonucu değiştirmemesi |
| **`SELECT … FOR UPDATE`** | Satırı transaction sonuna kadar kilitleyen okuma |
| **READ COMMITTED** | PostgreSQL varsayılanı: her ifade en son commit'i görür |
| **Check-then-act** | Önce oku sonra yaz — arada başkası girerse bozulan desen |
| **Webhook** | Dış servisin bize HTTP isteği atarak olay bildirmesi |

---

## 12. Sırada ne var?

**7.12 — `PublishInvitationAction`.** Faz 3'ten beri boş duran iskelet nihayet
doluyor: Policy → gereken plan → sahip olunan plan → yayın.
