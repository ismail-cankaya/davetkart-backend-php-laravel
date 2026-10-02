# `tests/Feature/PaywallTest.php`

> **Kod dosyası:** `tests/Feature/PaywallTest.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.19 · 🆕 **Faz 10**, adımlar 10.2 (§11) ve 10.7 (§12)
> **Test sayısı:** 62 (Faz 7: 33 · Faz 9: +18 · Faz 10: +7 +4)
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

## 10. Sırada ne vardı? (Faz 7)

**7.20 — `FAZ-7.md`** (faz özeti, kurallar, kararlar) ve
**`FAZ-7-ELLE-DOGRULAMA.md`** (testin kapatamadığı adımlar).

> ⚠️ **K18 borcu:** Faz 9'un bu dosyaya eklediği 18 test (serbest bırakma,
> bağlama, silme penceresi — `ClaimReleasedOrderAction`, `DeleteInvitationAction`)
> bu kılavuzda henüz anlatılmıyor ve yukarıdaki mutasyon tablosunda yok.
> Test denetiminin 10.49 adımı bu dosyaya zaten dönecek; borç orada kapanabilir.

---

## 11. 🆕 Faz 10 — yayındaki davetiyede modül (10.2 · K88)

**Bulgu:** rapor §1.1 · denetim **K-1**. Dosyanın 51 testinin hiçbiri yayından
sonra `PUT` atmıyordu; paywall'ın yalnızca yayın anında sorulduğunu bu yüzden
kimse görmedi. Üretim kodu: [`UpdateInvitationAction.md`](../../app/Actions/Invitation/UpdateInvitationAction.md) §9.

### 11.1 Yedi test, yedi iddia

| Test | İddia | 🔴 Kanıt (T14) |
|---|---|---|
| `a_published_invitation_cannot_enable_a_module_above_its_tier` | Standart + galeri → 402 `PAYWALL_TIER_INSUFFICIENT`, `requiredTier: elit` | `show_gallery` hâlâ `false` **ve** aynı istekteki başlık yazılmadı |
| `a_published_invitation_can_enable_a_module_within_its_tier` | Gold + zaman çizelgesi → 200 | Kolon `true` |
| `disabling_a_module_needs_no_covering_order` | İade edilmiş siparişle (hak **yok**) galeri kapatılabilir | Kolon `false` |
| `upgrading_the_order_lets_the_owner_enable_the_module` | Aynı istek: önce 402, Elit alınınca 200 | `show_gift` + `bank_name` yazıldı |
| `a_draft_invitation_can_enable_any_module_without_an_order` | Taslakta her şey serbest (K43) | İki bayrak `true`, durum `saved` |
| `a_published_invitation_stays_editable_when_the_price_map_changes` | Fiyat haritası değişse de metin düzeltilebilir | `venue` yazıldı |
| `enabling_a_module_after_a_refund_asks_for_a_purchase` | Hak yoksa 402 `PAYMENT_REQUIRED` (yayın ucuyla aynı iki kod) | Kolon `false` |

Plan beş test istemişti. Son ikisi **seçilen kuralın** kanıtı: kural son hâle
değil **farka** bakıyor (Action kılavuzu §9.3). Onlar olmasaydı kuralı *"sahip
olunan plan son hâli kapsıyor mu"*ya çeviren bir değişiklik bütün testleri
yeşil geçerdi (mutasyon M3) — ve iade sonrası kullanıcı davetiyesine kilitlenirdi.

### 11.2 İlk testin iki katmanlı kanıtı

```php
->putJson(route('invitations.update', $invitation), ['invitation' => [
    'title' => 'Nikâhımıza Davetlisiniz',
    'showGallery' => true,
]])
->assertStatus(402);

