# `phpstan.neon` — Kılavuz

> **Kod dosyası:** `phpstan.neon` (proje kökü)
> **Faz:** 0 — Zemin ve kalite kapıları (adım 0.9)
> **Kurulu sürümler:** Larastan v3.10 · PHPStan 2.2

---

## 1. Statik analiz nedir?

**Kodu çalıştırmadan** hata aramaktır. Derleyici olmayan dillerde (PHP, Python,
JavaScript) bu iş ayrı bir araca düşer.

```php
function fiyat(?User $user): int
{
    return $user->plan->price;   // ← $user null olabilir, PHPStan söyler
}
```

Bu satır **çalışana kadar** sorunsuz görünür. Kullanıcı `null` geldiği anda
üretimde `Call to a member function on null` fırlatır. PHPStan bunu **yazarken**
söyler.

### Pint'ten farkı

```
Pint      → biçim:  "bu kod çirkin yazılmış"
PHPStan   → anlam:  "bu kod null döndürebilir"
```

İkisi farklı iş yapar, birbirinin yerine geçmez. Pint kodu değiştirir, PHPStan
sadece rapor eder — **düzeltmeyi sen yaparsın.**

### Test'ten farkı

| | Test | Statik analiz |
|---|---|---|
| Ne bulur | Yanlış **davranışı** | Yanlış **tipi/yapıyı** |
| Kapsam | Yazdığın senaryolar | **Tüm kod yolları** |
| Yazma maliyeti | Her senaryo için kod | Bir kere yapılandır |

Test "bu senaryoda doğru mu?" sorar; statik analiz "bu kod hiçbir durumda
patlar mı?" sorar. Test edilmeyen bir `if` dalını test bulamaz, PHPStan bulur.

---

## 2. Larastan neden gerekli?

Laravel yoğun biçimde **çalışma anı sihri** kullanır:

```php
User::where('email', $email)->first();
$user->invitations;              // ilişki — sınıfta böyle bir özellik yok
Cache::remember('key', 60, fn () => ...);
```

Saf PHPStan bunlara bakınca haklı olarak şikâyet eder: `User` sınıfında `where`
diye statik metot **yoktur** (`__callStatic` ile üretilir), `invitations` diye
özellik **yoktur** (`__get` ile ilişkiden gelir).

**Larastan bir PHPStan eklentisidir**: Laravel'in bu kalıplarını tanır,
modelleri okuyup hangi özelliklerin/ilişkilerin var olduğunu çıkarır. Onsuz
PHPStan yüzlerce yanlış alarm üretir ve kimse kullanmaz.

```
PHPStan   = motor
Larastan  = Laravel sözlüğü
```

---

## 3. Dosya biçimi: NEON

PHPStan **NEON** biçimi kullanır — YAML'a çok benzer, Nette framework'ünden
gelir.

```neon
parameters:
    level: 5
    paths:
        - app
```

Kurallar: girinti **boşlukla** (tab değil), `-` liste öğesi, `#` yorum satırı.

> JSON'dan farkı: yorum yazılabilir. Bu yüzden dosyaya kısa açıklamalar
> koyabildim — `pint.json`'da yapamamıştım.

---

## 4. Ayarların gerekçeleri

### `includes: vendor/larastan/larastan/extension.neon`

Larastan'ın kural setini içeri alır. **Bu satır olmadan** Larastan kurulu olsa
bile devreye girmez — yüzlerce yanlış alarm alırsın.

### `paths` — ne taranıyor, ne taranmıyor

| Taranan | Neden |
|---|---|
| `app` | Asıl iş kodu |
| `config` | `env()` yanlış kullanımı, dizi yapısı |
| `database` | Migration, factory, seeder |
| `routes` | Var olmayan controller/metot referansı |
| `tests` | Test kodu da koddur; bozuk assertion yakalanır |

**Taranmayanlar:** `vendor` (başkasının kodu), `storage` ve `bootstrap/cache`
(üretilmiş dosyalar), `public` (giriş noktası).

### 🔴 `level: 5` — ve neden 9 değil

PHPStan katılığı **0–10** arasında ayarlanır. Her seviye bir öncekini kapsar.

| Seviye | Neyi yakalamaya başlar |
|---|---|
| 0 | Var olmayan sınıf, fonksiyon, metot |
| 1 | Tanımsız değişken, yazım hatası |
| 2 | Bilinmeyen metot çağrıları (`@var` dahil) |
| 3 | Dönüş tipi ve özellik atama uyumu |
| 4 | Ölü kod — hiç çalışmayan `if` dalları |
| **5** | **Fonksiyon argümanlarının tipi** ← buradayız |
| 6 | Eksik tip bildirimi (`array` yetmez, `array<int, User>` ister) |
| 7 | Birleşim (union) tiplerin yanlış dallanması |
| 8 | 🔴 `null` üzerinde metot çağrısı |
| 9 | `mixed` tipin katı denetimi |
| 10 | Örtük `mixed` bile hata |

