# `app/Enums/PaymentMethod.php` — ödeme yöntemleri

> **Faz:** 13, adım 13.1 · **Karar:** K134
> **Test:** `PaymentMethodsTest` (dolaylı)

---

## 1. Neden var?

Bir müşteri planı üç yoldan ödeyebilir:

| Değer | Anlamı | Para nereye gider? |
|---|---|---|
| `card` | Kart; aktif sağlayıcının (Shopier) sayfasında yazılır | Sağlayıcı → bizim hesap |
| `bank_transfer` | Havale/EFT; ekranda IBAN ve tutar gösterilir | Doğrudan bizim hesap (elle kontrol) |
| `gift_code` | Hediye kodu; tutarı düşürür ya da sıfırlar | %100'lük kodda hiç para yok |

Bu kelimeler üç yerde aynı yazılmak zorunda: `.env`'deki `PAYMENT_METHODS` satırı,
fiyat uçlarının yanıtı (`paymentMethods`) ve frontend'in `types.ts` → `PaymentMethod` tipi.
Enum, üçünün de tek kaynağı. Yazım hatası (`'gift_cod'`) PHPStan'de ya da açılışta yakalanır
(bkz. [PaymentMethods.md](../Services/Payment/PaymentMethods.md) §3).

## 2. Neden "açık mı?" sorusu burada değil?

Enum **hangi yöntemler var** sorusunu cevaplar. **Hangisi şu an açık** sorusu ise ortama
bağlı (`.env`) ve havalede ikinci bir koşulu var (hesap bilgisi geçerli mi). O soru
`Services/Payment/PaymentMethods`'ta. Enum'a `isEnabled()` koysaydık enum `Config`'e
bağımlı olurdu. Enum saf bir değer listesidir, dış dünyayı tanımaz.

## 3. Sıra neden önemli?

`PaymentMethods::enabled()` sonucu `cases()` sırasında döner: kart, havale, kod. Frontend
düğmeleri bu sırada çizer. `.env`'de `gift_code,card` yazmak ekranı değiştirmez. Aynı ayar
iki biçimde yazıldığında ekranın değişmesi, hata ayıklarken kafa karıştırırdı.
