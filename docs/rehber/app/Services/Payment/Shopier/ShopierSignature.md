# `app/Services/Payment/Shopier/ShopierSignature.php`

> **Faz:** 12 — adım 12.5 · **Test:** `tests/Unit/ShopierSignatureTest.php`
> **Kararlar:** **K120** (E4, kendi sürücümüz) · **K117** (callback imzası `status`'ü kapsamıyor) · W2 (`hash_equals`)

---

## 1. Kaynak ve 🔴 bir uyarı

Formüller **resmi dokümandan değil**, topluluk kaynaklarından:

- Ödeme formu ve callback: `erkineren/shopier` (PHP SDK, Packagist)
- OSB: topluluk örnekleri (`res` + `hash`, yanıt `success`)

§A (Shopier panelinden İsmail'in toplayacağı bilgi) boş geldi. Formül resmi
dokümanla çelişirse **resmi doküman kazanır** ve bu dosya ile test vektörleri
birlikte değişir. Elle doğrulama betiğinin 3. adımı (gerçek kartla ödeme) ödeme
formu imzasını ilk kez gerçek Shopier'e karşı sınayacak.

## 2. Üç formül

| Ne | Girdi (birleştirme sırası) | Çıktı |
|---|---|---|
| Ödeme formu | `random_nr . platform_order_id . total_order_value . currency` | base64(ham HMAC) |
| Tarayıcı callback'i | `random_nr . platform_order_id` | base64(ham HMAC) |
| OSB | `res . osb_kullanıcı_adı` (anahtar: OSB anahtarı) | hex HMAC |

İlk ikisinin anahtarı `API_SECRET`, üçüncüsünün OSB anahtarı.

### Neden "saf fonksiyon" (statik, durumsuz)?

Girdi aynıysa çıktı aynı; sır bile parametre. Test etmek için hiçbir şey kurmak
gerekmez (konteyner yok, config yok, veritabanı yok). Sürücü (`ShopierGateway`)
config'i okur ve bu fonksiyonlara verir: **I/O kenarda, mantık ortada**.

## 3. 🔴 Callback imzası neyi kanıtlar, neyi kanıtlamaz?

```
imzalanan:    random_nr + platform_order_id
imzalanmayan: status, payment_id, installment
```

`verifyCallback()` true dönüyorsa: *"bu sipariş kimliği ve bu random_nr ikilisini
Shopier üretti."* Ödemenin **başarılı** olduğunu kanıtlamaz. Kendi başarısız
ödemesinden geçerli bir imza alan biri `status=failed`'ı `status=success` yapıp
tekrar gönderebilir; imza hâlâ geçerlidir. K117'nin (`osb` varsayılanı) bütün
gerekçesi bu satırdır.

## 4. Üç küçük ama önemli ayrıntı

**`base64_decode($signature, true)` — katı kip.** Gevşek kip base64 alfabesi dışındaki
karakterleri **sessizce atar**: `"abc***def"` → `"abcdef"` çözülür. İmza
karşılaştırmasından önce girdiyi "düzelten" bir fonksiyon, saldırgana deneme alanı
açar. Katı kip `false` döner, biz de reddederiz.

**`hash_equals`, `===` değil (W2).** `===` ilk farklı baytta durur. Yanıt süresini
milisaniyenin altında ölçebilen biri imzayı bayt bayt tahmin edebilir (zamanlama
saldırısı). `hash_equals` her zaman bütün baytları karşılaştırır.

**OSB'de önce imza, sonra çözme (L1).** `res` **base64 metni olarak** imzalanıyor.
Önce imzayı doğrulayıp sonra `base64_decode` + `json_decode` yapıyoruz. Ters
sırada imzasız bir istek bile JSON ayrıştırıcısını çalıştırırdı.

## 5. 🔴 Sabit test vektörleri neden başka bir araçla hesaplandı?

```php
// ❌ Kendini kendisiyle karşılaştıran test
$expected = base64_encode(hash_hmac('sha256', $r.$o.$t.$c, $s, true));
$this->assertSame($expected, ShopierSignature::forPayment($r, $o, $t, $c, $s));
```

Bu test formülü kopyalar. Formül yanlışsa (sıra ters, hex yerine base64) **iki taraf
aynı şekilde yanlış** olur ve test yeşil kalır. Testteki değerler Python'un `hmac`
modülüyle, bu koddan bağımsız hesaplandı:

```python
import hmac, hashlib, base64
base64.b64encode(hmac.new(b'test-secret',
    b'482913' b'01JB2Z8Q4M7X3K9V5N6P0R1T2W' b'399.00' b'0', hashlib.sha256).digest())
# b'knN7UNt6zPH8MgWa6X41OMaBK6Vw64OuFZNcQ1nL55k='
```

İki bağımsız uygulama aynı sonucu veriyorsa, ikisinin de aynı yanlışı yapma olasılığı
düşüktür. Topluluk SDK'sının kendi örnek değerleri bulunamadı; bulunursa vektör
olarak eklenmeli.

## 6. Mutasyon

| Değişiklik | Kırılan test |
|---|---|
| `forPayment`'ta `$currency`'yi birleşimden çıkar | `the_payment_signature_matches_the_fixed_vector` |
| `verifyCallback`'te `base64_decode(..., true)` → gevşek | `a_forged_callback_is_refused` (base64 olmayan) |
| `verifyOsb`'de `strtolower` sil | `a_correct_osb_hash_is_accepted_in_either_case` |

## 7. Kendin dene

```php
php artisan tinker
>>> use App\Services\Payment\Shopier\ShopierSignature as S;
>>> S::forPayment('482913', '01JB2Z8Q4M7X3K9V5N6P0R1T2W', '399.00', '0', 'test-secret');
=> "knN7UNt6zPH8MgWa6X41OMaBK6Vw64OuFZNcQ1nL55k="
>>> S::verifyCallback('482913', '01JB2Z8Q4M7X3K9V5N6P0R1T2W', '7QBWgAzXJ9Ric3YrzjJ9luYEB5f1FEzJfFQ/BtSuT3o=', 'test-secret');
=> true
```