$this->assertDatabaseHas('invitations', [
    'show_gallery' => false,                 // reddedilen alan yazılmadı
    'title' => 'Düğünümüze Davetlisiniz',    // 🔴 masum alan da yazılmadı
]);
```

İkinci satır olmasaydı *"402 dön ama başlığı yine de kaydet"* diyen bir kod
(örneğin kontrolü `save()`'ten sonra, transaction **dışında** yapan) testi
geçerdi. Autosave her seferinde **tüm** davetiyeyi gönderdiği için bu fark
önemli: frontend reddedilen isteğin **hiçbir parçasının** yazılmadığını bilerek
anahtarı geri alır ve kalanını yeniden gönderir (frontend 10.8).

### 11.3 Veri artık oyuncak değil

Test denetimi *"oyuncak veri"* (`Dugunumuz`) ve *"ASCII isimler"* diye iki kez
not düşmüştü. Yeni testler gerçek metin kullanıyor: `Nikâhımıza Davetlisiniz`,
`Çırağan Sarayı, İstanbul`, `Ziraat Bankası`. `assertDatabaseHas` karşılaştırmayı
veritabanında yapar, JSON kaçışı (`\u00e7`) araya girmez — Türkçe karakter
burada güvenle doğrulanır.

### 11.4 Mutasyon tablosu (kum havuzunda koşturuldu)

| # | Mutasyon (`UpdateInvitationAction`) | Kırılan test |
|---|---|---|
| 34 | `status === Published` kontrolünü kaldır | `a_draft_invitation_can_enable_any_module_without_an_order` |
| 35 | `covers()` reddini kaldır | `a_published_invitation_cannot_enable_…` · `upgrading_the_order_…` |
| 36 | Fark kuralını kaldır (son hâle bak) | `disabling_a_module_needs_no_covering_order` · `…_stays_editable_when_the_price_map_changes` |
| 37 | Önceki gereksinimi `fill()`'den sonra ölç | `a_published_invitation_cannot_enable_…` (+2) |
| 38 | `noPurchase()` → `insufficientTier()` | `enabling_a_module_after_a_refund_asks_for_a_purchase` |
| 39 | `lockForUpdate()`'i sil | ⚠️ **Hiçbiri** — T15 (§7.1'in 32. satırıyla aynı sınıf) |
| 40 | Kontrolü `save()`'ten sonraya al | ⚠️ **Hiçbiri** — transaction geri alıyor; fark yalnızca bir cache temizliği |

### 11.5 Kendin dene

```powershell
php artisan test --filter=PaywallTest
# 58 passed
```

Elle karşılığı: Standart ile bir davetiye yayınla → dashboard'dan **Düzenle** →
*Tasarımını Düzenle* → **Fotoğraf Galerisi** anahtarını aç. Network sekmesinde
`PUT /api/invitations/{id}` → **402**. Frontend tarafı 10.8'de.

---

## 12. 🆕 Faz 10 — geç gelen ödeme (10.7 · K89)

**Bulgu:** rapor §1.2 · denetim **K-4**. Üretim kodu üç dosyaya dağıldı:
[`OrderStatus.md`](../../app/Enums/OrderStatus.md) §11 (durum + geçiş) ·
[`ExpireStaleOrders.md`](../../app/Console/Commands/ExpireStaleOrders.md) §9 (yazan) ·
[`HandlePaymentCallbackAction.md`](../../app/Actions/Payment/HandlePaymentCallbackAction.md) §13 (log'lar).

### 12.1 Dört yeni test, bir değişen test

| Test | İddia | 🔴 Kanıt (T14) |
|---|---|---|
| `an_expired_order_grants_nothing` | `expired` hak vermez | Resolver `null` döner |
| `a_paid_webhook_after_expiry_still_grants_the_order` | Uçtan uca K-4: `orders:expire` → `expired` → imzalı `paid` → **yayın** | Sipariş `paid`, `paid_at` dolu, `POST /publish` **200**, `Log::warning` |
| `a_paid_webhook_after_a_provider_failure_is_rejected_loudly` | `failed` final kalır ama red **gürültülü** | Sipariş `failed`, `paid_at` `NULL`, `Log::critical` **birebir** bağlamla |
| `a_repeated_paid_webhook_is_not_reported_as_critical` | Tekrar normaldir | Ne `critical` ne `warning` |
| `a_signed_webhook_marks_the_order_paid` *(Faz 7, değişti)* | Zamanında ödeme uyarı üretmez | `shouldNotHaveReceived('warning')` |

### 12.2 🔴 Uçtan uca testin zinciri

```php
$order = Order::factory()->forInvitation($invitation)->create([
    'provider_ref' => 'ref-gec',
    'expires_at' => now()->subMinute(),       // pencere kapandı
]);

