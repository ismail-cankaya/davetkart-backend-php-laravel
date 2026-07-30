# `pint.json` — Kılavuz

> **Kod dosyası:** `pint.json` (proje kökü)
> **Faz:** 0 — Zemin ve kalite kapıları (adım 0.8)

---

## 1. Pint nedir, ne değildir?

**Laravel Pint bir kod formatlayıcıdır.** Kodun *nasıl göründüğünü* düzenler:
girinti, boşluk, tırnak tipi, `use` sırası, süslü parantez konumu.

**Ne yapmaz:** kodun *ne yaptığına* karışmaz. Hatalı mantığı bulmaz, güvenlik
açığı aramaz, tip uyuşmazlığı söylemez. O iş **Larastan**'ın (adım 0.9).

```
Pint      → "bu kod çirkin yazılmış"     (biçim)
Larastan  → "bu kod null döndürebilir"   (anlam)
```

İkisi birbirinin yerine geçmez.

### Zaten kuruluydu

`composer.json` içindeki `require-dev` bölümünde `laravel/pint` var — Laravel 13
iskeletiyle geliyor. Yol haritasındaki 0.8 adımı "kurulum" diyordu, gerçekte
sadece **yapılandırma** gerekiyordu. Bu dosya o yapılandırmadır.

### Altında ne var?

Pint, **PHP-CS-Fixer** adlı olgun bir aracın Laravel'e uyarlanmış arayüzüdür.
Kuralların tamamı PHP-CS-Fixer kurallarıdır; Pint sadece Laravel'in tercih
ettiği seti hazır sunar ve komut satırını sadeleştirir.

---

## 2. Neden formatlayıcı kullanıyoruz?

### Sebep 1 — Tartışmayı bitirmek

Kod stili tartışmaları (tek tırnak mı çift mi, süslü parantez alt satıra mı)
**hiçbir değer üretmez** ama zaman yer. Formatlayıcı kararı bir kez verir ve
konuyu kapatır. Tek geliştirici olsan bile: 3 ay önceki halinle tartışmazsın.

### Sebep 2 — Git diff'lerini temiz tutmak

Formatlayıcı yoksa aynı dosyaya iki kez dokunduğunda girinti farkları oluşur.
Sonra `git diff` şöyle görünür:

```diff
- 47 satır değişti
```

Oysa gerçekte 2 satır iş yapan değişiklik, 45 satır boşluk oynaması vardır. Kod
incelemesi imkânsızlaşır. Formatlayıcı bunu sıfırlar: **diff'te görünen her
satır gerçek bir değişikliktir.**

### Sebep 3 — Standarda uymak

`preset: "laravel"` altında **PSR-12** yatar: PHP topluluğunun ortak stil
standardı. Buna uymak, kodun başka bir PHP geliştiricisi için tanıdık olması
demektir.

---

## 3. `preset: "laravel"` ne getiriyor?

Preset, hazır kural paketidir. Pint'in seçenekleri: `laravel`, `psr12`,
`symfony`, `empty`.

`laravel` preseti PSR-12'yi alır ve Laravel'in tercihlerini ekler:

| Kural | Etkisi |
|---|---|
| Tek tırnak tercihi | `"metin"` → `'metin'` (değişken içermiyorsa) |
| Girinti | 4 boşluk, tab değil |
| Süslü parantez | Sınıf/fonksiyonda **alt satıra**, `if`/`foreach`'te **aynı satırda** |
| Satır sonu | Unix (`\n`) — Windows'ta yazsan bile |
| `use` blokları | Alfabetik sıralı, boş satırsız |
| Dosya sonu | Tek boş satırla biter |
| `<?php` | Kapanış `?>` etiketi silinir |

`rules` bölümüne yazdıklarımız bu presetin **üzerine** eklenir veya onu ezer.

---

## 4. Eklediğimiz kurallar ve gerekçeleri

### 🔴 `declare_strict_types: true`

**En önemli kural.** Her PHP dosyasının başına şunu ekler:

```php
<?php

declare(strict_types=1);
```

PHP varsayılan olarak **hoşgörülü** (coercive) tip modundadır: `int` bekleyen
bir fonksiyona `"5"` verirsen sessizce dönüştürür.

```php
function fiyat(int $kurus): int { return $kurus * 2; }

fiyat("249");    // strict yok  → 498       sessizce dönüştürdü
fiyat("249");    // strict var  → TypeError hemen söyledi
```

Kolaylık gibi görünür, gerçekte **hata gizler**. Somut senaryo: ödeme tutarı
frontend'den `"249.00"` metin olarak gelir, `int` alana atanır, PHP `249` yapar
— kuruşlar sessizce uçar. Strict modda bu satır patlar ve sen laptop'ta görürsün.

Bunu elle her dosyaya yazmak yerine Pint'e yaptırıyoruz — **unutma ihtimali
sıfırlanır.**

