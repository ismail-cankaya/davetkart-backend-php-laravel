# `app/Actions/Payment/StartCheckoutAction.php`

> **Kod dosyaları:** `app/Actions/Payment/StartCheckoutAction.php` ·
> `app/Actions/Payment/CheckoutResult.php`
> **Faz:** 7 — Ödeme ve paywall, dosya 7.10
> **Bağımlılıkları:** [`PaymentGateway`](../../Services/Payment/PaymentGateway.md) ·
> [`TierResolver`](../../Services/Pricing/TierResolver.md)

---

## 1. Bu Action'ın tehdit modeli farklı

Faz 5 ve 6'da savunma **bota** karşıydı (honeypot, hız sınırı, MIME
doğrulaması). Burada saldırgan **giriş yapmış meşru bir kullanıcıdır** ve
elindeki silah tarayıcının geliştirici araçlarıdır.

```
1. Yetki      → rota + Gate::authorize('publish')      (controller)
2. Biçim      → StoreCheckoutRequest ('in:' plan listesi)
3. Yeterlilik → seçilen plan davetiyenin modüllerini kapsıyor mu
4. 🔴 FİYAT   → gövdeden DEĞİL, sunucudaki config'ten
5. Telafi     → sağlayıcı patlarsa sipariş 'failed' (F3)
```

---

## 2. 🔴 4. katman — fazın en kısa ve en pahalı satırı

```php
$order->amount_minor = $tier->price() * 100;
```

Fiyat istekten okunsaydı:

```json
POST /api/payments/checkout
{ "tier": "elit", "price": 1 }
```

Elit plan **1 kuruşa** satılırdı. Ve dikkat: hiçbir doğrulama kuralı bunu
yakalayamaz, çünkü gönderilen değer **biçimsel olarak geçerlidir** —
`integer`, `min:1`, hepsini geçer.

Bu, `CLAUDE.md` §3'ün *"paywall sunucuda yeniden hesaplanır"* kuralının en
somut hâli. Doğrulama katmanı **biçimi** doğrular; **değeri** kim üretecek
sorusu bir mimari karardır.

> Aynı refleks Faz 3'te de vardı: `status` ve `user_id`, `Invitation`'ın
> `#[Fillable]` listesinde bilerek yok. Bir alanın "sunucunun malı" olması, o
> alanın istekten hiç okunmaması demektir.

---

## 3. 3. katman — yeterlilik neden burada **ve** yayında sınanıyor?

```php
if (! $tier->covers($required)) {
    throw PaywallViolationException::insufficientTier($required, $tier);
}
```

Aynı kontrol `PublishInvitationAction`'da (7.12) tekrar yapılıyor. Tekrar mı?
**Hayır — iki farklı ana bakıyorlar:**

| An | Soru |
|---|---|
| Checkout | "Şu an bu davetiye için bu planı almak **mantıklı mı**?" |
| Publish | "Yayın anında elindeki plan **yetiyor mu**?" |

Arada kullanıcı davetiyeye **galeri ekleyebilir**. Standart plan satın alıp
sonra Elit modülü açan biri, yalnızca checkout'ta kontrol edilseydi paywall'ı
aşardı.

Checkout'taki kontrol bir **güvenlik** katmanı değil, bir **kullanıcı
korumasıdır**: yetmeyeceği baştan belli bir planı satın almasını engeller.
Güvenlik kararı yayın anındakidir.

### Paket alımda neden atlanıyor?

```php
if ($invitation !== null) { … }
```

Paket (K42) **hesabın tamamı** için alınır; kontrol edilecek tek bir davetiye
yoktur. Yeterlilik yayın anında zaten sınanacak.

---

## 4. 🔴 Sıra: önce satır, sonra sağlayıcı (F3'ün dış servis hâli)

```php
$order = $this->createPendingOrder(...);      // 1. veritabanı
$session = $this->gateway->startCheckout($order);   // 2. dış servis
```

Faz 6'nın **F3** kuralı şöyleydi: *"dosya sistemi transaction'a dâhil değildir
— geri alınamayan iş elle telafi edilir."*

**Dış servis de transaction'a dâhil değildir.** Ters sırada:

```
1. Sağlayıcıda oturum aç      ✅  kullanıcı ödeyebilir
2. Veritabanına yaz           ❌  hata
→ ÖDENMİŞ AMA KAYDI OLMAYAN bir ödeme — parayı iade etmekten başka çare yok
```

Genel kural: **geri alınamayan iş en sona.** Sıra bir üslup değil, bir
kurtarma stratejisidir.

### Telafi: silmek değil, `failed` işaretlemek

```php
$order->status = OrderStatus::Failed;
$order->save();
```

Faz 6'da telafi `Storage::delete()` idi — dosya silindi. Burada satır
**silinmiyor**, çünkü:

- Silmek denemenin **izini** de silerdi; *"neden ödeyemiyorum?"* sorusu
  cevapsız kalırdı
- `failed` bir **son durumdur** (`isFinal()`), yani yanlışlıkla yeniden
  canlanamaz
- Muhasebe ve destek için başarısız denemeler de veridir

> ⚠️ Süreç ölürse (`kill -9`) telafi çalışmaz ve sipariş `pending` kalır.
> `expires_at` bu satırları işaretlemek için yazılıyor ama **onları temizleyen
> bir iş henüz yok** — Faz 9 borcu. **B6**: neyi kapatmadığımız da yazılır.

