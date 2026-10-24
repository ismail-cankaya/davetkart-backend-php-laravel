# `app/Exceptions/GiftCodeException.php` — hediye kodu kullanılamadı

> **Faz:** 11, adım 11.5 · **Karar:** K113 (D7)
> **Test:** `GiftCodeCheckoutTest`, `GiftCodePreviewTest`

---

## 1. Biçim

```php
GiftCodeException::invalid();                          // GIFT_CODE_INVALID, 422
GiftCodeException::alreadyUsed();                      // GIFT_CODE_ALREADY_USED, 422
GiftCodeException::notApplicable(SubscriptionTier::Gold); // GIFT_CODE_NOT_APPLICABLE, 422, params.tier=gold
```

`HasErrorCode` arayüzünü uyguladığı için **eşleme yazmaya gerek yok**: `ApiExceptionRenderer`
kodu exception'ın kendisinden okur, durumu `ErrorCode::status()`'tan alır. `bootstrap/app.php`
bu fazda değişmedi.

## 2. 🔴 `invalid()` neden argüman almıyor?

Dört sebep (yok, kapalı, süresi dolmuş, tükenmiş) **dışarıdan ayırt edilemez** olmalı (K113).
Sebep bir parametreyle taşınsaydı, örneğin `invalid('expired')`, mesajı farklı olurdu.
Üretimde mesaj yanıta girmiyor (`APP_DEBUG=false`), ama yerel `debug` bloğu ve log'lar onu
gösterirdi. Argüman olmayınca farklı bir mesaj üretmek **mümkün değil**: kural yorumla değil
imzayla korunuyor.

> **Mutasyon notu (23 Ekim 2026):** `invalid()` mesajına rastgele bir ek konduğunda testler
> **yeşil kaldı**, çünkü test ortamı üretim kipinde (`APP_DEBUG=false`) ve mesaj yanıta hiç
> girmiyor. Üretimdeki bayt eşitliğini korunan şey zarf; mesajın tekliğini koruyan şey imza.

> ⚠️ Yerel geliştirmede (`APP_DEBUG=true`) `debug` bloğu exception'ın **satır numarasını**
> da gösterir. "Tükenmiş" kontrolü ayrı bir satırda fırlattığı için yerelde ayırt edilebilir.
> Üretimde `debug` bloğu yok (K20).

## 3. Kurucu `private`

Dışarıdan `new GiftCodeException(ErrorCode::GiftCodeInvalid, Tier::Gold, ...)` yazılamaz.
Yani `INVALID` koduna yanlışlıkla bir `tier` iliştirilemez. `PaywallViolationException` ile
aynı desen (A2 ailesi).

## 4. Özellik adı `$errorCode`, `$code` değil

`Exception`'ın kendi `protected int $code` özelliği var. Aynı adı kullanmak onu gölgeler ve
PHPStan üç LSP ihlali bildirir (`PaywallViolationException.md` §2).

## 5. `errorParams()`

Yalnızca `notApplicable` `['tier' => 'gold']` döner. Dönen dizi her zaman
`ErrorCode::filterParams()` beyaz listesinden geçer (H9). `GIFT_CODE_NOT_APPLICABLE`'ın
listesinde yalnızca `tier` var. Bir gün yanlışlıkla `max` ya da `remaining` eklense bile
yanıta sızmaz.

## 6. Örnek yanıt

```json
HTTP/1.1 422
{ "error": { "code": "GIFT_CODE_NOT_APPLICABLE", "params": { "tier": "gold" } } }
```
