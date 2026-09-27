# `app/Enums/OrderStatus.php`

> **Kod dosyası:** `app/Enums/OrderStatus.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.1 · 🆕 **Faz 10**, adım 10.3 (§11: `expired`)
> **Birlikte değişenler:** `..._create_orders_table.php` (CHECK kısıtı),
> `app/Models/Order.php` (cast), `HandlePaymentCallbackAction` ·
> Faz 10: `2026_09_25_100000_add_expired_to_orders_status.php` (CHECK'in yeniden
> kurulması), `ExpireStaleOrders` (yazan), `tests/Unit/OrderStatusTest.php`
> **Kaynağı:** `docs/09-TUM-FAZLAR-PLANI.md` §Faz 7 → *"7.1 `OrderStatus`
> `pending | paid | failed | refunded`"* · Faz 10: **K89**

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
| Tüm case'ler | `OrderStatus::cases()` | `[Pending, Paid, Failed, Expired, Refunded]` |

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
   │          ▲
   │          │ (Faz 10: geç gelen ödeme)
   ├────→ expired
   └────→ failed
```

Bu bir **durum makinesidir (state machine)**: geçerli olan sadece geçişlerdir,
durumların kendisi değil. Faz 7'deki hâli yalnızca `pending → paid | failed` ve
`paid → refunded` idi; `expired` ve ondan `paid`'e dönen ok Faz 10'da geldi (§11).

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

Faz 7'de yazılırken gerekçe şuydu: süresi dolmuş siparişleri temizleyen iş
yalnızca **durulmamış** satırlara dokunmalıdır ve *"pending değilse"* kontrolü
tek yerde durmalıdır.

🔴 **Faz 10'da iki gerçek ortaya çıktı** ve metot değişti (§11.4):

1. **Kimse çağırmıyordu.** Faz 9'da yazılan `orders:expire` doğrudan
   `where('status', Pending)` kullandı — ve doğru olan oydu (sorgunun
   kapsamında, E2). `isFinal()` dokuz fazdır ölü kod (ders 26).
2. **Gövdesi yalan söylüyordu.** `return $this !== self::Pending;` → `paid`
   *"sonlu"* diyordu, oysa `paid → refunded` meşru. `expired` eklenince yalan
   büyüyecekti: `expired → paid` meşru ama metot *"sonlu"* diyecekti.

Artık cevap **durum makinesinden türetiliyor**: gidebileceği hiçbir durum
yoksa sonludur. Bugün yalnızca `failed` ve `refunded`.

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
| 7 | 🆕 "Bekledik, gelmedi" ile "sağlayıcı reddetti"yi aynı değere yazmak | Geç gelen ödeme sessizce yutulur (Faz 9 → 10, K-4) |
| 8 | 🆕 Türetilebilecek bir cevabı (`isFinal`) elle yazmak | Yeni durum eklenince metot sessizce yalan söyler |
| 9 | 🆕 `expired`'i `hasBeenPaid()`'e eklemek | `orders_paid_at_check` her süresi dolmuş satırda `paid_at` ister — yazılamaz |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Enums\OrderStatus;

OrderStatus::values();                                    // ['pending','paid','failed','expired','refunded']
OrderStatus::default();                                   // OrderStatus::Pending
OrderStatus::Paid->grantsPublishRight();                  // true
OrderStatus::Pending->grantsPublishRight();               // false

OrderStatus::Pending->canTransitionTo(OrderStatus::Paid); // true
OrderStatus::Paid->canTransitionTo(OrderStatus::Paid);    // 🔴 false — webhook tekrarı burada eleniyor
OrderStatus::Failed->canTransitionTo(OrderStatus::Paid);  // false
OrderStatus::Expired->canTransitionTo(OrderStatus::Paid); // 🆕 true — geç gelen ödeme (K89)
OrderStatus::Expired->grantsPublishRight();               // false
OrderStatus::Paid->isFinal();                             // false — iade edilebilir
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

## 10. Sırada ne vardı? (Faz 7)

**7.2 — `..._create_orders_table.php`.** Fazın ticari çekirdeğinin şeması:
`provider_ref` UNIQUE (idempotansın veritabanı yarısı) ve `invitation_id`
nullable (K42 — tekil satın alma ile paket aboneliğin aynı tabloda durması).

| İlgili | Nerede |
|---|---|
| Plan enum'u | [`SubscriptionTier.md`](SubscriptionTier.md) |
| Aynı desenin Faz 5 hâli | [`RsvpStatus.md`](RsvpStatus.md) |
| Faz planı | `docs/09-TUM-FAZLAR-PLANI.md` §Faz 7 |

---

## 11. 🆕 Faz 10 — `expired` (10.3 · K89)

### 11.1 Hata: `failed` iki gerçeği anlatıyordu

Faz 9'un `orders:expire` komutu, ödeme penceresi dolan `pending` siparişleri
`failed` yapıyordu. O andan itibaren `failed` iki ayrı olguyu aynı biçimde
temsil etti:

| Kim yazdı | Anlamı | Kesin mi? |
|---|---|---|
| Sağlayıcının webhook'u | *"Kart reddedildi"* | ✅ Kesin |
| `StartCheckoutAction`'ın telafisi (F3) | *"Ödeme hiç başlatılamadı"* | ✅ Kesin |
| `orders:expire` | *"30 dakika bekledik, haber gelmedi"* | ❌ **Tahmin** |

Üçüncüsü bir tahmindi ve tahmin yanlış çıkabiliyordu. Denetim bunu kum havuzunda
**yeniden üretti** (K-4):

