# `app/Support/EmailNormalizer.php`

> **Faz:** 10 — Sertleştirme, adım 10.12 · **Kurallar:** C3 · ders 52
> **Karar:** **D-5** (27 Eylül 2026, İsmail): `İ→i` dönüşümü, ASCII dışını reddetmek **değil**
> **Kapattığı bulgu:** test denetimi **K-3** (24 Eylül 2026)
> **Önce oku:** [`IpHasher.md`](IpHasher.md) — aynı klasör, aynı *"tek kural, tek yer"* gerekçesi

---

## 1. Problem: ekranda aynı, baytta farklı

Faz 2'den beri e-posta dört yerde `mb_strtolower(trim(...))` ile küçültülüyordu.
Türkçe büyük `İ` dışında her harf için doğru:

```php
mb_strtolower('İsmail@Gmail.com');   // "i̇smail@gmail.com"
```

Sonuç ekranda `ismail` gibi görünür. Baytlara bakınca:

```
i       → 69
i̇       → 69 cc 87      (i + U+0307 COMBINING DOT ABOVE)
```

Unicode'un **varsayılan** küçük harf eşlemesinde `İ` (U+0130) tek bir harf değil,
**iki** kod noktası olur: `i` ve üstüne binen bir nokta. Bu Unicode'un hatası
değil: `İ`'nin küçüğü Türkçede `i`, ama *"bu metin Türkçe mi?"* sorusunu
`mb_strtolower()` soramaz. Noktayı korumak, bilgiyi kaybetmemenin güvenli
yolu.

### Denetimde üretilen senaryo (K-3)

| Adım | İstek | Beklenen | Gerçek (Faz 9) |
|---|---|---|---|
| 1 | Kayıt: `İsmail.Cankaya@gmail.com` | 201 | 201 → `i̇smail.cankaya@…` yazıldı |
| 2 | Giriş: `ismail.cankaya@gmail.com` | 200 | 🔴 **401** — parola doğru |
| 3 | Kayıt: `ismail.cankaya@gmail.com` | `REGISTRATION_FAILED` | 🔴 **201** — ikinci hesap |

Üçüncü adım asıl zarar: kullanıcının ödediği davetiye birinci hesapta kalır,
kendisi ikincisinde oturur.

---

## 2. Kural: "küçült, sonra `i̇`→`i`" (planın sırası değil)

Plan (10.12) şunu diyordu: *"`trim` → `İ→i` → `mb_strtolower`"*. Uygulanan:

```php
str_replace("i\u{0307}", 'i', mb_strtolower(trim($email)));
```

Fark şu: aynı harf **iki biçimde** gelebilir.

| Girdi | Kod noktaları | Nereden gelir | Planın sırası | Uygulanan |
|---|---|---|---|---|
| `İ` | U+0130 | Türkçe klavye | ✅ `i` | ✅ `i` |
| `İ` | U+0049 U+0307 | Ayrıştırılmış (NFD) biçim — bazı kopyala-yapıştır kaynakları | ❌ `i̇` (`İ` karakteri yok, eşleşmez) | ✅ `i` |
| `i̇` | U+0069 U+0307 | **Veritabanımızda** Faz 9'a kadar yazılmış satırlar | ❌ `i̇` | ✅ `i` |

Küçültüldükten sonra üç biçim de aynı diziye (`i` + U+0307) düşüyor; o diziyi
değiştirmek üçünü birden kapsıyor. Üçüncü satır özellikle önemli: 10.14'ün
bakım komutu mevcut satırları **aynı fonksiyonla** düzeltecek. Normalizer
kendi eski çıktısını tanımasaydı ikinci bir kural gerekirdi.

> Bu, planın metninden bir sapma. Plana §9'da kayıt olarak işlenecek.

### Neyi **bilerek** dönüştürmüyor?

| Girdi | Sonuç | Neden |
|---|---|---|
| `ı` (U+0131, noktasız) | `ı` kalır | Ayrı bir harf. `ısmail@` ile `ismail@` farklı adresler |
| `I` (ASCII) | `i` | `mb_strtolower()` zaten yapıyor |
| `Ş`, `Ğ`, `Ü`… | `ş`, `ğ`, `ü` | `mb_strtolower()` bunları tek kod noktasına doğru indiriyor; sorun yalnızca `İ`'de |

