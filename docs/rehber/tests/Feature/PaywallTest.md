# `tests/Feature/PaywallTest.php`

> **Kod dosyası:** `tests/Feature/PaywallTest.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.19
> **Test sayısı:** 33
> **Bitti ölçütü (`docs/09`):** *"Standart planla galeri açık davetiye
> yayınlanamıyor (402); sahte ödeme sonrası yayınlanabiliyor. Aynı webhook iki
> kez gelince tek order."*

---

## 1. Bu dosya neyi kanıtlıyor?

Faz 7 projenin **ticari çekirdeğidir**: buradaki bir hata para kaybettirir ya
da ürünü bedavaya verir. Bu yüzden testlerin çoğu **yanıta değil etkiye** bakar
(**T14**):

| İddia | Yanıt ne der | 🔴 Gerçek kanıt |
|---|---|---|
| Aynı webhook iki kez işlenmez | `204` (ikisinde de) | `paid_at` damgası **değişmedi** |
| Geçersiz imza reddedildi | `404` | Sipariş hâlâ `pending` |
| Fiyat sunucudan geliyor | `201` | `amount_minor` **kolonu** |
| Sağlayıcı hatası telafi edildi | `502` | Sipariş `failed`, `provider_ref` `null` |
| Yayın engellendi | `402` | `status` hâlâ `saved`, `published_at` `null` |

Faz 5'in **44. dersi**: *"sessizlik bir savunma olabilir — ve o zaman testin
yükü artar."* Burada sessiz olan taraf webhook'tur.

---

## 2. Bölümler

| Bölüm | Test | Neyi kapsıyor |
|---|---|---|
| `TierResolver` | 4 | Modül → plan haritası, en yüksek gereksinim |
| `PublishEntitlementResolver` | 7 | K42'nin iki kolu, IDOR, durum filtresi |
| Yayın ucu | 7 | Auth, IDOR, 402 × 2, 200, 409, uçtan uca |
| Checkout | 8 | Auth, doğrulama, IDOR, sunucu fiyatı, 402, paket, sızıntı, 502 |
| Webhook | 7 | İmza, idempotans, durum makinesi, bilinmeyen ref, 400 |
| LCV kotası | 2 | Faz 5'in dikiş yeri gerçek kaynağa bağlandı |
| K63 saat dilimi | 2 | Son tarih davetiyenin diliminde |

---

## 3. 🔴 En önemli üç test

### 3.1 `the_same_webhook_twice_does_not_move_paid_at`

```php
$this->signedWebhook([...])->assertNoContent();
$firstStamp = $order->refresh()->paid_at;

$this->travel(5)->minutes();                       // 🔴 zaman ilerletiliyor

$this->signedWebhook([...])->assertNoContent();

$this->assertSame($firstStamp->getTimestamp(), $order->refresh()->paid_at?->getTimestamp());
```

**`travel(5)->minutes()` olmasaydı test yalan söylerdi.** İki çağrı aynı saniye
içinde çalışır, damga yeniden yazılsa bile **aynı değeri** alırdı ve test
yeşil kalırdı.

Faz 6'nın **49. dersi** birebir bu: *"örtük bir zaman bağımlılığı, flaky bir
testi 'geçen test' gibi gösterir."* Orada `touch()` aynı saniyede olay
fırlatmıyordu; burada zaman bir **girdi** olarak açıkça kontrol ediliyor.

### 3.2 `the_order_amount_comes_from_the_server_side_price`

```php
->postJson(route('payments.checkout'), [
    'tier' => 'elit',
    'price' => 1,          // 🔴 saldırganın denemesi
    'amountMinor' => 1,
])
->assertCreated();

$this->assertDatabaseHas('orders', [
    'amount_minor' => SubscriptionTier::Elit->price() * 100,   // 54900
]);
```

İki katman birden sınanıyor: `StoreCheckoutRequest` bu alanları **kabul
etmiyor** ve `Order`'ın `#[Fillable]` listesi **boş**. İkisinden biri
gevşetilirse test kırılır.

Beklenen değer sabit (`54900`) yazılmadı, `SubscriptionTier::price()`'tan
türetildi — fiyat değişince test de üretimle birlikte hareket etsin (B4).

### 3.3 `the_rsvp_deadline_is_evaluated_in_the_invitation_timezone`

```php
$this->travelTo(CarbonImmutable::parse('2026-09-03 01:00:00', 'UTC'));

// UTC+14 → orada 3 Eylül 15:00 → 2 Eylül geçmişte  → 403
// UTC−11 → orada hâlâ 2 Eylül 14:00 → son gün dâhil → 201
```

**Tek an, tek son tarih, iki saat dilimi, iki farklı sonuç.** Zaman
dondurulmasaydı test koşma saatine göre bazen yeşil bazen kırmızı olurdu.

