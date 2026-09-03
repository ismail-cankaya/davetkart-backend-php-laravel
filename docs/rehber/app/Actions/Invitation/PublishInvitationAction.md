# `app/Actions/Invitation/PublishInvitationAction.php`

> **Kod dosyaları:** `app/Actions/Invitation/PublishInvitationAction.php` ·
> `app/Policies/InvitationPolicy.php` (`publish()`)
> **Faz:** 7 — Ödeme ve paywall, dosya 7.12
> **Kararlar:** **K47** (yayın ucu Faz 4'te yazılmadı) · **K42** · **K43** ·
> **K6** · **K48**

---

## 1. Üç fazdır bekleyen dosya

Bu sınıf Faz 3'te `make:class` ile oluşturuldu ve **boş bir iskelet** olarak
kaldı. Faz 4'te yazılmadı ve gerekçe **K47** olarak kaydedildi:

> *"Faz 4 salt okuma kalır; yayın ucu Faz 7'de. Şimdi yazılırsa paywall'sız bir
> 'bedava yayın' yolu açılır ve K42/K43 bozulur."*

Bugün doldurulabiliyor, çünkü kapıyı kilitleyecek anahtarlar **artık var**:

```
TierResolver (7.8)                  →  gereken plan
PublishEntitlementResolver (7.9)    →  sahip olunan plan
PaywallViolationException (7.5)     →  402 + requiredTier
```

**Ders:** bir dosyayı erken yazmak, onu yanlış yazmaktır. Boş iskelet üç faz
boyunca "burada bir şey olacak" diye durdu ve kimseyi yanıltmadı.

---

## 2. Beş katman, ucuzdan pahalıya (L1)

```
1. Sahiplik      → Gate::authorize('publish')   → yoksa 404  (controller)
2. Durum         → zaten yayında?               → 409
3. Gereken plan  → TierResolver                 → sunucuda hesaplanır
4. Sahip olunan  → PublishEntitlementResolver   → 402 (iki farklı kod)
5. Yayın         → status + published_at        → kilitli transaction
```

Sıra hem **maliyete** hem **gizliliğe** göre: sahiplik en başta, çünkü
başkasının davetiyesi hakkında hiçbir şey — plan bilgisi bile —
sızdırılmamalı. Yanıt 404'tür (**H7**): 403 kaynağın varlığını doğrulardı.

---

## 3. 🔴 Neden kilitleyip yeniden okuyoruz?

```php
$fresh = Invitation::query()
    ->whereKey($invitation->getKey())
    ->lockForUpdate()
    ->firstOrFail();
```

Elimizdeki `$invitation` **rota bağlamasından** geldi — yani bir süre önce
okundu. O okuma ile bu an arasında başka bir istek davetiyeyi yayınlamış
olabilir.

Kilitsiz:

```
istek A: oku → saved      istek B: oku → saved
istek A: yaz → published  istek B: yaz → published
```

**409 hiç fırlamaz**, `published_at` iki kez yazılır ve kullanıcı iki kez
ödediğini sanabilir. Klasik **check-then-act** yarışı (**E9**); Faz 5'in LCV
kotası ve Faz 6'nın medya kotası aynı deseni kullanıyordu.

> **Not:** kilitli yeniden okuma, ilişkileri (`timelineEvents`) beraberinde
> getirmez. Controller yanıtı üretmeden önce `->load('timelineEvents')`
> çağırır; katı kip aksi hâlde `LazyLoadingViolationException` fırlatır (Faz 3,
> 3.9).

---

## 4. 409: "zaten yayında" neden başarı sayılmıyor?

```php
if ($fresh->status === InvitationStatus::Published) {
    throw new InvitationAlreadyPublishedException;
}
```

Webhook'ta idempotans **istiyorduk** (7.11), burada **istemiyoruz**. Çelişki
değil — çağıran farklı:

| | Webhook | Publish |
|---|---|---|
| Tekrar eden | **Makine** (retry politikası) | **İnsan** (düğmeye ikinci kez bastı) |
| Niyeti | "Aynı sonucu teyit et" | "Yeni bir şey yap" |
| Doğru cevap | Sessizce aynı sonuç | Açıkça "zaten öyle" (409) |

Ders 42'nin bir örneği daha: *bir kuralı uygulamak, gerekçesini kontrol etmeden
kopyalamak değildir.*

---

## 5. 🔴 İki ayrı red, iki ayrı kod

```php
if ($owned === null) {
    throw PaywallViolationException::noPurchase($required);          // PAYMENT_REQUIRED
}

if (! $owned->covers($required)) {
    throw PaywallViolationException::insufficientTier($required, $owned);  // PAYWALL_TIER_INSUFFICIENT
}
```

İkisi de **402** döner ama kullanıcının önündeki eylem farklıdır:

- Hiç ödeme yok → plan kartlarını göster
- Plan yetmiyor → yükseltme akışını göster

`docs/08` §4: *durum kodu kaba sınıflandırma, `code` ince ayrım.* İkisini tek
koda indirseydik frontend hangi ekranı çizeceğini yanıtın **başka bir
yerinden** tahmin etmek zorunda kalırdı.

### `covers()` ne yapıyor?

```php
public function covers(self $required): bool
{
    return $this->rank() >= $required->rank();
}
```

Faz 0'da yazılmış, **yedi faz boyunca hiç çağrılmamıştı.** İlk çağrısı burada.
`SubscriptionTier` enum'unun tamamı bu an için yazılmıştı (`docs/09` §Faz 7:
*"Faz 0'da yazılan `SubscriptionTier` enum'u nihayet burada kullanılır"*).