---

## 3. D-5: neden reddetmek değil?

İki seçenek vardı:

| Seçenek | `İsmail@…` yazan kullanıcı | Bedeli |
|---|---|---|
| **(a) `İ→i`** ✅ | Hiçbir şey fark etmez, doğru hesaba düşer | Normalizasyon tek yerde olmalı (bu dosya) |
| (b) ASCII dışını reddet | **422**, adresi yeniden yazmak zorunda | Türkçe klavyeli kullanıcıyı cezalandırır; büyük sağlayıcılar (Gmail vb.) zaten ASCII dışı yerel kısım kabul etmiyor, yani kimseyi korumaz |

---

## 4. Neden tek yerde? (C3)

Normalizasyonu yapan dört yer vardı:

| Yer | Ne zaman çalışır | Unutulursa |
|---|---|---|
| `RegisterRequest::prepareForValidation()` | Kayıt, doğrulamadan önce | UNIQUE karşılaştırması ham adresle yapılır |
| `LoginRequest::prepareForValidation()` | Giriş, doğrulamadan önce | Kayıttaki adresi bulamaz → 401 |
| `User::setEmailAttribute()` | Modele her yazımda | Seeder/tinker ile yazılan adres farklı biçimde saklanır |
| `AppServiceProvider::authLimits()` | Hız sınırı anahtarı, **doğrulamadan önce** | 🔴 `İsmail@` ve `ismail@` **iki ayrı kova**: saldırgana iki kat deneme hakkı |

Dördüncüsü sessiz bir güvenlik açığıdır: hiçbir test kırılmaz, hiçbir kullanıcı
şikâyet etmez. Dört yerin aynı fonksiyonu çağırması **10.13**'ün işi. Bu dosya
yalnızca fonksiyonu kuruyor.

---

## 5. Neden statik metot?

[`IpHasher.md`](IpHasher.md) §4 ile aynı gerekçe: saf fonksiyon. Aynı girdi
her zaman aynı çıktıyı verir, durum yok, yan etki yok, ikinci bir uygulaması
olamaz. Arayüz koysaydık dikiş yerinin öbür tarafı boş kalırdı (ders 52).

Sabit (`DOTTED_SMALL_I`) neden var? `"i\u{0307}"` koda düz yazılsaydı ekranda
`i̇` ya da `i` gibi görünürdü. Okuyan kişi neyin neyle değiştirildiğini
göremezdi. Adı olan bir sabit, görünmeyen bir karakterin tek belgesidir.

---

## 6. Hangi test korur?

| Test | Ne kanıtlar |
|---|---|
| `tests/Unit/EmailNormalizerTest.php` (10.12) | Üç `İ` biçimi, `trim`, ASCII büyük harf, noktasız `ı`'nın korunması |
| `tests/Feature/AuthTest.php` (10.15) | Uçtan uca: `İsmail@` ile kayıt → `ismail@` ile giriş 200 · ikinci kayıt reddedilir |

**Mutasyon:** `str_replace`'i kaldır (yalnızca `mb_strtolower`) → birim testinin
`İ` satırları kırılmalı.

---

## 7. Kendin dene

```powershell
php artisan tinker
```

```php
bin2hex(mb_strtolower('İ'));                              // "69cc87" — iki kod noktası
App\Support\EmailNormalizer::normalize('İsmail@Gmail.com'); // "ismail@gmail.com"
bin2hex(App\Support\EmailNormalizer::normalize('İ'));     // "69" — tek harf
```

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Kod noktası** | Unicode'da bir karakterin numarası (`U+0130`). Ekranda tek görünen şey birden çok kod noktası olabilir |
| **Birleşen işaret** (combining mark) | Kendinden önceki harfin üstüne/altına binen karakter (U+0307: üstte nokta) |
| **NFC / NFD** | Aynı metnin birleşik (`İ` = 1 kod noktası) ve ayrıştırılmış (`I` + nokta = 2) biçimleri |
| **Kanonik biçim** | Aynı anlama gelen farklı yazımların indirildiği tek biçim; karşılaştırma yalnızca onunla yapılır |
