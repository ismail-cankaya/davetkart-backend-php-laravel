# `app/Actions/Payment/ClaimReleasedOrderAction.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.8
> **İlgili:** [`../Invitation/DeleteInvitationAction.md`](../Invitation/DeleteInvitationAction.md) ·
> [`../Invitation/PublishInvitationAction.md`](../Invitation/PublishInvitationAction.md) ·
> [`../../Enums/OrderScope.md`](../../Enums/OrderScope.md)
> **Kurallar:** **K42** (iki kaynak, tek arayüz) · **K3** · **E9** · **M8** (idempotans iki katmandır)

---

## 1. Kapanan halka

A2.6 hakkı **serbest bırakıyor** (`invitation_id = NULL`, `scope` değişmiyor).
Ama serbest bir hak kimsenin işine yaramaz: `OrderEntitlementResolver` onu
bilerek eşleşmeyen bırakıyor. Üç dosya birlikte bir döngü kuruyor:

```
DeleteInvitationAction   →  hakkı KOPARIR      (invitation_id = NULL)
OrderEntitlementResolver →  koparılmışı SAYMAZ (hiçbir davetiye açılmaz)
ClaimReleasedOrderAction →  hakkı YENİDEN BAĞLAR (invitation_id = yeni davetiye)
```

Ortadaki adım olmasaydı kullanıcı hakkını kaybederdi; sondaki adım olmasaydı
serbest hak ölü veri olurdu.

---

## 2. 🔴 Neden `PublishInvitationAction`'ın içine yazılmadı?

Tek satırlık bir sorgu gibi görünüyor ve oraya yazmak kolaydı. Yazılmadı,
çünkü `PublishInvitationAction` **K42 gereği hak kaynaklarının varlığını bile
bilmiyor**:

```php
$owned = $this->entitlements->highestTierFor($fresh);   // "ne kadar hakkım var?"
```

O Action `orders` tablosunu, tekil/paket ayrımını, `scope` kolonunu
tanımıyor — Faz 7 bunu bilerek böyle kurdu. İçine `Order::query()` yazsaydık
arayüzün kurduğu soyutlama **ilk değişiklikte** delinirdi ve yarın üçüncü bir
hak kaynağı (kampanya kodu) eklendiğinde iki ayrı yerde iş olurdu.

Ayrı bir Action, `PublishInvitationAction`'ın bağımlılık listesini bir satır
uzatıyor ama **bildiği şeyleri** uzatmıyor: hâlâ "hak nereden geliyor"
sorusunun cevabını bilmiyor, yalnızca "gerekiyorsa şunu dene" diyor.

---

## 3. 🔴 Koşullu çalışır — ve bu bir optimizasyon değil, bir iş kuralı

```php
if ($owned === null || ! $owned->covers($required)) {
    if ($this->claims->handle($fresh, $required)) {
        $owned = $this->entitlements->highestTierFor($fresh);
    }
}
```

Ters sıra ("önce bağla, sonra sor") daha kısa olurdu ve **para yakardı**:
Elit paketi olan bir kullanıcı her yayında elindeki serbest tekil siparişi de
harcardı — zaten yeterli hakkı varken.

> **Kural:** bir tüketim adımı, tüketmeden önce *"gerekli mi"* diye sorar.

Bağlama başarılı olursa cevap **yeniden** soruluyor; `handle()`'ın döndürdüğü
plan varsayılmıyor. Tek doğruluk kaynağı resolver (**C3**) — iki yerden
hesaplanan bir değer bir gün ayrışır.

---

## 4. 🔴 "En yüksek" değil, "yeten en düşük"

```php
if ($best === null || $order->tier->rank() < $best->tier->rank()) {
    $best = $order;
}
```

`OrderEntitlementResolver` tam tersini yapar: **en yüksek** planı döndürür.
Çelişki değil — iki farklı soru:

| | Resolver | Bu Action |
|---|---|---|
| Soru | "Ne kadar hakkım **var**?" | "Hangisini **harcayayım**?" |
| Doğru cevap | En yüksek | Yeten en düşük |
| Yanlış cevap ne yapardı | Hakkı olduğundan az gösterirdi | 249 ₺'lik iş için 549 ₺'lik hakkı yakardı |

**Okuma** ile **tüketim** aynı yönde optimize edilmez. Bu ayrım, aynı veriye
bakan iki kodun neden farklı sıralama yapabileceğinin somut örneği.

---

## 5. 🔴 `lockForUpdate()` — gerçek bir yarış

