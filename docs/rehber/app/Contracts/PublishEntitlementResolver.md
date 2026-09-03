# `app/Contracts/PublishEntitlementResolver.php`

> **Kod dosyaları:** `app/Contracts/PublishEntitlementResolver.php` (arayüz) ·
> `app/Services/Pricing/OrderEntitlementResolver.php` (uygulama) ·
> `app/Providers/AppServiceProvider.php` (bağlama)
> **Faz:** 7 — Ödeme ve paywall, dosya 7.9
> **Karar:** **K42** — *"Yayın hakkı iki kaynaktan, tek arayüzden sorulur"*
> **Kardeşi:** [`../Services/Pricing/TierResolver.md`](../Services/Pricing/TierResolver.md)
> — o **gerekeni**, bu **sahip olunanı** söyler

---

## 1. İki soru, iki sınıf

Yayın kararı iki bağımsız bilginin karşılaştırmasıdır:

```
TierResolver::requiredFor($inv)        →  gereken plan   (davetiyeden)
EntitlementResolver::highestTierFor()  →  sahip olunan   (siparişlerden)

           sahipOlunan?->covers(gereken) ?  yayınla  :  402
```

İkisini tek sınıfa koymak, ikisini de yeniden kullanılamaz hâle getirirdi:
LCV kotası (7.20) yalnızca **ikincisine** ihtiyaç duyuyor, "hangi plan
gerekiyor" sorusuna değil.

---

## 2. 🔴 K42: iki kaynak, tek arayüz

`orders` tablosu iki satış tipini birlikte taşır (7.2):

```
invitation_id = '01j…'   →  TEKİL alım: yalnızca o davetiyeyi açar
invitation_id = NULL     →  PAKET alım: hesabın tüm davetiyelerini açar
```

Arayüz olmasaydı bu iki kol, soruyu soran **her yere** kopyalanırdı:

- `PublishInvitationAction`
- `SubscriptionRsvpQuotaResolver`
- ileride bir "siparişlerim" ekranı

Üçünden birinde paket kolu unutulsaydı, **ödeme yapmış bir kullanıcı 402
alırdı** — ve hata yalnızca paket alan kullanıcılarda görüneceği için "bazen
oluyor" diye raporlanırdı. Hata ayıklaması en zor hata sınıfı budur.

Faz 3'ün **P1** kuralı (*"sahiplik kuralı tek yerde"*) ile aynı fikir, ticari
eksende.

---

## 3. Dönüş tipi neden `?SubscriptionTier`, `bool` değil?

```php
public function highestTierFor(Invitation $invitation): ?SubscriptionTier;
```

`canPublish(): bool` daha basit görünürdü. Reddedildi:

| | `bool` | `?SubscriptionTier` ✅ |
|---|---|---|
| Yayın kararı | ✅ | ✅ |
| **LCV kotası** ("planın limiti ne?") | ❌ aynı kaynağı ikinci kez sorgular | ✅ |
| Hata mesajı ("planın X, gereken Y") | ❌ | ✅ |

**C3**: aynı bilgiyi üreten iki yol olmamalı. Planı döndürmek iki tüketiciye
birden hizmet eder — biri *"kapsıyor mu"* diye sorar, diğeri *"kotası ne"*.

### 🔴 `null` neden `'standart'` değil?

Ders 45 (Faz 5): *"bir değerin yokluğunu, o değerin uzayındaki bir değerle
temsil etme."*

- `null` → **hiç ödeme yok** → `PAYMENT_REQUIRED`
- `Standart` → **en ucuzunu almış** → belki yeterli, belki değil

Bedava katman **yok** (`docs/09`). `'standart'` döndürseydik ödeme yapmamış
herkes en az Standart plana sahip sayılır ve **paywall tamamen düşerdi.**
`RsvpQuotaResolver`'ın `?int` kararı (`null` = sınırsız) ile aynı tip tasarımı
dersi, ters yönde.

---

## 4. Uygulama: tek sorgu, üç koşul

```php
Order::query()
    ->grantingPublishRight()                       // 1. ödenmiş mi
    ->where('user_id', $invitation->user_id)       // 2. bu kullanıcının mı
    ->where(function (Builder $query) use ($invitation): void {
        $query->whereNull('invitation_id')         // 3a. paket
            ->orWhere('invitation_id', $invitation->getKey());   // 3b. tekil
    })
    ->get();
```

Üç koşul da **sorgunun kapsamındadır** — Faz 3'ün **P3** kuralı: *"koleksiyon
uçlarında sahiplik Policy ile değil sorgu ile korunur."* PHP'de filtrelenseydi
yetkisiz satırlar en azından belleğe gelir ve bir `dd()` sırasında sızabilirdi.

### 4.1 🔴 İkinci koşul bir tekrar değil

*"Tekil sipariş zaten davetiyeye bağlı, `user_id` kontrolü fazlalık"* — yanlış.

**Paket siparişin davetiyeyle hiçbir bağlantısı yoktur.** Onu bu davetiyeye
bağlayan tek şey `user_id`'dir. Kaldırılsaydı:

```
Ayşe paket alır  →  Mehmet'in davetiyesi de yayınlanabilir
```

IDOR'un ödeme katmanındaki hâli.

### 4.2 🔴 İç içe closure — operatör önceliği bir güvenlik meselesi

Parantezsiz yazılsaydı üretilen SQL şu olurdu:

```sql
WHERE status IN (...) AND user_id = ? AND invitation_id IS NULL OR invitation_id = ?
```

SQL'de `AND`, `OR`'dan **önce** bağlar. Yani ifade şuna eşdeğer:

```sql
WHERE (status IN (...) AND user_id = ? AND invitation_id IS NULL)
   OR (invitation_id = ?)                    -- 🔴 tek başına eşleşir!
```

Son kol tek başına eşleştiği için **ödenmemiş** ve **başkasına ait** bir
sipariş bile bu davetiyeyi açardı. Closure, Laravel'in ürettiği SQL'e parantez
koyar:

```sql
AND (invitation_id IS NULL OR invitation_id = ?)
```

> **Ders:** operatör önceliği okunabilirlik meselesi sanılır; bir yetki
> sorgusunda **bir güvenlik açığıdır.**

### 4.3 En yükseği PHP'de bulmak

```php
foreach ($orders as $order) {
    if ($highest === null || $order->tier->rank() > $highest->rank()) {
        $highest = $order->tier;
    }
}
```

Neden SQL'de `ORDER BY` değil? `rank` bir **kolon değil**, `config/davetkart.php`
içinde bir iş kuralı (Faz 0). SQL'e taşımak ya kolon eklemeyi ya da sorguya
gömülü bir `CASE WHEN tier='elit' THEN 2 …` yazmayı gerektirirdi — ikincisi
kuralın **ikinci bir kopyası** olurdu (K39'un yasakladığı şey).

Bir kullanıcının bir davetiye için ödenmiş sipariş sayısı tek haneli; PHP'de
gezmek ölçülebilir bir maliyet değil.

---

## 5. Bağlama neden `bind`, `singleton` değil?

```php
$this->app->bind(PublishEntitlementResolver::class, OrderEntitlementResolver::class);
```

Sınıf **durumsuz** — paylaşılacak bir hâli yok. Üstelik `singleton` olsaydı
istek içinde bayat veri tutma riski doğardı: bir ödeme kaydedildikten sonra
aynı istekte sorulan hak, eski cevabı verebilirdi.

`RsvpQuotaResolver.md` §7'nin 5. maddesi bu hatayı Faz 5'te zaten yazmıştı.

---

## 6. Bu arayüzün YAPMADIKLARI (B6)

| Yapmaz | Nerede |
|---|---|
| Gereken planı hesaplamak | `TierResolver` (7.8) |
| Yayınlamak / durum değiştirmek | `PublishInvitationAction` (7.16) |
| Süresi dolmuş siparişleri temizlemek | **Hiçbir yerde** — `expires_at` yazılıyor ama bir temizlik işi yok (Faz 9 borcu) |
| İadeyi geriye işlemek | `OrderStatus::Refunded` hak vermez; ama **var olan** bir yayını geri almaz (§7) |

### 🔴 İadenin kapatmadığı şey

`refunded` bir sipariş `grantsPublishRight()` döndürmez, yani **yeni** bir
yayın açmaz. Ama **zaten yayınlanmış** bir davetiyeyi geri çekmez —
`invitations.status` bağımsız bir kolondur. Bugün iade akışı yok; olduğu gün
bu kararın açıkça verilmesi gerekecek. **B6**: bir savunmanın neyi kapatmadığı
da yazılır.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Yayın hakkını `$invitation->orders()` ile sormak | Paket alım görünmez; ödeyen kullanıcı 402 alır |
| 2 | `OR` kolunu parantezsiz yazmak | Başkasının ödenmemiş siparişi yayın açar (§4.2) |
| 3 | `user_id` koşulunu kaldırmak | Başkasının paketi bu davetiyeyi açar |
| 4 | `null` yerine `'standart'` döndürmek | Paywall tamamen düşer |
| 5 | `bool` döndürmek | Kota sorusu aynı kaynağı ikinci kez sorgular (C3) |
| 6 | `singleton` bağlamak | İstek içinde bayat hak bilgisi |
| 7 | `rank` sıralamasını SQL'e gömmek | Kuralın ikinci kopyası doğar (K39) |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Contracts\PublishEntitlementResolver;
use App\Models\{Invitation, Order};
use App\Enums\SubscriptionTier;

$r = app(PublishEntitlementResolver::class);
$inv = Invitation::factory()->create();

$r->highestTierFor($inv);                                       // null — ödeme yok

// Tekil alım
Order::factory()->paid()->tier(SubscriptionTier::Gold)->forInvitation($inv)->create();
$r->highestTierFor($inv)->value;                                // 'gold'

// Paket alım daha yüksek → o kazanır
Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()
    ->create(['user_id' => $inv->user_id]);
$r->highestTierFor($inv)->value;                                // 'elit'

// 🔴 Başkasının paketi görünmüyor mu?
Order::factory()->paid()->tier(SubscriptionTier::Elit)->package()->create();  // başka user
$r->highestTierFor(Invitation::factory()->create())            // null
```

**Mutasyon denemesi (kural 14):** `->where('user_id', $invitation->user_id)`
satırını sil. `php artisan test --filter=PaywallTest` çalıştır.
`another_users_package_does_not_grant_publish_rights` kırılmalı.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Entitlement** | Bir kullanıcının hak ettiği/satın aldığı yetki |
| **Query scope** | Modele yazılan, sorguya eklenen adlandırılmış filtre |
| **Operatör önceliği** | `AND`'in `OR`'dan önce bağlaması |
| **IDOR** | Başkasının kaynağına kimlik değiştirerek erişme açığı |
| **Durumsuz (stateless)** | Örnekler arası paylaşılan hâli olmayan sınıf |

---

## 10. Sırada ne var?

**7.10 — `app/Actions/Payment/StartCheckoutAction.php`.** Siparişin doğduğu
yer: fiyat **sunucudan** okunur, plan yeterliliği **sunucuda** doğrulanır ve
sağlayıcı oturumu ancak satır yazıldıktan sonra açılır.
