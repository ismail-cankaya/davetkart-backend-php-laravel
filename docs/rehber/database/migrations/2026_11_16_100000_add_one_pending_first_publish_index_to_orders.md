# `2026_11_16_100000_add_one_pending_first_publish_index_to_orders` — tek kullanımlık hakkın kilidi

> **Faz:** 18.3 (`SEC-01`), adım 18.3.1 · **Kararlar:** K158 (değişmez), K162 (göç veri değiştirmez)
> **Yazan kod:** `StartCheckoutAction` · **Bağlı kılavuzlar:** [`StartCheckoutAction.md`](../../app/Actions/Payment/StartCheckoutAction.md), [`FirstPublishEligibility.md`](../../app/Services/Pricing/FirstPublishEligibility.md)
> **Test:** `tests/Feature/OrderFirstPublishIndexTest.php`

---

## 1. Hangi deliği kapatıyor?

"İlk yayın" indirimi (%60) bir kullanıcıya **bir kez** verilir. Eski kural yalnızca *ödenmiş* siparişe
bakıyordu: iki sekmede ödeme sayfası açan yeni kullanıcı iki `pending` sipariş alıyor, ikisi de
indirimli oluyordu (249,00 → 99,60 TL). İkisini de ödeyince iki hak da indirimli alınmış oluyordu.
On sekme, on indirimli hak (`AUD-2026-11-12`, sonda P13).

Bu bir **TOCTOU** hatasıdır (*time of check ≠ time of use*): "henüz ödeme yok" gözlemi ile ödeme
arasında durum değişir ve karar yeniden sınanmaz. Tek kullanımlık bir hakkı korumanın yolu onu
**ayırmaktır** (reserve): indirimi alan bekleyen sipariş, hakkı tutan kayıttır. Bu index o kaydın
kullanıcı başına **en fazla bir tane** olabileceğini veritabanına yazar.

## 2. Index

```sql
CREATE UNIQUE INDEX orders_one_pending_first_publish_per_user
    ON orders (user_id)
    WHERE status = 'pending' AND promotion = 'first_publish';
```

**Kısmi (partial) UNIQUE index:** benzersizlik yalnızca `WHERE` koşulunu sağlayan satırlar arasında
aranır. Koşulun dışındaki satırlar index'e hiç girmez.

| Satır | Index'e girer mi? | Neden |
|---|---|---|
| `pending` + `first_publish` | ✅ | Hakkı **şu an tutan** satır |
| `paid` / `refunded` + `first_publish` | ❌ | Hak kullanılmış; yeni siparişi K131 zaten indirimsiz açar |
| `expired` / `failed` + `first_publish` | ❌ | Hak serbest kaldı: terk edilen ödeme indirimi yakmaz |
| `pending`, indirimsiz ya da kampanyalı | ❌ | İlk yayın hakkını tutmuyor |
| `user_id IS NULL` (silinmiş hesap, K97) | girer ama çakışmaz | UNIQUE index `NULL`'ları birbirinden **farklı** sayar |

Değerler elle yazılmadı: `OrderStatus::Pending->value` ve `PromotionKind::FirstPublish->value`
(K39). Enum değeri değişirse göç de onunla değişir.

