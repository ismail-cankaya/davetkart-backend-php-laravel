# `2026_10_23_100200_add_discount_and_gift_rules_to_orders.php` — indirim ve hediye kuralı

> **Faz:** 11, adım 11.2 · **Kararlar:** K107 (D1), K108 (D2)
> **Test:** `GiftCodeModelTest::the_database_rejects_a_gift_order_that_costs_money` ve `..._a_free_order_that_is_not_a_gift`

---

## 1. Ne değişiyor?

| Değişiklik | Önce | Sonra |
|---|---|---|
| `discount_minor` | yok | `integer default 0`, CHECK `>= 0` |
| `orders_amount_minor_check` | `amount_minor > 0` | `amount_minor >= 0` |
| `orders_gift_amount_check` | yok | `(provider = 'gift') = (amount_minor = 0)` |

## 2. 🔴 Hediye = sipariş (K107)

Kod planın tamamını karşılıyorsa sağlayıcıya gidilmez. `provider = 'gift'`, `status = 'paid'`,
`amount_minor = 0` olan normal bir sipariş satırı yazılır.

Neden ayrı bir "hak" tablosu değil? Yayın hakkını soran her yer (`OrderEntitlementResolver`,
`ClaimReleasedOrderAction`, paywall'ın 402 kontrolü, iade sonrası yayından kaldırma) zaten
`orders` tablosuna bakıyor. Ayrı bir kaynak yazılsaydı bu dört yerin her biri iki tabloya
bakmak zorunda kalırdı ve biri unutulduğunda hediye hakkı ya hiç işlemez ya da iade edilemezdi.
Bu yolla hiçbirine dokunulmadı: `PaywallTest` değişmeden yeşil.

## 3. 🔴 Kural `if` ile değil kısıtla (E2)

Eski kısıt `amount_minor > 0` idi. "Bedava plan yok" demekti. Artık bedava sipariş var ama
**yalnızca** hediye siparişi için. Kural iki yönlü:

```sql
CHECK ((provider = 'gift') = (amount_minor = 0))
```

| provider | amount_minor | Sonuç |
|---|---|---|
| `gift` | 0 | ✅ hediye siparişi |
| `fake` / `iyzico` | 24900 | ✅ normal sipariş |
| `gift` | 100 | ❌ "hediye ama para alıyor": kodda bir hata var |
| `fake` | 0 | ❌ "sağlayıcıya 0 TL'lik ödeme": paywall delindi |

İkinci ❌ satırı en önemlisi: yarın biri `amount_minor` hesabında bir hata yaparsa (örneğin
indirimi iki kez düşerse) sağlayıcıya 0 liralık bir ödeme gitmez, veritabanı satırı reddeder.

> Prompt bunu iki ayrı kısıt olarak yazmıştı: `(amount_minor > 0) OR (provider = 'gift')` ve
> `(provider <> 'gift') OR (amount_minor = 0)`. İkisinin birleşimi tam olarak bu eşitliktir.
> Tek kısıt seçildi çünkü `orders_paid_at_check` aynı kalıbı kullanıyor. İki kolon da
> NOT NULL olduğu için eşitlik hiçbir satırda NULL üretmez (üç değerli mantık tuzağı yok).

## 4. Neden `'gift'` sabite (`Order::GIFT_PROVIDER`) bağlanmadı?

Bir migration koştuğu günün dünyasını anlatır. Sabite bağlansaydı sabit bir gün değiştiğinde
eski migration sessizce farklı bir kısıt kurardı. Ayrıca sabit 11.4'te (modeller) ekleniyor;
11.2'deki migration ona bağlansaydı o commit tek başına çalışmazdı. Değer migration'ın kendi
`private const`'unda duruyor ve modeldeki sabitle aynı olduğu
`GiftCodeModelTest::the_database_accepts_a_paid_zero_amount_gift_order` ile sınanıyor.

## 5. `discount_minor` (K108)

Kısmi indirimde sipariş normal ödeme yolundan gider. `amount_minor` **ödenecek** tutardır
(fiyat − indirim), `discount_minor` düşülen tutardır. Faz 12'de Shopier imzalı tutarı doğrudan
`amount_minor`'dan alacak; indirim sağlayıcıya şeffaf kalır.

Fiyat = `amount_minor + discount_minor`. Fiyat ayrı bir kolonda saklanmadı: iki değerden
türetilebiliyor (E1).

## 6. 🔴 Geri alma (`down()`)

0 tutarlı bir hediye siparişi varsa eski `amount_minor > 0` kısıtı kurulamaz. `ADD CONSTRAINT`
bütün tabloyu doğrular. Satırları silmek ödeme kaydını yok etmek olurdu. Bu yüzden `down()`
**durur ve hata verir**:

```
RuntimeException: 3 adet 0 tutarli (hediye) siparis var; geri alma bu kayitlari silmeyi gerektirir. Elle karar verilmeli.
```

`2026_10_01_100000_make_orders_user_id_nullable` ile aynı refleks. Karar bir insana kalır.

> ⚠️ **Canlıda** hediye kodu bir kez kullanıldıktan sonra bu migration'ı geri almak mümkün
> değil. Geri dönüş gerekiyorsa önce bu siparişlerin ne olacağına karar verilmeli.

## 7. Deneme

```powershell
php artisan migrate
php artisan tinker
>>> App\Models\Order::factory()->create(['provider' => 'fake', 'amount_minor' => 0]);
# QueryException: violates check constraint "orders_gift_amount_check"
```
