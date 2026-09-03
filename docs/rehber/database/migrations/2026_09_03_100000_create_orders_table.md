# `database/migrations/2026_09_03_100000_create_orders_table.php`

> **Kod dosyası:** `database/migrations/2026_09_03_100000_create_orders_table.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.2
> **Birlikte değişenler:** `app/Enums/OrderStatus.php` (`values()`,
> `paidValues()`), `app/Enums/SubscriptionTier.php` (`values()` eklendi)
> **Kaynağı:** `docs/09` §Faz 7 → *"7.2 `..._create_orders_table.php` 🔴
> `provider_ref` **UNIQUE**"*

---

## 1. Bu tablo hangi soruya cevap veriyor?

Tek soru: **"bu kullanıcı bu davetiyeyi yayınlayabilir mi?"**

Ticari model (`docs/09`): ücretsiz katman yok, yayınlamak için tek seferlik
plan satın alınır. Yani yayın hakkı bir **satın alma kaydından** doğar. Bu
tablo o kaydın kendisidir.

Beş kolon bu soruyu cevaplar:

| Kolon | Sorusu |
|---|---|
| `user_id` | Kim aldı? |
| `invitation_id` | Hangi davetiye için? (**NULL = paket**, K42) |
| `tier` | Hangi planı? |
| `status` | Ödeme gerçekten oldu mu? |
| `provider_ref` | Sağlayıcının o ödemeye verdiği kimlik (idempotans) |

Kalanlar (`amount_minor`, `currency`, `provider`, `paid_at`, `expires_at`)
**muhasebe ve göç** kayıtlarıdır: bugünün fiyatını, bugünün sağlayıcısını ve
bugünün para birimini geleceğe dondururlar.

---

## 2. 🔴 `invitation_id` neden nullable? (K42)

K42 kararı şöyle: *"Yayın hakkı **iki kaynaktan**, **tek arayüzden** sorulur."*

```
orders.invitation_id = '01j…'   →  TEKİL alım: yalnızca o davetiyeyi açar
orders.invitation_id = NULL     →  PAKET alım: hesabın tüm davetiyelerini açar
```

İki ayrı tablo (`orders` + `subscriptions`) kurmak da mümkündü. Kurmadık:

| | İki tablo | Tek tablo + nullable FK ✅ |
|---|---|---|
| Sorgu | İki `UNION` ya da iki ayrı sorgu | Tek sorgu, iki `WHERE` kolu |
| Muhasebe | Satış toplamı iki yerden | Tek yerden |
| Yeni satış tipi | Üçüncü tablo | Aynı tabloya bir kol |

Dağılmış `if` zincirini önleyen şey tablo sayısı değil, **arayüz**:
`PublishEntitlementResolver` (7.9). Çağıran kod hangi kolun cevap verdiğini
hiç bilmez.

### `nullOnDelete`, `cascadeOnDelete` değil — neden?

```php
$table->foreignUlid('invitation_id')->nullable()->constrained()->nullOnDelete();
```

Davetiye silinince **ödeme kaydı kaybolmaz**. Kullanıcı bir "sil" tıkıyla
muhasebe geçmişini yok edemez; sipariş, sahibi olmayan bir satış kaydı olarak
kalır. K60'ın (`rsvps` medya FK'leri `nullOnDelete`) aynı gerekçesi, daha ağır
sonuçlusu.

> ⚠️ Ama `user_id` **cascade**: hesap silindiğinde KVKK "unutulma hakkı"
> kişisel veriyi de götürür. İki FK, iki farklı hukuki gerekçe — kopyalanmadı,
> ayrı ayrı düşünüldü (ders 42: *bir kuralı uygulamak, gerekçesini kontrol
> etmeden kopyalamak değildir*).

---

## 3. 🔴 `provider_ref` UNIQUE — idempotansın veritabanı yarısı

Ödeme sağlayıcıları webhook'u **birden çok kez** gönderir. Sebep kötü niyet
değil, ağ: sağlayıcı `200` yanıtını göremezse "ulaşmadı" varsayar ve tekrarlar.

```php
$table->string('provider_ref', 191)->nullable()->unique();
```

### Neden `if` yetmez?

```php
// ❌ Yarış koşulu (check-then-act, E2)
if (! Order::where('provider_ref', $ref)->exists()) {
    Order::create([...]);        // iki webhook aynı anda buraya girer
}
```

İki eşzamanlı webhook `exists()`'i **ikisi de false** okur ve **iki satır**
oluşur. Veritabanı kısıtı bu yarışı bilmez — ikinci `INSERT` `23505 unique
violation` ile düşer. Faz 2'de `RegisterUserAction` aynı dersi e-posta
benzersizliğinde öğrenmişti.

### Nullable + UNIQUE aynı anda olur mu?

Olur. SQL standardı `NULL`'ları **birbirine eşit saymaz**, dolayısıyla UNIQUE
indeks birden çok `NULL` kabul eder. Bu bize şunu verir: sağlayıcı kimliği
henüz atanmamış siparişler birbirini engellemez.

### 🔴 Bu kısıt neyi KAPATMAZ (B6)

| Kapatır | Kapatmaz |
|---|---|
| Aynı ödeme için **ikinci satır** | Var olan satırın **iki kez güncellenmesi** |

İkincisini `OrderStatus::canTransitionTo()` + `lockForUpdate()` kapatır
(7.15). `docs/09`'un *"UNIQUE kısıtı idempotansın tek garantisi"* cümlesi
yarım doğrudur ve **B6** gereği eksiği burada yazılıdır.

---

## 4. 🔴 Para neden `amount_minor`?

```php
$table->unsignedInteger('amount_minor');   // 24900 = 249,00 ₺
$table->string('currency', 3);             // 'TRY'
```

**Kural: para asla kayan noktalı sayıyla saklanmaz.**

```php
0.1 + 0.2 === 0.3;   // PHP'de false
```

İkili kayan nokta `0.1`'i tam gösteremez. Tek satırda görünmez, bin satırlık
bir toplamda kuruşlar kaybolur ve mutabakat tutmaz. Çözüm evrenseldir: parayı
**en küçük birimde tam sayı** olarak sakla (kuruş, cent). Aritmetik tamdır;
sunum (`249,00 ₺`) frontend'in işidir — K20/K21'in para birimindeki hâli.

`decimal(10,2)` de doğru bir seçenek olurdu ama PHP tarafında yine string↔float
dönüşümü gerektirir; tam sayı bu sınıf hatayı **dilin tip sisteminde** kapatır.

### `currency` neden kolonda? (F4)

`config('davetkart.currency')` **"şu an neyle satıyoruz"** sorusunun cevabıdır.
Kolon **"o satış neyle yapılmıştı"** sorusunun. Bir gün EUR eklenirse config'ten
okuyan bir rapor tüm geçmişi yeni para biriminde gösterirdi. Aynı gerekçe
`media.disk` için K54/F4'te yazılmıştı; burada ikinci kez işe yarıyor.

`provider` kolonu da öyle: bugün `fake`, yarın `iyzico`. Eski satırların hangi
sağlayıcıyla ödendiği kolonda durur.

---

## 5. Üç CHECK kısıtı

| Kısıt | Ne söylüyor | Neden |
|---|---|---|
| `orders_tier_check` | `tier ∈ SubscriptionTier::values()` | K39 |
| `orders_status_check` | `status ∈ OrderStatus::values()` | K39 |
| `orders_amount_minor_check` | `amount_minor > 0` | 🔴 PostgreSQL'de UNSIGNED yok |
| `orders_paid_at_check` | Para alındıysa damga var, alınmadıysa yok | Çok kolonlu değişmez |

### 🔴 `unsignedInteger` PostgreSQL'de bir yalan

Faz 5'te `rsvps.guest_count`, Faz 6'da `media.size_bytes` ile öğrenildi:
PostgreSQL'de `UNSIGNED` diye bir tip yoktur. Laravel `unsignedInteger`'ı düz
`integer`'a düşürür ve kolon `-100` kabul eder. Koruma CHECK'ten gelir.

### `orders_paid_at_check` — bir değişmezi şemaya yazmak

```sql
CHECK ((status IN ('paid','refunded')) = (paid_at IS NOT NULL))
```

İki kolonu birbirine bağlar: *"parası alınmış bir sipariş zaman damgası taşımak
zorundadır ve alınmamış olan taşıyamaz."* Uygulama kodunda bir `if` olsaydı
konsoldan, seeder'dan veya bir kuyruk işinden atlanabilirdi — **A8**: bir
sınıfın (burada bir tablonun) değişmezi doğrulama katmanına bırakılmaz.

`refunded` listede, çünkü iade edilmiş sipariş bir zamanlar ödenmişti; damgası
silinmez. Liste elle yazılmaz, `OrderStatus::paidValues()`'tan türetilir (K39).

---

## 6. İki indeks, iki sorgu deseni

```php
$table->index(['user_id', 'status']);        // paket kolu: bu hesabın ödenmişleri
$table->index(['invitation_id', 'status']);  // tekil kol: bu davetiyenin ödenmişi
```

`PublishEntitlementResolver` tek sorguda iki kolu birden sorar; ikisi de
indeksli olduğu için sorgu tabloyu taramaz. `provider_ref` ayrıca UNIQUE
olduğu için **zaten indekslidir** — webhook'un `where('provider_ref', …)`
araması bedava gelir.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Parayı `decimal`/`float` yerine `float` kolonda tutmak | Toplamlar kuruş kaçırır, mutabakat tutmaz |
| 2 | `provider_ref`'i UNIQUE yapmamak | Webhook tekrarı ikinci sipariş satırı üretir |
| 3 | `invitation_id`'yi `cascadeOnDelete` yapmak | Davetiye silinince satış kaydı yok olur |
| 4 | `unsignedInteger`'ın PostgreSQL'de koruduğunu sanmak | `-100` tutarlı sipariş kabul edilir |
| 5 | CHECK listelerini elle yazmak | Enum değişince kısıt sessizce eskir (K39) |
| 6 | `currency`/`provider`'ı config'ten okumak | Geçmiş satışlar bugünün ayarıyla yeniden yorumlanır (F4) |
| 7 | UNIQUE kısıtı tam idempotans sanmak | Var olan satır iki kez güncellenebilir (§3) |

---

## 8. Kendin dene

```powershell
php artisan migrate
```

pgAdmin'de:

```sql
\d orders
-- id char(26) PK · user_id · invitation_id (nullable) · tier · status
-- amount_minor · currency · provider · provider_ref UNIQUE · paid_at · expires_at
-- CHECK orders_tier_check · orders_status_check
-- CHECK orders_amount_minor_check · orders_paid_at_check
```

Kısıtları elle sına:

```sql
-- 🔴 Hepsi HATA vermeli
INSERT INTO orders (id,user_id,tier,status,amount_minor,currency,provider,created_at,updated_at)
VALUES ('01test','1','platin','pending',24900,'TRY','fake',now(),now());   -- tier_check

