# `app/Enums/SubscriptionTier.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Enums/SubscriptionTier.php`
> **Yol haritasındaki yeri:** Adım 2 (ikinci dosya)
> **Bağlantılı:** [config/davetkart.md](../../config/davetkart.md)

---

## 0. Bir dakikalık özet

Üç plan var: Standart, Gold, Elit. Bu dosya onları **tip** hâline getirir.
Artık kodda `'gold'` yazan bir string dolaşmaz; `SubscriptionTier::Gold` dolaşır.

Enum ayrıca **davranış** taşır: bir planın fiyatını, sırasını, LCV kotasını ve
"bu plan şunu kapsıyor mu?" sorusunun cevabını kendisi bilir.

---

## 1. PHP temelleri

### 1.1 `namespace` nedir?

```php
namespace App\Enums;
```

Namespace = sınıfların **soyadı**. İki farklı paket aynı adda sınıf tanımlarsa
çakışmasınlar diye vardır.

- Bu sınıfın tam adı: `App\Enums\SubscriptionTier`
- Başka dosyada kullanmak için: `use App\Enums\SubscriptionTier;`

TypeScript'te `import { X } from './x'` dosya yoluna bakar. PHP'de **namespace
klasör yapısıyla eşleşmek zorundadır**:

```
app/Enums/SubscriptionTier.php   →   namespace App\Enums;
```

Bu eşleşmeye **PSR-4** denir ve `composer.json` içinde tanımlıdır
(`"App\\": "app/"`). Eşleşme bozulursa `Class not found` hatası alırsın.

> ⚠️ Windows harf duyarsızdır, Linux sunucu duyarlıdır. Klasörü `app/enums/`
> yaparsan yerelde çalışır, sunucuda patlar. Klasör adları **PascalCase**.

### 1.2 Enum nedir?

Enum = değerleri **önceden sayılmış** bir tip. "Bu değişken yalnızca şu üç
şeyden biri olabilir" demenin dile gömülü yolu.

```php
enum SubscriptionTier: string
{
    case Standart = 'standart';
    case Gold     = 'gold';
    case Elit     = 'elit';
}
```

- `: string` → **backed enum**. Her case'in bir veritabanı/JSON karşılığı var.
  `: string` olmasaydı (*pure enum*) değer taşımazdı ve DB'ye yazamazdık.
- `case Gold = 'gold';` → `Gold` sabitin adı (kodda kullanılır), `'gold'` değeri
  (DB'de ve API'de görünür).
- Neden isim `Gold`, değer `'gold'`? Çünkü **kod stili** ile **veri sözleşmesi**
  ayrı şeylerdir. PHP tarafında PascalCase okunur; dış dünyaya frontend'in
  beklediği küçük harfli değer gider (`src/types.ts` → `'standart' | 'gold' | 'elit'`).

TypeScript karşılığı şuydu:

```ts
export type SubscriptionTier = 'standart' | 'gold' | 'elit';
```

Aradaki fark: TS'teki bu tip **derleme sonrası kaybolur**, PHP enum'u çalışma
zamanında yaşar ve metot taşıyabilir.

### 1.3 Enum'un hazır gelen metotları

```php
SubscriptionTier::Gold->value;        // 'gold'      — ham değer
SubscriptionTier::Gold->name;         // 'Gold'      — case adı
SubscriptionTier::from('gold');       // Gold        — eşleşme yoksa hata fırlatır
SubscriptionTier::tryFrom('xyz');     // null        — eşleşme yoksa null
SubscriptionTier::cases();            // [Standart, Gold, Elit]
```

**`from` ve `tryFrom` farkı önemli:**

