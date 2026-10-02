# `app/Actions/Payment/HandlePaymentCallbackAction.php`

> **Kod dosyası:** `app/Actions/Payment/HandlePaymentCallbackAction.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.11 · 🆕 **Faz 10**, adım 10.6 (§13: reddedilen ödeme artık sessiz değil)
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
pending → paid | failed | expired
expired → paid                      (Faz 10, K89)
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
| İadede yayını geri çekmek | Bugün iade akışı yok — açık karar (10.61) |
| Süresi dolmuş siparişleri kapatmak | `orders:expire` (Faz 9) — Faz 10'dan beri `expired` yazar |
| Tutarsızlığı kendisi çözmek | 🆕 `failed` + gelen `paid` otomatik çözülmez; `Log::critical` bir insanı çağırır (§13) |

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
| 8 | 🆕 Reddedilen `paid`'i sessizce yutmak | Para alındı, hak açılmadı, iz yok (K-4) |
| 9 | 🆕 Her reddedilen `paid`'de alarm | Webhook tekrarları alarmı gürültüye çevirir; gerçek olan kaybolur (§13.3) |
| 10 | 🆕 Geç kabulü `Log::info` ile yazmak | Üretimde `LOG_LEVEL=warning` — satır hiç yazılmaz (§13.6) |

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

## 12. Sırada ne vardı? (Faz 7)

**7.12 — `PublishInvitationAction`.** Faz 3'ten beri boş duran iskelet nihayet
doluyor: Policy → gereken plan → sahip olunan plan → yayın.

---

## 13. 🆕 Faz 10 — reddedilen ödeme artık sessiz değil (10.6)

### 13.1 Sessizlik bir hataydı

§4'teki geçiş kontrolü idempotansı kuruyordu ve kuruyor. Ama kontrol **bir**
soruya cevap veriyordu (*"bu geçiş meşru mu?"*) ve reddedilen bildirimlerin
hepsine **aynı** tepkiyi veriyordu: sessizce dön. Denetim (K-4) bu
sessizliğin bir ödemeyi yuttuğunu kum havuzunda gösterdi.

Faz 10 bunu iki parçada kapattı:

| Parça | Nerede | Etkisi |
|---|---|---|
| `expired` durumu + `expired → paid` | 10.3 · 10.5 | Geç gelen ödeme artık **kabul** ediliyor |
| Log'lar | **Bu dosya** (10.6) | Kabul edilemeyen ödeme **bağırıyor**, geç kabul **iz bırakıyor** |

### 13.2 Hangi ret alarm ister?

Gelen durum `paid` iken, siparişin olası durumları:

| Siparişin durumu | Geçiş | Anlamı | Tepki |
|---|---|---|---|
| `pending` | ✅ | Normal ödeme | — |
| `expired` | ✅ | **Geç** ödeme, kabul | `Log::warning` (§13.5) |
| `paid` | ❌ | Webhook **tekrarı** | Sessiz — normal işleyiş |
| `refunded` | ❌ | Eski bildirim (iade zaten `paid`'den sonra gelir) | Sessiz |
| `failed` | ❌ | Sağlayıcı önce *"reddedildi"*, şimdi *"ödendi"* diyor | 🔴 **`Log::critical`** |

Koşul tam olarak son satırı seçiyor:

```php
if ($notification->status === OrderStatus::Paid && ! $order->status->hasBeenPaid()) {
    Log::critical('Paid notification rejected: the order cannot become paid', [
        'order_id' => $order->id,
        'provider_ref' => $order->provider_ref,
        'status' => $order->status->value,
    ]);
}
```

`hasBeenPaid()` *"bu sipariş için para daha önce alındı mı"* sorusunu zaten
cevaplıyor (`paid`, `refunded` → evet). Alındıysa gelen `paid` bir tekrardır;
alınmadıysa ve geçiş yine de reddedildiyse, sağlayıcının sözü ile bizim
kaydımız **çelişiyor**. Yeni bir durum eklenirse (ör. `cancelled`) koşul onu
kendiliğinden kapsar — liste yazılmadı, soru soruldu (K50'nin ailesi).

### 13.3 🔴 Neden tekrarlarda alarm yok?

Webhook tekrarı bir istisna değil, **normal işleyiştir** (§1). Sağlayıcı aynı
`paid`'i beş kez gönderirse beş `critical` satırı düşer; bir hafta sonra kimse
`critical`'a bakmaz. **Alarm yorgunluğu**, gerçek alarmı öldüren şeydir.
`a_repeated_paid_webhook_is_not_reported_as_critical` bu yüzden var.

### 13.4 Neden yine 204?

Kabul edilmeyen bir ödemede sağlayıcıya hata dönmek cazip gelebilir (*"tekrar
dene"*). Ama tekrar denemek hiçbir şeyi değiştirmez: durum makinesi yine
reddeder. Hata dönmek yalnızca sağlayıcının kuyruğunu doldurur ve her denemede
bir `critical` daha üretir. Doğru tepki: **aldım (204) + bir insana haber ver**.
Çelişkiyi çözecek olan iade ya da elle eşleştirmedir, HTTP değil.

### 13.5 Geç kabul neden bir uyarı?

```php
$previous = $order->status;
// …
$order->save();

