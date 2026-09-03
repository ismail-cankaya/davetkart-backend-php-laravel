# `database/factories/OrderFactory.php`

> **Kod dosyası:** `database/factories/OrderFactory.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.4
> **Kullanıcısı:** `tests/Feature/PaywallTest.php` (7.22)

---

## 1. Fabrika nedir, neden var?

Bir **model factory**, testin ihtiyaç duyduğu satırı tek satırda üretir:

```php
Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($inv)->create();
```

Bu olmasaydı her test on bir kolonu elle doldururdu; bir kolon eklendiğinde
otuz test birden kırılırdı. Fabrika, **test verisinin tek doğruluk
kaynağıdır**.

---

## 2. 🔴 Varsayılan neden `pending` ve `package`?

```php
'invitation_id' => null,
'status' => OrderStatus::default(),   // pending
```

Bir fabrikanın varsayılanı, testin **açıkça istemesi gereken şeyi bedava
vermemelidir.**

`Order::factory()->create()` yayın hakkı doğursaydı, paywall testleri
şöyle bir yanılgıya düşerdi: *"402 bekliyordum, 200 aldım — demek paywall
çalışmıyor"* ya da tersi, *"200 bekliyordum ve aldım"* — oysa hakkı veren şey
test edilen kod değil **fabrikanın varsayılanıydı**.

Faz 3'te `InvitationFactory` aynı kararı vermişti: bütün `show_*` bayrakları
`false`, çünkü *"modül açıklığı paywall'ın konusu, varsayılan olamaz."*

---

## 3. 🔴 Tutar neden `minorFor()` ile türetiliyor?

```php
private static function minorFor(SubscriptionTier $tier): int
{
    return $tier->price() * 100;
}
```

Fabrikaya `'amount_minor' => 24900` yazılabilirdi. Yazılmadı, çünkü o an
`config/davetkart.php`'deki fiyatla fabrikadaki sayı **iki ayrı doğruluk
kaynağı** olurdu. Fiyat 279 ₺'ye çıktığı gün üretim kodu 27900 yazar, testler
24900 bekler ve **testler yeşil kalırken üretim yanlış olur** — ya da tersi.

**B4**'ün fabrika hâli: *"dokümanda (burada: testte) verilen söz, kodda
karşılığı yoksa yalandır."*

---

## 4. `paid()` — bir kısıtın öğretmenliği

```php
public function paid(): static
{
    return $this->state(fn (array $attributes): array => [
        'status' => OrderStatus::Paid,
        'paid_at' => now(),          // 🔴 ikisi BİRLİKTE
    ]);
}
```

`paid_at` yazılmasaydı `orders_paid_at_check` kısıtı fabrikayı **patlatırdı**.
Bu bir engel değil, tasarımın kendini anlatmasıdır: `status='paid'` ve
`paid_at` **tek bir olguyu** anlatır, ikisi ayrı ayrı ayarlanacak bağımsız
alanlar değildir.

`InvitationFactory::published()` (status + `published_at`) aynı deseni Faz
3'te kurmuştu — orada kısıt yoktu, kural yalnızca fabrikada duruyordu. Faz 7
kuralı **şemaya taşıdı** (A8: bir değişmez doğrulama katmanına bırakılmaz).

---

## 5. `forInvitation()` neden `user_id`'yi de taşıyor?

```php
'invitation_id' => $invitation->id,
'user_id' => $invitation->user_id,      // ← birlikte
```

Üretim kodunda (`StartCheckoutAction`) bir kullanıcı **kendisine ait olmayan**
bir davetiye için sipariş açamaz — Policy engeller. Fabrika bunu üretebilseydi
testler **gerçekte var olmayan bir duruma** dayanırdı.

Faz 4'ün 40. dersi tersi yöndeydi (*"test edilebilirlik ile doğruluk
çatışırsa testi uyarla"*); burada çatışma **hiç doğmuyor**, çünkü fabrika
üretimin kurallarını taklit ediyor.

---

## 6. `provider_ref` — tek rastgele alan

```php
'provider_ref' => 'test_'.Str::ulid()->toBase32(),
```

Kolon **UNIQUE**. Sabit bir değer yazılsaydı ikinci `Order::factory()->create()`
`23505 unique violation` ile düşerdi ve hata mesajı testin niyetiyle hiç
ilgisiz olurdu.

Rastgelelik burada **davranışı etkilemiyor** — hiçbir test bu değerin ne
olduğuna bakmıyor; bakan testler kendi değerini açıkça verir:

```php
Order::factory()->create(['provider_ref' => 'known-ref']);
```

`'test_'` öneki bilinçli: üretim verisiyle karışan bir referans, bir gün
gerçek bir webhook'un test satırını bulmasına yol açabilirdi.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Varsayılanı `paid` yapmak | Paywall testleri boş yeşil yanar |
| 2 | `amount_minor`'ı elle yazmak | Fiyat değişince test ile üretim ayrışır (B4) |
| 3 | `paid()` içinde `paid_at` vermemek | `orders_paid_at_check` kısıtı patlar |
| 4 | `provider_ref`'i sabit vermek | İkinci satırda `23505` |
| 5 | `forInvitation()`'da `user_id`'yi bırakmak | Üretimde imkânsız bir durum test edilir |
| 6 | `definition()`'a `: array` dönüş tipi yazmak | Kovaryans bozulur, PHPStan kırılır (ders 19) |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Models\{Order, Invitation};
use App\Enums\{OrderStatus, SubscriptionTier};

$o = Order::factory()->create();
$o->status;                        // OrderStatus::Pending
$o->invitation_id;                 // null  (paket)
$o->amount_minor;                  // 24900 (config'ten türetildi)

$inv = Invitation::factory()->create();
$paid = Order::factory()->paid()->tier(SubscriptionTier::Elit)->forInvitation($inv)->create();
$paid->amount_minor;               // 54900
$paid->user_id === $inv->user_id;  // true

// 🔴 Kısıt gerçekten yerinde mi?
Order::factory()->create(['status' => OrderStatus::Paid]);  // QueryException: orders_paid_at_check
```

---

## 9. Sırada ne var?

**7.5 — `app/Services/Payment/PaymentGateway.php`.** Ödeme sağlayıcısını bir
arayüzün arkasına almak (K8, **Strategy Pattern**): Iyzico anlaşması
beklenmeden doğru akış kurulur ve testler ağa hiç çıkmaz.