> ⚠️ Bu kural PHP-CS-Fixer'da **"riskli"** (risky) sayılır: davranışı değiştirir,
> sadece görünümü değil. Bilinçli olarak açıyoruz. İlk çalıştırmada iskeletteki
> ~20 dosya değişecek — sonrasında `php artisan test` koşturup `git diff` ile
> gözden geçir.

### `fully_qualified_strict_types` + `global_namespace_import`

İkisi birlikte `use` ifadelerini düzenler.

```php
// Öncesi
public function handle(\Illuminate\Http\Request $request): \App\Models\User

// Sonrası
use App\Models\User;
use Illuminate\Http\Request;

public function handle(Request $request): User
```

Uzun tam nitelikli adlar (`\Foo\Bar\Baz`) `use` bloğuna taşınır, gövdede kısa ad
kalır. İmzalar okunur hale gelir.

**`import_functions` ve `import_constants` neden `false`?** `count()`, `strlen()`
gibi yerleşik fonksiyonları da `use function count;` diye içeri almak teknik
olarak mümkün ama gereksiz gürültü üretir. Sınıflar taşınır, fonksiyonlar
taşınmaz.

### `no_unused_imports`

Kullanılmayan `use` satırlarını **siler**. Refactor sonrası biriken ölü
import'lar temizlenir. Ölü kod okuyucuyu yanıltır: "bu sınıf burada kullanılıyor
mu?" sorusuna yanlış cevap verir.

### `ordered_imports: alpha`

`use` satırlarını alfabetik sıralar. Amaç estetik değil, **birleştirme
çakışmalarını (merge conflict) azaltmak**: iki kişi farklı import eklerse
belirlenmiş sırada farklı satırlara düşerler.

### `ordered_class_elements`

Sınıf içi üye sırasını sabitler:

```
use (trait) → case (enum) → sabit → özellik → __construct → sihirli metotlar → metotlar
```

Her sınıf aynı iskelete sahip olur. Bir `Action` sınıfını açtığında
`__construct`'ın nerede olduğunu aramazsın — hep aynı yerdedir.

### `class_attributes_separation`

Metotlar, özellikler ve sabitler arasında **tam bir boş satır** bırakır. Görsel
nefes alanı; yapışık metotlar okunmaz.

### `trailing_comma_in_multiline`

Çok satırlı dizi/argüman listelerinde son öğeden sonra virgül bırakır:

```php
$plans = [
    'standart',
    'gold',
    'elit',        // ← bu virgül
];
```

**Neden?** Yeni öğe eklerken tek satır değişir. Virgül olmasaydı `'elit'`
satırına virgül eklemen gerekirdi → diff'te 2 satır → gereksiz gürültü. Git
geçmişini temiz tutan küçük ama etkili bir alışkanlık.

### `phpdoc_separation`, `phpdoc_trim`, `no_empty_phpdoc`

Doküman bloklarını toparlar: farklı etiket grupları arasına boş satır koyar,
baştaki/sondaki boş satırları kırpar, tamamen boş `/** */` bloklarını siler.

**K18 ile uyumlu:** kodda kısa yorum tutuyoruz, detay `docs/rehber/`'de. Ölü
PHPDoc bloklarının temizlenmesi bu kararı destekler.

---

## 5. 🔴 Bilinçli olarak AÇMADIĞIM kurallar

Bir kararın gerekçesi kadar, **alınmayan kararın** gerekçesi de önemlidir.

### `strict_comparison` — kapalı

Bu kural `==` ifadelerini otomatik `===` yapar.

```php
if ($status == 'published')     // gevşek: '0' == 0 → true
if ($status === 'published')    // katı: tip de eşleşmeli
```

`===` kullanmak **doğru** olan. Ama bunu **otomatik dönüştürmek tehlikeli**:
bazı yerlerde gevşek karşılaştırma bilinçli tercihtir. Formatlayıcı bunu
ayırt edemez ve **çalışan kodu sessizce bozabilir**.

**Politikamız:** yeni kodu zaten `===` ile yazacağız. Kaçan olursa Larastan
uyaracak — formatlayıcı değil, statik çözümleyici.

### `strict_param` — kapalı

`in_array($a, $b)` çağrılarına üçüncü parametre `true` ekler. Aynı gerekçe:
riskli, davranış değiştirir, otomatikleştirilmemeli.

### `void_return` — kapalı

Dönüş tipi yazılmamış metotlara `: void` ekler. Sorun: metot **ileride** değer
döndürecek şekilde tasarlanmışsa, araç bunu bilemez. Dönüş tipini geliştirici
bilinçli yazmalı.

> **Genel ilke:** *Formatlayıcı görünümü değiştirir, anlamı değiştirmez.*
> Anlamı değiştiren "riskli" kuralları tek tek değerlendirdik; sadece
> `declare_strict_types`'ı açtık çünkü kazancı riskini kat kat aşıyor.

---

## 6. `exclude` — hangi klasörlere dokunulmuyor

