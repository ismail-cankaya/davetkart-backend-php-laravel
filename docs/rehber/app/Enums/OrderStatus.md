# `app/Enums/OrderStatus.php`

> **Kod dosyası:** `app/Enums/OrderStatus.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.1
> **Birlikte değişenler:** `..._create_orders_table.php` (CHECK kısıtı),
> `app/Models/Order.php` (cast), `HandlePaymentCallbackAction`
> **Kaynağı:** `docs/09-TUM-FAZLAR-PLANI.md` §Faz 7 → *"7.1 `OrderStatus`
> `pending | paid | failed | refunded`"*

---

## 1. Neden fazın ilk dosyası bu?

Kural 7 (`CLAUDE.md` çalışma ritmi): **bağımlılık sırası dosya sırasını
belirler.** Faz 7'nin ilk üç dosyası şu zinciri kurar:

```
OrderStatus (enum)
   ↓ values()          → migration'daki CHECK kısıtı
   ↓ ::class           → Order modelinin cast'i
   ↓ grantsPublishRight() → PublishEntitlementResolver'ın sorgusu
```

Enum yazılmadan migration yazılamaz, çünkü CHECK kısıtı geçerli değerleri
**enum'dan okur** (K39, Faz 3'te `InvitationStatus` ile kurulan desen). Elle
yazsaydık bir gün enum'a `chargeback` eklenir, kısıt eskimiş kalır ve
veritabanı kabul etmediği için üretimde 500 görürdük.

---

## 2. PHP temeli: "backed enum" nedir?

```php
enum OrderStatus: string
{
    case Pending = 'pending';
}
```

`: string` kısmı bu enum'un **destekli (backed)** olduğunu söyler: her case'in
bir ham değeri vardır.

| İşlem | Yazılışı | Sonuç |
|---|---|---|
| Case'ten değere | `OrderStatus::Paid->value` | `'paid'` |
| Değerden case'e (katı) | `OrderStatus::from('paid')` | `OrderStatus::Paid` |
| Değerden case'e (yumuşak) | `OrderStatus::tryFrom('xyz')` | `null` |
| Tüm case'ler | `OrderStatus::cases()` | `[Pending, Paid, Failed, Refunded]` |

TypeScript'teki karşılığı `type OrderStatus = 'pending' | 'paid' | ...`'dır ama
PHP enum'u **davranış da taşıyabilir** — aşağıdaki üç metot tam olarak bu.

---

## 3. Üç metot, üç ayrı sorumluluk

### 3.1 `values()` — şema ile kod arasındaki tek bağ

```php
$allowed = "'".implode("', '", OrderStatus::values())."'";
DB::statement("ALTER TABLE orders ADD CONSTRAINT ... CHECK (status IN ({$allowed}))");
```

> **Güvenlik notu:** burada string birleştirme yapılıyor ama **SQL enjeksiyonu
> değil** — kaynak bir derleme zamanı sabiti (`enum case`), kullanıcı girdisi
> değil. Aynı gerekçe `create_invitations_table` ve `create_media_table`'da da
> yazılı.

### 3.2 `grantsPublishRight()` — K50'nin ikizi

Faz 5'te `RsvpStatus::consumesQuota()` şu kuralı kurmuştu: *"hangi durumların
sayıldığını enum söyler, sorgu değil."* Buradaki karşılığı:

```php
// ❌ Kural sorgunun içine gömülür
$hasPaid = $user->orders()->where('status', 'paid')->exists();

// ✅ Kural enum'da; sorgu onu okur
$hasPaid = $user->orders()
    ->whereIn('status', OrderStatus::publishGrantingValues())  // türetilir
    ->exists();
```

Bugün tek bir durum hak veriyor. Yarın *"kısmi ödeme yapılmış sipariş de
yayınlatsın"* denirse **tek satır** değişir; sorguya yazsaydık üç dosya
aranırdı (`OrderEntitlementResolver`, `SubscriptionRsvpQuotaResolver`,
`PaywallTest`).

### 3.3 `canTransitionTo()` — 🔴 idempotansın uygulama yarısı

```php
pending ──→ paid ──→ refunded
   └────→ failed
