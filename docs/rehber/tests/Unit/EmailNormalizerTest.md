# `tests/Unit/EmailNormalizerTest.php`

> **Faz:** 10 — Sertleştirme, adım 10.12 (normalizer'la aynı adım)
> **Test edilen:** [`../../app/Support/EmailNormalizer.md`](../../app/Support/EmailNormalizer.md)
> **Kurallar:** **T6** (varlık ve yokluk birlikte) · **D-5** · denetim **K-3**
> **Önce oku:** [`OrderScopeTest.md`](OrderScopeTest.md) — `Tests\TestCase` yerine
> PHPUnit'in `TestCase`'inin neden seçildiği

---

## 1. Neden birim testi?

`EmailNormalizer::normalize()` saf bir fonksiyon: girdi bir string, çıktı bir
string. Laravel'i ayağa kaldırmaya, veritabanına dokunmaya gerek yok; sekiz
test milisaniyeler içinde biter.

Uçtan uca kanıt (kayıt → giriş, ikinci kayıt reddi) ayrı: `AuthTest`, adım
10.15. İkisi farklı soruları cevaplar:

| Test | Soru |
|---|---|
| Bu dosya | Fonksiyon **doğru mu**? |
| `AuthTest` (10.15) | Dört çağrı yeri fonksiyonu **gerçekten kullanıyor mu**? |

---

## 2. 🔴 Neden `bin2hex` ile de karşılaştırılıyor?

```php
$this->assertSame(bin2hex($expected), bin2hex($actual));
$this->assertSame($expected, $actual);
```

`i` ile `i̇` ekranda **aynı** görünür. Yalnızca metin karşılaştırılsaydı kırmızı
bir test şöyle derdi:

```
Failed asserting that two strings are identical.
-'ismail@gmail.com'
+'i̇smail@gmail.com'
```

İki satır okuyana özdeş görünür. `bin2hex` farkı görünür kılar:

```
-'69736d61696c…'
+'69cc87736d61696c…'
```

İkinci `assertSame` gereksiz görünebilir. Oysa testin okunur beklentisi
(`'ismail@gmail.com'`) o satırda duruyor. Onaltılık karşılaştırma yalnızca
**hata mesajı** için var.

---

## 3. Testler

| Test | T6 yarısı | Ne kanıtlar |
|---|---|---|
| `it_folds_every_form_of_the_turkish_capital_i_into_a_plain_i` (×4) | Varlık | `İ` (U+0130), `I`+U+0307, eski DB satırı (`i`+U+0307), birden çok `İ` (yerel kısım **ve** alan adı) |
| `it_trims_and_lowercases_plain_ascii` | Varlık | Faz 2'nin işi hâlâ yapılıyor (`trim`, ASCII küçültme) |
| `it_leaves_the_dotless_i_alone` | 🔴 **Yokluk** | `ı` ayrı bir harf; `i`'ye dönseydi iki farklı adres aynı hesaba düşerdi |
| `it_lowercases_other_turkish_letters_to_single_code_points` | Yokluk | Kural yalnızca `İ`'ye dokunuyor; `ŞĞÜÖÇ` zaten doğru iniyor |
| `it_is_idempotent` | — | Kanonik biçim sabit bir nokta: `normalize(normalize(x)) === normalize(x)`. 10.14'ün komutu bunu varsayar |

Veri sağlayıcının (`#[DataProvider]`) anahtarları Türkçe açıklama: kırmızı bir
satırda PHPUnit hangi biçimin düştüğünü **adıyla** söyler.

---

## 4. Mutasyon kanıtı (27 Eylül 2026, İsmail'in makinesi, PHP 8.5)

| Mutasyon (`EmailNormalizer.php`) | Sonuç |
|---|---|
| `str_replace`'i kaldır (Faz 9'un hâli: yalnızca `mb_strtolower(trim(…))`) | 4 test kırıldı (`İ`'nin dört satırı) |
| `ı`'yı da `i`'ye çevir | 1 test kırıldı (`it_leaves_the_dotless_i_alone`) |
| `trim`'i kaldır | 1 test kırıldı (`it_trims_and_lowercases_plain_ascii`) |

İkinci mutasyonun ilk denemesi **yanlış kuruldu**. Kabuktaki kaçış yüzünden
`"\u{0131}"` yerine düz `"{0131}"` yazıldı, test haklı olarak yeşil kaldı.
Mutasyonun kendisi doğrulanmadan sonucu okunmaz; mutasyonlu satırı ekrana
basmak bu yüzden adımın parçası.

---

## 5. Çalıştırma

```powershell
php artisan test --filter=EmailNormalizerTest
# 8 passed
```
