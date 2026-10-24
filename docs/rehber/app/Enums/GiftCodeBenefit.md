# `app/Enums/GiftCodeBenefit.php` — hediye kodunun fayda türü

> **Faz:** 11, adım 11.1 · **Karar:** K107–K112 (D1–D6)
> **Test:** `GiftCodeModelTest` (dolaylı)

---

## 1. Neden var?

Bir hediye kodu üç farklı şey yapabilir ve hangisini yaptığı **koddan koda değişir**:

| Değer | Anlamı | Hangi alanı kullanır |
|---|---|---|
| `free` | Belirli bir planı **bedava** açar | `tier` (zorunlu) |
| `percent` | Yüzde indirim, 1–100 | `percent_off` |
| `fixed` | Sabit tutar indirimi | `amount_off_minor` (kuruş) |

Bu üç değer bir **durum**, yani CLAUDE.md §1'in "sihirli string yasak" kuralının tam alanı.
Veritabanında `'percent'` yazılır ama kodda her zaman `GiftCodeBenefit::Percent` kullanılır.
Yazım hatası (`'precent'`) çalışma anında değil, PHPStan'de yakalanır.

## 2. PHP temelleri

```php
enum GiftCodeBenefit: string   // "backed enum": her case'in bir string değeri var
{
    case Free = 'free';
    ...
}

GiftCodeBenefit::from('free');     // GiftCodeBenefit::Free
GiftCodeBenefit::tryFrom('bogo');  // null (from() olsaydı ValueError fırlatırdı)
GiftCodeBenefit::Free->value;      // 'free'
```

`GiftCodeSpec::fromOptions()` operatörün yazdığı `--benefit` değerini `tryFrom()` ile okur. `null`
gelirse açık bir hata mesajı basılır; `from()` kullanılsaydı operatör anlaşılmaz bir
`ValueError` görürdü.

## 3. `values()` neden var? (K39)

Migration'daki CHECK kısıtı geçerli değer listesini **buradan** alır:

```php
$benefits = "'".implode("', '", GiftCodeBenefit::values())."'";
// CHECK (benefit IN ('free', 'percent', 'fixed'))
```

Liste elle yazılsaydı enum'a dördüncü bir tür eklendiği gün kısıt sessizce eskirdi. `OrderStatus`,
`SubscriptionTier` ve `OrderScope` enum'larıyla aynı desen.

## 4. Neden `label()` yok?

Prompt'un kararı. Bu değerler kullanıcıya gösterilmiyor. Frontend metni kendi i18n'inden
çizer (K20), komut çıktısı da (`gift-codes:list`) kendi biçimini kullanır (`ucretsiz`,
`%20`, `150,50 TL`). Bir `label()` metodu sunum kararını enum'a sokmuş olurdu.

## 5. İndirim hesabı neden burada değil?

Enum "hangi tür" sorusunu cevaplar, "ne kadar" sorusunu değil. Hesap kodun **satırına**
bağlı (`percent_off`, `amount_off_minor`, `tier`). Bu yüzden `GiftCode::discountFor()` içinde
duruyor. Enum'da olsaydı her çağrıda bu üç alan parametre olarak taşınırdı.

## 6. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `$code->benefit === 'free'` | Her zaman `false`: cast sonrası değer enum nesnesi | `=== GiftCodeBenefit::Free` |
| `match` içinde bir case'i unutmak | PHP `UnhandledMatchError` fırlatır | `discountFor()`'daki `match` bütün case'leri sayıyor; PHPStan eksik case'i yakalar |
| CHECK listesini elle yazmak | Enum büyüyünce kısıt eskir | `values()` |

## 7. Deneme

```powershell
php artisan tinker
>>> App\Enums\GiftCodeBenefit::values()
=> ["free", "percent", "fixed"]
>>> App\Enums\GiftCodeBenefit::tryFrom('bogo')
=> null
```