K63'ün üç faz süren borcunun kapandığının kanıtı bu tek testtir.

---

## 4. Sahte sağlayıcı: arayüzün ikinci kazancı

```php
private function bindExplodingGateway(): void
{
    $this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway {
        public function startCheckout(Order $order): CheckoutSession
        {
            throw new RuntimeException('provider is down');
        }
        …
    });
}
```

502 yolu ve **F3 telafisi**, gerçek bir sağlayıcı olmadan test ediliyor. Somut
sınıfa bağımlı olsaydık bu testi yazmanın tek yolu **ağı kesmekti** — yani
testin hiç yazılmaması.

Faz 5'in `RsvpQuotaResolver` kılavuzu bunu şöyle demişti: *"arayüzün ikinci ve
daha az konuşulan kazancı: test edilebilirlik."* İkinci kanıt burada.

---

## 5. İmza nasıl üretiliyor?

```php
$body = json_encode($payload);
$signature = hash_hmac('sha256', $body, Config::string('app.key'));

return $this->withHeader($this->signatureHeader(), $signature)
    ->postJson(route('public.payments.webhook'), $payload);
```

`postJson` gövdeyi `json_encode($data)` ile serileştirir — testteki
`json_encode($payload)` **aynı çıktıyı** ürettiği için imza tutar.

🔴 Bu bir tesadüf değil, kuralın kendisi: **imza neyin üzerinden hesaplandıysa,
doğrulama da tam olarak onun üzerinden yapılır.** Controller `$request->all()`
kullansaydı bu test kırılırdı — ve kırılması **doğru** olurdu.

---

## 6. T13: guard sıfırlama

```php
$this->withToken($token)->postJson(...)->assertOk();
$this->forgetAuthState();                              // 🔴
$this->withToken($token)->postJson(...)->assertStatus(409);
```

`RequestGuard` çözdüğü kullanıcıyı özellikte tutar ve `setRequest()` onu
temizlemez (Faz 3, **T13**). Çağrılmazsa ikinci istek token'a **hiç bakmaz**.

Burada aynı kullanıcı olduğu için sonuç değişmezdi — ama alışkanlık, farklı
kullanıcıyla yazılan bir sonraki testte hayat kurtarır.

---

## 7. 🔴 Mutasyon tablosu (T16 — faz kapanış ölçütü)

Her satır: *"bu korumayı boz, şu test kırılmalı."* Kırılmıyorsa test süs
demektir.

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 1 | `OrderStatus::canTransitionTo()`'da `Pending` kolunu `true` yap | `the_same_webhook_twice_does_not_move_paid_at` |
| 2 | `canTransitionTo()`'ya `Paid => Failed` ekle | `a_paid_order_cannot_be_moved_back_to_failed` |
| 3 | `grantsPublishRight()`'ı `true` döndür | `a_pending_order_grants_nothing` · `a_refunded_order_grants_nothing` |
| 4 | `OrderEntitlementResolver`'daki `where('user_id', …)` sil | `another_users_package_does_not_grant_publish_rights` |
| 5 | `OR` kolunun closure'ını kaldır (parantezi boz) | `another_users_package_does_not_grant_publish_rights` |
| 6 | `whereNull('invitation_id')` kolunu sil | `a_package_order_grants_the_tier_account_wide` |
| 7 | `TierResolver`'daki `>` → `<` | `the_highest_required_module_wins` · `a_gallery_invitation_requires_the_elit_tier` |
| 8 | `module_tiers` haritasını yok say, hep `lowest()` dön | `a_timeline_invitation_requires_the_gold_tier` |
| 9 | `PublishInvitationAction`'daki `covers()` kontrolünü sil | `a_gold_order_cannot_publish_a_gallery_invitation` |
| 10 | `$owned === null` kontrolünü sil | `publishing_without_any_order_returns_payment_required` (500 olur) |
| 11 | `noPurchase()` → `insufficientTier()` | `publishing_without_any_order_…` (kod farkı) |
| 12 | "Zaten yayında" kontrolünü sil | `publishing_twice_returns_conflict` |
| 13 | `InvitationPolicy::publish()`'i `true` döndür | `owner_cannot_publish_someone_elses_invitation` |
| 14 | Controller'daki `Gate::authorize('publish', …)` sil | `owner_cannot_publish_someone_elses_invitation` · `checkout_for_someone_elses_invitation_…` |
| 15 | `amount_minor`'ı `$request->input('price')` yap | `the_order_amount_comes_from_the_server_side_price` |
| 16 | `StartCheckoutAction`'daki `covers()` kontrolünü sil | `a_tier_that_does_not_cover_the_invitation_is_rejected` |
| 17 | `catch` bloğundaki `status = Failed` satırını sil | `a_failing_gateway_marks_the_order_failed_and_returns_502` |
| 18 | `PaymentProviderException::rejected()` → `unavailable()` | aynı test (502 ≠ 503) |
| 19 | `hash_equals(...)` → `true` | `the_webhook_rejects_an_invalid_signature` |
| 20 | `InvalidWebhookSignatureException` kodunu `Unauthenticated` yap | aynı test (401 ≠ 404) |
| 21 | Bilinmeyen referansta `abort(404)` | `an_unknown_provider_ref_is_accepted_silently` |
| 22 | `translateStatus()`'a `default => Paid` koy | `an_unknown_provider_status_is_rejected` |
| 23 | Webhook'ta davetiyeyi de yayınla | `the_webhook_does_not_publish_the_invitation` |
| 24 | `paid_at`'ı her bildirimde yaz | `a_refund_keeps_the_paid_at_stamp` |
| 25 | `OrderResource`'a `providerRef` ekle | `the_checkout_response_never_exposes_the_provider_ref` |
| 26 | `SubscriptionRsvpQuotaResolver`'da `?? lowest()` → `?->` | `an_unpaid_invitation_falls_back_to_the_narrowest_quota` |
| 27 | Bağlamayı eski `TierRsvpQuotaResolver`'a çevir | `a_gold_order_makes_the_rsvp_quota_unlimited` |
| 28 | `CarbonImmutable::now($timezone)` → `now()` | `the_rsvp_deadline_is_evaluated_in_the_invitation_timezone` |
| 29 | `PublicInvitationResource`'ta `?? config(...)` → `?? ''` | `the_public_payload_always_carries_a_timezone` |
| 30 | `'in:'` kuralını `Rule::enum()` yap | `checkout_rejects_an_unknown_tier` (rule adı `in` değil) |
| 31 | `Order`'ın `#[Fillable]`'ına `status` ekle | ⚠️ **Hiçbiri** — §7.1 |
| 32 | `lockForUpdate()`'leri sil (üç yerde) | ⚠️ **Hiçbiri** — §7.1 |
| 33 | `hash_equals` → `===` | ⚠️ **Hiçbiri** — §7.1 |

