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

---

## 🆕 Faz 9 eklemesi — kapsam satın alma anında yazılır

```php
$order->scope = $invitation === null
    ? OrderScope::Account
    : OrderScope::Invitation;
```

Bu satır bir **türetme** gibi görünüyor ve az önce türetmenin yarattığı bir
hatayı kapatıyoruz. Çelişki değil; ayırt edici soru **ne zaman** türetildiği:

| | Faz 7 (hatalı) | Faz 9 (doğru) |
|---|---|---|
| Nerede | `OrderEntitlementResolver` | `StartCheckoutAction` |
| Ne zaman | **Her okumada** | **Bir kez**, satın alma anında |
| Kaynak | `invitation_id`'nin o anki değeri | Çağrı **yolu** (K64'ün iki ucu) |
| Sonuç | Kolon değişince anlam sessizce değişti | Sonuç kolona **yazıldı**, bir daha değişmiyor |

Sınırda bir kez türet ve sakla; her okumada yeniden türetme. Faz 3'ün
**30. dersinin** (*"savunma kodu güven sınırına yazılır"*) veri katmanındaki
hâli.

### Neden `handle()`'a ayrı bir `scope` parametresi eklemedim?

Controller'ın iki metodu (`forInvitation` / `forAccount`) kapsamı zaten biliyor,
yani parametre olarak geçirilebilirdi. Geçirilmedi çünkü o zaman **iki bağımsız
girdi** olurdu (`?Invitation` ve `OrderScope`) ve tutarsız bir çift göndermek
mümkün hâle gelirdi: `Account` + dolu davetiye. Bir değişmezi, çağıranın doğru
çift göndermesine bırakmak yerine tek kaynaktan türetmek **A8**'in kuralıdır
(*bir sınıfın değişmezi doğrulama katmanına bırakılmaz*).

Yedek savunma yine de var ve veritabanında:
`orders_account_scope_has_no_invitation_check`. Kural iki katmanda birden duruyor
— biri niyeti (`Action`), diğeri imkânı (`CHECK`) kapatıyor.

---

## 🆕 Faz 10 (10.58 · K99) — kapsam hep `'invitation'`

```php
$order->scope = OrderScope::Invitation;
```

Davetiyesiz (fiyat sayfasından) alınan sipariş artık **bağsız tekil sipariş**: `invitation_id`
boş, ilk yayında bağlanır. `POST /payments/checkout` ucu ve frontend akışı değişmedi; değişen
yalnızca siparişin ne açtığı. Gerekçe: `PublishEntitlementResolver.md` → *Faz 10*.

Mutasyon: kapsam yeniden `$invitation === null ? Account : Invitation` yazılınca
`the_package_checkout_creates_an_order_without_an_invitation` kırılıyor.

---

## 🆕 Faz 11 (11.7 · K107, K108): hediye kodu

İmzaya isteğe bağlı bir dördüncü parametre eklendi:

```php
public function handle(User $user, ?Invitation $invitation, SubscriptionTier $tier, ?string $giftCode = null): CheckoutResult
```

Varsayılan `null` olduğu için kodsuz çağrılar (ve `PaywallTest`) değişmeden çalışıyor.

### Akış

```
1. Yeterlilik (402)                      ← DEĞİŞMEDİ, koddan ÖNCE
2. DB::transaction(
     a. kod varsa: ReserveGiftCodeAction  ← kod satırı kilitlenir (E9)
     b. toplam 0 → createGiftOrder()      ← paid, provider=gift, amount=0
        toplam > 0 → createPendingOrder() ← pending, amount = fiyat − indirim
     c. kod varsa: kullanım satırı
   )
3. hediye siparişi → CheckoutResult(order, başarı adresi)   ← sağlayıcı YOK
4. normal sipariş → sağlayıcı (transaction DIŞINDA, F3)
```