```

Bu bir **durum makinesidir (state machine)**: geçerli olan sadece geçişlerdir,
durumların kendisi değil.

🔴 En önemli satır **olmayan** satırdır: `paid → paid` yasak.

Ödeme sağlayıcıları webhook'u **birden çok kez** gönderir (ağ hatası, timeout,
retry politikası). İkinci bildirim geldiğinde:

```php
if (! $order->status->canTransitionTo(OrderStatus::Paid)) {
    return $order;      // sessizce, yan etkisiz
}
```

`paid → paid` serbest olsaydı `paid_at` damgası yenilenir, "ödemeniz alındı"
e-postası ikinci kez giderdi ve muhasebe kaydı çiftlenirdi.

---

## 4. 🔴 İdempotans neden İKİ katmanlı?

`docs/09` §Faz 7 şöyle diyor: *"`provider_ref` UNIQUE kısıtı idempotansın tek
garantisi."* Bu **yarısı doğru** bir cümle ve **B6** gereği neyi kapatıp neyi
kapatmadığını yazmak zorundayız:

| Katman | Neyi imkânsız kılar | Neyi kılmaz |
|---|---|---|
| `provider_ref` **UNIQUE** (veritabanı) | Aynı ödeme için **ikinci bir sipariş satırı** oluşmasını | Var olan satırın iki kez güncellenmesini |
| `canTransitionTo()` + satır kilidi | Aynı satıra **etkinin iki kez uygulanmasını** | — |

İkisi farklı yarışları kapatır. UNIQUE kısıt "iki satır olamaz" der; durum
makinesi "bir satır iki kez ilerleyemez" der. Bunu bilmeden UNIQUE'e güvenmek,
Faz 4'ün 39. dersinin (*"bir savunmanın neyi kapatmadığını yazmak, kapattığını
yazmak kadar önemlidir"*) yeni bir örneği olurdu.

> ⚠️ `canTransitionTo()` tek başına **yeterli değildir**: iki eşzamanlı webhook
> aynı `pending` durumunu okuyup ikisi de "geçiş mesru" diyebilir
> (check-then-act, **E2/E9**). Bu yüzden `HandlePaymentCallbackAction` kontrolü
> `lockForUpdate()` altında, tek transaction içinde yapar (7.15).

---

## 5. `isFinal()` neden var?

Süresi dolmuş siparişleri temizleyen iş (`order_expires_after_minutes`,
`config/payment.php`) yalnızca **durulmamış** satırlara dokunmalıdır. `isFinal()`
bu soruyu tek yerde cevaplar; "pending değilse" kontrolünü üç ayrı yere yazmak
K50'nin yasakladığı dağılmadır.

---

## 6. `label()` neden YOK?

`SubscriptionTier` enum'unda `label()` var, burada yok — ve bu bir tutarsızlık
değil, **K21'in** doğrudan sonucu:

| Enum | Değeri kim görür | `label()` |
|---|---|---|
| `SubscriptionTier` | Kullanıcı **plan seçim ekranında** görür; ad ticari bir isim ("Gold") | ✅ var |
| `OrderStatus` | Değer yalnızca **makineler** arasında dolaşır | ❌ yok |

Sipariş durumunun kullanıcıya nasıl anlatılacağı bir **sunum kararıdır** ve
frontend'e aittir (K20/K21). Yazılsaydı hiç çağrılmayan bir metot olurdu —
Faz 5'in **26. dersi** (`RsvpStatus::label()` tam olarak bu yüzden yazılmadı).

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Kodda `'paid'` düz metnini yazmak | `CLAUDE.md` §1 ihlali. Yazım hatası çalışma anına kaçar; `OrderStatus::Paid` yazım hatasında **anında** patlar |
| 2 | CHECK kısıtındaki listeyi elle yazmak | Enum değişince kısıt sessizce eskir (K39) |
| 3 | `canTransitionTo()`'ya `paid => paid` eklemek | Webhook tekrarı yan etkiyi ikinci kez uygular |
| 4 | Durum makinesini yeterli idempotans sanmak | İki eşzamanlı webhook aynı `pending`'i okur (E9); kilit şart |
| 5 | `grantsPublishRight()` yerine sorguya `where('status','paid')` yazmak | Kural üç dosyaya dağılır (K50 ihlali) |
| 6 | Enum'a `label()` eklemek | K21 ihlali + çağrılmayan ölü kod (ders 26) |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Enums\OrderStatus;

OrderStatus::values();                                    // ['pending','paid','failed','refunded']
OrderStatus::default();                                   // OrderStatus::Pending
OrderStatus::Paid->grantsPublishRight();                  // true
OrderStatus::Pending->grantsPublishRight();               // false

OrderStatus::Pending->canTransitionTo(OrderStatus::Paid); // true
OrderStatus::Paid->canTransitionTo(OrderStatus::Paid);    // 🔴 false — webhook tekrarı burada eleniyor
OrderStatus::Failed->canTransitionTo(OrderStatus::Paid);  // false
OrderStatus::tryFrom('chargeback');                       // null
```

**Mutasyon denemesi (kural 14):** `canTransitionTo()`'daki `self::Pending`
kolunu `$next === self::Paid || $next === self::Failed` yerine `true` yap.
`php artisan test --filter=PaywallTest` çalıştır. `the_same_webhook_twice_
produces_one_paid_order` kırılmalı. Kırılmıyorsa test etkiyi değil yanıtı
doğruluyordur (T14).

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Backed enum** | Her case'i bir ham değer (string/int) taşıyan PHP enum'u |
| **Durum makinesi** | Geçerli durumları **ve aralarındaki geçişleri** tanımlayan model |
| **İdempotans** | Aynı işlemi bir veya çok kez uygulamanın sonucu değiştirmemesi |
| **Check-then-act** | Önce oku, sonra yaz — arada başkası girerse bozulan desen |
| **CHECK kısıtı** | Bir kolonun alabileceği değerleri veritabanı seviyesinde sınırlayan kural |

---

## 10. Sırada ne var?

**7.2 — `..._create_orders_table.php`.** Fazın ticari çekirdeğinin şeması:
`provider_ref` UNIQUE (idempotansın veritabanı yarısı) ve `invitation_id`
nullable (K42 — tekil satın alma ile paket aboneliğin aynı tabloda durması).

| İlgili | Nerede |
|---|---|
| Plan enum'u | [`SubscriptionTier.md`](SubscriptionTier.md) |
| Aynı desenin Faz 5 hâli | [`RsvpStatus.md`](RsvpStatus.md) |
| Faz planı | `docs/09-TUM-FAZLAR-PLANI.md` §Faz 7 |
