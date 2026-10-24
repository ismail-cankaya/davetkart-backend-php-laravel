# `app/Services/Payment/ShopierGateway.php`

> **Kod dosyaları:** `ShopierGateway.php` · `Shopier/ShopierConfirmation.php` · `Shopier/ShopierCallback.php`
> **Faz:** 12 — adım 12.6 · **Test:** `tests/Feature/ShopierGatewayTest.php`
> **Kararlar:** K94 · **K117** (onay kanalı) · K120 (kendi sürücümüz) · **K121** (kimlikler) ·
> **K123** (sır koruması) · K124 (tutar) · K125 (alıcı alanları) · **K127** (callback ayrı metot)
> **Formüller:** [`Shopier/ShopierSignature.md`](Shopier/ShopierSignature.md) · [`Shopier/ShopierAmount.md`](Shopier/ShopierAmount.md)

---

## 1. Büyük resim: kart bizim sunucumuza hiç gelmiyor

```
Tarayıcı ──POST /invitations/{id}/checkout──▶ API
                                              StartCheckoutAction (fiyat sunucuda)
                                              ShopierGateway::startCheckout
Tarayıcı ◀── {checkout:{type:'form', url, fields}} ──
Tarayıcı ──gizli form POST──▶ shopier.com/…/api_pay4.php    ◀── kart BURADA yazılır
                                     │
            ┌────────────────────────┴───────────────────────┐
            ▼ (tarayıcı)                                       ▼ (Shopier sunucusu)
 POST /api/public/payments/shopier/callback        POST /api/public/payments/shopier/osb
 parseCallback() → yönlendir (303)                 parseOsb() → 'paid' kararı (K117)
```

Kart numarası yalnızca Shopier'in sayfasında yazılıyor. Bizim kodumuz onu **hiçbir
zaman görmüyor**. PCI DSS açısından sonuç: kart verisine dokunan bir sunucumuz yok,
denetim kapsamının en küçük seviyesindeyiz.

## 2. Üç metot, üç kanal

| Metot | Kim çağırır | Ne döner | Durumu değiştirebilir mi? |
|---|---|---|---|
| `startCheckout(Order)` | `StartCheckoutAction` | `FormPostAction` | — |
| `parseCallback(Request)` | `ShopierCallbackController` | `ShopierCallback` | Yalnızca `callback` modunda |
| `parseOsb(Request)` / `parseNotification(Request)` | `ShopierOsbController` | `PaymentNotification` | **Evet** (her modda) |

## 3. 🔴 Onay kanalı: `ShopierConfirmation` (K117)

Callback imzası `random_nr + platform_order_id`'yi kapsıyor; **`status` imzanın
dışında.** Saldırı:

```
1. Reddedilecek bir kartla öde → Shopier tarayıcıya imzalı bir callback verir:
   platform_order_id=01J…  random_nr=482913  signature=GEÇERLİ  status=failed
2. DevTools'ta aynı isteği tekrarla, yalnızca status=success yap
3. İmza hâlâ geçerli (status imzada yok) → sipariş 'paid'
```

Savunma bir `if` değil, bir **mod**:

| Mod | Callback | OSB |
|---|---|---|
| `osb` (varsayılan) | Yalnızca yönlendirir. `ShopierCallback::$notification` **null** | `paid` kararını verir |
| `callback` | İmzası doğruysa durumu işler (riskli) | Kuruluysa yine işler (idempotan) |

Neden enum ve neden sürücünün yanında? Değerler sihirli string olmasın (CLAUDE.md
§1). Ama bu bir alan kavramı değil, tek sürücünün ayarı: `app/Enums/`'a koymak onu
olduğundan daha geniş gösterirdi.

Bilinmeyen mod (`SHOPIER_CONFIRMATION=browser`) sürücüyü **kurmaz** (503). Sessizce
`osb`'ye düşmek, yanlış yazılmış bir `callback`'i "neden ödemeler onaylanmıyor"
gizemine çevirirdi; sessizce `callback`'e düşmek riski kimsenin seçmediği bir yerde
açardı.

## 4. Neden `parseCallback` ayrı bir metot? (K127)

Prompt iki seçenek bıraktı: arayüzün `parseNotification`'ı callback'i ayrıştırsın
ve `osb` modunda "ilerletmeyen" bir sonuç dönsün, ya da ayrı bir metot.

`PaymentNotification`'ın sözü: *"bu nesne var ise imzası doğrulanmış ve işlenebilir
bir bildirimdir"* (Faz 7). `osb` modundaki bir callback bu sözü **tutamaz**: imzası
doğru ama işlenmemeli. Onu `PaymentNotification` olarak döndürmek, sözleşmeye bir
istisna eklemek ("…ama bazen işleme") demekti ve `HandlePaymentCallbackAction`'ı
çağıran her yer o istisnayı hatırlamak zorunda kalırdı.

Bunun yerine callback'in kendi sonuç tipi var:

```php
final readonly class ShopierCallback {
    public string $orderId;                         // nereye yönlendirilecek
    public bool $succeeded;                          // hangi sayfa (imzasız ipucu)
    public ?PaymentNotification $notification;       // osb modunda HİÇ ÜRETİLMEZ
}
```