**1 neden koddan önce?** Galerili bir davetiye için Standart plana `free/standart` kodu
uygulanırsa önce 402 döner ve kod harcanmaz
(`GiftCodeCheckoutTest::an_insufficient_tier_is_rejected_before_the_code_is_spent`).

**2 neden tek transaction?** Üç yazım birlikte anlamlı. Kod rezerve edilip sipariş yazılamazsa
kilit boşa tutulmuş olur. Sipariş yazılıp kullanım satırı yazılamazsa indirimli bir sipariş
kodsuz kalır, yani kod tükenmeden indirim verilmiş olur. Kodsuz checkout da artık bu
transaction'ın içinde. Tek satır yazdığı için davranışı değişmedi.

**4 neden transaction dışında?** F3'ün kuralı korunuyor: sağlayıcı geri alınamayan bir dış
çağrıdır ve kod satırının kilidi sağlayıcı yanıt verene kadar (saniyeler) tutulmamalı.

### 🔴 İndirim de sunucuda (K108)

```php
$discount = $quote === null ? 0 : $quote->discountMinor;
$order->amount_minor = $tier->price() * 100 - $discount;
$order->discount_minor = $discount;
```

Fiyat gibi indirim de istekten **okunmaz**. Gövdede `amount`, `discount`, `price` alanları
gönderilse bile `StoreCheckoutRequest` onları tanımaz, Action'a hiç ulaşmazlar
(`GiftCodeCheckoutTest::amount_and_discount_in_the_body_are_ignored`).

### Hediye yolu (K107)

`createGiftOrder()` sağlayıcıya gitmeden **ödenmiş** bir sipariş yazar. Alanların tamamı ve
kısıtlarla bağı: `docs/rehber/app/Models/Order.md` → "Faz 11". Davetiye varsa
`InvitationChanged` olayı fırlar (K102 kalıbı: misafir sayfasının önbelleği commit'ten sonra
düşer). `redirectUrl`, `FakeGateway`'in başarı adresiyle aynı biçimde
(`/odeme/basarili?order=<id>`). Frontend ödeme dönüş sayfası siparişi zaten bu adresten yokluyor.

### Sağlayıcı patlarsa

Mevcut telafi değişmedi: sipariş `failed` olur. Kullanım satırı **silinmez**. `failed` sipariş
kullanımı kendiliğinden iade eder (K110). Kullanıcı aynı kodu hemen tekrar deneyebilir
(`GiftCodeCheckoutTest::a_failing_provider_marks_the_order_failed_and_frees_the_code`).

### Log

Kullanımda tek satır `Log::info('Gift code redeemed', [gift_code_id, code_hint, order_id, gift_order])`.
**Düz kod log'a ve Sentry bağlamına girmez** (K109); yalnızca son 4 karakter.

---

## 🆕 Faz 12 (12.2 · K118): sonuç bir `CheckoutAction`

```php
// hediye siparişi (K107): sağlayıcı yok, başarı sayfasına git
return new CheckoutResult($order, new RedirectAction($this->successUrlFor($order)));

// normal yol: sürücü ne verdiyse (adres ya da imzalı form)
return new CheckoutResult($order, $session->action);
```

Action, sağlayıcının **nasıl** yönlendirdiğini bilmiyor. Fake bir adres veriyor,
Shopier imzalı bir form. Bu Action'da tek satır değişti; fiyat, indirim, telafi ve
sıra kuralları olduğu gibi duruyor. Strategy desenini bunun için kurmuştuk.

🔴 **Shopier için önemli bir sonuç:** tutar (`amount_minor`) Faz 11'de olduğu gibi
**burada** hesaplanıyor ve satıra yazılıyor. Shopier sürücüsü tutarı satırdan okuyup
imzanın **içine** koyuyor (E8). Yani indirim sağlayıcıya şeffaf: Shopier yalnızca
"399,00 değil 199,50" görür.

---

## 🆕 Faz 13 (13.5 · K129, K133, K134, K136)

