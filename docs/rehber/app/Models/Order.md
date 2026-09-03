# `app/Models/Order.php`

> **Kod dosyası:** `app/Models/Order.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.3
> **Birlikte değişenler:** `app/Models/User.php` (`orders()`),
> `app/Models/Invitation.php` (`orders()`)
> **Kılavuz kardeşleri:** [`../Enums/OrderStatus.md`](../Enums/OrderStatus.md) ·
> [`../../database/migrations/2026_09_03_100000_create_orders_table.md`](../../database/migrations/2026_09_03_100000_create_orders_table.md)

---

## 1. 🔴 Boş `#[Fillable]` — bu tablonun en doğru ifadesi

```php
#[Fillable([])]
class Order extends Model
```

Faz 6'da `Media` modeli de boş listeyle yazılmıştı. Buradaki gerekçe aynı ama
sonucu **çok daha ağır**:

| Alan | Kim belirler | İstemci belirlerse |
|---|---|---|
| `tier` | `StartCheckoutAction` (istekten okur ama **doğrular**) | — |
| `amount_minor` | Sunucudaki `config/davetkart.php` | **Kullanıcı fiyatını kendisi yazar** |
| `status` | Yalnızca sağlayıcının webhook'u | **`{"status":"paid"}` bedava yayın** |
| `paid_at` | Aynı geçişle birlikte | Sahte muhasebe |
| `provider_ref` | Sağlayıcı üretir | İdempotans anahtarı taklit edilir |

