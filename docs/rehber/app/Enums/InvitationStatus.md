# `app/Enums/InvitationStatus.php`

> **Kod dosyası:** `app/Enums/InvitationStatus.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.1
> **Bu dosya kimin için:** PHP'yi ilk kez gören biri için. Baştan sona okunur.

---

## 1. Bu dosya ne işe yarar?

Bir davetiye satırı veritabanında doğduğu andan itibaren bir **durumda** olur.
İki durumumuz var:

| Durum | Anlamı | Misafir görebilir mi? |
|---|---|---|
| `saved` | Hesaba kaydedilmiş, üzerinde çalışılıyor | ❌ Hayır |
| `published` | Yayında, link paylaşılabilir | ✅ Evet |

Bu dosya o iki durumu **tipe dönüştürür**. Yani `'saved'` bir metin parçası
olmaktan çıkıp PHP'nin tanıdığı, yanlış yazılamayan bir değer hâline gelir.

🔴 **Bu bir güvenlik sınırıdır, süsleme değil.** Faz 4'te misafir sayfası
`WHERE status = 'published'` diyecek. O sorguda `'publish'` yazılırsa hiçbir
davetiye görünmez; `'saved'` yazılırsa **taslak davetiyeler misafire sızar.**
Enum, bu iki hatayı da yazım anında imkânsız kılar.

---

## 2. Kodun satır satır PHP karşılığı

```php
<?php
```

Her PHP dosyası bu etiketle başlar. PHP başlangıçta HTML içine gömülen bir dildi;
`<?php` "buradan itibarı PHP olarak yorumla" demektir. Dosya **sadece** PHP
içeriyorsa kapanış etiketi (`?>`) **yazılmaz** — yazılırsa sonrasındaki tek bir
boşluk bile HTTP yanıtına sızar ve "headers already sent" hatası doğurur.

```php
declare(strict_types=1);
```

**Katı tip kipi.** PHP varsayılan olarak nazik davranır: `int` bekleyen bir
fonksiyona `"5"` verirsen sessizce `5`'e çevirir. Bu kolaylık, hatayı sakladığı
için tehlikelidir. `strict_types=1` bunu kapatır — yanlış tip verirsen program
**anında** `TypeError` fırlatır.

TypeScript karşılığı: `strict: true`. Farkı, PHP'de bunun **dosya başına**
açılması ve **çalışma anında** denetlenmesidir; TypeScript'te derleme anında.

Bizim `pint.json` bu satırı **zorunlu** tutar (Faz 0, K1). Yazmayı unutursan
`composer lint` ekler.

```php
namespace App\Enums;
```

**Ad alanı.** Sınıf adlarının çakışmasını önler. `App\Enums\InvitationStatus`
ile `Vendor\Paket\InvitationStatus` aynı projede yan yana yaşayabilir.

Laravel'de ad alanı **klasör yoluyla birebir eşleşmek zorundadır** (PSR-4
standardı): `app/Enums/` → `App\Enums`. Dosyayı `app/enums/` (küçük harf) içine
koyarsan Windows'ta çalışır, canlı Linux sunucusunda **"class not found"** alırsın
(Faz 0, ders 6).

```php
enum InvitationStatus: string
```

İşin özü burada. `enum` = *enumeration*, "sayılabilir küme". PHP 8.1 ile geldi.

`: string` kısmı bunu bir **backed enum** (destekli enum) yapar: her durumun
arkasında bir ham değer (`'saved'`) durur. Bu ham değer veritabanına yazılan ve
JSON'a çıkan şeydir.

```php
case Saved = 'saved';
case Published = 'published';
```

İki üye. Dikkat: **isim `Saved`, değer `'saved'`.**

- `InvitationStatus::Saved` → PHP tarafında kullandığın nesne
- `InvitationStatus::Saved->value` → `'saved'` metni, DB ve JSON'a giden

Bu ayrım önemli. Yarın frontend `'saved'` yerine `'draft'` demeye başlarsa
**sadece bu satırdaki tırnak içi değişir**, kodun geri kalanı `::Saved` demeye
devam eder.

---

## 3. Enum nedir, neden "sihirli string" yasak?

Enum'suz dünyada durum şöyle yazılır:

```php
$invitation->status = 'published';   // sihirli string
```

Bunun dört ayrı sorunu var:

| Sorun | Örnek | Ne zaman fark edersin? |
|---|---|---|
| Yazım hatası sessizdir | `'publised'` | Üretimde, kullanıcı şikâyet edince |
| Geçerli değerler belgesizdir | `'live'` mi `'published'` mü? | Kodu arayınca, tahminle |
| Editör yardım edemez | Tamamlama yok | Hiç |
| Değer değişince her yeri elle bulmalısın | 40 dosyada arama | Bir tanesini kaçırınca |

Enum ile:

```php
$invitation->status = InvitationStatus::Published;   // tip
```

`InvitationStatus::Publised` yazarsan PHP **dosyayı yüklerken** patlar. Editör
`::` yazdığın an iki seçeneği listeler. PHPStan (bizim statik analizcimiz)
geçersiz bir durumu fark eder ve `composer analyse` kırılır — yani hata **senin
laptop'unda**, kullanıcıya ulaşmadan yakalanır.

`CLAUDE.md` §1 bunu kural olarak yazar: *"Uygulama içinde kesinlikle sihirli
string kullanılmamalıdır. Durumlar ve tipler için mutlaka PHP 8 Backed Enum
kullanılmalıdır."*

### Backed enum ile pure enum farkı

```php
enum Renk { case Kirmizi; case Mavi; }                  // pure — ham değeri yok
enum Renk: string { case Kirmizi = 'kirmizi'; }         // backed — ham değeri var
```

Veritabanına yazılacak veya JSON'a çıkacak her enum **backed** olmalıdır; aksi
hâlde "bunu nasıl saklayacağım?" sorusunun cevabı yoktur. Bizim tüm enum'larımız
backed'dir.

---

## 4. Neden iki durum, neden üç değil?

Plan (`03-MIMARI-PLAN.md`) başlangıçta üç durum öngörüyordu:
`draft | saved | published`. Faz 3'te bu **ikiye indirildi.**

Bir durum makinesine durum eklemenin ölçütü şudur:

> **Bir durum, ancak onu doğuran bir olay varsa vardır.**

`draft` ile `saved` arasındaki geçişi hangi olay tetikleyecekti? Klasik cevap
"kullanıcı Kaydet'e bastı" olurdu. Ama bizim frontend'imizde **Kaydet düğmesi
yok**: `hooks/useInvitationAutoSave.ts` son düzenlemeden 1,5 saniye sonra
kendiliğinden `POST` atıyor. Yani satır veritabanına düştüğü an zaten
kaydedilmiştir — `draft` durumunu doğuracak bir an mevcut değil.

Tanımsız bir durumun bedeli:

1. **Ölü kod.** Hiçbir kod onu üretmez, ama PHP'nin `match` ifadesi tüm durumları
   kapsamayı zorunlu tuttuğu için her `match` bloğunda boş bir kol açarsın.
2. **Yalan doküman.** Kılavuzda "bu ne zaman olur?" sorusuna cevap yazamazsın.
   Bu, Faz 2'de kurulan **B4 kuralının** ihlalidir: *dokümanda verilen söz, kodda
   karşılığı yoksa yalandır.*
3. **Yanlış sorgu riski.** `status != 'published'` yazan bir sorgu üç durumla
   farklı, iki durumla farklı davranır.

Tersine, ihtiyaç sonradan doğarsa maliyet küçüktür: enum'a bir `case`, migration'a
bir CHECK güncellemesi. **Değer eklemek ucuz, kullanılmayan değeri çıkarmak veri
geçişi gerektirir.**

Frontend `types.ts` da zaten `'published' | 'saved'` diyor — yani iki durum aynı
zamanda sözleşme uyumudur.

---

## 5. `default()` — neden bir metot, neden `static`?

```php
public static function default(): self
{
    return self::Saved;
}
```

**`static` ne demek?** Normal (örnek) metotlar bir nesne üzerinde çağrılır:
`$status->value`. `static` metotlar **sınıfın kendisi** üzerinde çağrılır ve
elinde bir nesne olmasını gerektirmez:

```php
InvitationStatus::default()      // static  — nesne yok, sınıf var
$status->value                   // örnek   — elinde bir durum var
```

Burada `static` şart, çünkü "varsayılan durum nedir?" sorusunu sormak için elinde
zaten bir durum olması gerekmiyor.

**`self` ne demek?** "Bu sınıfın kendisi" — yani dönüş tipi `InvitationStatus`.
`self::Saved` = `InvitationStatus::Saved`. Sınıf adı değişirse bu satırlar
kendiliğinden doğru kalır.

**Neden metot, neden doğrudan `InvitationStatus::Saved` yazmıyoruz?**

Bu değer üç ayrı yerde kullanılacak: migration'ın `default()` tanımı, model
`$attributes` dizisi ve `CreateInvitationAction`. Üçünde de elle `::Saved`
yazsaydık, varsayılan bir gün değiştiğinde üçünü de bulmak zorunda kalırdık —
birini kaçırmak sessiz bir tutarsızlık üretir.

Bu, **tek doğruluk kaynağı** (single source of truth) ilkesidir. Faz 2'de
kurulan **C3 kuralının** aynısı: *DRY'ın amacı satır tasarrufu değil, tek
doğruluk kaynağıdır.*

---

## 6. `values()` — enum'dan veritabanı kısıtı üretmek

```php
/**
 * @return list<string>
 */