### Yeni akış

```
1. Yeterlilik (değişmedi)
2. Kod verildiyse: gift_code yöntemi açık mı?                → değilse 403
3. TRANSACTION
   a. fiyat = QuotePriceAction(user, tier)                    (kampanya / ilk yayın)
   b. kod varsa: rezerve et, KALAN fiyata uygula               (K133)
   c. tutar 0  → hediye siparişi (sağlayıcı YOK)
      tutar >0 → card açık mı? değilse 403 (rezervasyon geri alınır)
                 sağlayıcıyı ŞİMDİ kur (K136) → bekleyen sipariş
   d. kullanım satırı
4. tutar >0 ise sağlayıcı oturumu (transaction DIŞINDA, F3)
```

### Kolonlar tek yerde: `applyPrice()`

Hediye siparişi ve bekleyen sipariş aynı yardımcıdan geçer:

| Kolon | Değer |
|---|---|
| `amount_minor` | `$price->totalMinor()` |
| `discount_minor` | `$price->discountMinor()` (otomatik + kod) |
| `promotion` | `first_publish` · `campaign` · null |
| `promotion_discount_minor` | otomatik indirimin tutarı |
| `price_campaign_id` | yalnızca kampanyada |

Fiyat siparişe **dondurulur**. Kampanya yarın bitse de bu siparişin tutarı değişmez ve
sağlayıcıdan gelen bildirimdeki tutar bu satırla karşılaştırılır (K126).

### Kart kontrolü neden transaction İÇİNDE?

Kodun %100 mü, %50 mi olduğunu ancak rezervasyondan sonra biliyoruz. Kart kapalıyken %50'lik
kod girildiyse sipariş açılamaz. Kontrol transaction içinde olduğu için fırlatılan 403
**rezervasyonu da geri alır**: kod tek kullanımlıksa hakkı yanmaz
(`with_the_card_off_a_partial_code_is_refused_and_the_code_stays_free`).

### Havale neden burada yok?

Havale sipariş açmıyor. Kullanıcı IBAN'a para gönderir, sen elle kontrol edersin. Bu uç
yalnızca **kart** ya da **bedava kod** ile sipariş açar. `bank_transfer` açık olsa bile kart
kapalıyken ödenecek tutarı olan bir checkout 403 alır.

---

## 🆕 Faz 18.3 (`SEC-01` · K158, K160, K161) — ilk yayın indirimi tek açık siparişe

### Sorun

Her checkout çağrısı yeni bir `pending` sipariş açıyordu ve hiçbiri diğerinden haberdar değildi.
İki sekme = iki indirimli sipariş (`SEC-01`); çift tıklama = iki satır (`ARC-09`). Kök neden bir
**TOCTOU** hatası: "indirim boşta mı?" diye bakılıyor, cevap bir satıra yazılmadan önce ikinci
istek aynı soruyu sorup aynı cevabı alıyordu.

### Yeni akış (transaction'ın içi)

```
1. Kullanıcı satırını kilitle            User::…->lockForUpdate()       K158, 1. katman
2. Penceresi dolmuş satırları kapat      pending -> expired             K161
3. Bu hedefin açık siparişlerini oku     awaitingPayment() + hedef
4. Fiyatı hesapla (BU hedef için)        QuotePriceAction               K159
5. Aynı niyet mi?  evet -> o siparişi DÖNDÜR                            K160
6. Değilse hedefin eski siparişini kapat pending -> expired             K161
7. Hediye kodunu ayır, siparişi yaz      (Faz 11 ve 13'teki gibi)
```

### 1. Neden **kullanıcı** satırı kilitleniyor?

Korunan şey henüz var olmayan bir satır: "ikinci indirimli sipariş". Olmayan satır kilitlenemez
(hayalet/phantom sorunu). Çözüm, bütün checkout'ların ortak atası olan satırı kilitlemek:
aynı kullanıcının iki isteği sıraya girer, ikincisi birincinin yazdığını **görerek** karar verir.
Hediye kodundaki (K111) desenin aynısı; orada kilitlenen ortak satır kodun kendisiydi.