```php
Order::query()->…->whereNull('invitation_id')->lockForUpdate()->get();
```

Kilitsiz senaryo, aynı kullanıcı iki davetiyeyi eşzamanlı yayınlarsa:

```
İstek A                          İstek B
───────                          ───────
serbest siparişi okur (#1)
                                 serbest siparişi okur (#1)   ← AYNI satır
#1.invitation_id = A yazar
                                 #1.invitation_id = B yazar   ← A'nın üstüne
```

Sonuç: **bir ödeme, iki yayın**. Faz 7'nin **M8**'i bunun webhook'taki
ikiziydi: *UNIQUE kısıt "iki satır olamaz" der, "bir satır iki kez
ilerleyemez" demez.* Burada da kısıt yok, kilit var.

> `PublishInvitationAction` zaten davetiye satırını kilitliyor — ama o kilit
> **davetiyeyi** koruyor. Burada yarışan iki istek **farklı** davetiyeler için
> geliyor ve ortak nesneleri sipariş satırı. Kilit, yarışılan nesnenin
> üstünde olmalı.

---

## 6. Bu Action'ın YAPMADIKLARI (B6)

| Yapmaz | Nerede |
|---|---|
| Hakkı serbest bırakmak | `DeleteInvitationAction` (9.7) |
| "Yeterli mi" kararı vermek | `PublishInvitationAction` + resolver |
| `scope`'u değiştirmek | 🔴 Hiçbir yerde — kapsam değişmez |
| Yayınlamak | `PublishInvitationAction` |
| Paket siparişlere dokunmak | `releasable()` onları dışarıda bırakır |
| Kaç yayın hakkı olduğunu saymak | 🔴 K43 hâlâ açık: paket bugün sınırsız |

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Koşulsuz bağlamak | Paketi olan kullanıcı her yayında bir tekil siparişini yakar |
| 2 | En yüksek planı harcamak | 249 ₺'lik iş için 549 ₺'lik hak gider (§4) |
| 3 | `lockForUpdate()` yazmamak | Bir ödeme iki yayın açar (§5) |
| 4 | Bağlarken `scope`'u `'account'` yapmak | 🔴 Faz 9'un kapattığı delik geri gelir |
| 5 | `where('user_id', …)` unutmak | Başkasının serbest hakkı kullanılır (IDOR) |
| 6 | `grantingPublishRight()` unutmak | Ödenmemiş sipariş yayın açar |
| 7 | Bağlama sonrası resolver'ı tekrar sormamak | İki doğruluk kaynağı (C3) |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Models\{Invitation, Order, User};
use App\Enums\SubscriptionTier;
use App\Actions\Payment\ClaimReleasedOrderAction;

$user = User::factory()->create();
$inv  = Invitation::factory()->create(['user_id' => $user->id]);

$ucuz  = Order::factory()->paid()->tier(SubscriptionTier::Standart)->released()->create(['user_id' => $user->id]);
$pahali = Order::factory()->paid()->tier(SubscriptionTier::Elit)->released()->create(['user_id' => $user->id]);

app(ClaimReleasedOrderAction::class)->handle($inv, SubscriptionTier::Standart);

$ucuz->refresh()->invitation_id;    // $inv->id   ← yeten en düşük harcandı
$pahali->refresh()->invitation_id;  // null       ← dokunulmadı
```

**Mutasyon denemeleri (T16):**

| Mutasyon | Kırmızıya dönmesi gereken test |
|---|---|
| `rank() <` → `rank() >` | `the_cheapest_covering_released_order_is_claimed` |
| Koşulu kaldır (hep bağla) | `publishing_does_not_claim_when_a_package_already_covers_it` |
| `where('user_id', …)` sil | `another_users_released_order_is_never_claimed` |
| `covers()` kontrolünü sil | `a_released_order_that_is_too_cheap_is_not_claimed` |
| `lockForUpdate()` sil | 🔴 **Hiçbiri** — tek süreçli PHPUnit yarışı kuramaz (**T15** ailesi, B6) |

Son satır bu tablonun kabul ettiği boşluktur: koruma yalnızca kod
incelemesiyle korunur.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Claim (bağlama)** | Serbest bir hakkı belirli bir kayda tahsis etmek |
| **`lockForUpdate()`** | Satırı transaction sonuna kadar başka yazıcılara kapatan kilit |
| **Tüketim (consumption)** | Tek kullanımlık bir hakkın harcanması |
| **Blast radius** | Bir hatanın etkilediği alanın genişliği |