$this->assertSame(Command::SUCCESS, Artisan::call('orders:expire'));   // 1. gerçek komut
$this->assertSame(OrderStatus::Expired, $order->refresh()->status);

$this->signedWebhook(['providerRef' => 'ref-gec', 'status' => 'paid'])  // 2. gerçek imza
    ->assertNoContent();

$this->withToken(…)->postJson(route('invitations.publish', $invitation))   // 3. gerçek yayın
    ->assertOk();
```

Süresi dolmuş sipariş **fabrikayla** (`['status' => Expired]`) üretilebilirdi.
Üretilmedi: o zaman test, komutun gerçekten `expired` yazdığını değil, bizim
elle koyduğumuz değeri sınardı. Zincirin üç halkası da gerçek kod — biri
bozulursa (komut `failed` yazsa, geçiş kapansa, yetki okunmasa) test kırılır.
Mutasyon tablosunda üç ayrı mutasyon (a, d, g) bu tek testi kırıyor.

Son halka (`publish` → 200) en önemlisi: *"sipariş `paid` oldu"* bir ara
sonuçtur; kullanıcının umursadığı şey **davetiyenin yayınlanabilmesidir**.

### 12.3 `Log::spy()` — log'u bir etki olarak sınamak

```php
$logger = Log::spy();
// … istek …
$logger->shouldHaveReceived('critical', [
    'Paid notification rejected: the order cannot become paid',
    Mockery::on(fn (array $context): bool => $context === [...]),
]);
```

`spy()` Log facade'ının arkasına bir **casus** koyar: çağrılar gerçekten
yapılır ama kaydedilir, sonra sorgulanır. `MediaTest`'in PHP sınırı testindeki
(`a_file_dropped_by_the_php_limit_is_logged`) desenin aynısı.

Bu testlerde log bir yan ayrıntı değil, **ürünün kendisi**: reddedilen bir
ödemenin tek çıktısı o satır. Onu sınamamak, K-4'ün *"log yok"* yarısını açık
bırakmak olurdu.

`shouldNotHaveReceived` iddiaları (**T6**) en az olumlular kadar önemli: her
webhook tekrarında `critical` atan bir kod da *"reddedildi ve log yazıldı"*
testini geçerdi. Alarm yorgunluğunu bu iki test (`a_repeated_…`,
`a_signed_webhook_…`) önlüyor.

### 12.4 Mutasyon tablosu (kum havuzunda koşturuldu)

| # | Mutasyon | Kırılan test |
|---|---|---|
| 41 | `OrderStatus`: `expired → paid` kapalı | `a_paid_webhook_after_expiry_still_grants_the_order` (+ Unit) |
| 42 | `ExpireStaleOrders` yine `failed` yazsın | `a_paid_webhook_after_expiry_still_grants_the_order` · `MaintenanceTest::it_expires_…` |
| 43 | `critical` bloğunu kaldır | `a_paid_webhook_after_a_provider_failure_is_rejected_loudly` |
| 44 | `critical` koşulundan `! hasBeenPaid()`'i sil | `a_repeated_paid_webhook_is_not_reported_as_critical` |
| 45 | `critical` bağlamına `user_id` ekle | `a_paid_webhook_after_a_provider_failure_is_rejected_loudly` |
| 46 | Geç kabul uyarısını sil | `a_paid_webhook_after_expiry_still_grants_the_order` |
| 47 | Uyarıyı her ödemede yaz | `a_signed_webhook_marks_the_order_paid` |

### 12.5 Bu testlerin kapatamadıkları (B6)

| Kapatmaz | Neden |
|---|---|
| `critical`'ın Sentry'ye ulaşması | Bugün ulaşmıyor — `HandlePaymentCallbackAction.md` §13.7; plan 10.55'in yanına yeni satır |
| Toplu `UPDATE` ile eşzamanlı webhook yarışı | **T15**. Mantık `ExpireStaleOrders.md` §9.3'te |
| Çifte tahsilatın **tespiti** | Uyarı yalnızca iz bırakır; iki ödemeyi eşleştiren bir iş yok |

### 12.6 Kendin dene

```powershell
php artisan test --filter=PaywallTest
# 62 passed
php artisan test --testsuite=Unit
# 11 passed
```

---

## 13. 🆕 Faz 10 — fiyat ve modül haritası sabit yazıldı (10.49)

`TEST-DENETIMI` §2 bu dosyada üç boş yeşil buldu. Üçü de aynı kökten: **beklenen değer,
sınanan kodla hesaplanıyordu**.

| Mutant | Neden yeşildi | Şimdi kıran |
|---|---|---|
| `SubscriptionTier::price()` → `return 1` | Beklenen `Elit->price() * 100` idi: o da 100 oldu | `the_order_amount_…` · `each_tier_is_charged_…` ×3 |
| `currency` → `USD` | Hiç sınanmıyordu | aynı dört test |
| `show_envelope`: gold → standart | Yalnızca program (gold) ve galeri (elit) sınanıyordu | `each_module_requires_its_published_tier` |

### 13.1 Kural: beklenen değeri elle yaz

```php
// ❌ Önce: test kendisiyle karşılaştırılıyor
'amount_minor' => SubscriptionTier::Elit->price() * 100,