Kilit, siparişlere bakan ilk sorgudan **önce** alınır. Sonra alınsaydı okuma ile kilit arasında
yine boşluk kalırdı (E9). `FirstPublishCheckoutTest::the_user_row_is_locked_before_the_checkout_looks_at_orders`
koşan SQL'i `DB::listen` ile yakalar ve sırayı da sınar (`TST-02` deseni).

**Kilit sırası** (kilitlenme/deadlock olmasın diye): kullanıcı → siparişler → hediye kodu. Ödeme
bildirimi (`HandlePaymentCallbackAction`) yalnızca sipariş satırını kilitler, kullanıcıyı
kilitlemez; döngü oluşmaz.

### 5. "Aynı niyet" ne demek? (K160)

| Karşılaştırılan | Neden |
|---|---|
| hedef | sorgunun kendisi: aynı davetiye ya da ikisi de davetiyesiz |
| plan (`tier`) | Gold siparişi Elit isteğine geri verilmez |
| liste fiyatı + otomatik indirim **tutarı** | arada kampanya açıldıysa ya da fiyat değiştiyse eski tutar geri verilmez: gösterilen = tahsil edilen |
| hediye kodu (kimliğiyle) | kodsuz istek, kodlu siparişi geri almaz; tersi de |
| sağlayıcı (`provider`) | sürücü arada değiştiyse eski oturum yeniden açılamaz |

Hepsi tutuyorsa sipariş **olduğu gibi** döner: yeni satır yok, yeni rezervasyon yok.

- **`provider_ref` ve `expires_at` korunur.** Kimlik değişseydi ilk oturumun bildirimi siparişi
  bulamazdı; pencere uzasaydı sayfayı yenilemek indirimi süresiz tutardı.
- **Yanıt yine 201.** Yanıt biçimi değişmedi (K163); istemci aynı `orderId` ve `checkout` alanını
  alır. Katı REST'te ikinci yanıt 200 olurdu; frontend'in tek bir başarı yolu kalsın diye dokunulmadı.
- **Sağlayıcı ikinci denemede patlarsa sipariş `failed` yapılmaz.** İlk oturum hâlâ ödenebilir;
  `failed` final olduğu için o ödeme reddedilirdi. Telafi yalnızca bu isteğin açtığı satıra uygulanır.
- **Kart kapalıysa (K134) yeniden kullanma da reddedilir:** 403.

Eskiden aynı kodla ikinci deneme `GIFT_CODE_ALREADY_USED` alıyordu (kullanımı kendi bekleyen
siparişi tutuyordu). Artık aynı sipariş döner.

### 6. Farklı niyet eskisini kapatır (K161)

Kullanıcı Gold seçip ödeme sayfasına gitti, vazgeçti, Elit seçti. İki seçenek vardı:

| Seçenek | Sonuç |
|---|---|
| Yeni sipariş **indirimsiz** açılsın | Güvenli ama fikir değiştiren kullanıcıyı 30 dakika cezalandırır |
| **Eskisi kapansın, indirim yenisine geçsin** ✅ | Kullanıcı dostu; geç ödeme davranışına bağlı |

İkincisi seçildi, çünkü geç ödeme koddan net okunuyor: kapatma durumu `expired`, ve
`expired → paid` geçişi açık (K89). Eski sekmedeki sayfadan sonradan ödeme gelirse sipariş
**donmuş tutarıyla** `paid` olur, `Late payment accepted…` uyarısı düşer, ve iki indirimli sipariş
ödenmişse son kapı `critical` yazar. `failed` yazılsaydı o para alınır ama hak açılmazdı.

