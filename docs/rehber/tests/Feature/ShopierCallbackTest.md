# `tests/Feature/ShopierCallbackTest.php`

> **Faz:** 12 — adım 12.7 · **Sınadığı:** `POST /api/public/payments/shopier/callback`

## 🔴 Bu dosyanın en önemli testi

`a_failed_signature_with_a_flipped_status_does_not_pay_in_osb_mode`:

```php
$body = $this->signed($order->id, 'failed');   // Shopier'in reddedilen ödemeye verdiği imzalı gövde
$body['status'] = 'success';                    // saldırgan yalnızca bunu değiştirir
$this->postCallback($body);
$this->assertSame(OrderStatus::Pending, ...);   // 'osb' modunda ÖDENMEZ
```

K117'nin bütün gerekçesi bu test. Mutasyon: `ShopierGateway::parseCallback`'te bildirimi
her modda üret → bu test ve `osb` modunun diğer iki testi kırmızı (denendi).

## 🔴 Bilinçli riskin belgesi

`callback_mode_trusts_the_unsigned_status_by_design` aynı saldırıyı `callback` modunda
yapıyor ve siparişin **ödendiğini** iddia ediyor. Garip bir test: bir açığın var olduğunu
kanıtlıyor. Amacı unutulmamak: biri `SHOPIER_CONFIRMATION=callback` yaptığında bu riski
kabul ediyor. Test bir gün kırmızıya dönerse (Shopier `status`'ü imzalamaya başladı) K117
yeniden değerlendirilir: test silinmez, karar güncellenir.

## Gruplar

| Grup | Testler |
|---|---|
| Yol kilidi | Forma yazılan adres = rota |
| `osb` modu | Başarılı callback yalnızca yönlendirir · çevrilmiş durum ödemez · başarısız → hata sayfası |
| `callback` modu | Başarılı → `paid` + `provider_payment_id` · tekrar → tek geçiş (`paid_at` aynı) · başarısız → `failed` · bilinçli risk · K89 geç ödeme |
| Reddedilenler | Yanlış imza → değişiklik yok, `?order=` yok, log'da imza yok · boş gövde · bilinmeyen sipariş satır yaratmaz · open redirect |

## Neden `post()`, `postJson()` değil?

Shopier'in tarayıcıya yaptırdığı istek `application/x-www-form-urlencoded`. `post()`
tam olarak onu gönderir. `postJson` başka bir yol sınardı.

## Neden helper adı `postCallback`?

`callback()` PHPUnit'in `Assert::callback()` statik metodunu gölgeliyordu (PHPStan
yakaladı). Test sınıfının yardımcıları framework'ün adlarıyla çakışmamalı.
