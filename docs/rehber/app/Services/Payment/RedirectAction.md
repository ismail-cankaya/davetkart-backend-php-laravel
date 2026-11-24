# `app/Services/Payment/RedirectAction.php`

> **Faz:** 12 (K118) · 11 · **Ana kılavuz:** `CheckoutAction.md` · **Test:** `tests/Feature/GiftCodeCheckoutTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

"Bu adrese git": FakeGateway'in ve hediye siparişinin (ödenecek 0) sonucu.

## Neden böyle

Adres göreli olabilir (`/odeme/basarili?order=…`): frontend kendi rotasına gider. Boş adres hiçbir yere gitmemektir; kurucuda reddedilir.

## Kurallar

- Boş ya da yalnızca boşluk adres → `InvalidArgumentException`.

## Sık hata

| Hata | Sonuç |
|---|---|
| Boş adresle dönmek | Kullanıcı ödeme sonrası boş sayfada kalır |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
