# `app/Services/Pricing/TierResolver.php`

> **Kod dosyası:** `app/Services/Pricing/TierResolver.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.8
> **İkizi:** `davetkart-frontent/src/stores/useSubscriptionStore.ts` →
> `getRequiredTier()`
> **Kararlar:** **K6** (`show_*` ayrı kolon) · `CLAUDE.md` §3 (paywall
> sunucuda yeniden hesaplanır)

---

## 1. Tek soru

> **"Bu davetiyeyi yayınlamak için en az hangi plan gerekir?"**

Cevap davetiyenin **açık modüllerinden** çıkar:

```
show_gallery veya show_gift  →  elit
show_envelope veya show_timeline → gold
diğer her şey               →  standart
```

Bu harita `config/davetkart.php` → `module_tiers`'ta durur, kodda değil:

```php
'module_tiers' => [
    'show_gallery' => 'elit',
    'show_gift' => 'elit',
    'show_envelope' => 'gold',
    'show_timeline' => 'gold',
    'show_timer' => 'standart',
    'show_rsvp' => 'standart',
],
```

**E6**: *"kısıt yalnızca backend'in sahibi olduğu kurala konur."* Hangi modülün
hangi plana ait olduğu bir **fiyatlandırma kararıdır**; pazarlama yarın "galeri
Gold'a insin" derse bu bir **deploy** değil bir **config** değişikliği olmalı.

---

## 2. 🔴 Frontend'de aynı fonksiyon var — bu bir tekrar mı?

`useSubscriptionStore.ts`:

```ts
export function getRequiredTier(invitation: Invitation): SubscriptionTier {
  if (invitation.showGallery || invitation.showGift) return 'elit';
  if (invitation.showEnvelope || invitation.showTimeline) return 'gold';
  return 'standart';
}
```

**Hayır.** İki kopya aynı kuralı değil, **iki farklı sorumluluğu** taşır:

| | Frontend | Backend |
|---|---|---|
| Ne için | **Arayüz kararı** — hangi kart vurgulansın, modal ne zaman açılsın | **Güvenlik kararı** — yayın gerçekten açılır mı |
| Güvenilir mi | ❌ DevTools'tan değiştirilebilir | ✅ Tek yetkili |
| Silinirse | Kullanıcı deneyimi bozulur | **Paywall tamamen düşer** |

`CLAUDE.md` §3 bunu emrediyor: *"Sınır ve yetki kısıtlamaları kesinlikle
Frontend'den gelen isteklere güvenilerek yapılamaz."*

### "Tek doğruluk kaynağı" ilkesi burada neden geçerli değil?

Geçerli — ama neyin doğruluk kaynağı olduğuna dikkat: **kural** tek yerde
değil, **karar** tek yerde. İstemciden gelen bir hesabın sonucuna güvenmek,
hesabı hiç yapmamakla aynıdır.

> Sözleşme paylaşımıyla bu tekrarı kaldırmak (ortak bir kural paketi)
> denenebilirdi. Denenmedi: K31 aynı soruyu hata kodlarında sormuş ve
> *"paylaşılan tip paketi iki repoyu bağımlı kılar"* diyerek tek yönlü üretimi
> seçmişti. Aynı gerekçe burada da geçerli.

---

## 3. K6'nın bedeli burada ödendi

Faz 3'te bir karar verilmişti (K6, hibrit veri modeli):

> `show_*` bayrakları JSON içinde değil **ayrı kolon** olacak — paywall'ı SQL
> ile doğrulamak için.

`docs/09` §Faz 7'nin uyarısı da aynıydı: *"Faz 3'te `show_*` alanları boolean
kolon olarak açılmazsa, Faz 7'de paywall'ı SQL ile doğrulamak
imkânsızlaşır."*

O gün ödenen bedel (JSON'un esnekliğinden vazgeçmek) bugün karşılığını
veriyor: `$invitation->getAttribute('show_gallery')` bir kolon okumasıdır ve
gerektiğinde `WHERE show_gallery = true` diye **sorguya da** dönüşebilir.
JSON'da olsaydı her davetiyeyi PHP'ye çekip açmak gerekirdi.

**Ders:** doğru katmanda alınmış bir karar umulmadık bir yerde ikinci kez işe
yarar (Faz 4, ders 41).

---

## 4. `rank()` ile karşılaştırma — neden sıralı sayı?

```php
if ($tier->rank() > $required->rank()) {
    $required = $tier;
}
```

`SubscriptionTier::rank()` Faz 0'da yazılmıştı: `standart=0 < gold=1 < elit=2`.
Enum'lar doğal olarak sıralanmaz; "gold, standart'tan büyüktür" bilgisi bir
yerde **açıkça** durmak zorunda.

Frontend'de `TIER_RANK` sabiti aynı işi yapıyor — iki tarafın da sıralamayı
bilmesi gerekiyor çünkü ikisi de "kapsıyor mu?" sorusunu soruyor.

Döngü basit bir **maksimum bulma**dır: açık modüllerin gerektirdiği planların
en yükseği. Erken çıkış (`elit` bulununca `break`) yazılmadı — altı elemanlı
bir dizide okunabilirlikten daha değerli bir şey kazandırmaz.

---

## 5. 🔴 İki gürültülü hata — sessizlik neden kabul edilemez?

### 5.1 Bilinmeyen kolon adı

```php
$invitation->getAttribute($column) !== true
```

Katı kip (`Model::shouldBeStrict()`, Faz 0) yerelde
`MissingAttributeException` fırlatır. Config'e `show_musik` gibi yanlış bir ad
yazılırsa **gürültülü** patlar.

Alternatif (`?? false`) sessizce "bu modül kapalı" derdi — yani **yazım hatası
olan bir modül paywall'dan muaf olurdu.** Bir güvenlik kuralında sessiz
varsayılan, kuralın yokluğudur.

### 5.2 Bilinmeyen plan adı

```php
if ($tier === null) {
    throw new RuntimeException("… is not a valid tier.");
}
```

`'platinum'` yazılsaydı `tryFrom` `null` dönerdi ve modül **hiçbir plan
gerektirmez** sayılırdı — Elit modülü bedavaya açılırdı.

Aynı refleks Faz 5'te `TierRsvpQuotaResolver`'da yazılmıştı: *"kota
okunamıyorsa kotasız devam etmek, ödemeli bir sınırın sessizce kalkması
demektir."*

### `!== true` neden `!` değil?

`show_*` kolonları `boolean` cast'li, yani `true`/`false` gelir. Ama
`getAttribute()` imzası `mixed` döndürür; `!$value` yazsaydık `0`, `''`,
`'0'` de kapalı sayılırdı — **P4**: güvenlik karşılaştırmasında iki tarafın
tipi garanti olmalı.

---

## 6. Bu sınıfın YAPMADIKLARI (B6)

| Yapmaz | Nerede yapılır |
|---|---|
| Kullanıcının **hangi planı aldığını** bilmek | `PublishEntitlementResolver` (7.9) |
| Yayınlamak | `PublishInvitationAction` (7.16) |
| Fiyat hesaplamak | `SubscriptionTier::price()` |
| Sipariş oluşturmak | `StartCheckoutAction` (7.14) |

Bu sınıf yalnızca **gereken**i söyler; **sahip olunanla** karşılaştırmak başka
birinin işi. İki soruyu tek sınıfa koymak, ikisini de yeniden kullanılamaz
hâle getirirdi (S harfi).

---

## 7. Neden arayüz yok?

`RsvpQuotaResolver` ve `PaymentGateway` birer arayüzün arkasında; bu sınıf
değil. Fark:

| | Arayüz gerekli mi | Neden |
|---|---|---|
| `PaymentGateway` | ✅ | Uygulaması **değişecek** (fake → iyzico) |
| `RsvpQuotaResolver` | ✅ | Kaynağı **değişecekti** (config → sipariş) |
| `TierResolver` | ❌ | Tek bir doğru cevabı var; ikinci bir uygulama **düşünülemiyor** |

Arayüz bir **maliyettir** (fazladan dosya, dolaylılık). Yalnızca değişeceğini
bildiğin yere konur. K15'in *"soyutlama bütçesi"* ilkesi: bütçe gerçekten
değişecek yerlere harcanır.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | İstemciden gelen `tier`'a güvenmek | Paywall DevTools'tan aşılır (`CLAUDE.md` §3) |
| 2 | Haritayı koda gömmek | Fiyatlandırma değişikliği deploy ister (E6) |
| 3 | Bilinmeyen kolonu `?? false` saymak | Yazım hatalı modül paywall'dan muaf olur |
| 4 | Bilinmeyen planı yok saymak | Elit modülü bedava açılır |
| 5 | `!$value` yazmak | `0`/`''` kapalı sayılır (P4) |
| 6 | Bu sınıfa "kullanıcı ne aldı" sorusunu eklemek | İki sorumluluk, ikisi de yeniden kullanılamaz |
| 7 | Sırf simetri için arayüz eklemek | Soyutlama bütçesi boşa harcanır (K15) |

---

## 9. Kendin dene

```php
// php artisan tinker
use App\Models\Invitation;
use App\Services\Pricing\TierResolver;