`$guarded = []` (`CLAUDE.md` §3'te zaten **yasak**) ya da geniş bir `$fillable`
burada bir **mass assignment** açığı olurdu ve açığın adı "ödeme" olurdu.

> **Mass assignment nedir?** `Model::create($request->all())` gibi bir çağrıda
> istekten gelen **her anahtarın** kolona yazılmasıdır. Saldırgan gövdeye
> beklenmeyen bir alan ekler (`status`), kod onu görmez, veritabanı yazar.
> Beyaz liste bunu yapısal olarak imkânsız kılar.

Boş liste, her alanın **açıkça** atanmasını zorunlu kılar (E7 ailesi):

```php
$order = new Order;
$order->tier = $tier;                 // açık
$order->amount_minor = $tier->price() * 100;
```

---

## 2. Cast'ler: sınırda tip belirsizliğini çözmek

```php
'user_id' => 'integer',
'tier'    => SubscriptionTier::class,
'status'  => OrderStatus::class,
'amount_minor' => 'integer',
'paid_at' => 'immutable_datetime',
```

### 2.1 `user_id => 'integer'` — Faz 3'ün 29. dersi

Eloquent birincil anahtarı **otomatik** cast eder — ama `getIncrementing()`
`true` dönen modellerde. `HasUlids` onu `false` yapar, dolayısıyla `Order` ve
`Invitation` bu otomatiği **almaz**. Katı karşılaştırma (`===`) yapan bir
yerde `'7' === 7` **false** döner ve bir yetki kontrolü sessizce herkesi
kilitler. Cast bu belirsizliği **sınırda** çözer (ders 30).

### 2.2 Enum cast'i ne yapar?

```php
$order->status;              // OrderStatus::Paid  (nesne, string değil)
$order->status->grantsPublishRight();
$order->status = OrderStatus::Paid;   // veritabanına 'paid' yazılır
```

Sihirli string tamamen ortadan kalkar (`CLAUDE.md` §1). Yazım hatası çalışma
anına kaçamaz.

> ⚠️ `phpstan.neon`'daki `parseModelCastsMethod: true` Faz 4'te açıldı; bu
> cast'lerin **okunuyor olması** ona bağlı (ders 35). Kapalı olsaydı Larastan
> `$order->status`'u `string` sanır ve `->grantsPublishRight()` çağrısını
> yakalayamazdı.

### 2.3 `amount_minor => 'integer'`

PostgreSQL sürücüsü `integer` kolonu duruma göre **string** döndürebilir. Para
üzerinde `===`, `+` veya `<` kullanan her yer buna güvenemez (**P4**).

---

## 3. `scopeGrantingPublishRight()` — kural sorguda değil enum'da

```php
Order::query()->grantingPublishRight()->exists();
```

**Query scope** nedir? Modele yazılan, sorguya eklenen adlandırılmış bir
filtredir. `scopeXxx` diye tanımlanır, `->xxx()` diye çağrılır.

Neden `where('status', 'paid')` değil?

```php
// ❌ Kural SQL'in içine gömülür — üç dosyada üç kopya
->where('status', 'paid')

// ✅ Kural enum'da; scope onu SQL'e çevirir
->grantingPublishRight()
```

Bugün tek durum hak veriyor. Yarın *"kısmi ödeme de yayınlatsın"* denirse
`OrderStatus::grantsPublishRight()` içinde **tek satır** değişir;
`OrderEntitlementResolver`, `SubscriptionRsvpQuotaResolver` ve `PaywallTest`
hiç değişmez. Faz 5'in K50'si (`RsvpStatus::consumesQuota()`) tam olarak bu
dersti.

`array_filter` + `array_column` zinciri listeyi enum'dan **türetir** — elle
yazsaydık K39'un yasakladığı ikinci doğruluk kaynağı doğardı.

---

## 4. `isExpired()` ve `null`'ın anlamı (N4)

```php
return $this->expires_at !== null && $this->expires_at->isPast();
```

`expires_at === null` **"süre sınırı yok"** demektir, "süresi dolmuş" değil.
Faz 5'in **N4** kuralı: `null` ile bir değer **farklı bilgilerdir**; `??` ile
birleştirmek onları sessizce eşitler.

> 🔴 Buradaki `isPast()` **doğru** — çünkü `expires_at` bir **timestamp**'tir.
> Faz 5'in **E8** kuralı (`isPast()` bir **date** kolonunda bir gün kaydırır)
> `rsvp_deadline` için geçerliydi; kuralı gerekçesini kontrol etmeden
> kopyalamak ders 42'nin tam olarak uyardığı hatadır.

---

## 5. İki ilişki, K42'nin iki kolu

```php
// User.php
public function orders(): HasMany            // hesabın tüm siparişleri

// Invitation.php
public function orders(): HasMany            // yalnızca TEKİL alımlar
```

🔴 `Invitation::orders()` **paket alımları görmez** — ve bu bir eksiklik değil,
bir sınırdır: bir Eloquent ilişkisi bir yabancı anahtarı izler, *"hesabın her
davetiyesi"* gibi bir **iş kuralını** izleyemez.

Bu yüzden hiçbir yerde şu yazılmaz:

```php
// ❌ Yayın hakkını buradan sormak, paket alımı görmezden gelmektir
$invitation->orders()->grantingPublishRight()->exists();
```

İki kaynağı tek cevaba indiren yer `PublishEntitlementResolver` arayüzüdür
(7.9) — K42'nin *"iki kaynaktan, tek arayüzden"* cümlesinin kod karşılığı.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `#[Fillable]`'a `status` veya `amount_minor` eklemek | Gövdeden gelen `status: paid` bedava yayın açar |
| 2 | `$guarded = []` yazmak | `CLAUDE.md` §3 ihlali; yukarıdakinin tamamı |
| 3 | Yayın hakkını `$invitation->orders()` üzerinden sormak | Paket alım görünmez, ödeyen kullanıcı 402 alır |
| 4 | `user_id` cast'ini silmek | `'7' === 7` false → yetki karşılaştırması herkesi kilitler |
| 5 | `expires_at` için `?->isPast() ?? true` yazmak | "Sınırsız" siparişler süresi dolmuş sayılır (N4) |
| 6 | Sorguya `where('status','paid')` yazmak | K50 ihlali; kural üç dosyaya dağılır |
| 7 | Fiyatı `$request` gövdesinden okumak | Kullanıcı 1 kuruşa Elit alır |

---

## 7. Kendin dene

```php
// php artisan tinker
use App\Models\Order;
use App\Enums\OrderStatus;

$order = Order::factory()->paid()->create();

$order->status;                       // OrderStatus::Paid  (enum nesnesi)
$order->status->grantsPublishRight(); // true
$order->tier->rsvpLimit();            // plana göre int|null
$order->amount_minor;                 // int (kuruş)
$order->isExpired();                  // false

Order::query()->grantingPublishRight()->count();   // 1

// 🔴 Toplu atama gerçekten kapalı mı? (E7 kanıtı)
$o = new Order(['status' => 'paid', 'amount_minor' => 1]);
$o->getAttributes();                  // [] — hiçbiri geçmedi
```

**Mutasyon denemesi (kural 14):** `#[Fillable([])]` yerine
`#[Fillable(['status'])]` yaz ve `PaywallTest`'i çalıştır. Eğer hiçbir test
kırılmıyorsa, "toplu atama kapalı" iddiasının **testte karşılığı yok**
demektir — B4'ün ta kendisi.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Mass assignment** | İstek gövdesindeki anahtarların doğrudan kolona yazılması |
| **Beyaz liste** | Varsayılan kapalı; yalnızca sayılanlar açık |
| **Cast** | Kolon değerinin PHP tipine (enum, tarih, int) çevrilmesi |
| **Query scope** | Modele yazılan, sorguya eklenen adlandırılmış filtre |
| **`HasUlids`** | Birincil anahtarı ULID olarak üreten Eloquent özelliği |
| **`immutable_datetime`** | `CarbonImmutable` cast'i — `->addDay()` orijinali bozmaz |

---

## 9. Sırada ne var?

**7.4 — `database/factories/OrderFactory.php`.** Test verisinin
deterministik üretimi: `paid()`, `pending()`, `forInvitation()` ve
`package()` durumları. Fabrika olmadan `PaywallTest` yazılamaz — ve
`orders_paid_at_check` kısıtı yüzünden `paid` durumu `paid_at` olmadan
üretilemez.