---

## 6. `InvitationPolicy::publish()` — neden yalnızca sahiplik?

```php
public function publish(User $user, Invitation $invitation): bool
{
    return $this->owns($user, $invitation);
}
```

Plan yeterliliğini Policy'ye koymak cazipti. Reddedildi — **çünkü Policy'nin
cevabı bir `bool`dur** ve `bool` bilgi taşıyamaz:

| Katman | Sorusu | Reddin karşılığı | Taşıdığı bilgi |
|---|---|---|---|
| Policy | "Bu kayıt senin mi?" | **404** (H7) | Hiçbiri — kaynak gizlenir |
| Action | "Planın yetiyor mu?" | **402** | `requiredTier` |

Policy'ye konsaydı paywall reddi 404'e dönüşür, kullanıcı *"davetiyem
kayboldu"* derdi. Kural iki katmana **doğru yerlerinden** bölündü.

Aynı yetenek checkout ucunda da kullanılıyor: bir davetiye için plan satın
almak, yalnızca yayınlayabileceğin davetiye için anlamlıdır — ikinci bir
ability tanımlamak aynı kuralın ikinci kopyası olurdu (P1).

---

## 7. Cache temizliği neden burada yok?

```php
$fresh->save();     // ← tek satır, üç sonuç
```

`Invitation::$dispatchesEvents` (Faz 4, **K48**):

```php
'updated' => InvitationChanged::class,
```

`save()` → `updated` olayı → `InvitationChanged` → `ClearInvitationCache`.

Bu Action cache'i temizlemeyi **hatırlamak zorunda değil** — Faz 4 olayı
Action'lardan değil **modelden** fırlatmayı tam olarak bu yüzden seçmişti:

> *"Yeni bir yazma yolu eklendiğinde kimsenin hatırlaması gerekmesin."*

Bugün o "yeni yazma yolu" gerçekten eklendi ve karar karşılığını verdi. Faz
4'ün 41. dersi: *doğru katmanda alınmış bir karar umulmadık bir yerde ikinci
kez işe yarar.*

---

## 8. Planın YAPMADIKLARI (B6)

`docs/09` §Faz 7 akışında bir adım daha vardı:

```
4. public_slug üret, status = published, published_at = now()
```

🔴 **`public_slug` üretilmiyor** — çünkü **K40** onu geçersiz kıldı:
`invitations.id` zaten bir ULID'dir ve paylaşılan linkin kendisidir. Ayrı bir
slug ikinci bir kimlik olurdu.