### `Log::error` neden gerekli?

**H8**: sağlayıcının ham hatası yanıta girmez. Ama kaybolmaz da. Üretimde
teşhis edilemeyen bir ödeme hatası, teşhis edilemeyen bir **gelir kaybıdır**.
`PaymentProviderException::rejected($e)` ayrıca orijinali `previous` olarak
taşır.

---

## 5. `provider_ref` neden sonradan yazılıyor?

```php
$order->provider_ref = $session->providerRef;
$order->save();
```

Sağlayıcı referansı **oturum açılmadan önce bilinmez**. Bu yüzden kolon
nullable (7.2) ve UNIQUE — PostgreSQL birden çok `NULL`'a izin verdiği için
henüz referansı olmayan siparişler birbirini engellemez.

Referans yazıldığı andan itibaren o ödeme için **ikinci bir sipariş satırı
imkânsızdır**: webhook idempotansının veritabanı yarısı burada devreye girer.

---

## 6. `CheckoutResult` — neden `Order` yetmiyor?

```php
final readonly class CheckoutResult
{
    public function __construct(public Order $order, public string $redirectUrl) {}
}
```

`redirectUrl` bir **sipariş alanı değildir**:

- Veritabanında saklanmaz — **E1**: türetilebilir/geçici veri saklanmaz
- Sağlayıcıdan gelir ve yalnızca **bu isteğin yanıtı** için anlamlıdır

Order nesnesine geçici bir özellik olarak iliştirmek onu bir **kolonmuş gibi**
gösterirdi ve bir gün `toArray()` çıktısına sızabilirdi. Faz 6'da
`Media::url()`'un accessor değil **metot** olmasının gerekçesi birebir aynıydı.

Ad, frontend sözleşmesiyle bilerek aynı: `types.ts` → `CheckoutResult`.

---

## 7. Bağımlılıklar kurucudan — autowiring

```php
public function __construct(
    private readonly PaymentGateway $gateway,
    private readonly TierResolver $tiers,
) {}
```

Konteyner tip bildirimini okur, bağlamaya bakar (`AppServiceProvider`), nesneyi
üretir ve verir. Action **hangi sağlayıcının** bağlı olduğunu bilmez —
Dependency Inversion'ın günlük hayattaki görüntüsü budur.

Testte tek satırla değiştirilebilir:

```php
$this->app->bind(PaymentGateway::class, fn () => new class implements PaymentGateway {
    public function startCheckout(Order $o): CheckoutSession { throw new RuntimeException('boom'); }
    …
});
```

Böylece 502 yolu, gerçek bir sağlayıcı olmadan test edilebilir.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Fiyatı istekten okumak | Elit plan 1 kuruşa satılır |
| 2 | Önce sağlayıcı, sonra DB | Ödenmiş ama kaydı olmayan ödeme |
| 3 | Telafide siparişi silmek | Başarısız denemenin izi kaybolur |
| 4 | Sağlayıcının ham hatasını yanıta koymak | H8 ihlali |
| 5 | Yeterliliği yalnızca checkout'ta kontrol etmek | Ödeme sonrası modül eklenerek paywall aşılır |
| 6 | `redirectUrl`'i Order'a kolon yapmak | Geçici veri kalıcı şemaya sızar (E1) |
| 7 | `Order::create($request->validated())` yazmak | Mass assignment; `#[Fillable]` boş olduğu için zaten çalışmaz |

---

## 9. Kendin dene

```php
// php artisan tinker
use App\Actions\Payment\StartCheckoutAction;
use App\Models\{User, Invitation};
use App\Enums\SubscriptionTier;

$user = User::factory()->create();
$inv = Invitation::factory()->create(['user_id' => $user->id, 'show_gallery' => true]);

$action = app(StartCheckoutAction::class);

// 🔴 Yetersiz plan reddediliyor mu?
$action->handle($user, $inv, SubscriptionTier::Standart);
// PaywallViolationException: owned tier 'standart' does not cover 'elit'.

$result = $action->handle($user, $inv, SubscriptionTier::Elit);
$result->order->status;          // OrderStatus::Pending
$result->order->amount_minor;    // 54900  ← config'ten, gövdeden değil
$result->order->provider;        // 'fake'
$result->order->provider_ref;    // 'fake_01J…'
$result->redirectUrl;            // '/odeme/basarili?order=01J…'
```

**Mutasyon denemesi (kural 14):** `$order->amount_minor = $tier->price() * 100;`
satırını `= 1;` yap. `php artisan test --filter=PaywallTest` çalıştır.
`the_order_amount_comes_from_the_server_side_price` kırılmalı.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Compensating transaction** | Geri alınamayan işi telafi eden ikinci işlem |
| **Autowiring** | Tip bildiriminden bakarak bağımlılığı çözme |
| **Idempotans anahtarı** | Aynı işlemi tanımlayan, tekrarı tespit ettiren değer |
| **Son durum (final state)** | Bir daha değişmeyen durum |
| **Mass assignment** | İstek anahtarlarının doğrudan kolona yazılması |

---

## 11. Sırada ne var?

**7.11 — `app/Actions/Payment/HandlePaymentCallbackAction.php`.** İdempotansın
uygulama yarısı: kilitli transaction içinde durum geçişi, aynı webhook iki kez
gelse de **tek** etki.