public static function values(): array
{
    return array_column(self::cases(), 'value');
}
```

### `self::cases()`

Her backed enum'un otomatik olarak sahip olduğu bir metottur. Tüm üyeleri
tanımlanma sırasıyla bir dizi hâlinde verir:

```php
[InvitationStatus::Saved, InvitationStatus::Published]
```

### `array_column()`

PHP'nin yerleşik fonksiyonu. Bir dizinin içindeki her öğeden **aynı alanı** çekip
düz bir dizi üretir:

```php
array_column($cases, 'value')   →   ['saved', 'published']
```

JavaScript karşılığı `cases.map(c => c.value)`. PHP'de bu yazım daha kısa ve
enum'lar üzerinde özel olarak desteklenir.

### Bu ne işe yarayacak?

Bir sonraki dosyada (3.2, migration) veritabanı kısıtını **elle yazmayacağız**:

```php
// ❌ Elle: enum değişince burası sessizce eskimiş kalır
$table->string('status')->default('saved');
DB::statement("ALTER TABLE invitations ADD CONSTRAINT ... CHECK (status IN ('saved','published'))");

// ✅ Enum'dan beslenerek: tek doğruluk kaynağı
InvitationStatus::values()   →   ['saved', 'published']
```

Aynı dizi doğrulama kuralında da işe yarar (`Rule::in(InvitationStatus::values())`),
ama orada Laravel'in `Rule::enum()` kuralı daha doğrudandır — 3.8'de göreceğiz.

### `@return list<string>` — bu docblock neden var?

PHP'de `array` demek yetersizdir: hangi anahtar tipi, hangi değer tipi? PHPStan
(seviye 6'dan itibaren, K22) bunu sormaya başlar ve cevap vermezsen
`composer analyse` kırılır.

| Yazım | Anlamı |
|---|---|
| `array` | Belirsiz — PHPStan seviye 6'da hata |
| `array<string>` | Değerleri string, anahtarları belirsiz |
| `list<string>` | Değerleri string, anahtarları **0'dan başlayan ardışık** tam sayı |

`array_column()` her zaman `list` üretir, o yüzden en dar ve en doğru tip budur.
**Tipi olabildiğince dar yazmak** statik analizin sana yardım edebilmesinin ön
koşuludur.

---

## 7. Laravel bu enum'u nasıl kullanacak?

Enum tek başına hiçbir şey yapmaz — üç yerde tüketilecek:

```
3.2  Migration      status kolonu + CHECK kısıtı  ← InvitationStatus::values()
3.4  Model          protected function casts()    ← 'status' => InvitationStatus::class
3.8  FormRequest    Rule::enum(InvitationStatus::class)
```

**Model cast'i** en önemlisi. Şunu yazdığımızda:

```php
protected function casts(): array
{
    return ['status' => InvitationStatus::class];
}
```

Eloquent iki yönlü çeviriyi üstlenir:

```
Veritabanından okurken:  'published'  →  InvitationStatus::Published
Veritabanına yazarken:   InvitationStatus::Published  →  'published'
```

Yani kodun hiçbir yerinde `->status === 'published'` yazmazsın; her zaman
`->status === InvitationStatus::Published` yazarsın. Metin hiçbir zaman elinde
olmaz, dolayısıyla yanlış yazma ihtimali de yoktur.

**Resource'ta ise ters yön gerekir:**

```php
'status' => $this->status->value,   // frontend metin bekliyor
```

`->value` yazmayı unutursan JSON'a `{"status": {}}` gibi bir şey çıkar. 3.9'da
bu tuzağa dikkat edeceğiz.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `case Saved = 'Saved';` (büyük harf değer) | Frontend `'saved'` bekliyor, karşılaştırma tutmaz | Değer küçük harf, isim PascalCase |
| 2 | JSON'a `$this->status` (`->value` yok) | `{"status":{}}` veya serileştirme hatası | `$this->status->value` |
| 3 | `$inv->status === 'published'` | **Her zaman `false`** — nesne ile metin karşılaştırılıyor | `=== InvitationStatus::Published` |
| 4 | `enum InvitationStatus` (`: string` yok) | Pure enum — veritabanına yazılamaz | `enum InvitationStatus: string` |
| 5 | `InvitationStatus::from('gecersiz')` | `ValueError` fırlar, 500 döner | Dış girdide `tryFrom()` — `null` döner |
| 6 | `declare(strict_types=1);` unutmak | `composer lint` kırılır | Pint ekler; `composer lint` çalıştır |
| 7 | Dosyayı `app/enums/` içine koymak | Windows'ta çalışır, Linux'ta "class not found" | Klasör adı **PascalCase** |
| 8 | `match` bloğunda yeni case'i unutmak | `UnhandledMatchError` | PHPStan bunu yakalar |

### 5. maddenin ayrıntısı: `from()` ile `tryFrom()`

Her backed enum'un iki dönüştürme metodu vardır:

```php
InvitationStatus::from('saved')       // → InvitationStatus::Saved
InvitationStatus::from('gecersiz')    // → ValueError fırlatır 💥
InvitationStatus::tryFrom('gecersiz') // → null 🙂
```

**Kural:** Veri sana **dışarıdan** (HTTP isteği, üçüncü parti API) geliyorsa
`tryFrom()` kullan ve `null` durumunu ele al. Veri **kendi veritabanından**
geliyorsa `from()` kullan — orada geçersiz değer varsa bu gerçekten bir hatadır
ve gürültü çıkarması gerekir.

Bizim durumumuzda ikisini de elle çağırmayacağız: FormRequest doğrulaması geçersiz
değeri zaten kapıda durduracak, model cast'i de dönüşümü kendisi yapacak.

---

## 9. Kendin dene

Kodu yazdıktan sonra:

```powershell
php artisan tinker
```

Açılan kabukta sırayla:

```php
use App\Enums\InvitationStatus;

