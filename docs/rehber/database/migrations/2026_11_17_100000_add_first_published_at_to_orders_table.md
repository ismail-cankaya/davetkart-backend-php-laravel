# `2026_11_17_100000_add_first_published_at_to_orders_table` — "bu hak bir davetiyeyi yayınladı" damgası

> **Faz:** 18.4 (`SEC-02`) · **Karar:** K164 (damga), K169 (geriye dönük doldurma)
> **Yazan:** [`StampFirstPublishAction`](../../app/Actions/Payment/StampFirstPublishAction.md) (tek yazar) ·
> **Okuyan:** [`RefundEligibility`](../../app/Services/Refund/RefundEligibility.md), `refunds:list`
> **Test:** `tests/Feature/OrderFirstPublishedAtTest.php`

---

## 1. Hangi deliği kapatıyor?

Bir siparişin hakkı dört yerde durabilir. Göçten önce ilk ikisi **aynı görünüyordu**:

| Durum | Göçten önceki temsil | İade edilebilir mi? |
|---|---|---|
| Hiç kullanılmadı | `invitation_id = NULL`, `consumed_at = NULL` | evet (K139) |
| Bir davetiyeyi yayınladı, 3 gün içinde silindi, **serbest kaldı** (K82) | `invitation_id = NULL`, `consumed_at = NULL` | ❌ olmamalı; **oluyordu** |
| Bir davetiyede | `invitation_id = <davetiye>` | hayır |
| Yandı (pencere kapandıktan sonra silindi, K137) | `consumed_at` dolu | hayır |

Senaryo (denetim sondası P47): kullanıcı Standart alır, yayınlar, bağlantıyı dağıtır, misafirler
LCV bırakır; üç gün dolmadan davetiyeyi siler. Sipariş serbest kalır ve "hiç kullanılmamış" hakla
aynı biçime girer. İade talebi 201 ile açılır; yönetici `refunds:list`'te bu hakkın bir davetiyeyi
yayınladığını gösteren hiçbir şey görmez.

İki kural ayrı ayrı doğruydu. Hata **birleşim yerindeydi**: K82 "kullanıldı" bilgisini siliyor,
K139 o bilgiye güveniyordu.

## 2. Neden bir damga?

