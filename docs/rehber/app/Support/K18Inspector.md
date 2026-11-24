# `app/Support/K18Inspector.php` — K18 ölçümü (tek dosya)

> **Faz:** 19.28 (`ARC-03`, K259) · **Kullanan:** [`k18:check`](../Console/Commands/CheckK18.md) ·
> **Test:** `tests/Feature/K18CheckTest.php`

## Kural (kodun başındaki sabitler)

| Sabit | Değer | Anlamı |
|---|---|---|
| `MAX_LINES` | 3 | Bir yorum bloğunda en çok 3 **içerik** satırı (K18: "1–3 satır") |
| `ROOTS` | `app/`, `routes/`, `config/`, `database/` | Ölçülen kökler; `tests/` ve `bootstrap/` dışarıda |
| `TYPE_TAGS` | `@param`, `@return`, `@var`, `@property*`, `@template*`, `@extends`, `@implements`, `@use`, `@mixin`, `@method`, `@throws`, `@phpstan-*`, `@psalm-*` | Tip ek açıklaması: PHPStan'in ihtiyacı, açıklama değil; **sayılmaz** |

İki ihlal türü:

1. **Kılavuz yok:** `app/Foo/Bar.php` → `docs/rehber/app/Foo/Bar.md` dosyası yoksa.
2. **Uzun blok:** ardışık yorum satırlarında 3'ten fazla içerik satırı.

## Blok nasıl sayılır

PHP'nin kendi tokenizer'ı (`token_get_all`) kullanılır; düzenli ifadeyle satır taramak `'// bu bir dize'` gibi
dizeleri yorum sanırdı.

- `T_COMMENT` (`//`, `#`, `/* */`) ve `T_DOC_COMMENT` (`/** */`) belirteçleri, **arada boş satır ya da kod yoksa**
  tek blok sayılır. Boş satır bloğu böler; kod satırı bitirir.
- İçerik satırı = yorum işaretleri (`/**`, `*`, `*/`, `//`, `#`) atıldıktan sonra boş olmayan satır.
  Yani `/**` ve `*/` tek başına satır saymaz; 3 satırlık metinli bir docblock geçer.
- Tip etiketi satırları sayılmaz. Etiket çok satırlı bir tipe açılırsa (`@return array{` … `}`) parantezler
  kapanana kadar devam satırları da etiketin parçası sayılır.
- `#[Attribute]` yorum değildir (`T_ATTRIBUTE`); sayılmaz.

## Sık hata

| Hata | Neden yanlış |
|---|---|
| Açıklamayı `@see` / `@note` gibi uydurma etiketlere yazmak | Yalnızca tip etiketleri muaf; başka etiket metin sayılır |
| Uzun yorumu kod satırlarının sonuna (`$x = 1; // ...`) dağıtmak | Ardışık satırlar yine tek blok olur |
| Eşiği "3'ten çok" yerine "3 ve üstü" yazmak | 3 satır kurala uygun; mutant testi bunu yakalar |

## Terimler

- **Token (belirteç):** PHP derleyicisinin kaynak kodu böldüğü en küçük anlamlı parça (anahtar kelime, dize, yorum…).
- **Docblock:** `/** ... */` biçimli yorum; IDE ve PHPStan içindeki `@` etiketlerini okur.
