# `app/Services/Payment/CheckoutSession.php`

> **Faz:** 7 · 12 (K118) · **Ana kılavuz:** `PaymentGateway.md` §4 · **Test:** `tests/Feature/PaymentDriverResolutionTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Sağlayıcının açtığı ödeme oturumunun sonucu: sağlayıcı kimliği (`orders.provider_ref`), kullanıcının ödeme sayfasına nasıl gideceği (`CheckoutAction`: adres ya da form) ve varsa son kullanma anı.

## Neden böyle

Dizi ne alan taşıdığını söylemez; yazım hatası çalışma anında `null` olur. `readonly` sınıf alanları ve tipleri sözleşmeye bağlar. Değişmezlik güvenlik özelliği: ödeme oturumunun kimliği yolda değişemez.

## Kurallar

- `action` Faz 12'de `string $redirectUrl`'in yerini aldı: Shopier imzalı form ister (`FormPostAction`).
- `expiresAt = null`: sağlayıcı pencere vermiyor.

## Sık hata

| Hata | Sonuç |
|---|---|
| Oturumu dizi olarak döndürmek | `redirectURL` yazım hatası sessizce yayılır |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