**Neden 5'ten başlıyoruz?** Seviye 9'da başlanırsa ilk çalıştırmada yüzlerce
hata döker; hepsini düzeltmek Faz 0'ı günlerce uzatır ve öğrenme akışını keser.
Sonuç genelde şudur: geliştirici bunalır, aracı kapatır.

**Kademeli plan:**

| Ne zaman | Seviye |
|---|---|
| Şimdi (Faz 0) | 5 |
| Faz 2 sonu (Auth çalışınca) | 6 |
| Faz 5 sonu | 8 |
| Faz 9 (üretim) | 8+ hedef |

Seviye 8 özellikle değerli: **`null` üzerinde metot çağrısını** yakalar — PHP'de
en sık üretim çökmesi sebebi.

> Bu, "sonra yaparız" değil, kayıtlı bir taahhüt. Yükseltme her fazın bitiş
> ölçütüne eklenecek.

### `checkModelProperties: true`

Eloquent modellerinde **tanımsız özellik erişimini** yakalar:

```php
$user->emial;     // ← yazım hatası. Bu ayar olmadan PHPStan susar
```

PHP'de `__get` sihri yüzünden bu satır çalışma anında sessizce `null` döner —
hata bile vermez. Faz 3'te 28 alanlı `Invitation` modeliyle çalışırken bu ayar
çok işe yarayacak.

> Karşılığında modellerin PHPDoc'unun doğru olması gerekir. `@property` blokları
> eksikse alarm üretir. Faz 2'de `User` modelini yazarken bunları da yazacağız.

### `phpVersion: 80300`

Analizi PHP 8.3 kurallarına göre yapar. Senin makinende 8.4 kurulu olsa bile
**hedef sürüme göre** denetler — 8.4'e özgü bir sözdizimi kullanırsan uyarır,
çünkü üretim sunucusu 8.3 olabilir.

### `treatPhpDocTypesAsCertain: false`

PHPDoc yorumlarını **kesin doğru** kabul etmez.

```php
/** @return User */          // ← yorum böyle diyor
public function find() { ... }  // ama gerçekte null dönebilir
```

Yorumlar eskir; kod eskimez. Bu ayar PHPStan'a "yoruma değil, gerçek koda bak"
der. Yanlış PHPDoc'tan doğan gizli hataları açığa çıkarır.

### `ignoreErrors` — bilinçli olarak boş

Buraya yazılan desenler yok sayılır. **Şu an hiçbir istisna kabul edilmedi.**

🔴 **Politika: buraya bir satır eklemek için gerekçe yorumu zorunlu.** Aksi
halde araç zamanla anlamını yitirir — herkes hatayı düzeltmek yerine listeye
ekler, iki ay sonra 40 satırlık bir görmezden gelme listesi kalır.

---

## 5. Komutlar

🔴 **Doğru kullanım `composer analyse`** — `--memory-limit=1G` bayrağı içine
gömülüdür (bkz. §5.1).

```powershell
composer analyse          # tam analiz (onerilen)
composer check            # pint --test + phpstan + test  (Faz bitis olcutu)

# Ham komutlar
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/phpstan clear-result-cache        # garip sonuclarda ilk deneme
./vendor/bin/phpstan analyse --generate-baseline
```

### 5.1 🔴 "PHP memory limit: 128M" hatası

İlk çalıştırmada büyük olasılıkla şunu görürsün:

```
Child process error: PHPStan process crashed because it reached
configured PHP memory limit: 128M
while running parallel worker
```

**Bu bir hata değil, beklenen davranıştır.** Sebebi:

PHP CLI'ın varsayılan `memory_limit` değeri **128 MB**'tır. PHPStan ise tüm
kod tabanının **tip grafiğini bellekte** kurar — hangi sınıf hangi tipi
döndürüyor, hangi metot nereden çağrılıyor. Larastan bunun üstüne **Laravel'in
tüm modellerini ve cephelerini** çözümler. 128 MB yetmez.

`while running parallel worker` satırı da bilgi verir: PHPStan işi çekirdek
sayısına bölüp paralel koşar; **sınır her işçi için ayrı ayrı** uygulanır.

**Üç çözüm var, biz üçüncüsünü seçtik:**

| Çözüm | Değerlendirme |
|---|---|
| Her seferinde `--memory-limit=1G` yazmak | Unutulur. Yeni gelen aynı hataya çarpar |
| `php.ini`'de `memory_limit` yükseltmek | Makineye özel — repoya giremez, ekip arkadaşında yine patlar |
| ✅ **`composer.json`'a script eklemek** | Repoda yaşar, herkes aynı komutu çalıştırır |

