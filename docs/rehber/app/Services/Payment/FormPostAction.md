# `app/Services/Payment/FormPostAction.php`

> **Faz:** 12 (K118) · **Ana kılavuz:** `CheckoutAction.md` · **Test:** `tests/Feature/ShopierGatewayTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

"Bu alanları bu adrese POST et": Shopier'in klasik ödeme formu. Frontend görünmez bir form kurup gönderir.

## Neden böyle

Alanların bir kısmı imzanın içinde (tutar, sipariş kimliği). Kurulduktan sonra değiştirilebilen bir form imzayla uyuşmayan bir form demek olurdu; bu yüzden değişmez.

## Kurallar

- 🔴 Adres mutlak `http(s)` olmalı (form başka siteye gider).
- 🔴 Her anahtar ve değer **string**: HTML formunda sayı yok; `100` ile `"100"` imzada aynı, JSON'da farklı.
- En az bir alan.

## Sık hata

| Hata | Sonuç |
|---|---|
| Sayısal alanı `int` bırakmak | JSON'da sayı, formda metin: imza doğrulaması tutarsızlaşır |
| Göreli adres | Form kendi sitemize gider |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
