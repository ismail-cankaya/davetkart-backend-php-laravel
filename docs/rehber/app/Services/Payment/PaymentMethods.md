# `app/Services/Payment/PaymentMethods.php` — hangi ödeme yöntemleri açık?

> **Faz:** 13, adım 13.1 · **Karar:** K134 (yöntem anahtarı), K135 (havale backend'e taşındı)
> **Test:** `tests/Feature/PaymentMethodsTest.php`

---

## 1. Tek satırlık anahtar

```dotenv
PAYMENT_METHODS=gift_code                      # bugün: yalnızca hediye kodu
PAYMENT_METHODS=card,gift_code                 # Shopier canlıya alınınca
PAYMENT_METHODS=card,bank_transfer,gift_code   # hepsi
```

Değişiklik sonrası sunucuda `php artisan config:cache` (deploy betiği bunu zaten yapar).
Frontend'i yeniden derlemek **gerekmez**: frontend açık yöntemleri fiyat ucundan okur.

## 2. Bu sınıf neden var? "İki kullanıcı, tek kaynak"

| Kim soruyor? | Ne yapıyor? |
|---|---|
| `StartCheckoutAction`, `PreviewGiftCodeAction` | Kapalı yöntemi **reddeder** (403) |
| `PricingResource` (fiyat uçları) | Açık yöntemleri **bildirir**, frontend düğmeleri buna göre çizer |

Frontend'in düğmeyi gizlemesi bir **kolaylık**. Güvenlik backend'in reddinde. Kart
kapalıyken biri tarayıcı konsolundan `POST /payments/checkout` gönderirse yine 403 alır.
Bu, paywall'ın "istemciye güvenme" ilkesinin aynısı (CLAUDE.md §3).

Kuralı iki yere yazsaydık (biri reddetmek, biri bildirmek için) bir gün ayrışırlardı:
ekran kartı gösterir, checkout reddeder. Tek sınıf bunu yapı gereği imkânsız kılar.

## 3. 🔴 Yanlış yazılmış kelime neden 503?

```php
$methods[] = PaymentMethod::tryFrom($token)
    ?? throw PaymentMethodException::misconfigured($token);
```

`PAYMENT_METHODS=gift_cod` yazıldığını düşün. Bilinmeyen kelimeyi **sessizce atlasaydık**
liste boş kalırdı, yani satış dururdu ve kimse nedenini bilmezdi. K70 (bilinmeyen ödeme
sürücüsü 503 verir) aynı gerekçeyle kurulmuştu: **bir yapılandırma hatası gürültülü olmalı.**

Hata yalnızca ödeme ve fiyat uçlarını etkiler (blast radius). Davetiye okuma, LCV ve giriş
çalışmaya devam eder. Sınıf `Config`'i **çağrı anında** okuduğu için yanlış değer
uygulamanın açılışını kırmaz.

Boş liste ise hata değil: `PAYMENT_METHODS=` bilinçli olarak "hiçbir yöntem açık değil"
demektir (örneğin bakım sırasında satışı durdurmak için).

## 4. Havale: listede olmak yetmez (K135)

```php
&& ($method !== PaymentMethod::BankTransfer || $this->bankAccount() !== null)
```

Havale ancak şu üç koşulda açılır: `BANK_TRANSFER_BANK_NAME`, `_ACCOUNT_HOLDER` ve
`_IBAN` dolu olmalı, **ve** IBAN sağlama toplamından (`Iban::isValid`, mod-97) geçmeli.

Bu bilgi eskiden frontend'in `.env`'indeydi (`VITE_BANK_TRANSFER_*`) ve derlenen JS'e
gömülüyordu. Hesabı değiştirmek frontend'i yeniden derlemeyi gerektiriyordu. Artık backend
`.env`'inde duruyor ve fiyat ucu yalnızca yöntem açıkken veriyor.

🔴 Tek hanesi yanlış bir IBAN müşterinin parasını başka birinin hesabına gönderir.
Bu yüzden geçersiz IBAN'da yöntem **kapanır** ve `warning` log'u yazılır. IBAN'ın kendisi
log'a girmez.

## 5. Neden `app/Services/Payment/`?

CLAUDE.md §1: `Services/` dış servis iletişimi, `Contracts/` kendi soyutlamalarımız. Bu
sınıf ikisi de değil, tam olarak bir **ödeme alanı ayarının okuyucusu**. Ödeme klasöründe
durması, "ödemeyle ilgili her şey burada" beklentisini karşılıyor. `Config::get` çağrısının
tek yerde olması, `payment.methods` anahtarının adı değişirse yalnızca bu dosyanın
değişmesi demek.

## 6. Kendin dene

```powershell
php artisan tinker
>>> config(['payment.methods' => 'gift_code']);
>>> app(App\Services\Payment\PaymentMethods::class)->enabled();
=> [App\Enums\PaymentMethod {+name: "GiftCode", +value: "gift_code"}]
```