Aynı desen depoda bir kez daha var: `refund_requests_one_open_per_order` (Faz 14, "bir siparişte
en fazla bir açık iade talebi").

## 3. Neden üç katmandan biri, tek başına değil?

| Katman | Nerede | Neyi kapatır | Neyi kapatmaz |
|---|---|---|---|
| 1. Kilit | `StartCheckoutAction`: kullanıcı satırı `lockForUpdate()` | Aynı kullanıcının iki eşzamanlı checkout'u sıraya girer | Kilit bir gün silinirse hiçbir test bağırmaz (`TST-02`) |
| **2. Index** | **bu göç** | Kilit atlansa bile ikinci satır **yazılamaz** | Zamanı bilmez: penceresi dolmuş ama hâlâ `pending` duran satırı da sayar |
| 3. Son kapı | `HandlePaymentCallbackAction` | İki indirimli sipariş yine de ödenirse `Log::critical` | Engellemez; para alınmıştır |

Backend `CLAUDE.md` §3'ün dersi: *UNIQUE kısıt ikinci **satırı** engeller; uygulama kuralı hangi
satırın açılacağına karar verir.* Kısıt bir karar vermez, bir **imkânsızlık** tanımlar.

⚠️ **Index zamanı bilmez (B6).** `expires_at` koşula yazılamaz: index koşulu değişmez (immutable)
olmak zorundadır, `now()` değildir. Bu yüzden penceresi dolmuş ama `orders:expire`'ın henüz
uğramadığı bir satır yeni indirimli siparişi **bloklar**. Çözüm uygulamada: `StartCheckoutAction`
kullanıcının penceresi dolmuş satırlarını önce `expired` yapar (K161).

## 4. Göç neden durabilir? (K162)

Index kurulmadan önce çakışan satır aranır. Varsa göç **veri değiştirmeden** durur:

```
1 kullanicinin birden fazla bekleyen ilk yayin indirimli siparisi var (user_id: 7). Goc veri degistirmez; cozum: … §4
```

Neden kendisi düzeltmiyor? Hangi siparişin kapanacağı bir insan kararıdır: biri o an ödeniyor
olabilir. Canlıda müşteri verisi yok; bu ancak yerel deneme verisinde çıkar.

**Önce bak:**

```sql
SELECT user_id, id, tier, amount_minor, expires_at, created_at
FROM orders
WHERE status = 'pending' AND promotion = 'first_publish'
  AND user_id IN (
      SELECT user_id FROM orders
      WHERE status = 'pending' AND promotion = 'first_publish' AND user_id IS NOT NULL
      GROUP BY user_id HAVING COUNT(*) > 1
  )
ORDER BY user_id, created_at;
```

**Sonra kapat** (her kullanıcıda en yenisi kalır, eskiler `expired` olur; `expired → paid` geçişi
açık olduğu için geç gelen bir ödeme yine kabul edilir, K89):

```sql
UPDATE orders SET status = 'expired', updated_at = NOW()
WHERE status = 'pending' AND promotion = 'first_publish'
  AND id NOT IN (
      SELECT DISTINCT ON (user_id) id FROM orders
      WHERE status = 'pending' AND promotion = 'first_publish' AND user_id IS NOT NULL
      ORDER BY user_id, created_at DESC
  )
  AND user_id IS NOT NULL;
```

Penceresi zaten dolmuş satırlar için daha kısa yol: `php artisan orders:expire`, sonra yeniden
`php artisan migrate`.

## 5. Kilit ve süre

`CREATE INDEX` (eşzamansız değil) tabloya yazmayı index kurulana kadar bekletir. Sipariş tablosu
küçük; milisaniyeler sürer. Büyük bir tabloda `CREATE INDEX CONCURRENTLY` gerekirdi, ama o
transaction içinde çalışmaz ve Laravel PostgreSQL göçlerini transaction'da koşar; bugün gerek yok.

## 6. Sık hatalar

| Hata | Sonuç |
|---|---|
| Koşula `expires_at > now()` yazmaya çalışmak | PostgreSQL reddeder: index koşulu değişmez olmalı |
| Index'i `(user_id, invitation_id)` üstüne kurmak | Hedef başına bir indirim olur; açık geri gelir (iki davetiye, iki indirim) |
| "Kilit var, index gereksiz" demek | Kilit görünmez bir satırdır; silindiğini hiçbir test fark etmez (`TST-02`) |
| Göçe `UPDATE` ekleyip çakışmayı sessizce çözmek | Birinin açık ödemesi kapanır; K162 bunu yasaklar |
| Değerleri elle `'pending'` yazmak | Enum değişince index sessizce yanlış satırları sayar (K39) |

## 7. Kendin dene

```powershell
php artisan test --filter=OrderFirstPublishIndexTest
```

```php
// php artisan tinker   (yerel veritabanı; satırlar gerçek, sonunda sil)
use App\Models\{Order, User};
config(['davetkart.pricing.first_publish_percent' => 60]);

$u = User::factory()->create();
Order::factory()->for($u)->firstPublish()->create();   // tamam
Order::factory()->for($u)->firstPublish()->create();   // QueryException: orders_one_pending_first_publish_per_user

Order::where('user_id', $u->id)->delete(); $u->delete();
```

**Mutant:** göçte `'CREATE UNIQUE INDEX '` → `'CREATE INDEX '` yap (index var ama benzersiz değil) →
`OrderFirstPublishIndexTest::a_second_pending_first_publish_order_for_the_same_user_is_rejected_by_the_database`
kırmızı. Test veritabanı her koşuda `migrate:fresh` ile kurulduğu için değişiklik hemen etkir.

## 8. Sözlük

| Terim | Anlamı |
|---|---|
| TOCTOU | Kontrol anı ile kullanım anı arasında durumun değişmesi |
| Rezervasyon (reserve) | Tek kullanımlık hakkı, kullanılmadan önce bir kayda bağlamak |
| Kısmi index | Yalnızca `WHERE` koşulunu sağlayan satırları kapsayan index |
| Değişmez (invariant) | Sistemin hiçbir anda bozulmaması gereken kural |