`osb` modunda bildirim nesnesi **hiç kurulmuyor**. Controller bir gün yanlışlıkla
`$action->handle(...)` çağırsa bile elinde işlenecek bir şey yok. Bu, "kontrol et"
yerine "imkânsız kıl" (K118'deki tagged union fikrinin aynısı).

Arayüzün `parseNotification(Request)`'ı ise Shopier için **OSB**'dir: sunucudan
sunucuya, bütün gövdesi imzalı, Fake'in webhook'unun gerçek karşılığı.

## 5. `startCheckout`: formun 29 alanı

| Grup | Alanlar | Kaynak |
|---|---|---|
| Kimlik | `API_key`, `website_index`, `platform_order_id` | config · `orders.id` |
| Ürün | `product_name` (`DavetKart Gold`), `product_type=1` (dijital) | `tier->label()` |
| Alıcı | `buyer_name`, `buyer_surname`, `buyer_email`, `buyer_account_age` | `users` |
| Yer tutucu | `buyer_id_nr`, `buyer_phone`, `billing_*`, `shipping_*` | config (K125) |
| Tutar | `total_order_value` (`199.50`), `currency=0` (TL) | `amount_minor` (K124) |
| Sabit | `platform=0`, `is_in_frame=0`, `current_language=0`, `modul_version` | SDK |
| Güvenlik | `random_nr`, `signature` | `random_int` · HMAC |
| Dönüş | `callback` | `url(CALLBACK_PATH)` |

Notlar:

- **`product_name` için `SubscriptionTier::label()`** kullanıldı. Bu metot Faz 0'dan
  beri hiçbir yerden çağrılmıyordu (Faz 7 açık kararı #4: "silinmeli mi?"). Artık bir
  çağıranı var; o açık soru kendiliğinden kapandı.
- **`random_nr`** her checkout'ta yeni (`random_int`, kriptografik). Aynı imzanın iki
  form arasında tekrar kullanılmasını zorlaştırır.
- **`expiresAt: null`**: Shopier oturum penceresi vermiyor; `StartCheckoutAction` kendi
  30 dakikalık penceresini (`order_expires_after_minutes`) zaten yazmıştı ve `null`
  onu ezmez.
- **`callback` neden `route()` değil?** Rota bir sonraki adımda (12.7) yazıldı; her
  adımın kendi başına yeşil kalması için yol bir sabit. `ShopierCallbackTest` sabit
  ile rotanın aynı adres olduğunu kilitliyor; biri değişirse test kırılır.

## 6. Kurucu: sır yoksa sürücü yok (K123)

```php
$this->apiSecret = $this->secret('api_secret') ?? throw PaymentProviderException::unavailable(self::NAME);
```

Boş bir sırla HMAC hesaplanabilir: `hash_hmac('sha256', $data, '')` geçerli bir değer
üretir. **Herkes** aynı değeri üretebilir. Yani boş sırla çalışan bir sürücü, imza
doğrulaması yapıyormuş gibi görünen ama hiçbir şeyi doğrulamayan bir sürücüdür.

Kontrol neden `resolvePaymentGateway`'de değil de kurucuda? İki yerden kuruluyor:
`PaymentGateway` çözümü (checkout) ve controller'ların doğrudan istediği
`ShopierGateway` (callback, OSB). Kurucu ikisinin de geçmek zorunda olduğu tek kapı.
`GeminiProvider::apiKey()` aynı fikri tembel (çağrı anında) uyguluyor (A8); burada
kurucuda, çünkü sırsız bir Shopier sürücüsünün yapabileceği **hiçbir** güvenli iş yok.

## 7. OSB: sıra ve eşleme

```
kimlik var mı? → imza (res + kullanıcı adı) → base64 + JSON → istest? → eşleme → tutar
```

- **`istest=1` + üretim** → 400. Shopier'in test bildirimiyle canlıda sipariş açılmasın.
- **Eşleme (`matchOsbToOrder`) 🔴 tahmin.** OSB'nin API ödemelerinde bizim
  `platform_order_id`'mizi taşıyıp taşımadığı bilinmiyor (§A-c boş). Kural: ULID olan
  `platform_order_id` ya da `orderid`. Shopier'in kendi sayısal numarası ULID olamaz,
  yani yanlış siparişle eşleşmez; eşleşmezse `null` + `critical`. Elle doğrulama adım
  7 bunu kesinleştirecek. Değişecek tek yer bu metot.
- **Tutar** (`price`) okunamazsa 400 + `critical`: doğrulanamayan ödeme `paid` yapılmaz.
  Okunursa `PaymentNotification::$amountMinor`'a girer ve
  `HandlePaymentCallbackAction` siparişinkiyle karşılaştırır (K126).

## 8. Log'da ne var, ne yok?

Var: sipariş kimliği, Shopier'in sipariş numarası, beklenen ve gelen tutar.
**Yok:** `API_SECRET`, OSB anahtarı, imza, `res` gövdesi (alıcının e-postası içinde).
Sentry'ye giden her şey log'dan geçer; log'a girmeyen Sentry'ye de gitmez.

## 9. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Callback'te `status=success` görüp siparişi `paid` yapmak | §3'teki saldırı |
| Tutarı `$amount / 100` ile biçimlemek | Float; bir gün imza geçersiz |
| `base64_decode($sig)` (gevşek) | Bozuk girdi "düzeltilir", deneme alanı açılır |
| OSB'de önce JSON'u çözüp sonra imzaya bakmak | İmzasız istek ayrıştırıcıyı çalıştırır (L1) |
| `PAYMENT_SUCCESS_URL`'i göreli bırakmak | 303 API'nin alan adına gider |

## 10. Kendin dene

```php
php artisan tinker
>>> config(['payment.providers.shopier.api_key' => 'k', 'payment.providers.shopier.api_secret' => 's',
...         'payment.providers.shopier.osb.username' => 'u', 'payment.providers.shopier.osb.key' => 'o']);
>>> $o = App\Models\Order::factory()->create(['provider' => 'shopier']);
>>> $s = (new App\Services\Payment\ShopierGateway)->startCheckout($o);
>>> $s->action->fields()['total_order_value'];
>>> config(['payment.providers.shopier.api_secret' => '']);
>>> new App\Services\Payment\ShopierGateway;      // PaymentProviderException (503)
```
