# `config/payment.php` — Kılavuz

> **İlk yazım:** Adım 2 (Faz 0) · **Yeniden yazım:** Faz 12, adım 12.3
> **Kararlar:** K8 (Strategy) · K70 (bilinmeyen sürücü → 503) · **K94** (Shopier girdisi) ·
> **K117** (onay kanalı) · **K123** (sır koruması) · **K125** (alıcı yer tutucuları)

Faz 12'de üç şey değişti: `iyzico` bloğu **silindi**, yerine `shopier` geldi;
`webhook` bloğu **silindi** (aşağıda neden).

---

## 1. Anahtarlar

| Anahtar | Ne işe yarar |
|---|---|
| `default` | Aktif sağlayıcı: `fake` \| `shopier`. Başka bir değer → 503 (K70) |
| `providers.shopier.api_key` / `api_secret` | Shopier panelindeki API anahtarı ve sırrı. Biri boşsa sürücü kurulmaz (K123) |
| `providers.shopier.website_index` | Paneldeki site sırası; tek siteli hesapta `1` |
| `providers.shopier.payment_url` | Formun POST edildiği adres. **Env'den okunmuyor**: Shopier'in test ortamı yok, adres ortamdan ortama değişmiyor |
| `providers.shopier.confirmation` | `osb` (varsayılan) \| `callback` → §3 |
| `providers.shopier.osb.username` / `key` | Otomatik Sipariş Bildirimi'nin imza kimlikleri. `osb` modunda zorunlu |
| `providers.shopier.buyer_placeholders` | TCKN, telefon, adres için yer tutucular → §4 |
| `return_urls` | Ödeme sonrası kullanıcının döneceği **frontend** rotaları. Shopier'de **mutlak** adres olmalı |
| `order_expires_after_minutes` | Ödenmemiş siparişin penceresi (30 dk) |

## 2. Silinenler ve neden

| Silinen | Neden |
|---|---|
| `providers.iyzico.*` | Sağlayıcı Shopier oldu. `IyzicoGateway` hiç yazılmadı; blok yalnızca "var olan bir sürücü" yanılgısı üretiyordu |
| `webhook.signature_header` | İmzanın nerede durduğu sürücünün sözleşmesi, ortam ayarı değil. `FakeGateway::SIGNATURE_HEADER` oldu (12.2, K118) |
| `webhook.tolerance_seconds` | **Faz 0'dan beri hiçbir kod okumuyordu** (ders 26: ya kullan ya sil). Shopier'in imzasında zaman damgası yok; uygulanacak bir pencere de yok. Tekrar gönderime (replay) karşı koruma durum makinesinde: `paid → paid` geçişi yasak |

Ders 26'nın en kısa hâli: *okunmayan bir ayar, var olan bir korumaymış gibi görünen
bir yalandır.* `tolerance_seconds` bir yıl boyunca "replay saldırısına karşı 5 dk
penceremiz var" dedi; yoktu.

## 3. 🔴 `confirmation`: siparişi kim `paid` yapar? (K117)

Shopier ödeme sonucunu iki kanaldan bildirebilir:

| Kanal | Gönderen | İmzası neyi kapsıyor |
|---|---|---|
| **Callback** | Kullanıcının **tarayıcısı** (otomatik form POST) | `random_nr + platform_order_id`. **`status` imzanın DIŞINDA** |
| **OSB** | Shopier'in **sunucusu** | Bütün gövde (`res`) |

`status`'ün imzasız olması şu saldırıyı mümkün kılar:

```
1. Saldırgan bilerek reddedilecek bir kartla öder
2. Shopier tarayıcısına imzalı bir callback verir: status=failed, signature=GEÇERLİ
3. Saldırgan gövdede yalnızca status=failed → status=success yapar, tekrar POST eder
4. İmza hâlâ geçerli (status imzada yok) → sipariş 'paid' → bedava Elit
```

Bu yüzden varsayılan **`osb`**: tarayıcı callback'i yalnızca kullanıcıyı doğru
sayfaya **yönlendirir**, hiçbir siparişin durumunu değiştirmez. `paid` kararını
yalnızca imzası bütün gövdeyi kapsayan OSB verir.