if ($previous === OrderStatus::Expired) {
    Log::warning('Late payment accepted for an expired order', […]);
}
```

`expired → paid` meşru, ama **olağan dışı**. Aradaki sürede ne olmuş olabilir?

```
12:29  kullanıcı öder, webhook kaybolur
13:00  orders:expire → expired
13:02  kullanıcı "ödeme olmadı" sanıp YENİDEN öder → ikinci sipariş paid
13:05  ilk webhook gelir → expired → paid   ⚠️ iki ödeme, bir davetiye
```

Sistem ikisini de doğru işler; ama müşteri iki kez ödemiştir ve birinin iade
edilmesi gerekir. Uyarı bu çifte tahsilatı bulmak için gereken **tek izdir**.

Önceki durum `save()`'ten **önce** yakalanıyor (`$previous`), log `save()`'ten
**sonra** yazılıyor: kayıt başarısız olursa *"kabul edildi"* diye yalan bir satır
düşmesin. (Commit'ten sonra yazmak için `DB::afterCommit()` de kullanılabilirdi;
aynı transaction'daki başarılı bir `UPDATE`'ten sonra commit'in düşmesi ihmal
edilebilir, ek karmaşıklığa değmedi.)

### 13.6 Neden `warning`, `info` değil?

Üretim şablonunda (`docs/10`) `LOG_LEVEL=warning`. `Log::info` üretimde **hiç
yazılmaz**. Bir satırın seviyesini seçmek, onu kimin göreceğini seçmektir.

### 13.7 🔴 `critical` bugün Sentry'ye GİTMİYOR

Plan 10.6 *"Log::critical … Sentry'ye düşer"* diyordu. Uygulamada doğrulandı:
**düşmüyor.**

| Mekanizma | Neyi Sentry'ye yollar |
|---|---|
| `Integration::handles($exceptions)` (`bootstrap/app.php`) | Yalnızca **istisnaları** |
| `sentry` log kanalı | Log satırlarını — ama yalnızca `LOG_STACK` onu içeriyorsa |

`sentry/sentry-laravel` bir `sentry` kanalını kendiliğinden kaydediyor
(`ServiceProvider::registerLogChannels()`), ama üretimde `LOG_STACK=daily` —
kanal yığında yok. Bugün bu satır yalnızca sunucudaki
`storage/logs/laravel-YYYY-MM-DD.log`'a `CRITICAL` seviyesinde düşer. Kimse
okumazsa kimse bilmez.

Kanalı körlemesine yığına eklemek de yanlış: paketin kaydettiği kanalın seviyesi
yok (`debug`) ve Laravel yakalanmamış istisnaları log'a `error` seviyesinde de
yazıyor → her istisna Sentry'ye **iki kez** gider. Doğru ayar iki satır:

```php
// config/logging.php → 'channels'
'sentry' => ['driver' => 'sentry', 'level' => 'critical'],
```

```dotenv
# üretim .env
LOG_STACK=daily,sentry
```

`critical` seviyesi istisnaların `error` satırlarını dışarıda bırakır; yalnızca
`critical`/`alert`/`emergency` gider. **Dilim A'da yapılmadı**: bir işletim
ayarı ve plan dışı bir dosya (`config/logging.php`). FAZ-10 planına 10.55'in
yanına yeni bir satır olarak eklendi — ikisi aynı soruyu çözüyor: *"bir şey
ters gittiğinde haberim olsun."*

> ✅ **Faz 10 (10.55b) — kapandı.** Kanal tam olarak yukarıdaki iki satırla tanımlandı
> (`config/logging.md` → *Faz 10*), üretim şablonu `LOG_STACK=daily,sentry` oldu.
> `ExceptionReportingTest::the_sentry_log_channel_takes_critical_but_not_error`
> eşiği iki yönlü sınıyor.

### 13.8 Log bağlamı bir beyaz listedir (K14)

Test bağlamı **birebir** doğruluyor:

```php
Mockery::on(fn (array $context): bool => $context === [
    'order_id' => $order->id,
    'provider_ref' => 'ref-red',
    'status' => OrderStatus::Failed->value,
]),
```

`user_id` ya da e-posta eklemek testi kırar — bilerek. Log'lar üçüncü taraflara
(Sentry) gidebilir; oraya ne gittiğine karar veren yer kod değil, bu listedir.
Sipariş kimliği bir insanı müşteriye götürmeye yeter.

### 13.9 Mutasyon tablosu (kum havuzunda koşturuldu)

| # | Mutasyon | Kırılan test |
|---|---|---|
| a | `OrderStatus`'ta `expired → paid`'i kapat | `a_paid_webhook_after_expiry_still_grants_the_order` |
| b | `critical` bloğunu `if (false)` yap | `a_paid_webhook_after_a_provider_failure_is_rejected_loudly` |
| c | Koşuldan `! hasBeenPaid()`'i sil (her tekrar alarm) | `a_repeated_paid_webhook_is_not_reported_as_critical` |
| d | Geç kabul uyarısını sil | `a_paid_webhook_after_expiry_still_grants_the_order` |
| e | Uyarıyı her ödemede yaz | `a_signed_webhook_marks_the_order_paid` |
| f | `critical` bağlamına `user_id` ekle | `a_paid_webhook_after_a_provider_failure_is_rejected_loudly` |
| g | `orders:expire` yine `failed` yazsın | `a_paid_webhook_after_expiry_still_grants_the_order` |

### 13.10 Kendin dene

```php
// php artisan tinker
use App\Actions\Payment\HandlePaymentCallbackAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Payment\PaymentNotification;

