# `app/Providers/AppServiceProvider.php` — Kılavuz

> **Kod dosyası:** `app/Providers/AppServiceProvider.php`
> **Faz:** 0 — Zemin ve kalite kapıları (adım 0.11)

---

## 1. Service Provider nedir?

Laravel'in **açılış (bootstrap) mekanizmasıdır**. Uygulama her istekte sıfırdan
ayağa kalkar; provider'lar bu ayağa kalkış sırasında çalışan kancalardır.

İki metot vardır ve **sıraları önemlidir**:

```php
public function register(): void   // 1. TÜM provider'ların register'ı çalışır
public function boot(): void       // 2. sonra TÜM provider'ların boot'u çalışır
```

| Metot | Ne yapılır | Ne yapılmaz |
|---|---|---|
| `register()` | Servis kabına bağlama (`bind`, `singleton`) | Başka servisi **kullanmak** |
| `boot()` | Ayar, olay dinleyicisi, Blade direktifi | — |

**Neden bu ayrım?** `register()` sırasında diğer provider'lar henüz kayıt
olmamış olabilir; oradan başka bir servisi çağırırsan "henüz yok" hatası
alırsın. `boot()` çalıştığında **her şey kayıtlıdır**.

Faz 7'de `PaymentGateway` arayüzünü `FakeGateway`'e bağlarken `register()`
kullanacağız — bu dosyanın asıl işi orada başlayacak.

---

## 2. Bu dosya neden Faz 0'da düzenleniyor?

Faz 0'ın amacı *"yanlışı anında söyleyen araçları kurmak"*. Pint biçimi,
PHPStan tipi denetliyor. Ama bazı hatalar **yalnızca çalışma anında** görünür:

- N+1 sorgu problemi
- Sessizce atılan veri
- Var olmayan alana erişim

Bu dosya onları **exception'a çevirir**. Yani sessiz hatayı gürültülü hataya
dönüştürür — sessiz hata en tehlikeli hata türüdür.

---

## 3. `Model::shouldBeStrict()` — üç koruma birden

```php
Model::shouldBeStrict(! $this->app->isProduction());
```

### ⚠️ Plandan küçük bir sapma

`07-GELISTIRME-YOL-HARITASI.md` adım 0.11 şunu diyordu:

> `Model::preventLazyLoading()`, `Model::shouldBeStrict()`

**İkisini birden yazmak gereksiz.** `shouldBeStrict()` zaten
`preventLazyLoading()`'i **içeriyor** — Laravel kaynağında şu üçünü çağırır:

```php
public static function shouldBeStrict(bool $shouldBeStrict = true): void
{
    static::preventLazyLoading($shouldBeStrict);
    static::preventSilentlyDiscardingAttributes($shouldBeStrict);
    static::preventAccessingMissingAttributes($shouldBeStrict);
}
```

Tek satır yeterli. İkisini yazmak zararsız ama okuyanı *"aralarında ne fark
var?"* diye düşündürür.

### 3.1 `preventLazyLoading` — N+1 sorgu problemi

**En değerli koruma.** Sorunu bir örnekle anlatayım.

100 davetiyeyi listeliyorsun ve her birinin LCV sayısını gösteriyorsun:

```php
$invitations = Invitation::all();          // 1 sorgu

foreach ($invitations as $invitation) {
    echo $invitation->rsvps->count();      // her dönüşte 1 sorgu daha!
}
```

Toplam: **101 sorgu**. Adı buradan gelir: 1 + N.

Yerelde 10 kayıtla test edersin, göz açıp kapayana kadar biter. Üretimde 5.000
davetiye olunca sayfa 30 saniyede açılır ve veritabanı sunucusu diz çöker.

**Doğrusu — eager loading:**

```php
$invitations = Invitation::with('rsvps')->get();   // toplam 2 sorgu
```

`preventLazyLoading` açıkken ilk örnek **exception fırlatır**:

```
Attempted to lazy load [rsvps] on model [App\Models\Invitation]
but lazy loading is disabled.
```

Yani hatayı **laptop'ta** görürsün, üretimde değil. `CLAUDE.md` §4'teki
*"her zaman `with()` ile Eager Loading"* kuralının yapısal karşılığı budur.

### 3.2 `preventSilentlyDiscardingAttributes` — sessiz veri kaybı

`$fillable` listesinde olmayan bir alan doldurmaya çalışırsan Laravel normalde
onu **sessizce atar**:

```php
// Invitation modelinde $fillable = ['title', 'venue'];

Invitation::create([
    'title' => 'Düğünümüz',
    'venu'  => 'Grand Hotel',   // ← yazım hatası: venu / venue
]);
```

Varsayılan davranış: kayıt oluşur, `venue` **boş kalır**, hata yok. Kullanıcı
"mekân adı neden kaydolmadı?" der, sen saatlerce ararsın.

