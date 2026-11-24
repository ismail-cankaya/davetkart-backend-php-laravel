# `app/Services/Payment/BankTransferAccount.php`

> **Faz:** 13 (K135) · **Ana kılavuz:** `PaymentMethods.md` §4 · **Test:** `tests/Feature/PaymentMethodsTest.php`
> Kılavuz Faz 19.28'de (`ARC-03`, K259) eksik kılavuz envanterinden yazıldı.

## Ne

Havale/EFT için gösterilecek hesap: banka adı, hesap sahibi, IBAN.

## Neden böyle

Yalnızca `PaymentMethods` üretir; IBAN orada sağlama toplamından geçmiş ve boşluksuz büyük harf hâline getirilmiştir. Bu sınıfın varlığı "doğrulanmış hesap" anlamına gelir.

## Kurallar

- Saf veri; doğrulama üreticide.
- Hesap bilgisi `config`'ten; koda ya da depoya yazılmaz (sır değil ama tek ev).

## Sık hata

| Hata | Sonuç |
|---|---|
| Sınıfı config'ten doğrudan kurmak | Bozuk IBAN müşteriye gösterilir |

## Terimler

- **`final readonly class`:** kurucudan sonra hiçbir alanı değişmeyen, alt sınıfı olmayan sınıf; değer nesnesi (DTO) için.
