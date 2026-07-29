# `config/payment.php` — Kılavuz

> **Bu dosya yeni yazıldı** (Adım 2). Ödeme sağlayıcı seçimi ve sırları.

## Neden sağlayıcı anlaşması olmadan yazıyoruz?

Ödeme sağlayıcısı (Iyzico/PayTR) henüz seçilmedi. Ama **akış** sağlayıcıdan
bağımsızdır: order oluştur → kullanıcıyı ödemeye gönder → webhook'u karşıla →
idempotan biçimde işaretle.

Bu yüzden `PaymentGateway` **arayüzü** ve onu uygulayan `FakeGateway` ile
başlıyoruz. Doğru akış bugün kurulur, gerçek sağlayıcı sonra `IyzicoGateway`
olarak eklenir — **Strategy Pattern**.

```php
interface PaymentGateway {
    public function createCheckout(Order $order): string; // ödeme URL'i
    public function verifyWebhook(Request $request): bool;
}
```

`AppServiceProvider`, `config('payment.default')` değerine bakıp arayüzü doğru
sınıfa bağlayacak. İş kodu (`StartCheckoutAction`) hangi sağlayıcının çalıştığını
**bilmez** — Dependency Inversion (SOLID'in D'si).

## Anahtarlar

| Anahtar | Ne işe yarar |
|---|---|
| `default` | Aktif sağlayıcı. `fake` \| `iyzico` |
| `providers.*` | Her sağlayıcının sürücü adı ve sırları |
| `return_urls` | Ödeme sonrası kullanıcının döneceği **frontend** rotaları |
| `webhook.signature_header` | İmzanın taşındığı HTTP başlığı |
| `webhook.tolerance_seconds` | İmzanın kabul edileceği zaman penceresi (300 sn) |
| `order_expires_after_minutes` | Ödenmemiş order'ın geçerlilik süresi |

## 🔴 Webhook güvenliği — iki ayrı önlem

**1. İmza doğrulaması.** Webhook rotası auth'suz ve CSRF muaftır. Doğrulama
olmazsa **herkes** `POST /api/payments/webhook` ile "ödeme başarılı" diyebilir
ve bedava Elit plan alır. Sağlayıcı, gövdeyi paylaşılan gizli anahtarla imzalar;
biz aynı hesabı yapıp karşılaştırırız.

**2. Zaman penceresi (`tolerance_seconds`).** Geçerli bir webhook isteği
kaydedilip günler sonra tekrar gönderilebilir (*replay attack*). İmzanın içindeki
zaman damgası 5 dakikadan eskiyse istek reddedilir.

## 🔴 İdempotans

Sağlayıcılar webhook'u ağ hatası/retry sebebiyle **birden çok kez** gönderir.
Aynı ödemenin iki kez işlenmesi = kullanıcıya iki plan, muhasebede çift kayıt.

Çözüm veritabanı seviyesinde: `orders.provider_ref` kolonu **UNIQUE**. İkinci
webhook ekleme yapamaz, sessizce 200 döneriz. Uygulama katmanındaki `if` kontrolü
yeterli değildir — iki webhook aynı anda gelirse ikisi de `if`'i geçer (race
condition); unique kısıt geçmez.

## Fiyat nereden okunur?

**`config/davetkart.php` → `tiers.*.price`**. Bu dosyada fiyat yoktur.

Ayrım kasıtlı: `payment.php` *nasıl ödeneceğini* (altyapı), `davetkart.php`
*ne kadar ödeneceğini* (iş kuralı) tanımlar. İstemciden gelen `amount` alanına
asla güvenilmez.

## Dikkat

- `IYZICO_BASE_URL` varsayılanı **sandbox**. Üretimde canlı adresle
  değiştirilmeli, aksi hâlde ödemeler test ortamında kalır.
- Sırların varsayılanı yoktur (`env('IYZICO_API_KEY')` → yoksa `null`);
  gerçek anahtar asla koda varsayılan olarak yazılmaz.