Bu ayar açıkken **exception** fırlar ve yazım hatası anında görünür.

> Bu koruma özellikle 28 alanlı `Invitation` modelinde (Faz 3) çok işe
> yarayacak.

### 3.3 `preventAccessingMissingAttributes` — olmayan alan

```php
$user->emial;    // yazım hatası
```

PHP'nin `__get` sihri yüzünden bu satır normalde sessizce `null` döner. Sonra
`if ($user->emial)` kontrolü hep `false` olur ve mantık sessizce bozulur.

Bu ayar açıkken exception fırlar.

> PHPStan'ın `checkModelProperties` ayarı aynı hatayı **yazarken** yakalar; bu
> ayar ise **çalışırken**. İkisi birbirini tamamlar: statik analiz her kod
> yolunu göremez, çalışma anı kontrolü ise gerçek veriyle karşılaşır.

### 3.4 🔴 Neden üretimde kapalı?

```php
Model::shouldBeStrict(! $this->app->isProduction());
                      ↑ ünlem: production DEĞİLSE true
```

| Ortam | Değer | Gerekçe |
|---|---|---|
| local / testing | `true` | Hatayı **yüzüne söyle** — düzeltilsin |
| production | `false` | Hata müşterinin isteğini **düşürmesin** |

Kaçan bir lazy loading üretimde yavaş çalışır ama **çalışır**. Exception fırlatsa
kullanıcı hata sayfası görürdü. Yavaş sayfa, bozuk sayfadan iyidir.

> Bu, geliştirme araçlarında genel ilkedir: **katılık geliştirmede, hoşgörü
> üretimde.** Aynı mantık `APP_DEBUG` için de geçerlidir — ters yönde.

---

## 4. `Date::use(CarbonImmutable::class)`

### ⚠️ Bu madde planda yoktu — gerekçemi okuyup itiraz edebilirsin

Laravel tarihleri **Carbon** nesnesi olarak döndürür. Varsayılan `Carbon` sınıfı
**değiştirilebilirdir** (mutable) ve bu şaşırtıcı hatalar üretir:

```php
$deadline = $invitation->rsvp_deadline;   // 1 Ağustos

$uyariTarihi = $deadline->subDays(3);     // 29 Temmuz bekliyorsun

echo $deadline;   // 29 Temmuz  ← ORİJİNAL DE DEĞİŞTİ!
```

`subDays()` yeni bir nesne döndürmez, **var olanı değiştirir**. Değişkeni başka
bir yere de geçirdiysen orası da bozulur — izi sürülmesi çok zor bir hata sınıfı.

`CarbonImmutable` ile:

```php
$uyariTarihi = $deadline->subDays(3);   // yeni nesne
echo $deadline;                          // 1 Ağustos ← korundu
```

**Neden bizim için önemli?** Projede tarih hesabı kritik yerlerde:

- LCV son tarihi kontrolü (Faz 5) — `deadline` geçti mi?
- Geri sayım sayacı (Faz 4)
- Zaman çizelgesi olayları (Faz 3)

Bu hesaplarda kazara mutasyon, **yanlış kişiye "süre doldu" demek** anlamına
gelir.

**Maliyeti:** Neredeyse sıfır. API aynı, sadece metotlar yeni nesne döndürüyor.
Tek dikkat noktası — bu artık işe yaramaz:

```php
$date->addDay();          // ❌ sonucu atmadın, hiçbir şey olmadı
$date = $date->addDay();  // ✅
```

Bu "tuzak" aslında bir **özellik**: mutasyon beklentisiyle yazılmış kod hemen
görünür hale gelir.

> İtiraz edersen tek satır silinir, geri dönmek serbest. Ama önerim kalması.

---

## 5. `DB::prohibitDestructiveCommands()`

```php
DB::prohibitDestructiveCommands($this->app->isProduction());
```

Üretim ortamında şu komutları **çalıştırılamaz** hale getirir:

| Komut | Ne yapardı |
|---|---|
| `migrate:fresh` | Tüm tabloları siler, sıfırdan kurar |
| `migrate:refresh` | Tüm migration'ları geri alıp yeniden koşar |
| `migrate:reset` | Tüm migration'ları geri alır |
| `db:wipe` | Veritabanını tamamen boşaltır |

**Neden gerekli?** Bu, gerçek şirketleri batırmış bir hata sınıfıdır: yanlış
terminal sekmesinde `php artisan migrate:fresh` yazmak. Komut sormaz, uyarmaz —
saniyeler içinde tüm müşteri verisi gider.

`--force` bayrağı `APP_ENV=production` iken onay ister ama **alışkanlık**
tehlikelidir: insanlar `--force`'u refleks olarak ekler.