Bunun bir yan sonucu: `ErrorCode::SlugTaken` (409) **hiçbir yerde
kullanılmıyor** ve katalogda duruyor. Silinmedi — bir kod adı yayınlandıktan
sonra sözleşmedir (`docs/08` §5.1) ve frontend'in çeviri anahtarı kırılır.

| Yapmaz | Nerede / Neden |
|---|---|
| Yayını geri almak (unpublish) | Bugün böyle bir uç yok — `INVITATION_LOCKED` (403) o gün kullanılacak |
| İade sonrası yayını düşürmek | `refunded` yeni yayın açmaz ama var olanı geri çekmez (7.9 §6) |
| Davetiyenin **eksiksiz** olduğunu doğrulamak | Şema tüm içerik alanlarını nullable bıraktı (Faz 3); "başlıksız davetiye yayınlanamaz" gibi bir kural **yok** — açık karar |
| K43 (plan kotası yayınlananı sayar) | Paket alımın **kaç yayın** açtığı sınırlanmıyor — 🔴 açık ticari karar, `FAZ-7.md` §9 |

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Kilitsiz okumak | İki eşzamanlı istek ikisi de yayınlar; 409 hiç fırlamaz |
| 2 | İstemciden gelen `tier`'a bakmak | Paywall DevTools'tan aşılır |
| 3 | Plan kontrolünü Policy'ye koymak | 402 yerine 404; kullanıcı ne yapacağını bilemez |
| 4 | "Zaten yayında"yı 200 saymak | Kullanıcı iki kez ödediğini sanır |
| 5 | İki paywall reddini tek koda indirmek | Frontend hangi ekranı çizeceğini bilemez |
| 6 | Action'da `Cache::forget()` çağırmak | K48'in kaldırdığı hatırlama yükü geri gelir + iki temizleme yolu (C3) |
| 7 | `$invitation` üzerinde çalışıp `$fresh`'i yok saymak | Kilit anlamsızlaşır; bayat durumla karar verilir |

---

## 10. Kendin dene

```php
// php artisan tinker
use App\Actions\Invitation\PublishInvitationAction;
use App\Models\{Invitation, Order};
use App\Enums\SubscriptionTier;

$inv = Invitation::factory()->create(['show_gallery' => true]);   // → elit gerekir
$action = app(PublishInvitationAction::class);

// 1. Hiç ödeme yok
$action->handle($inv);
// PaywallViolationException → PAYMENT_REQUIRED (402), params: requiredTier=elit

// 2. Yetersiz plan
Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($inv)->create();
$action->handle($inv);
// PaywallViolationException → PAYWALL_TIER_INSUFFICIENT (402)

// 3. Yeterli plan
Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($inv)->create();
$action->handle($inv)->status;          // InvitationStatus::Published

// 4. İkinci kez
$action->handle($inv->refresh());
// InvitationAlreadyPublishedException → 409
```

**Mutasyon denemesi (kural 14):** `if (! $owned->covers($required))` satırını
sil. `php artisan test --filter=PaywallTest` çalıştır.
`a_gold_order_cannot_publish_a_gallery_invitation` kırılmalı — kırılmıyorsa
paywall'ın testte karşılığı yoktur.

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Paywall** | Ücretli özelliklerin önündeki ödeme duvarı |
| **`lockForUpdate`** | Satırı transaction sonuna kadar kilitleyen okuma |
| **Check-then-act** | Önce oku sonra yaz — arada başkası girerse bozulan desen |
| **Ability** | Policy'de tanımlı tek bir yetki sorusu (`publish`, `update`) |
| **Alan olayı** | Modelden yapısal olarak fırlayan, iş anlamı taşıyan olay |

---

## 12. Sırada ne var?

**7.13 — `StoreCheckoutRequest` + `OrderResource`.** İsteğin doğrulanması
(hangi alanlar, hangi kurallar) ve yanıtın beyaz listesi — frontend'in
`CheckoutResult` tipiyle birebir.