Kapatma, kod rezervasyonundan **önce** yapılır: eski siparişin tuttuğu hediye kodu serbest kalır
ve yeni sipariş aynı kodu kullanabilir. Geçersiz bir kodla gelen istek hiçbir şeyi kapatmaz:
`GiftCodeException` transaction'ı geri alır.

Aynı mekanizma 2. adımda da çalışır: penceresi dolmuş ama `orders:expire`'ın henüz uğramadığı
satır kısmi UNIQUE index'e takılırdı (index zamanı bilmez). Komutun işi bu kullanıcı için öne çekilir.

### Bu düzeltme neyi KAPATMAZ? (B6)

| Kalan | Neden | Ne yakalar |
|---|---|---|
| Kapatılmış (ya da süresi dolmuş) indirimli siparişin açık kalan ödeme sayfasından **geç ödeme** | Sağlayıcının sayfasını biz kapatamayız; para alınmışsa geri çevrilemez (K89) | Son kapı: `Log::critical` (`HandlePaymentCallbackAction`) |
| Geç ödenen siparişin hediye kodu kullanımının yeniden "aktif" olması | Kullanım durumu siparişin durumundan türetilir (D4); Faz 11'den beri var | — (görüldü, dokunulmadı) |
| Checkout ile ödeme bildirimi aynı anda işlenirse "hiç ödememiş" gözleminin bayatlaması | Bildirim kullanıcı satırını kilitlemez (kilitlenme riski) | Pencere milisaniyeler; yalnızca iki indirimli ödeme olursa son kapı |

### Sık hatalar

| Hata | Sonuç |
|---|---|
| Kilidi fiyat hesabından sonra almak | Kilit var ama hiçbir şeyi korumuyor: karar kilitsiz verildi |
| Eski siparişi `failed` yapmak | Geç gelen ödeme reddedilir: para alınır, hak açılmaz |
| Eski siparişi silmek | Denemenin izi ve `provider_ref` kaybolur; geç bildirim "bilinmeyen referans" olur |
| Yeniden kullanmada tutarı karşılaştırmamak | Kampanya başladıktan sonra ekranda yeni fiyat, tahsilatta eski fiyat |
| Kodu rezerve ettikten sonra eski siparişi kapatmak | Aynı kod "zaten kullanıldı" der: kullanımı hâlâ eski sipariş tutuyor |
| Yeniden kullanılan siparişin `provider_ref`'ini ezmek | İlk oturumun bildirimi siparişi bulamaz |

### Kendin dene

```powershell
php artisan test --filter=FirstPublishCheckoutTest
```

Tarayıcıda (yerel, `PAYMENT_DRIVER=fake`): yeni bir kullanıcıyla bir davetiyenin ödeme sayfasında
"Öde"ye bas, geri dön, yeniden bas. `orders` tablosunda **tek** `pending` satır olmalı. Sonra
panelden başka bir davetiye için ödeme sayfasını aç: fiyat liste fiyatı olmalı ve açılan sipariş
`promotion = NULL` taşımalı.

**Mutantlar** (her biri ilgili testi kırmızıya çevirir):

| Mutant | Kırmızıya dönen test |
|---|---|
| `->lockForUpdate()` silinir | `the_user_row_is_locked_before_the_checkout_looks_at_orders` |
| `$existing = null;` (yeniden kullanma kalkar) | `a_second_checkout_for_the_same_target_reuses_the_pending_order` |
| `$this->supersede($open);` silinir | `a_different_plan_for_the_same_target_closes_the_old_order_and_takes_the_discount` |
| `$this->expireStaleOrders($user);` silinir | `an_abandoned_checkout_keeps_the_discount_for_the_next_try` |
| `if (! $reused)` → `if (true)` | `a_provider_failure_on_a_reused_order_leaves_it_pending` |


## 🆕 Faz 19 (19.14 · K191, SEC-09)