$r = app(TierResolver::class);

$inv = Invitation::factory()->create();
$r->requiredFor($inv)->value;                                   // 'standart'

$inv = Invitation::factory()->create(['show_timeline' => true]);
$r->requiredFor($inv)->value;                                   // 'gold'

$inv = Invitation::factory()->create(['show_gallery' => true, 'show_timeline' => true]);
$r->requiredFor($inv)->value;                                   // 'elit'  (en yükseği kazanır)

// 🔴 Yapılandırma hatası gürültülü mü?
config(['davetkart.module_tiers.show_gift' => 'platinum']);
$r->requiredFor($inv);   // RuntimeException: … is not a valid tier.
```

**Mutasyon denemesi (kural 14):** `if ($tier->rank() > $required->rank())`
satırındaki `>` yerine `<` yaz. `php artisan test --filter=PaywallTest`
çalıştır. `a_gallery_invitation_requires_the_elit_tier` kırılmalı.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Paywall** | Ücretli özelliklerin önündeki ödeme duvarı |
| **Sunucu ikizi** | İstemcideki bir hesabın sunucuda yeniden yapılan, yetkili sürümü |
| **`rank`** | Planların karşılaştırılabilmesi için verilen sıralı sayı |
| **Katı kip** | `Model::shouldBeStrict()` — eksik alan/lazy loading hata verir |
| **Soyutlama bütçesi** | Dolaylılığın maliyeti; yalnızca değişecek yerlere harcanır |

---

## 11. Sırada ne var?

**7.9 — `app/Contracts/PublishEntitlementResolver.php`** ve uygulaması. K42'nin
tam karşılığı: yayın hakkı **iki kaynaktan** (tekil alım + paket alım) ama
**tek arayüzden** sorulur.
