# `app/Actions/Payment/StampFirstPublishAction.php`

> **Faz:** 18.4 (`SEC-02`) · **Kararlar:** K164 (damga), K165 (yan kapı)
> **Çağıran:** [`PublishInvitationAction`](../Invitation/PublishInvitationAction.md) (yayın anı) ·
> [`DeleteInvitationAction`](../Invitation/DeleteInvitationAction.md) (hak davetiyeden ayrılmadan önce)
> **Okuyan:** [`RefundEligibility`](../../Services/Refund/RefundEligibility.md) · `refunds:list`
> **Test:** `tests/Feature/OrderFirstPublishStampTest.php`

---

## 1. Ne yapar?

Yayınlanmış bir davetiyeye bağlı, **parası alınmış** ve **henüz damgasız** her siparişe
`first_published_at` yazar. Başka hiçbir şey yapmaz: bağı değiştirmez, durumu değiştirmez.

```php
$stamped = $this->stamps->handle($invitation);   // damgalanan sipariş sayısı
```

Davetiye hiç yayınlanmadıysa (`published_at === null`) hiçbir şey basmaz ve 0 döner.

## 2. Neden var? (`SEC-02`)

Üç günlük pencerede silinen davetiyenin siparişi serbest kalır (K82). Serbest kalan sipariş "hiç
kullanılmamış" hakla aynı görünüyordu ve iade edilebiliyordu: hizmet kullanılıp parası geri
alınabiliyordu. "Kullanıldı" bilgisinin bağdan bağımsız bir yere yazılması gerekiyordu; o yer bu
damga, onu yazan tek yer bu sınıf. Kolonun ve kuralın tam hikâyesi:
[göç kılavuzu](../../../database/migrations/2026_11_17_100000_add_first_published_at_to_orders_table.md).

## 3. Tasarım: neden ayrı bir Action?

`PublishInvitationAction`, K42 gereği `orders` tablosunu **bilmez**: yalnızca "sahip olunan en yüksek
plan nedir?" diye sorar (`PublishEntitlementResolver`). Serbest hakkı bağlama işi bu yüzden
`ClaimReleasedOrderAction`'a ayrılmıştı. Damga da aynı ailedendir: bir siparişe yazılan bir yan etki.
Yayın Action'ının içine `Order::query()` yazmak o soyutlamayı delerdi.

İkinci gerekçe: **iki çağıran var** (§5). Kural iki yerde yazılsaydı biri diğerinden ayrışırdı (C3).

## 4. Üç kural

### 4.1 Yalnızca boşsa (`neverPublished()`)

"**İlk** yayın" bir olaydır. Serbest kalan hak ikinci bir davetiyeyi yayınladığında damga oynamaz;
yönetici listede ilk kullanımın tarihini görür. Süzgeç silinirse her yayın tarihi ezer:
`a_second_publish_does_not_move_the_stamp` kırmızıya döner.

### 4.2 Davetiyeye bağlı ödenmiş HER sipariş (`grantingPublishRight()`)