`handle()`'ın ilk satırı `VerifiedEmailGuard::assertVerified($user)` → doğrulanmamış hesap 403 `EMAIL_NOT_VERIFIED`.
Sipariş, hediye kodu rezervasyonu ve kullanıcı kilidi hiç alınmaz. Mutant: satır silinince
`EmailVerificationTest::an_unverified_account_cannot_start_a_checkout` kırmızı.

## K18 ile koddan taşınan açıklamalar (Faz 19 ortak düzeltme, A-E5)

Koddaki uzun yorum blokları buraya taşındı; kodda ilk cümle ve bu bölüme işaretçi kaldı. Satır numaraları taşımadan önceki hâlin.

### §A-E5-42 (satır 42)

```text
Bir plan icin odeme baslatir: siparis satirini yazar, saglayicida oturum acar.

🔴 Katmanli savunma — ama buradaki tehdit "bot" degil, ISTEMCIYE GUVENMEK:

  1. Yetki       -> rota + Gate::authorize('publish') (controller)
  2. Bicim       -> StoreCheckoutRequest ('in:' plan degerleri)
  3. Yeterlilik  -> secilen plan davetiyenin modullerini KAPSIYOR mu
  4. FIYAT       -> govdeden DEGIL, sunucudaki config'ten
  5. Telafi      -> saglayici patlarsa siparis 'failed' isaretlenir (F3)

Faz 11: istege bagli hediye kodu. Kod rezervasyonu + siparis + kullanim
satiri TEK transaction'da; tutar 0 ise saglayiciya hic gidilmez (D1).

Faz 13: tutar QuotePriceAction'dan (kampanya / ilk yayin, K129); hediye
kodu KALAN fiyata (K133). Yontem kapisi (K134): kod kapaliysa kod, kart
kapaliysa odenecek tutari olan siparis reddedilir (403). Saglayici yalnizca
gerektiginde kurulur (K136).

Faz 18.3 (SEC-01): ilk yayın indirimi TEK açık siparişe. Kullanıcı satırı
kilitlenir (K158); aynı niyet var olan siparişi döndürür (K160), farklı niyet
hedefin eski siparişini `expired` kapatır (K161). Uygunluk kuralı burada
değil FirstPublishEligibility'de (K159).

4. katman bu fazin en kisa ve en pahali satiridir: fiyat istekten okunsaydi
`{"tier":"elit","price":1}` govdesi Elit plani 1 kurusa satardi.
Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md
```

### §A-E5-103 (satır 103)

```text
3. KATMAN — yeterlilik. Kullanicinin daha UCUZ bir plan secip
galerili bir davetiye yayinlamasini burada engelliyoruz; yayin
aninda da tekrar bakilacak (7.12), cunku modul davetiyeye ODEME
SONRASINDA da eklenebilir.

Paket aliminda ($invitation === null) kontrol edilecek bir davetiye
yok: paket, hesaptaki HERHANGI bir davetiye icin alinabilir ve
yeterlilik yayin aninda sinanir.
```

### §A-E5-125 (satır 125)

```text
Faz 11: rezervasyon + siparis + kullanim satiri TEK transaction.
Kod satirinin kilidi kullanim satiri yazilana kadar tutulur (E9).
Saglayici (gerekirse) transaction icinde kurulur: kurulamazsa (503)
rezervasyon da siparis de geri alinir.
```

### §A-E5-202 (satır 202)

```text
🔴 F3'un dis servis hali: SAGLAYICI DA TRANSACTION'A DAHIL DEGILDIR.
Satir once yaziliyor, oturum sonra aciliyor. Ters sirada saglayici
oturumu acildiktan sonra veritabani yazimi patlarsa ODENMIS AMA
KAYDI OLMAYAN bir odeme kalirdi — geri alinamayan is EN SONA.
```

### §A-E5-209 (satır 209)