"Kullanıldı" bir **olaydır**; `invitation_id` ise hakkın **şu an nerede durduğunu** söyler. Olay bir
kez olur ve geri alınmaz; konum değişir. Bir olayı konumdan türetmek, konum değiştiği gün yanlış
cevap verir. Faz 14 aynı dersi `consumed_at` için almıştı (K137: "bir bilgi, kopabilecek bir bağın
içinde saklanmaz"); o damga yalnızca pencere kapandıktan sonra basılıyordu. Bu göç aynı deseni bir
adım erkene, **yayın anına** taşır.

| Seçenek | Neden olmadı |
|---|---|
| Serbest bırakmayı (K82) kaldırmak | Yanlışlıkla yayınlayan kullanıcı hakkını kaybederdi; K82 doğru bir kural |
| Serbest bırakırken siparişi "iade edilemez" diye işaretlemek | Bilgi yine çıkışta türetilir; yayınlanıp yayından kaldırılan davetiyede (`published_at = NULL`) kaybolur |
| `published_count` sayacı | Sayının kendisi hiçbir kuralda kullanılmıyor; "ne zaman" bilgisi yöneticiye daha yararlı |
| **`first_published_at` damgası** | Olay anında yazılır, silinmez, yöneticiye tarih gösterir ✅ |

## 3. Kolon ve kısıt

```php
$table->timestamp('first_published_at')->nullable();
```

```sql
CHECK (first_published_at IS NULL OR paid_at IS NOT NULL)
```

- **Parası alınmamış hak yayın yapmış olamaz.** `StampFirstPublishAction` zaten yalnızca ödenmiş
  siparişi damgalar; kısıt, o süzgeç bir gün silinirse veritabanında durdurur (`consumed_at` ile aynı
  desen).
- **Toplu atamaya kapalı:** `Order`'ın `#[Fillable]` listesi boş. Damgayı istek gövdesi yazamaz.
- ⚠️ **Plandan sapma: `timestampTz` değil `timestamp`.** Şemadaki bütün zaman kolonları tz'sizdir
  (`paid_at`, `consumed_at`, `published_at`) ve pgsql bağlantısında `timezone` ayarı yok. Tek bir
  tz'li kolon, oturumun saat dilimi UTC olmayan bir sunucuda (bu Windows makinesi gibi) diğer
  kolonlarla karşılaştırılırken üç saat kayardı; aşağıdaki `GREATEST(published_at, paid_at)` tam
  olarak böyle bir karşılaştırma. Uygulama saat dilimi UTC; kolonlar UTC duvar saatini taşır.

## 4. Geriye dönük doldurma (K169)

Göç, kolonu ekledikten sonra üç kuralla doldurur. Hepsi `first_published_at IS NULL` satırlara
bakar; göç yeniden koşsa da var olan damgayı ezmez.

| # | Kimi | Hangi zamanla | Güven |
|---|---|---|---|
| 1 | **Yayınlanmış** bir davetiyeye bağlı (çöptekiler dahil), parası alınmış sipariş | `GREATEST(davetiye.published_at, sipariş.paid_at)` | kesin |
| 2 | Yanmış hak (`consumed_at` dolu), 1'in bulamadığı | `consumed_at` | kullanıldığı kesin; an yaklaşık |
| 3 | **Serbest kalmış** hak: aynı kullanıcının yayınlanmışken silinmiş (çöpteki) davetiyesi var **ve** siparişin son güncellenmesi o silmeyle aynı an (±2 sn) | `GREATEST(davetiye.published_at, sipariş.paid_at)` | sezgisel |

**Neden `GREATEST`?** Yükseltme siparişi yayından **sonra** ödenmiş olabilir (Standart'la yayınla,
ertesi gün Gold al). O sipariş yayına ödemeyle hizmet etmeye başladı; "yayın: 10 Kasım, ödeme:
11 Kasım" diyen bir satır yöneticiyi şaşırtırdı.

**2. kuralda neden `consumed_at`?** Yanmış hakkın davetiyesi kalıcı silinmiş olabilir; yayın anı
artık hiçbir yerde yok. Bilinen tek kesin şey, hakkın en geç o anda kullanılmış olduğu.

**3. kural nasıl çalışıyor?** `DeleteInvitationAction` bağı koparırken (`orders.updated_at`) ve
davetiyeyi silerken (`invitations.deleted_at`) aynı transaction'da iki kez `now()` yazar. İkisi
arasında milisaniyeler var. Çöp kutusundaki davetiye 30 gün durduğu için bu iz o süre boyunca
okunabilir. Üç ek koşul yanlış eşleşmeyi daraltır: davetiye **yayınlanmış** olmalı, sipariş
silmeden **önce** ödenmiş olmalı, sipariş hâlâ bağsız ve yanmamış olmalı.

⚠️ **Sınırlar (bilerek kabul edildi):**

- Çöp kutusu boşaldıysa (30 gün, `data:purge`) iz yoktur; o siparişler damgasız kalır ve iade
  edilebilir görünür.
- Sipariş serbest kaldıktan sonra başka bir nedenle güncellendiyse (`updated_at` değişti) iz kaybolur.
- Sezgi yanlış eşleşirse (aynı kullanıcının bambaşka bir siparişi, yayınlanmış bir davetiyenin
  silindiği saniyede güncellendiyse) hiç kullanılmamış bir hak damgalanır ve iadesi reddedilir.
- **Canlıda müşteri verisi yok**; bu kurallar yalnızca yerel deneme verisinde satır bulur. 17 Kasım
  2026'da yerel `davetkart` veritabanında (salt okunur sayım): 24 siparişten 5'i 1. kurala, 1'i
  3. kurala uyuyor; 2. kural 0.

Yanlış damgayı düzeltmek bir insan kararıdır (uygulamada bunu yapan bir yol **bilerek** yok):

```sql
-- Önce bak: kim, hangi zamanla damgalandı?
SELECT id, user_id, tier, paid_at, first_published_at, invitation_id, consumed_at
FROM orders WHERE first_published_at IS NOT NULL ORDER BY first_published_at DESC;

-- Emin olduğun TEK satır için:
UPDATE orders SET first_published_at = NULL WHERE id = '<sipariş kimliği>';
```

## 5. Geri alma

`down()` kısıtı ve kolonu düşürür. Damgalar geri gelmez: yeniden `up()` yalnızca §4'ün
bulabildiklerini doldurur. Yayındaki bir sistemde `down()` çalıştırmak `SEC-02`'yi geri açar.

## 6. Sık hatalar

| Hata | Sonuç |
|---|---|
| Damgayı serbest bırakmada sıfırlamak ("hak yeniden kullanılabilir, temiz olsun") | `SEC-02` geri gelir. Test: `releasing_a_right_keeps_its_first_published_at` |
| İade kuralında damga yerine `invitation_id`'ye bakmak | Aynı delik: bağın **yokluğu** kanıt değildir |
| Damgayı yayın isteğinin gövdesinden ya da bir Resource'tan yazmak | Toplu atama kapalı; `MassAssignmentException` |
| Kolonu `timestampTz` yapmak | §3: diğer kolonlarla karşılaştırma saat dilimine göre kayar |
| Doldurma kurallarını "nasılsa veri yok" diye silmek | Açık bir iade talebi olan eski bir sipariş (P47'nin bıraktığı gibi) onaylanabilir kalır |

## 7. Testler (`OrderFirstPublishedAtTest`)

Doldurma testleri göçü geri alır (`down()`), kolon yokken **eski biçimde** satır yazar ve göçü
yeniden koşar. Kuralı testin kendisi değil göç uygulamalı; PostgreSQL'de DDL de transaction
içinde olduğu için `RefreshDatabase` her şeyi geri sarar.

| Test | Kanıtladığı | Öldürdüğü mutant |
|---|---|---|
| `a_new_order_has_never_published` · `the_stamp_is_read_as_an_immutable_date` | Varsayılan boş; cast `CarbonImmutable`; `hasPublished()` | — |
| `the_stamp_cannot_be_mass_assigned` | 🔴 Damgayı `fill()` yazamaz | `#[Fillable]`'a kolonu eklemek |
| `the_database_refuses_a_stamp_without_payment` | CHECK kısıtı | Kısıtı `CHECK (true)` yapmak |
| `the_backfill_stamps_a_paid_order_attached_to_a_published_invitation` | Kural 1; ödenmemiş sipariş atlanır | — |
| `the_backfill_uses_the_payment_time_for_an_order_paid_after_the_publish` | `GREATEST` | `GREATEST` → `published_at` |
| `the_backfill_skips_an_order_attached_to_a_draft` | Hiç yayınlanmamış davetiye sayılmaz | — |
| `the_backfill_stamps_a_consumed_order_with_its_consumption_time` | Kural 2 | `backfillConsumed()` çağrısını silmek |
| `the_backfill_stamps_a_released_order_traced_to_a_trashed_published_invitation` | 🔴 Kural 3: `SEC-02`'nin bıraktığı iz | `backfillReleased()` çağrısını silmek |
| `the_backfill_leaves_an_unused_order_of_the_same_user_alone` | Kural 3 kullanılmamış hakkı damgalamaz (K139) | Zaman çakışması, `paid_at <= deleted_at` ya da `published_at IS NOT NULL` koşulunu silmek (üç ayrı mutant) |
| `the_migration_can_be_rolled_back_and_run_again` | `down()` + `up()` | — |

## 8. Kendin dene

```powershell
php artisan test --filter=OrderFirstPublishedAtTest

# Göç yerelde ne dolduracak? (çalıştırmadan önce, salt okunur)
php artisan tinker
> DB::table('orders')->join('invitations','invitations.id','=','orders.invitation_id')->whereNotNull('invitations.published_at')->whereNotNull('orders.paid_at')->count();

php artisan migrate
> App\Models\Order::query()->whereNotNull('first_published_at')->get(['id','tier','paid_at','first_published_at','invitation_id']);
```

Mutant: göçte `$this->backfillReleased();` satırını sil → `OrderFirstPublishedAtTest` kırmızı. Geri al.