Bir davetiyede birden fazla ödenmiş sipariş olabilir (Standart alındı, sonra Gold'a yükseltildi).
Hangisinin "yayını açtığını" seçmeye çalışmıyoruz; hepsi o yayına hizmet ediyor:

- Silme akışı ikisini de **birlikte** serbest bırakır ya da **birlikte** yakar (`consumed_at`, K137).
- İade kuralı bağlıyken ikisini de reddeder (`in_use`).

Damga aynı kümeyi kullanır; "kullanıldı"nın üç kanıtı da aynı siparişler üzerinde konuşur.
Ödenmemiş (`pending`) sipariş hak vermez, damgalanmaz; CHECK kısıtı da reddederdi.

### 4.3 Zaman: yayın anı ya da ödeme anı, hangisi sonraysa

Yükseltme siparişi yayından **sonra** ödenmiş olabilir. O sipariş için hizmet ödemeyle başladı;
damga `paid_at` olur. Göçteki `GREATEST(published_at, paid_at)` ile aynı kural.

## 5. 🔴 İki çağıran ve yan kapı (K165)

| Çağıran | Ne zaman | Neden |
|---|---|---|
| `PublishInvitationAction` | Yayın yazıldıktan hemen sonra, **aynı transaction'da** | Olay anı. Yayın geri alınırsa damga da geri alınır |
| `DeleteInvitationAction` | Bağ koparılmadan / hak yakılmadan **hemen önce** | Yayın Action'ından hiç geçmemiş siparişler |

İkincisi denetim planında yoktu. Checkout, **yayındaki** bir davetiye için de sipariş açar
(yükseltme: yayındaki davetiyeye galeri eklemek isteyen kullanıcı Gold alır). O sipariş ödendiği an
canlı bir davetiyeye hizmet eder, ama `PublishInvitationAction` çalışmaz. Davetiye üç gün içinde
silinince damgasız serbest kalır ve iade edilirdi: `SEC-02`'nin aynısı, yan kapıdan.

Kapı çıkışta kapatıldı: hak bir davetiyeden **ayrılırken** davetiye yayınlanmışsa damgasız ayrılamaz.

**Neden ödeme bildiriminde (`HandlePaymentCallbackAction`) basmadık?** Orada basmak, "davetiye
yayında mı?" sorusunu yarışsız sormak için davetiye satırını kilitlemeyi gerektirir. Bildirim zaten
sipariş satırını kilitlemiş olur; silme akışı ise önce davetiyeyi sonra siparişi kilitler. Ters
sırada iki kilit, para yolunda bir **kilitlenme (deadlock)** riskidir: kaybeden taraf ödeme
bildirimi olursa "ödendi" bilgisi düşer. Silme akışı davetiye satırını zaten kilitlemiş durumda;
aynı kontrol orada bedavaya ve yarışsız yapılır.

Sınırı: yayındaki davetiyeye ödenen sipariş, davetiye silinene kadar damgasız durur. Bu sürede bağlı
olduğu için iade zaten reddedilir (`in_use`) ve `refunds:list` onu "davetiyeye bağlı" gösterir.

## 6. 🔴 Çağıranın sorumluluğu

Transaction içinde ve davetiye satırı kilitliyken çağrılır. İki çağıran da böyle. Kilit, aynı
davetiyenin yayını ile silinmesini sıraya sokar; "yalnızca boşsa" kontrolü bu yüzden yarışsızdır.

## 7. Bu Action'ın YAPMADIKLARI

- **Damgayı silmez.** Hiçbir yol silmez; yanlış damga elle düzeltilir (göç kılavuzu §4).
- **Yayın hakkını denetlemez.** O `PublishInvitationAction`'ın işi; buraya gelindiğinde yayın yazılmıştır.
- **İadeyi reddetmez.** Yalnızca kanıtı yazar; kuralı `RefundEligibility` uygular.

## 8. Sık hatalar

| Hata | Sonuç |
|---|---|
| Serbest bırakmada damgayı sıfırlamak | `SEC-02` geri gelir |
| Damgayı yalnızca "yayını açan" siparişe basmak | Aynı davetiyedeki ikinci ödenmiş sipariş iade edilir |
| Çağrıyı yayın transaction'ının dışına taşımak | Yayın yazılır, damga yazılamazsa hak kullanılmış ama iade edilebilir kalır |
| `DeleteInvitationAction`'daki çağrıyı "yayında zaten basıldı" diye silmek | Yükseltme siparişinin yan kapısı açılır |
| `neverPublished()`'i serbest hak sorgusuna eklemek | Serbest kalan hak yeniden kullanılamaz: K82 bozulur |

## 9. Testler (`OrderFirstPublishStampTest`)

Testler yayın ve silme **uçlarını** çağırır, sonra siparişin kolonuna bakar (T14: etkiye bak).

| Test | Kanıtladığı | Öldürdüğü mutant |
|---|---|---|
| `the_first_publish_stamps_the_order` | Damga = davetiyenin `published_at`'i | Yayındaki çağrıyı silmek |
| `a_package_claimed_by_the_publish_is_stamped` | Yayın anında bağlanan paket de damgalanır | aynı |
| `releasing_a_right_keeps_its_first_published_at` | 🔴 Bağ kopar, damga kalır (K82 + K164) | Serbest bırakmada damgayı sıfırlamak |
| `a_second_publish_does_not_move_the_stamp` | "İlk" yayın: ikinci davetiyede tarih oynamaz | `neverPublished()`'i silmek |
| `every_paid_order_of_the_invitation_is_stamped_and_unpaid_ones_are_not` | Ödenmiş hepsi, ödenmemiş hiçbiri | `grantingPublishRight()`'ı silmek |
| `a_refused_publish_stamps_nothing` | 402 alan yayın hiçbir hakkı kullanmış saymaz | — |
| `an_upgrade_paid_after_the_publish_is_stamped_when_the_right_is_released` | 🔴 Yan kapı; damga = ödeme anı | Silmedeki çağrıyı silmek · ödeme anı yerine yayın anı |
| `an_upgrade_is_stamped_when_the_right_burns` | Pencere kapalıyken de damgasız kalmaz | Silmedeki çağrıyı silmek |
| `deleting_a_draft_stamps_nothing` | Hiç yayınlanmamış davetiyenin hakkı temiz kalır (K139) | — |
| `a_pending_order_is_released_without_a_stamp` | Ödenmemiş sipariş serbest kalırken damgalanmaz | `grantingPublishRight()`'ı silmek |

## 10. Kendin dene

```powershell
php artisan test --filter=OrderFirstPublishStampTest
```

```php
// php artisan tinker — yayınlanmış bir davetiyenin siparişleri
$i = App\Models\Invitation::query()->whereNotNull('published_at')->first();
$i->orders()->get(['id', 'tier', 'status', 'paid_at', 'first_published_at']);
```

Mutant: `DeleteInvitationAction`'da `update(['invitation_id' => null])` satırına
`'first_published_at' => null` ekle → `releasing_a_right_keeps_its_first_published_at` kırmızı. Geri al.

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Damga (stamp)** | Bir olayın gerçekleştiğini ve ne zaman gerçekleştiğini kaydeden, sonradan silinmeyen kolon |
| **Serbest bırakma** | K82: 3 gün içinde silinen davetiyenin siparişinin bağının koparılması |
| **Yükseltme siparişi** | Zaten bir siparişi olan davetiyeye daha yüksek plan için açılan ikinci sipariş |
| **Yan kapı** | Bir kuralı, onu uygulayan koddan geçmeden aynı sonuca ulaştıran ikinci yol |