```text
TELAFI (compensating transaction). Siparis silinmiyor,
'failed' isaretleniyor: silmek denemenin izini de silerdi ve
"neden odeyemiyorum" sorusu cevapsiz kalirdi.

Faz 18.3 (K160): yalnızca BU isteğin açtığı satır. Yeniden kullanılan
siparişin ilk oturumu hâlâ ödenebilir; `failed` final olduğu için
o ödeme reddedilirdi.
```

### §A-E5-233 (satır 233)

```text
Saglayicinin verdigi kimlik ve pencere satira isleniyor.
provider_ref UNIQUE: ayni referansi tasiyan ikinci bir siparis
veritabani seviyesinde imkansiz (idempotansin yarisi, 7.2).

Faz 18.3 (K160): kimliği olan sipariş (yeniden kullanılan) olduğu gibi
kalır. Kimlik değişseydi ilk oturumun bildirimi siparişi bulamazdı;
pencere uzasaydı sayfayı yenilemek rezervasyonu süresiz tutardı.
```

### §A-E5-310 (satır 310)

```text
Hedefin önceki açık siparişlerini kapatır (K161).

🔴 `failed` DEĞİL `expired`: kullanıcının eski sekmesindeki ödeme sayfası
hâlâ açık olabilir. `expired -> paid` meşru (K89), `failed` finaldir; geç
gelen ödeme reddedilseydi para alınmış, hak açılmamış olurdu.
```

### §A-E5-375 (satır 375)

```text
🔴 KAPSAM SATIN ALMA ANINDA YAZILIR, okuma aninda turetilmez.
Faz 7'de bu bilgi hic saklanmadi; `invitation_id IS NULL` sorusuyla
her okumada yeniden turetiliyordu. O turetme, davetiye silinip
nullOnDelete atesledigi gun SESSIZCE yanlislaniyordu (N4).

Buradaki turetme farklidir ve mesrudur: cagri YOLU hangi kapsamin
alindigini kesin olarak biliyor (K64'un iki ucu) ve sonuc kolona
YAZILIYOR. Sinirda bir kez turet, sakla; her okumada yeniden
turetme (ders 30'un veri katmanindaki hali).

Faz 10 (10.58 · K99): paket de tek davetiyelik. Davetiyesiz alınan
sipariş bağlanmamış bir tekil sipariştir; ilk yayında o davetiyeye
bağlanır (ClaimReleasedOrderAction). 'account' kapsamı artık yazılmıyor.
```

### §A-E5-393 (satır 393)

```text
🔴 4. KATMAN. Fiyat SUNUCUDAN okunur. `$request` govdesinden
gelseydi kullanici kendi fiyatini yazardi — paywall'in en ucuz
atlatilma yolu budur ve hicbir dogrulama kurali onu yakalamaz,
cunku gonderilen deger BICIMSEL olarak gecerlidir.

Faz 11: indirim de SUNUCUDA hesaplanir (GiftCode::discountFor, D2).
Faz 13: dokum QuotePriceAction'dan; siparise DONDURULUR (K129).
```

### §A-E5-457 (satır 457)

```text
Fiyat dokumunu siparise yazar (Faz 13, K133). Iki yol da (odenecek ve
hediye) buradan gecer: kolonlarin kurali tek yerde.

  amount_minor             = odenecek
  discount_minor           = toplam indirim (otomatik + kod)
  promotion(_discount_minor), price_campaign_id = otomatik indirim

Liste fiyati = amount + discount; ayrica saklanmaz (E1).
```

## 🆕 Faz 22 (22.1 · K276) — doğrulama kapısı kalktı

Faz 19'un ilk satırı (`VerifiedEmailGuard::assertVerified($user)`) silindi. Doğrulanmamış hesap checkout başlatır; ilk yayın
indirimi `FirstPublishEligibility`'de doğrulama istemeye devam ettiği için liste fiyatından öder.
Test: `EmailVerificationTest::an_unverified_account_can_start_a_checkout_at_list_price` (sipariş 39900, `promotion` boş).