| Metot | Geçersiz değerde | Nerede kullanılır |
|---|---|---|
| `from()` | `ValueError` fırlatır | Veriye güveniyorsan (DB'den okuma) |
| `tryFrom()` | `null` döner | Kullanıcı girdisinde |

### 1.4 `$this` ve metotlar

```php
public function rank(): int
{
    return (int) $this->config('rank');
}
```

- `public` → dışarıdan çağrılabilir. `private` → sadece sınıf içinden.
- `function rank(): int` → dönüş tipi `int` olarak **zorunlu** kılınmış.
  `declare(strict_types=1)` sayesinde yanlış tip döndürmek hata verir.
- `$this` → "üzerinde çalıştığım case". `SubscriptionTier::Gold->rank()`
  çağrısında `$this` = `Gold`.
- `(int)` → **cast** (tip dönüşümü). `config()` `mixed` döner; biz `int`
  garantisi veriyoruz.

### 1.5 `?int` — nullable tip

```php
public function rsvpLimit(): ?int
```

Baştaki `?` "bu metot `int` **veya** `null` döndürebilir" demektir.
TypeScript'teki `number | null` karşılığı.

Burada `null` özel bir anlam taşıyor: **sınırsız**. `0` yazsaydık "sıfır misafir"
olurdu — tamamen ters anlam.

### 1.6 `match` ifadesi

```php
return match ($this) {
    self::Standart => 'Standart',
    self::Gold     => 'Gold',
    self::Elit     => 'Elit',
};
```

`match`, `switch`'in modern hâlidir. Üç üstünlüğü var:

| | `switch` | `match` |
|---|---|---|
| Karşılaştırma | `==` (gevşek) | `===` (katı) |
| `break` gerekir mi | Evet, unutulursa alttaki de çalışır | Hayır |
| Değer döndürür mü | Hayır | **Evet** (ifade, deyim değil) |
| Eşleşme yoksa | Sessizce geçer | `UnhandledMatchError` fırlatır |

Son madde bizim için kritik: yarın dörtüncü bir plan (`Platinum`) eklersek ve
`label()` içine eklemeyi unutursak, `match` **anında hata verir**. Sessiz hata
yerine gürültülü hata — hata ayıklamayı kolaylaştırır.

`self::Standart` → `SubscriptionTier::Standart` demenin sınıf içi kısayolu.

### 1.7 `static` metot

```php
public static function lowest(): self
{
    return self::Standart;
}
```

`static` = bir case'e değil, **tipin kendisine** ait metot. Çağrımı
`SubscriptionTier::lowest()` şeklindedir; `$this` yoktur çünkü ortada bir case yok.

`: self` dönüş tipi "kendi tipimden bir değer döndürürüm" demek.

---

## 2. Tasarım kararları

### 2.1 Neden string sabit değil, enum?

Enum olmasaydı kod şöyle olurdu:

```php
if ($user->tier === 'gold') { ... }
```

Sorunlar:

| Sorun | Enum nasıl çözüyor |
|---|---|
| `'Gold'`, `'gold '`, `'glod'` yazım hataları çalışma anına kadar fark edilmez | `SubscriptionTier::Glod` **yazarken** hata verir |
| Hangi değerler geçerli, koda bakarak anlaşılmaz | `cases()` tam listeyi verir |
| IDE otomatik tamamlama yapamaz | Enum'da yapar |
| Değer değişirse tüm dosyalarda arayıp değiştirmek gerekir | Tek yerde durur |

Buna **magic string** (sihirli string) problemi denir ve `CLAUDE.md`'de açıkça
yasaklanmıştır.

### 2.2 🔴 Neden fiyatlar enum'un içine gömülmedi?

Şöyle de yazabilirdik:

```php
public function price(): int
{
    return match ($this) {
        self::Standart => 249,   // ❌
        ...
    };
}
```

Yazmadık. Sebep: **fiyat bir iş kuralıdır, bir kod detayı değil.** Fiyat
değiştiğinde kod dosyası değiştirilip deploy edilmesi gerekirdi. Config'te
durduğunda değişiklik veri seviyesindedir.

Ayrıca `config/davetkart.php` zaten "iş sabitlerinin tek kaynağı" olarak
tanımlandı. Aynı bilgiyi iki yerde tutmak **Single Source of Truth** ihlalidir:
biri güncellenip diğeri unutulur.

Enum'un rolü: **kimlik + davranış**. Verinin kendisi config'te.

### 2.3 `private function config()` — tekrarı kaldırmak

```php
private function config(string $key): mixed
{
    return config("davetkart.tiers.{$this->value}.{$key}");
}
```

Bu olmasaydı her metot şunu yazardı:

```php
config("davetkart.tiers.{$this->value}.rank");
config("davetkart.tiers.{$this->value}.price");
config("davetkart.tiers.{$this->value}.rsvp_limit");
```

Config yolu üç yerde tekrar ederdi; yol değişirse üçünü birden düzeltmek
gerekirdi. Tek `private` metot bunu **tek noktaya** indirir — **DRY** (Don't
Repeat Yourself).

`private` olması önemli: bu metot sınıfın iç işidir, dışarıya API olarak
sunulmaz.

> **Not:** `"...{$this->value}..."` çift tırnak kullanıyor çünkü içinde değişken
> var. Tek tırnak olsaydı `{$this->value}` metni aynen yazılırdı.

### 2.4 `covers()` — paywall'ın çekirdek sorusu

```php
public function covers(self $required): bool
{
    return $this->rank() >= $required->rank();
}
```

Kullanımı okunur:

```php
$satinAlinan->covers($gereken)   // "Elit, Gold'u kapsıyor mu?" → true
```

**Neden `rank`, neden string karşılaştırması değil?**
Alfabetik sırada `"elit" < "gold"` — Elit alan kullanıcı Gold içerik
yayınlayamazdı. Sayısal sıra bunu tek `>=` işlemine indirger.

**Neden metot enum'da, Action'da değil?**
Çünkü bu bilgi *plana aittir*. "Bir planın diğerini kapsaması" sorusunun cevabı
`PublishInvitationAction`'a taşınırsa, aynı soru başka yerde sorulduğunda mantık
kopyalanır. Veriyi ve onun üzerindeki davranışı bir arada tutmaya **encapsulation**
(kapsülleme) denir.

### 2.5 `lowest()` neden var?

Adım 12'de `TierResolver` şöyle çalışacak:

```php
$gereken = SubscriptionTier::lowest();          // en düşükten başla

foreach (config('davetkart.module_tiers') as $kolon => $tier) {
    if (! $invitation->{$kolon}) continue;

    $modulTier = SubscriptionTier::from($tier);
    if ($modulTier->rank() > $gereken->rank()) {
        $gereken = $modulTier;                  // daha yükseğini bul
    }
}
```

Bu, "bir listenin maksimumunu bul" algoritmasıdır ve başlangıç değeri ister.
`SubscriptionTier::Standart` yazmak yerine `lowest()` demek, yarın planların
sırası değişirse kodu bozmaz — **niyeti** ifade eder, değeri değil.

### 2.6 `label()` — arayüz metni neden burada?

DB'de ve API'de değer `'gold'`; kullanıcıya `'Gold'` gösterilir. Bu ayrım tüm
enum'larda tekrarlanacak (`RsvpStatus` için `'attending'` → `'Katılıyor'`).

Kural: **veri İngilizce ve kararlı, gösterim yerelleştirilmiş.** DB'ye Türkçe
yazmak, yarın 10 dile açılırken (frontend zaten 10 dil destekliyor) yeniden
migration gerektirirdi.

---

## 3. Kullanım örnekleri

```php
use App\Enums\SubscriptionTier;

// Değer okuma
SubscriptionTier::Gold->price();        // 399
SubscriptionTier::Gold->rsvpLimit();    // null (sınırsız)
SubscriptionTier::Standart->rsvpLimit();// 100

// Kapsama kontrolü
SubscriptionTier::Elit->covers(SubscriptionTier::Gold);      // true
SubscriptionTier::Standart->covers(SubscriptionTier::Elit);  // false

// DB'den gelen string'i enum'a çevirme
SubscriptionTier::from($order->tier);      // güvenilir veri
SubscriptionTier::tryFrom($istek);         // kullanıcı girdisi → null olabilir

// Kota kontrolü (Adım 10'da SubmitRsvpAction içinde)
$limit = $tier->rsvpLimit();
if ($limit !== null && $mevcutToplam + $yeniKisi > $limit) {
    throw new RsvpQuotaExceededException();
}
```

**Eloquent cast'i (Adım 4'te `Order` modelinde):**