```json
"analyse": [
    "@php vendor/bin/phpstan analyse --memory-limit=1G"
]
```

> **İlke:** Bir aracın doğru çalışması için gereken bayrak, **kişinin hafızasında
> değil projede** yaşamalı. Aksi halde "bende çalışıyor" sınıfı hatalar doğar.

**Hâlâ yetmezse:** `2G` dene. Sürekli artırman gerekiyorsa `phpstan.neon`'a
paralel işçi sayısını sınırlamak da seçenek:

```neon
parameters:
    parallel:
        maximumNumberOfProcesses: 2
```

Daha az işçi = daha az eşzamanlı bellek, karşılığında daha yavaş analiz.

### Baseline nedir?

Mevcut tüm hataları bir dosyaya yazıp yok sayar; **yalnızca yeni hatalar**
raporlanır. Eski projeye statik analiz eklerken hayat kurtarır.

**Bizde gerekmiyor** — proje yeni, temiz başlıyoruz. Baseline oluşturmak
"borcu ertelemek"tir; sıfırdan yazılan projede buna gerek yok.

---

## 6. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `includes` satırını unutmak | Yüzlerce yanlış Laravel alarmı | Larastan `extension.neon` şart |
| NEON'da **tab** kullanmak | Ayrıştırma hatası | Sadece boşluk |
| Seviye 9'dan başlamak | Yüzlerce hata, moral çöküşü, araç kapanır | 5'ten başla, kademeli çık |
| Hatayı `ignoreErrors`'a atmak | Araç anlamını yitirir | Önce düzelt; olmuyorsa **gerekçe yaz** |
| PHPStan'ı Pint sanmak | "Neden formatlamadı?" | PHPStan rapor eder, değiştirmez |
| `vendor`'ı `paths`'e eklemek | Dakikalarca sürer, binlerce alarm | Sadece kendi kodun |
| Bellek hatası alıp pes etmek | — | `--memory-limit=1G` |
| Cache yüzünden bayat sonuç | Düzelttiğin hata görünmeye devam eder | `clear-result-cache` |

---

## 7. Deneme adımları

**1. Çalıştır:**

```powershell
./vendor/bin/phpstan analyse
```

Şu an `app/` altında 5 dosya var (`SubscriptionTier`, `User`,
`AppServiceProvider`, `Controller`, boş `PublishInvitationAction`). Beklenti:
**temiz geçmesi**. Hata çıkarsa birlikte bakarız — asıl öğrenme orada.

**2. Bilerek hata üret, aracın çalıştığını gör.**
`app/Enums/SubscriptionTier.php` içine geçici olarak ekle:

```php
public function hataliMetot(): int
{
    return 'metin';   // int bekleniyor, string dönüyor
}
```

Çalıştır → `Method ... should return int but returns string.` demeli.
**Sonra sil.**

> Bu adımı atlama. Bir aracın *çalıştığını* görmek, kurulu olduğunu varsaymaktan
> farklıdır. Yanlış yapılandırılmış bir denetleyici, hiç olmamasından kötüdür —
> sahte güven verir.

**3. Seviyeyi geçici yükseltip farkı gör** (isteğe bağlı):

`phpstan.neon` içinde `level: 5` → `level: 9` yap, çalıştır, çıkan hataları oku,
**geri al**. Seviyelerin ne anlama geldiğini somut görürsün.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Statik analiz** | Kodu çalıştırmadan inceleme |
| **Dinamik analiz** | Çalıştırarak inceleme (test, profiler) |
| **Yanlış alarm** (false positive) | Hata olmayan yere hata demek |
| **Baseline** | Mevcut hataları dondurup yalnızca yenileri raporlama |
| **NEON** | PHPStan'ın yapılandırma biçimi, YAML benzeri |
| **PHPDoc** | `/** @param int $x */` biçimli tip yorumları |
| **`__get` / `__callStatic`** | PHP'nin sihirli metotları. Laravel'in Eloquent sihri bunlara dayanır |
| **Union tip** | `int|string` — birden fazla tip olabilen değer |
| **`mixed`** | "Herhangi bir tip" — tip bilgisinin yokluğu |
| **Ölü kod** | Hiçbir koşulda çalışmayan kod |

---

## 9. Bağlantılar

| İlgili | Nerede |
|---|---|
| Biçimlendirici (tamamlayıcı araç) | [`pint.md`](pint.md) |
| `declare(strict_types=1)` gerekçesi | [`pint.md`](pint.md) §4 |
| Faz 0 bitti ölçütü | `docs/07-GELISTIRME-YOL-HARITASI.md` → Faz 0 |
| Kod standartları | `CLAUDE.md` |