```
12:00  checkout → pending, expires_at 12:30
12:29  kullanıcı 3D Secure'u bitirir, sağlayıcı parayı çeker
12:29  webhook denemesi başarısız (ağ, deploy, 429)
13:00  orders:expire → failed
13:05  sağlayıcı webhook'u tekrar dener: paid
       canTransitionTo(failed → paid) = false → 204, sipariş failed, log YOK
```

Para çekildi, hak açılmadı, hiçbir yerde iz yok.

> **E12'nin ikinci örneği:** *"bir kolonun anlamı, ona yazan tüm yolların
> toplamıdır."* `orders.scope`'u doğuran sorun (Faz 9: `invitation_id IS NULL`
> hem "paket" hem "davetiyesi silindi" demekti) burada `status` kolonunda
> tekrarlandı. Çözüm de aynı: iki gerçeğe **iki ayrı değer**.

### 11.2 Çözüm: tahmine kendi adı

```php
/** Odeme penceresi doldu, saglayicidan SONUC gelmedi (orders:expire yazar). */
case Expired = 'expired';
```

| Durum | Kimin sözü | Geri dönebilir mi? |
|---|---|---|
| `failed` | Sağlayıcının (ya da telafinin) | ❌ Hayır — final |
| `expired` | **Bizim** sabrımızın sonu | ✅ `paid`'e |

```php
self::Pending => in_array($next, [self::Paid, self::Failed, self::Expired], true),
self::Expired => $next === self::Paid,
```

🔴 **`failed → paid` hâlâ kapalı** (plan: *"`Failed` final kalır"*). Sağlayıcı
bir kez *"reddedildi"* dediyse sonradan gelen *"ödendi"* bir çelişkidir ve
**otomatik** çözülmemeli: bir insan bakmalı. O yüzden bu geçiş reddedilir ama
artık **gürültüyle** reddedilir — `HandlePaymentCallbackAction` `Log::critical`
yazar (10.6).

`pending → expired` geçişi tabloda **var** ama onu kullanan tek yazıcı
(`ExpireStaleOrders`) `canTransitionTo()`'yu çağırmıyor: koşulu toplu
`UPDATE`'in içinde, sorgunun kapsamında taşıyor (E2). Tablo yine de tam
yazıldı: durum makinesi *"kim yazarsa yazsın, hangi geçişler meşru"* sorusunun
**belgesidir**, yalnızca webhook'un kuralı değil.

### 11.3 Değişmeyen iki soru

| Metot | `expired` için | Neden |
|---|---|---|
| `grantsPublishRight()` | `false` | Para gelmedi; hak yok |
| `hasBeenPaid()` | `false` | 🔴 `true` olsaydı `orders_paid_at_check` her süresi dolmuş satırda `paid_at` isterdi — komut yazamazdı |

Plan bunu açıkça istedi: *"`grantsPublishRight()` ve `hasBeenPaid()` değişmez."*
Sonuç: `paidValues()` hâlâ `['paid', 'refunded']` ve `paid_at` kısıtına
migration'da (10.4) dokunulmuyor.

### 11.4 `isFinal()` türetildi

```php
public function isFinal(): bool
{
    foreach (self::cases() as $next) {
        if ($this->canTransitionTo($next)) {
            return false;
        }
    }

    return true;
}
```

`paidValues()`'un `hasBeenPaid()`'ten türetilmesiyle aynı fikir (**K39**'un
metot hâli): bir cevap başka bir cevaptan çıkarılabiliyorsa elle yazılmaz.

| Durum | Eski `isFinal()` | Yeni | Doğrusu |
|---|---|---|---|
| `pending` | false | false | ✅ |
| `paid` | **true** | false | `paid → refunded` meşru |
| `failed` | true | true | ✅ |
| `expired` | **true** (eklenseydi) | false | `expired → paid` meşru |
| `refunded` | true | true | ✅ |

Metodu **çağıran yok**, dolayısıyla `paid` satırındaki değişiklik bugün hiçbir
davranışı değiştirmiyor. Silmek de bir seçenekti (ders 26); ama 10.3'ün işi
enum'u tutarlı bırakmak, temizlik değil. Silinip silinmeyeceği Dilim H'de
(`SubscriptionTier::label()` ile aynı soru, 10.80) sorulabilir.

### 11.5 Birim testi: tablonun tamamı

`tests/Unit/OrderStatusTest.php` 5 × 5 = 25 çiftin **hepsini** sabitliyor.
Feature testleri makinenin yalnızca birkaç geçişini dener (webhook tekrarı,
iade, geç ödeme); yarın biri *"`expired → refunded` da açık olsun"* derse
feature testleri yeşil kalır, tablo testi kırılır. Ayrıntı:
[`../../tests/Unit/OrderStatusTest.md`](../../tests/Unit/OrderStatusTest.md).

**Mutasyon denemesi:** `self::Expired => $next === self::Paid` kolunu
`self::Expired => false` yap → `the_transition_table_is_exactly_the_documented_one`
ve `an_expired_order_can_still_become_paid_but_a_failed_one_cannot` kırılır;
10.7'den sonra `PaywallTest::a_paid_webhook_after_expiry_still_grants_the_order` da.

### 11.6 Frontend

`types.ts` → `OrderStatus`'a `'expired'` (10.9). Frontend bugün bu değeri
yalnızca `CheckoutResult.status`'ta görebilir (yeni siparişler hep `pending`);
asıl okuyucusu 10.27'nin ödeme dönüş sayfası olacak: `expired` → *"tekrar dene"*.