```php
protected function casts(): array
{
    return ['tier' => SubscriptionTier::class];
}
```

Bundan sonra `$order->tier` bir **string değil, enum** döner. Laravel yazarken
`->value`'yu, okurken `from()`'u kendisi çağırır.

---

## 4. Sık yapılan hatalar

| Hata | Sonucu | Doğrusu |
|---|---|---|
| `$order->tier === 'gold'` | Cast varken enum ile string karşılaştırılır → hep `false` | `$order->tier === SubscriptionTier::Gold` |
| Kullanıcı girdisinde `from()` | Geçersiz değerde 500 hatası | `tryFrom()` + doğrulama |
| Fiyatı enum'a gömmek | İki kaynak, biri unutulur | Config'ten oku |
| Klasörü `app/enums/` yapmak | Linux sunucuda `Class not found` | `app/Enums/` |
| `namespace` satırını unutmak | `Class not found` | `make:enum` ile üret |
| Enum'a yeni case ekleyip `label()`'ı güncellememek | `UnhandledMatchError` | Hata zaten uyarır — `match` bu yüzden tercih edildi |

---

## 5. Kendin dene

```powershell
php artisan tinker
```

```php
use App\Enums\SubscriptionTier;

SubscriptionTier::cases();                                  // 3 case
SubscriptionTier::Elit->price();                            // 549
SubscriptionTier::Elit->covers(SubscriptionTier::Standart); // true
SubscriptionTier::Standart->covers(SubscriptionTier::Elit); // false
SubscriptionTier::tryFrom('premium');                       // null
SubscriptionTier::Gold->label();                            // "Gold"
```

`Class not found` alırsan: `composer dump-autoload` çalıştır (PSR-4 haritasını
yeniler).

---

## 6. Bu enum'u kimler tüketecek?

| Tüketen | Ne için | Adım |
|---|---|---|
| `Order` modeli | `tier` kolonu cast'i | 4 |
| `TierResolver` | Gereken planı hesaplama | 12 |
| `PublishInvitationAction` | `covers()` ile paywall kontrolü | 12 |
| `StartCheckoutAction` | `price()` ile order tutarı | 12 |
| `SubmitRsvpAction` | `rsvpLimit()` ile kota | 10 |
| `OrderResource` | `label()` ile arayüz metni | 12 |

---

## 7. Sözlük

| Terim | Anlamı |
|---|---|
| **Enum** | Değerleri önceden sayılmış tip |
| **Backed enum** | Her case'in bir skaler karşılığı olan enum (`: string`) |
| **Namespace** | Sınıf adlarının çakışmasını önleyen ad alanı |
| **PSR-4** | Namespace ↔ klasör eşleşmesi standardı |
| **Magic string** | Anlamı bağlamdan çıkarılan, tekrarlanan çıplak string |
| **Cast** | Bir değerin başka tipe çevrilmesi |
| **Encapsulation** | Veri ile onun üzerindeki davranışın bir arada tutulması |
| **DRY** | Aynı bilginin tek yerde tutulması ilkesi |
| **Single Source of Truth** | Bir bilginin tek yetkili kaynağının olması |