Bu satır o kapıyı **tamamen kapatır**. Üretimde gerçekten şema sıfırlamak
gerekirse, bu satırı bilinçli olarak kaldırman gerekir — ve o an ne yaptığını
düşünürsün.

> **Güvenliği yapıya bağlamak.** Aynı ilkeyi K20'de de kullandık: `error.debug`
> bloğu üretimde *"unutulmasın"* diye değil, **kod hiç çalışmadığı için** yok.

---

## 6. Metotlara bölme — neden?

```php
public function boot(): void
{
    $this->configureModels();
    $this->configureDates();
    $this->configureCommands();
}
```

Üç satırın tamamı doğrudan `boot()` içine de yazılabilirdi. Bölmenin sebebi:

1. **Okunabilirlik.** `boot()` bir **içindekiler tablosu** gibi okunur.
2. **Büyüme.** Faz 7'de ödeme, Faz 8'de AI bağlamaları gelecek; `boot()` 30
   satırlık bir çorbaya dönmez.
3. **Adlandırma = belgeleme.** `configureModels()` adı, ne yaptığını yoruma
   gerek kalmadan söyler.

Bu, Clean Code'un *"fonksiyonlar tek bir soyutlama seviyesinde çalışmalı"*
ilkesidir: `boot()` **ne** yapıldığını söyler, alt metotlar **nasıl**.

---

## 7. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `register()` içinde başka servis kullanmak | "Servis bulunamadı" | Ayarlar `boot()`'a |
| `shouldBeStrict()` **ve** `preventLazyLoading()` | Gereksiz tekrar | Sadece `shouldBeStrict()` |
| `shouldBeStrict()`'i **üretimde de** açmak | Müşteri hata sayfası görür | `! isProduction()` ile sar |
| `! $this->app->isProduction()` yerine `app()->environment('local')` | `testing` ortamı kapsam dışı kalır | `isProduction()` tersini kullan |
| N+1 exception'ını `with()` yerine ayarı kapatarak "çözmek" | Sorun üretime taşınır | Sorguyu düzelt |
| `$date->addDay();` sonucu atmamak | Immutable'da hiçbir şey olmaz | `$date = $date->addDay();` |
| `boot()`'a `env()` yazmak | `config:cache` sonrası `null` | `config()` kullan |

---

## 8. Deneme adımları

**1. Uygulama hâlâ ayağa kalkıyor mu?**

```powershell
php artisan config:clear
php artisan test
composer analyse
```

Üçü de yeşil olmalı.

**2. Tarihlerin gerçekten immutable olduğunu kanıtla:**

```powershell
php artisan tinker
```

```php
get_class(now());
// "Carbon\CarbonImmutable"   ← "Carbon\Carbon" değil

$t = now();
$t->addDays(3);
$t->diffInDays(now());
// 0  ← orijinal DEĞİŞMEDİ. Mutable olsaydı 3 olurdu
```

**3. Katı kipin açık olduğunu gör:**

```php
Illuminate\Database\Eloquent\Model::preventsLazyLoading();
// true
```

> Gerçek N+1 exception'ını Faz 3'te ilişkiler yazılınca deneyeceğiz — şu an
> `User` dışında model yok.

**4. `isProduction()` mantığını doğrula:**

```php
app()->isProduction();   // false  (APP_ENV=local)
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Service Provider** | Uygulama açılışında çalışan kayıt/ayar sınıfı |
| **Servis kabı** (container) | Laravel'in nesne üretim ve bağımlılık çözüm mekanizması |
| **Bootstrap** | Uygulamanın ayağa kalkma süreci |
| **N+1 sorgu** | 1 ana sorgu + her kayıt için 1 ek sorgu |
| **Eager loading** | İlişkili veriyi önceden `with()` ile çekmek |
| **Lazy loading** | İlişkili veriyi erişildiği anda çekmek |
| **Mass assignment** | Diziden toplu alan doldurma. `$fillable` ile sınırlanır |
| **Mutable / Immutable** | Değiştirilebilir / değişmez nesne |
| **Carbon** | PHP tarih kütüphanesi, Laravel'in varsayılanı |
| **Sessiz hata** | Uyarı vermeden yanlış davranan kod — en tehlikeli tür |

---

## 10. Bağlantılar

| İlgili | Nerede |
|---|---|
| Statik analizin model denetimi | [`../../phpstan.md`](../../phpstan.md) §4 |
| Ortam değişkenleri | [`../../env.md`](../../env.md) |
| Test ortamı | [`../../phpunit.md`](../../phpunit.md) |
| Bu dosyanın büyüyeceği yer | Faz 7 — `PaymentGateway` → `FakeGateway` bağlama |
| Eager loading kuralı | `CLAUDE.md` §4 |
