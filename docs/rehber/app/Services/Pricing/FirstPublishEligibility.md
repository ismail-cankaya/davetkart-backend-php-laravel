# `app/Services/Pricing/FirstPublishEligibility.php` — "ilk yayın indirimine uygun mu?"

> **Faz:** 18.3 (`SEC-01`), adım 18.3.2 · **Kararlar:** K159 (uygunluk tek yerde), K131 (daraltıldı)
> **Soran kod:** `QuotePriceAction` (önizleme ve checkout'un ortak fiyat hesabı)
> **Test:** `tests/Feature/FirstPublishEligibilityTest.php` · `QuotePriceActionTest` · `FirstPublishCheckoutTest`

---

## 1. Neden ayrı bir sınıf?

"İlk yayın" indirimi (%60) tek kullanımlık bir haktır. Faz 13'te kural `QuotePriceAction`'ın
içinde özel bir metottu ve tek cümleydi: *"`paid_at` damgalı siparişin yoksa uygunsun"* (K131).
Bu cümle ödeme **bekleyen** siparişi saymıyordu; iki sekmede iki indirimli sipariş açılabiliyordu
(`SEC-01`).

Kural büyüyünce iki tehlike doğar:

1. **İki kopya.** Önizleme bir şey söyler, checkout başka bir şey keser. K128'in sözü
   ("ekrandaki sayı ile karttan çekilen sayı aynı hesaptan çıkar") bozulur.
2. **Kuralın bir Action'ın içinde kaybolması.** `QuotePriceAction` fiyat *hesaplar*; "kim uygun"
   ayrı bir sorudur (Single Responsibility).

Bu yüzden soru tek bir sınıfa taşındı. `TierResolver` ile aynı aile: **kendi veritabanımıza
bakan, sunucunun verdiği bir cevap.**

## 2. Kural

İndirim iki şekilde kapanır:

| # | Durum | Sorgudaki karşılığı | Karar |
|---|---|---|---|
| 1 | **Kullanılmış** | kullanıcının `paid_at` damgalı bir siparişi var | K131 (değişmedi): hediye siparişi ve iade edilmiş sipariş de sayılır |
| 2 | **Ayrılmış** | **başka bir hedefin** ödeme bekleyen, `first_publish` taşıyan siparişi var | K159 (yeni) |

İkisi de yoksa kullanıcı uygundur.

| Kullanıcının siparişleri | Hedef A için | Hedef B için |
|---|---|---|
| hiç yok | ✅ | ✅ |
| A için `pending` + indirimli (penceresi açık) | ✅ kendi rezervasyonu | ❌ hak A'da ayrılmış |
| A için `pending` + indirimli, **penceresi dolmuş** | ✅ | ✅ artık kimse tutmuyor |
| A için `expired` / `failed` + indirimli | ✅ | ✅ |
| A için `pending`, **indirimsiz** | ✅ | ✅ o satır hakkı tutmuyor |
| herhangi biri `paid` ya da `refunded` | ❌ | ❌ |

🔴 **Kendi hedefinin siparişi sayılmaz.** Kullanıcı ödeme sayfasından geri dönerse kendi ayırdığı
indirimi "kaybolmuş" görmemeli. K131'in ilk gerekçesi buydu; korunuyor.

## 3. "Hedef" nedir?

Bir checkout'un neyi satın aldığı: bir **davetiye** (`invitation_id` dolu) ya da **davetiyesiz
paket** (`invitation_id IS NULL`). Checkout'un iki ucuyla aynı ayrım (K42):

| Uç | Hedef | `$invitationId` |
|---|---|---|
| `POST /invitations/{id}/checkout` | o davetiye | davetiyenin ULID'i |
| `POST /payments/checkout` | davetiyesiz paket | `null` |

"Başka hedef" SQL'de bir tuzak taşır: `invitation_id != 'X'` koşulu `NULL` satırları
**eşleştirmez** (üç değerli mantık: `NULL != 'X'` bilinmezdir). Davetiyesiz sipariş de bir
"başka hedef"tir; `exceptTarget()` onu ayrıca sorar:

```php
$other->whereNull('invitation_id')->orWhere('invitation_id', '!=', $invitationId);
```

## 4. Tek sorgu

```sql
SELECT EXISTS (
  SELECT * FROM orders
  WHERE user_id = ?
    AND (
          paid_at IS NOT NULL                               -- 1. kullanılmış
       OR ( status = 'pending'                              -- 2. ayrılmış
            AND (expires_at IS NULL OR expires_at >= now)
            AND promotion = 'first_publish'
            AND <başka hedef> )
        )
)
```

İki ayrı `exists()` de yazılabilirdi. Tek sorgu, çünkü fiyat kataloğu her açılışta bunu sorar ve
`QuotePriceActionTest::three_tiers_cost_two_queries` sorgu sayısını kilitler (bir kampanya + bir
sipariş sorgusu). `(user_id, status)` index'i zaten var.

`awaitingPayment()` kapsamı `Order` modelinde: "bekliyor **ve** penceresi dolmamış". Durum tek
başına yetmez; penceresi dolmuş satır `orders:expire` uğrayana kadar `pending` görünür.

## 5. Bu sınıf neyi YAPMAZ? (B6)

| Yapmadığı | Kim yapar |
|---|---|
| Kilit almaz | `StartCheckoutAction` kullanıcı satırını kilitler, **sonra** buraya sorar. Kilitsiz iki istek aynı "uygun" cevabını okur |
| Satır yazmaz | Salt okunur; önizleme uçları da çağırır |
| İkinci satırı engellemez | Kısmi UNIQUE index (`orders_one_pending_first_publish_per_user`) |
| Ödeme anında yeniden bakmaz | `HandlePaymentCallbackAction` (son kapı) |
| Misafiri bilmez | `QuotePriceAction` misafire (`$user = null`) hiç sormaz |

Önizleme "uygun" deyip checkout "değil" diyebilir (arada başka bir sekme sipariş açmış olabilir).
Bu bir hata değil: **karar checkout'ta verilir**, önizleme o anki gözlemdir. Hediye kodu
önizlemesiyle aynı sözleşme.

## 6. Sık hatalar

| Hata | Sonuç |
|---|---|
| Kuralı `StartCheckoutAction`'a kopyalamak | Önizleme ile tahsilat ayrışır |
| `status = 'pending'` yazıp pencereyi unutmak | Terk edilmiş bir sipariş, `orders:expire` gelene kadar başka hedefin indirimini kapatır |
| `invitation_id != ?` ile yetinmek | Davetiyesiz siparişin tuttuğu hak görünmez; iki indirim |
| Kendi hedefini de saymak | Ödeme sayfasına dönen kullanıcı liste fiyatı görür |
| `paid_at` yerine `status = 'paid'` sormak | İade edilen siparişten sonra indirim geri gelir (K131 bunu bilerek kapatır) |

## 7. Kendin dene

```powershell
php artisan test --filter=FirstPublishEligibilityTest
```

```php
// php artisan tinker
use App\Models\{Invitation, Order, User};
use App\Services\Pricing\FirstPublishEligibility;
config(['davetkart.pricing.first_publish_percent' => 60]);

$inv = Invitation::factory()->create();
$u = User::find($inv->user_id);
$e = app(FirstPublishEligibility::class);

$e->isEligible($u, $inv->id);                       // true
Order::factory()->forInvitation($inv)->firstPublish()->create();
$e->isEligible($u, $inv->id);                       // true  (kendi rezervasyonu)
$e->isEligible($u, null);                           // false (paket: hak başka hedefte)
```

**Mutant:** `orWhere(` satırını `where(` yapıp gövdenin başına `return;` yaz ("açık indirimli
sipariş var mı" sorusu kalkar) → `FirstPublishCheckoutTest::the_first_publish_discount_is_granted_to_one_order_only`
ve dokuz test daha kırmızı.

## 8. Sözlük

| Terim | Anlamı |
|---|---|
| Uygunluk (eligibility) | Bir hakkın şu an bu kişiye verilip verilemeyeceği |
| Rezervasyon | Hakkı, kullanılmadan önce bir kayda (bekleyen siparişe) bağlamak |
| Hedef | Checkout'un satın aldığı şey: bir davetiye ya da davetiyesiz paket |
| Üç değerli mantık | SQL'de `NULL` ile karşılaştırmanın doğru/yanlış değil "bilinmez" vermesi |


## 🆕 Faz 19 (19.14 · K191, SEC-09) — doğrulanmamış hesaba indirim yok

`isEligible()`'ın ilk satırı: doğrulanmamışsa `false`. İndirim hesaba bağlıydı ve hesap uydurma bir adresle saniyeler içinde
açılabiliyordu: her davetiyede yeni hesap açan biri hiç liste fiyatı ödemezdi. Önizleme ve checkout aynı servisi okur (K163):
doğrulanmamışa liste fiyatı, doğrulamadan sonra indirimli fiyat. Test: `an_unverified_account_gets_no_first_publish_discount`.