### 🔴 7.1 — Tablonun kabul ettiği üç boşluk (B6)

| Mutasyon | Neden yakalanamıyor |
|---|---|
| `#[Fillable]`'a `status` eklemek | İstek gövdesinde `status` **hiç gönderilmiyor**; alanı açmak tek başına bir yol açmaz. Kapatmanın yolu, gövdeye `status` enjekte eden ayrı bir test |
| `lockForUpdate()` silmek | Eşzamanlılık **tek süreçli** bir testte kurulamaz (**T15**). Elle doğrulamada da yok; koruma yalnızca kod incelemesiyle korunur |
| `hash_equals` → `===` | Zamanlama saldırısı ölçülemez; aynı sınıf boşluk |

Bunu yazmak, olmadığını sanmaktan iyidir — **B6**: *bir savunmanın neyi
kapatmadığı da yazılır.* Faz 6'nın mutasyon tablosundaki 20. satır aynı
dürüstlükle yazılmıştı; ikisi de kapanmadı, ama ikisi de **bilinir** oldu.

> 31. satır Faz 6'nın açık bıraktığı `assertJsonStructure` boşluğunu ise
> **kapatıyor**: `the_checkout_response_never_exposes_the_provider_ref`
> `assertJsonMissingPath` kullanıyor (25. satır).

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | İdempotansı yalnızca durum koduyla test etmek | Damga iki kez yazılsa da test yeşil |
| 2 | `travel()` kullanmamak | Aynı saniye; zaman farkı görünmez (ders 49) |
| 3 | Beklenen tutarı sabit yazmak | Fiyat değişince test ile üretim ayrışır (B4) |
| 4 | Sahte sağlayıcıyı `Mockery` ile kurmak | Arayüz zaten var; anonim sınıf daha az bağımlılık |
| 5 | `forgetAuthState()` unutmak | İkinci istek token'a bakmaz (T13) |
| 6 | Saat dilimi testini gerçek zamanla yazmak | Günün saatine göre flaky |
| 7 | Yalnızca yanıtı doğrulamak | T14 ihlali: etki doğrulanmamış olur |

---

## 9. Kendin dene

```powershell
php artisan test --filter=PaywallTest
# 33 passed

php artisan test
# 156 test (123 + 33)
```

---

## 10. Sırada ne var?

**7.20 — `FAZ-7.md`** (faz özeti, kurallar, kararlar) ve
**`FAZ-7-ELLE-DOGRULAMA.md`** (testin kapatamadığı adımlar).