`callback` moduna ancak §A'nın şu iki cevabından biri **gösterildiğinde** geçilir:
`status` imzanın içinde (resmi doküman), ya da başarısız ödemede callback hiç
gelmiyor (elle doğrulama adım 5-6). Bu bir `.env` değişikliğidir ve K94'e yazılır.

Varsayılanın güvenli taraf olması K70'in ruhu: *bir yapılandırma eksiği (OSB'yi
kurmayı unutmak) bedava yayına değil, bekleyen siparişe dönüşmeli.*

## 4. Alıcı yer tutucuları (K125)

Shopier formu TCKN, telefon, fatura ve teslimat adresi istiyor. Bizde yok:
`users` tablosunda yalnızca ad, soyad, e-posta var ve ürün dijital. Seçenekler:

| Seçenek | Sonuç |
|---|---|
| Kullanıcıdan TCKN/adres iste | Toplamadığımız kişisel veriyi toplamak (KVKK: veri minimizasyonu). Ödeme formu uzar |
| ✅ Config'te yer tutucu | Ad/soyad/e-posta gerçek, gerisi sabit. §A-e "kabul edilmiyor" derse burası değişir |

`11111111110` TCKN biçim kuralını (11 hane, kontrol haneleri) geçen bilinen bir test
değeridir. Shopier gerçek kimlik doğrulaması yaparsa reddedilir; elle doğrulama
betiğinin 3. adımı bunu ortaya çıkarır.

## 5. 🔴 İdempotans (değişmedi)

`orders.provider_ref` UNIQUE + `OrderStatus::canTransitionTo()` + `lockForUpdate()`.
Shopier'de `provider_ref = orders.id` (K121): callback ve OSB aynı siparişi
bildirirse ikincisi `paid → paid` geçişine takılır.

## 6. Fiyat nereden okunur? (değişmedi)

**`config/davetkart.php` → `tiers.*.price`**. Bu dosyada fiyat yoktur. Shopier'e giden
tutar `orders.amount_minor`'dan (indirim düşülmüş) **tamsayı aritmetiğiyle** metne
çevrilir ve imzanın içine girer (K124).

## 7. Dikkat

- Sırların varsayılanı yoktur (`env('SHOPIER_API_SECRET')` → yoksa `null`).
- `.env` değiştikten sonra `php artisan config:cache`. Unutulursa eski ayar okunur.
- `PAYMENT_SUCCESS_URL` göreli (`/odeme/basarili`) kalırsa Shopier callback'i
  kullanıcıyı **API'nin** alan adındaki `/odeme/basarili`'ye yollar (404).

---

## 🆕 Faz 13 (13.1 · K134–K135) — `methods` ve `bank_transfer`

| Anahtar | `.env` | Anlamı |
|---|---|---|
| `methods` | `PAYMENT_METHODS` | Açık yöntemler, virgüllü: `card`, `bank_transfer`, `gift_code` |
| `bank_transfer.bank_name` | `BANK_TRANSFER_BANK_NAME` | Havale ekranında gösterilen banka |
| `bank_transfer.account_holder` | `BANK_TRANSFER_ACCOUNT_HOLDER` | Hesap sahibi |
| `bank_transfer.iban` | `BANK_TRANSFER_IBAN` | Sağlama toplamından geçmezse havale kapalı |

- **Varsayılan `card,gift_code`.** Değişken hiç yazılmamışsa Faz 12'nin davranışı sürer.
  `.env.example` ise bugünkü kararı yazıyor: `PAYMENT_METHODS=gift_code`.
- `methods` ile `default` (sağlayıcı) **ayrı sorular**. `default` "kart hangi sağlayıcıdan
  geçer?" sorusunu, `methods` "kart açık mı?" sorusunu cevaplar. Kart kapalıyken
  `PAYMENT_PROVIDER=shopier` ve boş sırlar sorun değil: sürücü hiç kurulmaz (K136, bkz.
  `StartCheckoutAction.md`).
- Okuyan tek sınıf: [`PaymentMethods`](../app/Services/Payment/PaymentMethods.md).