InvitationStatus::cases();
// => [InvitationStatus {#... +name: "Saved", +value: "saved"}, ...]

InvitationStatus::values();
// => ["saved", "published"]

InvitationStatus::default();
// => InvitationStatus {#... +name: "Saved", +value: "saved"}

InvitationStatus::default()->value;
// => "saved"

InvitationStatus::from('published');
// => InvitationStatus {#... +name: "Published"}

InvitationStatus::tryFrom('draft');
// => null          ← draft artık yok; sessizce null döner

InvitationStatus::from('draft');
// => ValueError: "draft" is not a valid backing value for enum App\Enums\InvitationStatus

InvitationStatus::Saved === InvitationStatus::Saved;
// => true          ← enum üyeleri tekildir (singleton), === ile karşılaştırılır

InvitationStatus::Saved === 'saved';
// => false         ← 3 numaralı tuzak: nesne ≠ metin
```

Çıkmak için `exit`.

Ardından kalite kapısı:

```powershell
composer lint      # Pint — düzeltir
composer check     # pint --test → phpstan → errors:export → test
```

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Enum** (*enumeration*) | Sonlu ve sayılabilir bir değer kümesini tip olarak tanımlayan yapı |
| **Backed enum** | Her üyesinin arkasında ham bir `string`/`int` değeri olan enum; saklanabilir |
| **Pure enum** | Ham değeri olmayan enum; yalnızca bellek içinde anlamlıdır |
| **Case** | Enum'un tek bir üyesi (`case Saved = 'saved';`) |
| **Sihirli string** (*magic string*) | Kodun içine gömülmüş, anlamı bağlamdan çıkarılan çıplak metin |
| **Durum makinesi** (*state machine*) | Bir varlığın alabileceği durumlar ve aralarındaki geçişleri tanımlayan model |
| **Static metot** | Nesne değil, sınıf üzerinde çağrılan metot (`Sinif::metot()`) |
| **`self`** | "Bu sınıfın kendisi" — dönüş tipi ve sabit erişiminde kullanılır |
| **Ad alanı** (*namespace*) | Sınıf adlarının çakışmasını önleyen mantıksal klasörleme |
| **PSR-4** | Ad alanı ile klasör yolunu birebir eşleyen PHP standardı |
| **Cast** | Eloquent'in kolon değerini PHP tipine (ve tersine) otomatik çevirmesi |
| **`list<T>`** | 0'dan başlayan ardışık tam sayı anahtarlı, tüm değerleri `T` olan dizi |
| **Tek doğruluk kaynağı** | Bir bilginin sistemde yalnızca tek bir yerde tanımlı olması |

---

## 11. Sırada ne var?

**3.2 — `database/migrations/..._create_invitations_table.php`**

Bu enum orada iki kez kullanılacak: `status` kolonunun varsayılanı
(`InvitationStatus::default()->value`) ve CHECK kısıtının değer listesi
(`InvitationStatus::values()`).

Migration'da ayrıca şu kararlar uygulanacak:

- **ULID birincil anahtar** — `id` hem dahili kimlik hem paylaşılan link
- **`VARCHAR + CHECK`** — native `ENUM` yerine (değer eklemek/çıkarmak kolay)
- **6 ayrı `show_*` boolean kolonu** — paywall SQL ile doğrulanabilsin diye (K6)
- **`phone_background` kolonu YOK** — `preset_id`'den türetilir (E1)
- **`INDEX (user_id, status)`** — dashboard sorgusunun çalışacağı indeks