INSERT INTO orders (id,user_id,tier,status,amount_minor,currency,provider,created_at,updated_at)
VALUES ('01test','1','gold','paid',39900,'TRY','fake',now(),now());        -- paid_at_check
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Minor unit** | Para biriminin en küçük altbirimi (kuruş, cent) |
| **İdempotans** | Aynı işlemi bir veya çok kez uygulamanın sonucu değiştirmemesi |
| **`nullOnDelete`** | Üst kayıt silinince FK kolonu `NULL` olur, satır kalır |
| **`cascadeOnDelete`** | Üst kayıt silinince alt satır da silinir |
| **CHECK kısıtı** | Satırın sağlaması gereken koşul; veritabanı zorlar |
| **Değişmez (invariant)** | Her zaman doğru kalması gereken kural |
| **23505** | PostgreSQL'in "unique violation" hata kodu |

---

## 10. Sırada ne var?

**7.3 — `app/Models/Order.php`.** `#[Fillable]` beyaz listesi (Media'daki gibi
neredeyse boş: bu tablodaki hiçbir alan istemcinin malı değil), `tier` ve
`status` cast'leri, `User`/`Invitation` ilişkileri ve para okumayı güvenli
kılan tek yardımcı.

| İlgili | Nerede |
|---|---|
| Durum enum'u | [`../../app/Enums/OrderStatus.md`](../../app/Enums/OrderStatus.md) |
| Plan enum'u | [`../../app/Enums/SubscriptionTier.md`](../../app/Enums/SubscriptionTier.md) |
| Aynı desenin Faz 6 hâli | [`2026_08_28_130000_create_media_table.md`](2026_08_28_130000_create_media_table.md) |