| Klasör | Neden |
|---|---|
| `vendor` | Başkasının kodu. Formatlarsan `composer update` her şeyi geri alır |
| `node_modules` | Aynı gerekçe, JS tarafı |
| `storage` | Log, cache, oturum dosyaları — üretilmiş içerik |
| `bootstrap/cache` | `config:cache` çıktısı — üretilmiş |
| `public/build` | Vite derleme çıktısı |

**Ortak ilke: üretilmiş veya dışarıdan gelen dosyaya dokunulmaz.** Bunları
formatlamak boşa CPU harcar, `git status`'u kirletir ve bir sonraki derlemede
geri alınır.

---

## 7. Komutlar

```powershell
# Tüm projeyi formatla (dosyaları DEĞİŞTİRİR)
./vendor/bin/pint

# Sadece kontrol et, hiçbir şeyi değiştirme  ← CI ve commit öncesi
./vendor/bin/pint --test

# Sadece git'te değişmiş dosyaları formatla  ← günlük kullanım, hızlı
./vendor/bin/pint --dirty

# Ne değişeceğini satır satır göster
./vendor/bin/pint --test -v
```

**`--test` neden önemli?** Değişiklik yapmaz, sadece **çıkış kodu** döndürür:
her şey düzgünse `0`, değilse `1`. Faz 0'ın "bitti" ölçütlerinden biri budur.
İleride CI kurarsak (GitHub Actions) formatsız kod birleştirilemez hale gelir.

**Günlük ritim:** kod yaz → `pint --dirty` → `git commit`.

---

## 8. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `pint.json`'da yorum satırı (`//`) | JSON yorumu desteklemez, çöker | Yorumu bu kılavuza yaz |
| Son öğeden sonra virgül (JSON'da) | Sözdizimi hatası | JSON'da sondaki virgül **yasak** — PHP'de serbest |
| `--test`'siz çalıştırıp commit'lememek | Bir sonraki çalıştırma dev diff üretir | Formatla, sonra commit'le |
| `vendor`'ı `exclude`'dan çıkarmak | Binlerce dosya değişir, `composer update` geri alır | Listede kalsın |
| Pint ile Larastan'ı karıştırmak | "Pint neden bu hatayı bulmadı?" | Pint biçim, Larastan anlam |
| Riskli kuralları toptan açmak | Çalışan kod sessizce bozulur | Tek tek değerlendir |
| Formatlamayı en sona bırakmak | Yüzlerce dosyalık gürültülü commit | Her dosya sonrası `--dirty` |

---

## 9. Deneme adımları

**1. Önce ne değişeceğini gör** (hiçbir şeyi değiştirmez):

```powershell
./vendor/bin/pint --test -v
```

Muhtemelen 20+ dosya listelenecek — iskelet dosyalarına `declare(strict_types=1)`
eklenecek. **Bu beklenen davranış.**

**2. Uygula:**

```powershell
./vendor/bin/pint
```

**3. 🔴 Ne değiştiğini incele** — bu adımı atlama:

```powershell
git diff --stat
git diff config/davetkart.php
```

**4. Hiçbir şey bozulmadığını doğrula:**

```powershell
php artisan test
php artisan config:clear
php artisan tinker
```

Tinker'da: `config('davetkart.tiers.gold.price');` → `399` dönmeli.

**5. Tekrar kontrol et:**

```powershell
./vendor/bin/pint --test
```

Beklenen: `PASS` — artık formatlanacak bir şey yok.

> **Sorun çıkarsa geri alma kolay:** `git checkout .` ile tüm biçim
> değişiklikleri geri döner. Bu yüzden Pint'i **temiz bir git ağacında**
> çalıştırmak iyi alışkanlıktır.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Formatlayıcı** (formatter) | Kodun görünümünü kurallara göre düzenleyen araç |
| **Statik çözümleyici** (static analyzer) | Kodu **çalıştırmadan** mantık hatası arayan araç |
| **PSR-12** | PHP topluluğunun kod stili standardı |
| **Preset** | Hazır kural paketi |
| **Riskli kural** (risky rule) | Sadece görünümü değil **davranışı** da değiştirebilen kural |
| **Coercive typing** | PHP'nin varsayılan hoşgörülü tip modu — sessiz dönüşüm yapar |
| **Strict typing** | `declare(strict_types=1)` ile açılan katı mod |
| **Çıkış kodu** (exit code) | Komutun bitince döndürdüğü sayı. `0` = başarı |
| **CI** | *Continuous Integration* — her push'ta testleri koşturan otomasyon |
| **Diff gürültüsü** | Anlamlı değişikliği gizleyen biçimsel farklar |

---

## 11. Bağlantılar

| İlgili | Nerede |
|---|---|
| Statik analiz (tamamlayıcı araç) | `docs/rehber/phpstan.md` (adım 0.9) |
| `declare(strict_types=1)` ayrıntısı | [`lang/tr/validation.md`](lang/tr/validation.md) §2 |
| Faz 0 bitti ölçütü | `docs/07-GELISTIRME-YOL-HARITASI.md` → Faz 0 |
| Kod standartları (bağlayıcı) | `CLAUDE.md` |