// ✅ Şimdi: fiyat sayfasındaki söz
'amount_minor' => 54900,
'currency' => 'TRY',
```

Fiyat bir iş kararı ve `config/davetkart.php`'de duruyor (E6). Değiştiğinde bu testler
**bilerek** kırılır: değişikliği yapan kişi testteki sayıyı da değiştirmek zorunda kalır ve
bu, kararın bilinçli olduğunu kanıtlar. Config'ten okuyan bir test bunu sağlamaz: config'te
yanlışlıkla `54.9` yazılsa da yeşil kalırdı.

Aynı ders Faz 10'da iki yerde daha uygulandı: `InvitationTest` §12 (serbest bırakma
penceresi 3 gün) ve `MaintenanceTest` §6e (saklama süreleri).

### 13.2 Yeni testler

| Test | Vaka | İddia |
|---|---|---|
| `each_tier_is_charged_its_published_price` | standart 24900 · gold 39900 · elit 54900 | `amount_minor` kuruş, `currency` `TRY` (K: para en küçük birimde tam sayı) |
| `each_module_requires_its_published_tier` | 6 modül | galeri, hediye → elit · zarf, program → gold · geri sayım, LCV → standart |

Eski `a_timeline_…` ve `a_gallery_…` testleri kaldı: okunur bir *"hikâye"* anlatıyorlar.
Yeni veri sağlayıcı haritanın **tamamını** kilitliyor.

### 13.3 Mutasyon kanıtı (1 Ekim 2026)

| Mutasyon | Kırılan |
|---|---|
| `price()` → `1` | 4 |
| elit 549 → 548 | 2 |
| standart 249 → 199 | 1 |
| `currency` → `USD` | 4 |
| `* 100` → `* 10` (kuruş çevrimi) | 4 |
| zarf gold → standart | 1 |
| hediye elit → gold | 2 |
| LCV standart → gold | 1 |
| geri sayım standart → gold | 1 |

Dokuzu da öldü. Eski dosyaya karşı yeniden koşturuldu: yedisi bu adımdan önce **yeşildi**; kuruş çevrimini ve hediye → gold'u eski testler de yakalıyordu. Dosya 62 → 71 vaka.

---

## 14. 🆕 Faz 10 — paket tek davetiye (10.58 · K99)

| Test | Önce | Şimdi |
|---|---|---|
| `a_package_order_grants_the_tier_account_wide` | Paket hesabın tüm davetiyelerini açar | **Silindi** → `an_unclaimed_package_grants_nothing_on_its_own` |
| 🆕 `a_package_publishes_exactly_one_invitation` | — | İlk yayın paketi bağlar; ikinci davetiye 402 `PAYMENT_REQUIRED` |
| `another_users_package_does_not_grant_publish_rights` | Başkasının paketi | → `another_users_order_grants_nothing_even_if_bound_here`: bozuk veri elle kuruluyor, `user_id` savunması sınanıyor |
| `the_highest_paid_tier_wins` | Bağlı Standart + paket Elit | İki **bağlı** sipariş |
| `deleting_an_invitation_does_not_touch_a_package_order` | Silme paketi etkilemez | → `a_claimed_package_is_released_like_any_single_order` |
| `publishing_does_not_claim_when_a_package_already_covers_it` | Paket yetiyorsa serbest sipariş harcanmaz | → `…_when_a_bound_order_already_covers_it`: davetiyenin kendi siparişi yetiyorsa paket harcanmaz |
| 🆕 `the_migration_turns_old_packages_into_unclaimed_orders` | — | Eski `'account'` satırı çevriliyor |
| `the_package_checkout_creates_an_order_without_an_invitation` | — | + `scope = 'invitation'` |

**Mutasyon (1 Ekim 2026):**

| Mutasyon | Kırılan |
|---|---|
| Davetiyesiz sipariş yine `'account'` | checkout testi |
| Resolver bağsız siparişleri de saysın | 8 test (paket ve serbest sipariş testlerinin hepsi) |
| Resolver'dan `user_id` koşulu silindi | `another_users_order_grants_nothing_even_if_bound_here` (ilk denemede **hiçbiri**: eski test paket kolunu sınıyordu, yeniden yazıldı) |
| Migration hiçbir satırı çevirmesin | migration testi |

---

## 15. 🆕 Faz 10 — iade yayından kaldırır (10.61 · K100)

| Test | İddia |
|---|---|
| 🔴 `a_refund_unpublishes_an_invitation_left_without_cover` | Tek sipariş iade: taslak, `published_at` boş, public 404, `Log::warning` bağlamıyla |
| `a_refund_keeps_an_invitation_another_order_still_covers` | Gold (yeterli) + Elit iade: yayında kalır |
| `a_refund_unpublishes_when_the_remaining_order_is_too_cheap` | Galeri (Elit) + kalan Gold: kalkar |
| `a_failed_upgrade_does_not_unpublish_the_invitation` | Harita değişmiş, yükseltme başarısız: yayında kalır |
| `refunding_an_unclaimed_package_touches_no_invitation` | Bağsız paketin iadesi hiçbir davetiyeye dokunmaz |

**Mutasyon (1 Ekim 2026):**

| Mutasyon | Kırılan |
|---|---|
| İade davetiyeye hiç bakmasın | iki *"kalkar"* testi |
| Kapsama kontrolü silinsin (her iadede kalksın) | *"başka sipariş yetiyor"* |
| *"Herhangi bir sipariş yeter"* | *"kalan sipariş ucuz"* |
| `published_at` silinmesin | ilk test |
| Log `info`'ya düşsün (üretimde yazılmaz) | ilk test |
| Kontrol her durum değişiminde çalışsın | `a_failed_upgrade_…` (ilk koşuda **hayatta kaldı**, test bunun için eklendi) |

---

## 16. 🆕 Faz 10 — fiyat kartı vaatleri (10.66 · K102)

| Test | İddia |
|---|---|
| `a_premium_theme_requires_at_least_gold` | Videolu tema → Gold · + galeri → Elit · sıradan tema → Standart |
| `the_premium_themes_are_the_thirteen_video_themes` | Liste **sabit**: 13 kimlik, hepsi `gold` |
| `a_standart_order_cannot_publish_a_premium_theme` | Uçtan uca 402 `PAYWALL_TIER_INSUFFICIENT`, `requiredTier = gold` |
| `a_paid_or_refunded_order_refreshes_its_invitations_public_page` | `failed` → olay yok · `paid` → `InvitationChanged` |

**Mutasyon (2 Ekim 2026):** tema kuralı yok · tema modülü ezsin · listeden bir tema düşsün ·
ödemede olay yok · her durumda olay: beşi de kırıldı.