$action = app(HandlePaymentCallbackAction::class);

// 1) Geç ödeme: kabul + uyarı
$late = Order::factory()->create(['provider_ref' => 'ref-gec', 'status' => OrderStatus::Expired]);
$action->handle(new PaymentNotification('ref-gec', OrderStatus::Paid))->status;   // OrderStatus::Paid

// 2) Reddedilmiş siparişe ödeme: ret + CRITICAL
$red = Order::factory()->failed()->create(['provider_ref' => 'ref-red']);
$action->handle(new PaymentNotification('ref-red', OrderStatus::Paid))->status;   // OrderStatus::Failed
```

```powershell
Get-Content storage\logs\laravel.log -Tail 5
# … local.WARNING: Late payment accepted for an expired order {"order_id":"01…","provider_ref":"ref-gec"}
# … local.CRITICAL: Paid notification rejected: the order cannot become paid {"order_id":"01…","provider_ref":"ref-red","status":"failed"}
```

⚠️ `.env.example` yerelde `LOG_LEVEL=error` diyor: o ayarla **yalnızca** `CRITICAL`
satırı görünür, `WARNING` süzülür. Uyarıyı da görmek için kendi `.env`'inde
`LOG_LEVEL=warning` yap (ya da `debug`) ve `php artisan config:clear` çalıştır.
§13.6'nın aynı dersi, bu kez yerelde: seviye, satırı kimin göreceğini seçer.

---

## 🆕 Faz 10 (10.61 · K100) — iade davetiyeyi yayından kaldırabilir

Sipariş `refunded`'a geçtiğinde ve bir davetiyeye bağlıysa `WithdrawUncoveredInvitationAction`
çağrılıyor: kalan ödenmiş siparişler davetiyenin gerektirdiği planı karşılamıyorsa davetiye taslağa
dönüyor ve `Log::warning` bırakılıyor. Aynı transaction içinde: iade ile yayından kaldırma birlikte
commit ediliyor ya da hiçbiri.

Yalnızca **iade**: başarısız bir yükseltme siparişi davetiyeye dokunmuyor. Gerekçe ve testler:
[`WithdrawUncoveredInvitationAction.md`](../Invitation/WithdrawUncoveredInvitationAction.md).
