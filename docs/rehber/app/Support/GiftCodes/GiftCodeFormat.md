# `app/Support/GiftCodes/GiftCodeFormat.php` — kodun biçimi

> **Faz:** 11, adım 11.3 · **Karar:** K109 (D3)
> **Test:** `tests/Unit/GiftCodeFormatTest.php` (18 durum)

---

## 1. Ne yapar?

| Metot | Girdi → çıktı |
|---|---|
| `normalize()` | `" dk-7kq2 m9xa "` → `"DK7KQ2M9XA"` (büyük harf, boşluk ve tire atılır) |
| `hash()` | `sha256(normalize(kod))`, 64 onaltılık karakter |
| `hint()` | Normalize edilmiş kodun son 4 karakteri |
| `generate()` | `DK-XXXX-XXXX-XXXX`, kriptografik rastgele |
| `isValidCustom()` | Elle girilen kod için 4–32 karakter, yalnızca `A-Z0-9` (normalize sonrası) |

## 2. 🔴 Neden önce normalize, sonra özet?

Veritabanında yalnızca özet var (K109). Kullanıcı kodu küçük harfle, tireli ya da boşluklu
yazabilir. Özet bir harf farkında bile tamamen değişir:

```
sha256("DUGUN2026") = 5e3c…
sha256("dugun2026") = a81f…   ← bambaşka
```

Normalize edilmeden özetlenseydi `dugun2026` yazan kullanıcı "geçersiz kod" görürdü. Bu yüzden
**her** özet `normalize()`'dan geçer: kod yazılırken de (komutlar), aranırken de (checkout,
önizleme). İki yerin aynı fonksiyonu kullanması bu yüzden kritik (C3). Biri kendi
normalleştirmesini yazsaydı kodlar sessizce eşleşmezdi.

## 3. Alfabe: `23456789ABCDEFGHJKMNPQRSTVWXYZ` (30 sembol)

Crockford base32'den başlandı (I, L, O, U zaten yok), sonra **0 ve 1 de** çıkarıldı:

| Karışan | Neden |
|---|---|
| 0 / O | Basılı kartta ve ekranda ayırt edilemez |
| 1 / I / L | Aynı |
| U | Crockford'un kendi kararı (istenmeyen kelimeler oluşmasın) |

Kod bir kâğıda basılıp elle yazılacak (fuar, davetiye kartı). Yanlış okunan bir karakter
"geçersiz kod" demek. Bu da hem kullanıcıyı kızdırır hem hız sınırını boşa harcatır.

**Entropi:** 12 karakter × log₂(30) ≈ **58,9 bit**. Karşılaştırma: 1000 kodluk bir batch'ten
rastgele bir kodu tahmin etme olasılığı tek denemede yaklaşık 1000 / 2^58,9 ≈ 2·10⁻¹⁵. Hız
sınırı (saatte 30 deneme) ile bir milyon yılda bile anlamlı bir olasılığa ulaşılmaz.

## 4. `random_int`, `rand` değil

`random_int()` işletim sisteminin kriptografik kaynağını kullanır (`/dev/urandom`,
Windows'ta `BCryptGenRandom`). `rand()` / `mt_rand()` tahmin edilebilir: birkaç çıktıyı gören
biri iç durumu hesaplayıp sonraki kodları üretebilir. Para değerindeki kodda bu kabul
edilemez.

`random_int(0, 29)` ayrıca **eşit dağılımlıdır**. `random_bytes() % 30` yazılsaydı 256, 30'a
tam bölünmediği için ilk 16 sembol biraz daha sık çıkardı (modulo bias).

## 5. Elle girilen kod kuralı

`isValidCustom()`: normalize sonrası 4–32 karakter, yalnızca `[A-Z0-9]`.

- **Türkçe harf yok** (`DÜĞÜN`): kullanıcının klavyesi farklı büyük harf üretebilir
  (`i` → `I` / `İ`). ASCII'ye sınırlamak bu belirsizliği tamamen kaldırır.
- **4 karakter alt sınır:** `AB` gibi bir kod deneme yanılmayla saniyeler içinde bulunurdu.
- Kontrol normalize **sonrası** yapılıyor: `dugun-2026` geçerli, çünkü `DUGUN2026` olur.

> ⚠️ Bu kural yalnızca **kod oluştururken** uygulanır (`gift-codes:create`). Checkout ve
> önizleme kodu biçim kontrolüne sokmaz, doğrudan özetleyip arar. Biçim hatası ayrı bir hata
> kodu döndürseydi "bu biçimde kod yok" ile "bu kod yok" ayrışırdı (K113).

## 6. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Kodu normalize etmeden özetlemek | Küçük harfle yazan kullanıcı "geçersiz" görür |
| `strtoupper` yerine `mb_strtoupper` + Türkçe yerel | `i` → `İ` olur, ASCII kodla eşleşmez |
| `rand()` ile üretmek | Kodlar tahmin edilebilir |
| `bin2hex(random_bytes(6))` | 0/O ve 1/l karışır, `a-f` dışında harf yok (yalnızca 16 sembol) |

## 7. Deneme

```powershell
php artisan tinker
>>> use App\Support\GiftCodes\GiftCodeFormat;
>>> GiftCodeFormat::generate()
=> "DK-7KQ2-M9XA-P4TR"
>>> GiftCodeFormat::hash('dk-7kq2 m9xa p4tr') === GiftCodeFormat::hash('DK7KQ2M9XAP4TR')
=> true
>>> GiftCodeFormat::isValidCustom('DÜĞÜN')
=> false
```
