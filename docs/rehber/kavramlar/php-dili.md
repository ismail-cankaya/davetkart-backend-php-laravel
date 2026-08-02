# PHP Dili — TypeScript Bilenler İçin Referans

> **Kapsanan dosya:** yok — bu bir **dil referansıdır**, tasarım dokümanı değil.
> **Kime göre yazıldı:** TypeScript/React'e aşina, PHP'yi ilk kez gören birine.
> **Kapsamı:** Yalnızca **bu projede fiilen geçen** dil özellikleri. PHP'nin
> tamamı değil; okuduğun kodda gördüğün şeyler.
> **Yaşayan doküman:** Her fazda yeni özellik geldikçe büyür. Faz sonunda
> "bu fazda eklenenler" bölümüne bakılır.
> **Bağlantılı:** [`veritabani-ve-migration.md`](veritabani-ve-migration.md) ·
> [`app/Enums/ErrorCode.md`](../app/Enums/ErrorCode.md) ·
> [`CLAUDE.md`](../../../CLAUDE.md)

---

## 0. Nasıl kullanılır

Bu dosyayı baştan sona okumak zorunda değilsin. Kodda tanımadığın bir işaret
gördüğünde buraya bak. En sık aranacaklar:

| Gördüğün | Bölüm |
|---|---|
| `->` ile `::` farkı | [§4](#4--ile--ayrımı--en-sık-karıştırılan) |
| `/** @return list<string> */` | [§13](#13-docblock--yorum-değil-tip-bildirimi) |
| `#[Fillable([...])]` | [§12](#12-attribute-) |
| `fn () => ...` | [§10](#10-closure-ve-arrow-function) |
| `match (true)` | [§8](#8-match) |
| `$this->normalize(...)` | [§15.6](#156-first-class-callable-) |
| `! $e instanceof X` | [§17](#17-sık-yapılan-hatalar) |

---

## 1. Bir PHP dosyasının iskeleti

Projemizdeki **her** sınıf dosyası bu beş katmandan oluşur:

```php
<?php                                    // 1. açılış etiketi

declare(strict_types=1);                 // 2. katı tip kipi

namespace App\Enums;                     // 3. bu dosya nerede yaşıyor

use Illuminate\Http\JsonResponse;        // 4. dışarıdan ithal edilenler

enum ErrorCode: string { ... }           // 5. asıl içerik
```

### 1.1 `<?php` — ve kapanış etiketinin yokluğu

PHP aslında bir **şablon dilidir**: dosyanın `<?php` dışında kalan kısmı düz metin
olarak çıktıya basılır. `<?php` "buradan itibarası koddur" demektir.

🔴 **Sınıf dosyalarında `?>` kapanış etiketi yazılmaz.** Sebebi somuttur: `?>`
sonrasındaki tek bir boşluk veya satır sonu **çıktıya basılır**. Bu, HTTP
başlıkları gönderilmeden önce çıktı ürettiği için `headers already sent` hatası
verir ve kaynağını bulmak çok zordur. PSR-12 standardı bu yüzden yasaklar; Pint
de kontrol eder.

### 1.2 `declare(strict_types=1);`

Faz 0'ın **K1** kuralı: her dosya bununla başlar (Pint otomatik ekler).

Olmadan PHP tipleri sessizce dönüştürür:

```php
function status(int $code): string { ... }

status("404");    // strict_types YOK → 404'e çevrilir, çalışır
status("404");    // strict_types VAR → TypeError, anında patlar
```

TypeScript'te tip kontrolü **derleme anında** yapılır ve çalışma anında hiçbir şey
kalmaz. PHP'de tip bildirimi **çalışma anında** kontrol edilir — ama `strict_types`
olmadan kontrol yerine *dönüştürme* yapar. Bu satır, dönüştürmeyi kapatıp gerçek
kontrolü açar.

> **Neden önemli?** `"0"`, `""`, `null` gibi değerler PHP'de sessizce `false`'a,
> `0`'a, `""`'ye dönüşebilir. Bir kota hesabında `"abc"` değerinin `0` olması,
> hatayı fark ettirmeden yanlış cevap üretir. `strict_types` bu sınıf hataları
> yok eder.

### 1.3 `namespace` ve `use` — PSR-4

```php
namespace App\Enums;          // dosya: app/Enums/ErrorCode.php
```

`namespace`, sınıfın **tam adının** önekidir. `ErrorCode` sınıfının gerçek adı
`App\Enums\ErrorCode`'dur. Ters bölü (`\`) klasör ayracı değil, **isim ayracıdır**
— ama PSR-4 standardı gereği klasör yapısını **birebir** yansıtır:

```
namespace App\Enums;   +   class ErrorCode   →   app/Enums/ErrorCode.php
```

Composer bu eşlemeyi `vendor/autoload.php` içinde tutar. Bir sınıf ilk kez
kullanıldığında PHP dosyayı **otomatik bulup yükler** — `import` yazmana gerek
yoktur. TypeScript'te `import { X } from './x'` yolu söyler; PHP'de yol **isimden
hesaplanır**.

> Faz 0'ın 6. dersi burada anlam kazanır: klasör adları **PascalCase** olmalı.
> Windows dosya adlarında büyük/küçük harf ayırmaz, Linux ayırır. `app/enums/`
> yerelde çalışır, sunucuda "Class not found" verir.

`use` satırı ise yalnızca bir **takma ad** tanımlar:

```php
use Illuminate\Http\JsonResponse;
// bundan sonra JsonResponse yazınca Illuminate\Http\JsonResponse anlaşılır
```

`use X as Y` isim çakışmasını çözer — `User.php`'de gördüğün gibi:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
class User extends Authenticatable   // aksi hâlde iki tane "User" olurdu
```

---

## 2. Değişkenler ve tipler

```php
$code = $this->resolveCode($e);        // her değişken $ ile başlar
```

`$` zorunludur; `let`/`const`/`var` yoktur. Değişkenler **yeniden atanabilir**
(TS'teki `let` gibi); `const` yalnızca sınıf sabitleri içindir (§3.4).

### 2.1 Tip bildirimleri

```php
public function status(): int                       // dönüş: int
private function fromStatus(int $status): ErrorCode // parametre + dönüş
public function rsvpLimit(): ?int                   // int VEYA null
private function normalize(mixed $value): mixed     // her şey olabilir
public function handle(): int
public function render(Throwable $e): JsonResponse  // sınıf tipi
```

| PHP | TypeScript karşılığı |
|---|---|
| `int`, `float`, `string`, `bool` | `number`, `number`, `string`, `boolean` |
| `array` | `Array` veya `Record` (ikisi de! → §6) |
| `?int` | `number \| null` |
| `mixed` | `any` |
| `void` | `void` |
| `self` | `this` sınıfının tipi |
| `Throwable` | `Error` benzeri arayüz |

🔴 **PHP'nin tip sistemi TypeScript'inkinden kabadır.** `array` yazabilirsin ama
"string'lerden oluşan dizi" diyemezsin. Bu boşluğu **docblock** doldurur (§13) —
o yüzden PHPStan bu projede kritiktir.

### 2.2 `null` ve `??`

```php
$names = self::RULE_PARAM_NAMES[$rule] ?? null;
$retryAfter = $e->getHeaders()['Retry-After'] ?? null;
```

`??` **null coalescing** operatörüdür: soldaki *yoksa veya null'sa* sağdakini ver.
TypeScript'teki `??` ile aynı — ama bir farkla: PHP'de tanımsız dizi anahtarına
erişmek normalde **uyarı** üretir, `??` bunu da susturur.

---

## 3. Sınıf anatomisi

```php
final class ApiExceptionRenderer
{
    private const RULE_PARAM_NAMES = [ ... ];

    public function render(Throwable $e): JsonResponse { ... }

    private function resolveCode(Throwable $e): ErrorCode { ... }
}
```

### 3.1 Görünürlük (visibility)

| Anahtar kelime | Kim erişebilir |
|---|---|
| `public` | Herkes |
| `protected` | Bu sınıf + alt sınıfları |
| `private` | Yalnızca bu sınıf |

PHP'de görünürlük **zorunlu ve gerçektir** — TypeScript'teki `private` yalnızca
derleyici uyarısıdır, çalışma anında erişilebilir. PHP'de `private` bir metodu
dışarıdan çağırmak `Error` fırlatır.

> **Tasarım okuması:** `ApiExceptionRenderer`'ın **tek** `public` metodu var:
> `render()`. Diğer sekizi `private`. Bu, sınıfın *dış yüzeyinin* tek bir metot
> olduğunu söyler — yarın `fields()` metodunun imzasını değiştirsem hiçbir çağıran
> kırılmaz. Küçük yüzey = ucuz değişim.

### 3.2 `final`

```php
final class HealthController extends Controller
```

"Bu sınıftan miras alınamaz." Neden isteyelim ki?

Miras alınabilir bir sınıf, **her `protected` üyesi ve her metodun davranışı**
için sözleşme vermiş olur. Alt sınıf bir metodu ezerse, üst sınıfın varsayımları
sessizce bozulabilir. `final` bu yükü baştan reddeder.

Kural: kalıtım **tasarlanmalıdır**, kazara oluşmamalıdır. Tasarlamadıysak `final`.

### 3.3 `static` ve `$this` / `self`

```php
public function rank(): int
{
    return (int) $this->config('rank');      // $this = "bu örnek"
}

public static function lowest(): self
{
    return self::Standart;                    // self = "bu sınıf"
}
```

| | Ne demek | Ne zaman |
|---|---|---|
| `$this` | *Bu nesne örneği* | Normal (instance) metotta |
| `self` | *Bu sınıfın kendisi* | `static` metotta, sabitlerde, enum case'lerinde |

`static` metot, örnek oluşturmadan çağrılır: `SubscriptionTier::lowest()`.

### 3.4 `const` — sınıf sabiti

```php
private const RULE_PARAM_NAMES = [
    'between' => ['min', 'max'],
    'max' => ['max'],
];
```

Çalışma anında değişmez, bellekte tek kopya durur. `self::RULE_PARAM_NAMES` ile
erişilir (§4).

Neden sabit, neden property değil? Çünkü **her örnekte aynı**. Property olsaydı
her `ApiExceptionRenderer` nesnesi kendi kopyasını taşırdı — gereksiz.

### 3.5 Tipsiz property (eski Laravel kalıbı)

```php
protected $signature = 'errors:export {--path=...}';
protected $description = 'ErrorCode enum katalogunu ...';
```

Tip bildirimi **yok**. Bu bizim tercihimiz değil — Laravel'in `Command` sınıfı
bu property'leri tipsiz tanımlamış; alt sınıfta tip eklemek "declaration must be
compatible" hatası verir. Miras aldığın imzayı daraltamazsın.

Kendi yazdığımız yerlerde tip her zaman yazılır.

---

## 4. `->` ile `::` ayrımı — en sık karıştırılan

Bu ikisi PHP'ye yeni başlayan herkesin takıldığı yerdir. Kural basit:

```
->     NESNE üzerinde çalışır     (bir örneğin var)
::     SINIF üzerinde çalışır     (örneğe ihtiyacın yok)
```

Kodumuzdan yan yana:

```php
$code->status()                    // $code bir enum ÖRNEĞİ
ErrorCode::ValidationFailed        // ErrorCode bir SINIF adı
$this->config('rank')              // $this = bu örnek
self::Standart                     // self = bu sınıf
ErrorCode::cases()                 // static metot
self::RULE_PARAM_NAMES             // sınıf sabiti
self::SUCCESS                      // miras alınan sınıf sabiti
$e::class                          // bu nesnenin sınıf ADI (string)
HealthController::class            // sınıf adı, string olarak
```

### 4.1 `::class` özel durumu

```php
Route::get('/ping', HealthController::class);
$middleware->prependToGroup('api', ForceJsonResponse::class);
'exception' => $e::class,
```

`::class` sınıfın **tam adını string olarak** verir:
`"App\Http\Controllers\Api\V1\HealthController"`.

Neden düz string yazmıyoruz? Çünkü `::class` yazınca:

- IDE sınıfı tanır, yeniden adlandırınca **buradaki de değişir**
- Sınıf yoksa PHPStan yakalar
- "Bu sınıf nerede kullanılıyor" araması çalışır

Düz string yazsaydık üçü de kaybolurdu — `ErrorCode` enum'unun sihirli string'e
karşı verdiği savaşın aynısı.

---

## 5. Fonksiyonlar ve dönüş

```php
public function handle(Request $request, Closure $next): Response
{
    $request->headers->set('Accept', 'application/json');

    return $next($request);
}
```

TypeScript'e çok benzer. Farklar:

- `function` anahtar kelimesi metotlarda da **zorunlu**
- Dönüş tipi `:` ile **sonda**, TS ile aynı
- Varsayılan değer: `function f(int $x = 10)`
- **İsimli argüman** (§15.4)

---

## 6. Diziler — PHP'nin en tuhaf yanı

🔴 **PHP'de tek bir `array` tipi vardır ve o hem `Array` hem `Record`'dur.**

```php
$list = ['min', 'max'];                        // "liste": anahtarlar 0, 1
$map  = ['rule' => 'max', 'params' => [...]];  // "sözlük": anahtarlar string
```

İkisi de `array` tipindedir. TypeScript'te `string[]` ile
`Record<string, unknown>` ayrı tiplerdir; PHP'de değil.

Bu ayrımı **yalnızca docblock** yapabilir (§13):

```php
/** @return list<string> */          // 0'dan başlayan, boşluksuz dizi
/** @return array<string, mixed> */  // string anahtarlı sözlük
```

### 6.1 Sık kullandığımız dizi işlemleri

```php
$fields[$field][] = $entry;          // sona ekle (JS'te .push)
$named[$name] = $this->normalize(...); // anahtarla ata
count($codes)                        // uzunluk (.length değil)
```

🔴 `$fields[$field][] = $entry;` satırı PHP'ye özgü bir kolaylık içerir:
`$fields[$field]` **hiç yoksa PHP onu otomatik boş dizi olarak yaratır**
(*autovivification*). JS'te `obj[k].push(v)` yapmadan önce `obj[k] ??= []`
yazman gerekir; PHP'de gerekmez.

### 6.2 `foreach`

```php
foreach (ErrorCode::cases() as $case) { ... }                 // değerler
foreach ($e->validator->failed() as $field => $rules) { ... } // anahtar => değer
foreach ($names as $index => $name) { ... }
```

`for...of` ve `Object.entries()`'in tek bir yapıda birleşmiş hâli.

### 6.3 Kullandığımız dizi fonksiyonları

| Fonksiyon | Ne yapar | Nerede |
|---|---|---|
| `array_flip` | Anahtar ↔ değer takas eder | `filterParams()` |
| `array_intersect_key` | Yalnızca ikisinde de olan anahtarları tutar | `filterParams()` |
| `array_values` | Anahtarları atar, 0'dan yeniden numaralar | `fields()` |
| `array_map` | Her elemana fonksiyon uygular (JS `.map`) | `nameRuleParams()` |
| `array_key_exists` | Anahtar var mı (değeri null olsa bile) | `nameRuleParams()` |
| `in_array` | Değer dizide var mı | `isRetryable()` |
| `ksort` | Anahtara göre sırala (yerinde) | `buildCatalog()` |

Beyaz listenin uygulandığı satır bunların en zarifi:

```php
return array_intersect_key($params, array_flip($this->allowedParams()));
//                          ↑ gelen            ↑ ['requiredTier'] → ['requiredTier' => 0]
//     sonuç: yalnızca izinli anahtarlar
```

> 🔴 `in_array($this->status(), [429, 502, 503], true)` — üçüncü parametre `true`
> **zorunludur**. Olmazsa PHP gevşek karşılaştırma yapar ve eski sürümlerde
> `"abc" == 0` gibi sürprizler doğar. Üçüncü parametreyi her zaman yaz.

---

## 7. Karşılaştırma: `===` her zaman

```php
if ($params !== []) { ... }
if (config('app.debug') === true) { ... }
if ($this->option('check') === true) { ... }
$limit === null ? null : (int) $limit
```

| Operatör | Ne yapar |
|---|---|
| `==` | Tipleri **dönüştürerek** karşılaştırır — tuzak |
| `===` | Tip **ve** değer aynı mı |

TypeScript'teki `==` / `===` ile aynı fikir, ama PHP'de `==` çok daha tehlikelidir.
Projede istisnasız `===` kullanılır.

> `config('app.debug') === true` neden `if (config('app.debug'))` değil? Çünkü
> `.env`'den gelen değer `"false"` **string'i** olabilir ve boş olmayan her string
> doğrudur. `=== true` yazınca yalnızca gerçek `true` geçer — H3 kuralının
> ("debug bloğu üretimde hiç çalışmaz") teknik dayanağı budur.

---

## 8. `match`

TypeScript'te yok; PHP 8'in en sevilen özelliği.

```php
return match ($this) {
    self::MalformedRequest => 400,

    self::Unauthenticated,
    self::InvalidCredentials,
    self::TokenExpired => 401,          // virgülle çoklu kol

    default => ErrorCode::ServerError,  // isteğe bağlı
};
```

`switch`'ten dört farkı:

| | `switch` | `match` |
|---|---|---|
| Karşılaştırma | `==` (gevşek) | `===` (katı) |
| `break` | Unutulursa alta akar | Yok, akma yok |
| Değer üretir mi | Hayır (ifade değil) | **Evet** — `return match(...)` |
| Eşleşme yoksa | Sessizce geçer | `UnhandledMatchError` fırlatır |

Son satır kritik: `ErrorCode`'a yeni bir `case` eklersen ve `status()` içinde
karşılığını yazmazsan, kod **çalışma anında patlar**. Sessizce yanlış cevap
vermez. Bu "gürültülü hata" tercihi bilinçlidir.

### 8.1 `match (true)` — numaralandırılmış `if/elseif`

```php
return match (true) {
    $e instanceof ValidationException => ErrorCode::ValidationFailed,
    $e instanceof AuthenticationException => ErrorCode::Unauthenticated,
    $e instanceof ThrottleRequestsException => ErrorCode::RateLimited,
    // ...
    $e instanceof HttpExceptionInterface => $this->fromStatus($e->getStatusCode()),
    default => ErrorCode::ServerError,
};
```

Kollar birer **koşuldur**; `true` ile eşleşen ilki kazanır.

🔴 **Sıra anlamlıdır** — Faz 1'in **H13** kuralı ve 17. dersi. Normal `match`'te
değerler benzersizdir, sıra fark etmez. `match (true)`'da **yukarıdan aşağı**
denenir. `ThrottleRequestsException` aynı zamanda bir `HttpExceptionInterface`
olduğu için, genel kol yukarı çıkarsa özel kol **hiç çalışmaz** ve `retryAfter`
kaybolur.

**Kural: özelden genele sırala.**

---

## 9. Enum

```php
enum ErrorCode: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';

    public function status(): int { ... }
}
```

`: string` kısmı bunu **backed enum** yapar — her case'in bir "arka değeri" var.

| Yazım | Sonuç |
|---|---|
| `ErrorCode::ValidationFailed` | Enum **örneği** (nesne) |
| `ErrorCode::ValidationFailed->value` | `'VALIDATION_FAILED'` (string) |
| `ErrorCode::from('VALIDATION_FAILED')` | String'den örneğe; bulunamazsa fırlatır |
| `ErrorCode::tryFrom('YOK')` | Bulunamazsa `null` |
| `ErrorCode::cases()` | Tüm case'lerin dizisi |

TypeScript'in `enum`'undan **çok daha güçlüdür**: PHP enum'u gerçek bir sınıftır,
**metot taşıyabilir**. `status()`, `allowedParams()`, `covers()` bunun sayesinde
mümkün.

```php
foreach (ErrorCode::cases() as $case) {       // ExportErrorCodes'tan
    $codes[$case->value] = [
        'status' => $case->status(),
        'params' => $case->allowedParams(),
    ];
}
```

Bu döngü **G3** kuralının uygulamasıdır: üreteç veriyi kendisi bilmez, enum'dan
türetir. Yeni bir hata kodu eklendiğinde katalog kendiliğinden büyür.

---

## 10. Closure ve arrow function

### 10.1 Klasik closure

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->prependToGroup('api', ForceJsonResponse::class);
})
```

Adı olmayan fonksiyon. JS'teki `function () {}` ile aynı.

🔴 **PHP'de closure dışarıdaki değişkenleri OTOMATİK göremez.** JavaScript'te
kapsam zinciri kendiliğinden çalışır; PHP'de değişkeni açıkça içeri almalısın:

```php
$limit = 10;
$f = function () use ($limit) { return $limit; };   // use ZORUNLU
```

Bu, PHP'nin en çok şaşırtan farklarından biridir.

### 10.2 Arrow function — `use` gerektirmez

```php
fn (Throwable $e, Request $request) => $request->expectsJson()
    ? app(ApiExceptionRenderer::class)->render($e)
    : null,

fn (string $value): string => mb_strtolower(trim($value))
```

`fn` **tek ifadelik**tir, `return` yazılmaz ve dış kapsamı **otomatik yakalar**.
JS'in `=>` fonksiyonuna en yakın karşılık budur. Kısa olduğu için tercih ediyoruz.

| | `function` | `fn` |
|---|---|---|
| Gövde | Çok satır, `{}` | Tek ifade |
| `return` | Gerekir | Yazılmaz |
| Dış değişken | `use (...)` ile | Otomatik |

### 10.3 `Closure` bir tiptir

```php
public function handle(Request $request, Closure $next): Response
```

Middleware'deki `$next` bir closure'dır: "zincirdeki bir sonraki halka".
`$next($request)` onu **çağırır**. Faz 1'de anlatılan *Chain of Responsibility*
deseninin taşıyıcısı bu parametredir.

---

## 11. Trait

```php
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

PHP tek kalıtımlıdır (`extends` bir tane). Trait bu kısıtı aşar: gövdesi hazır
metotlardan oluşan bir paket, derleme anında sınıfın içine **kopyalanır**.

TypeScript'te doğrudan karşılığı yok; en yakını mixin desenidir.

> ⚠️ Sınıfın **içindeki** `use` (trait) ile dosyanın **üstündeki** `use` (ithal)
> aynı kelimedir ama tamamen farklı işlerdir.

---

## 12. Attribute — `#[...]`

```php
#[Fillable(['first_name', 'last_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
```

PHP 8 ile geldi: koda iliştirilen, çalışma anında **okunabilen** meta veri.
TypeScript decorator'larının karşılığıdır.

Attribute tek başına hiçbir şey yapmaz — birinin onu **okuması** gerekir. Burada
okuyan Eloquent'tir: model yüklenirken `#[Fillable]` özniteliğini bulur ve
beyaz listeyi oradan alır.

Laravel 13 öncesi aynı bilgi property'ydi:

```php
protected $fillable = ['first_name', ...];   // eşdeğer, eski biçim
```

İnternetteki eğitimlerin neredeyse tamamı eski biçimi gösterir.

---

## 13. Docblock — yorum değil, tip bildirimi

```php
/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
public function filterParams(array $params): array
```

🔴 **Bunlar süs değildir.** PHP'nin `array` tipi içeriği anlatamadığı için (§6),
eksik bilgi buraya yazılır. **PHPStan bu satırları okur ve zorlar.** Yanlış
yazarsan `composer analyse` kırılır.

Yani docblock bu projede **ikinci bir tip sistemidir** — PHP çalıştırmaz, PHPStan
denetler.

### 13.1 Kullandığımız tip ifadeleri

| İfade | Anlamı | Nerede |
|---|---|---|
| `list<string>` | 0'dan başlayan, boşluksuz string dizisi | `allowedParams()` |
| `array<string, mixed>` | String anahtarlı sözlük | `filterParams()` |
| `array<string, list<string>>` | String → string dizisi | `RULE_PARAM_NAMES` |
| `array{status: int, params: list<string>}` | **Şekil** — belirli anahtarlar | `buildCatalog()` |
| `@var` | Bir değişkenin tipini bildirir | `$payload`, `$path` |
| `@use HasFactory<UserFactory>` | Trait'e generic parametre | `User` |

`array{...}` en güçlüsüdür ve TypeScript'in `interface`'ine denk düşer:

```php
/** @return array{generatedAt: string, count: int, codes: array<string, ...>} */
```

### 13.2 `@var` neden gerekiyor?

```php
/** @var string $path */
$path = $this->option('path');
```

`option()` metodu `mixed` döndürür — Laravel bilemez. PHPStan `mixed`'i
`base_path()`'e veremez. `@var` ile "burada string geleceğini biliyorum" diyoruz.

> Bu bir **kaçış kapısıdır** ve dikkatli kullanılır: yanlış söylersen PHPStan
> sana inanır. Faz 0'ın **K4** kuralı (`ignoreErrors`'a gerekçe zorunlu) aynı
> disiplinin kardeşidir.

---

## 14. String'ler

```php
'application/json'                            // tek tırnak: düz metin
"davetkart.tiers.{$this->value}.{$key}"       // çift tırnak: değişken gömülür
base_path().DIRECTORY_SEPARATOR               // birleştirme: NOKTA
sprintf('%d hata kodu disari aktarildi: %s', count(...), $path)
```

| | Tek tırnak `'` | Çift tırnak `"` |
|---|---|---|
| Değişken | **Gömülmez** | Gömülür |
| `\n` | Düz metin | Satır sonu |
| Hız | Biraz daha hızlı | — |

🔴 **Birleştirme `+` değil `.`** (nokta). `+` PHP'de yalnızca sayı toplar;
string'lerde `TypeError` verir (strict_types sayesinde).

`{$this->value}` süslü parantezi karmaşık ifadeler için gerekir. `"$name"`
yeterliyken `"{$obj->prop}"` zorunludur.

---

## 15. Küçük ama sık görülenler

### 15.1 Tip dönüştürme (cast)

```php
(int) $this->config('rank')       // int'e çevir
(int) $retryAfter
(string) preg_replace(...)
```

`(int)` yazımı bir **dönüştürmedir**, kontrol değil. `strict_types` bunu
engellemez — sen açıkça istiyorsun.

### 15.2 `instanceof`

```php
if ($e instanceof ValidationException) { ... }
if (! $e instanceof ThrottleRequestsException) { ... }
```

"Bu nesne şu sınıfın (veya arayüzün) örneği mi?" TS'teki `instanceof` ile aynı.

🔴 İkinci satıra dikkat: `instanceof`, `!`'ten **daha sıkı bağlar**. Yani
`! $e instanceof X` aslında `!($e instanceof X)` demektir — istenen budur.
Pint boşluklu (`! $e`) yazımı dayatır ki niyet okunur kalsın.

### 15.3 `__invoke` — nesneyi fonksiyon gibi çağırmak

```php
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse { ... }
}
```

`__` ile başlayan metotlar PHP'nin **sihirli metotlarıdır**. `__invoke`, nesne
fonksiyon gibi çağrıldığında (`$obj()`) çalışır.

Laravel bunu görünce rotayı metot adı olmadan bağlayabilir:

```php
Route::get('/ping', HealthController::class);        // ::class yeter
// yerine: [HealthController::class, 'index']
```

Tek eylemi olan controller'lar için doğal biçim budur (SRP).

### 15.4 İsimli argümanlar

```php
Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
```

`ad: değer` biçimi PHP 8 ile geldi. Sırayı hatırlamak gerekmez ve **hangi
parametreyi geçtiğin okunur**. Aradaki parametreleri atlayabilirsin.

### 15.5 Sihirli sabitler

```php
__DIR__          // bu dosyanın bulunduğu klasör
dirname(__DIR__) // bir üst klasör (= proje kökü)
```

Çalışma anında PHP tarafından doldurulur. Mutlak yol yazmaktan kurtarır.

### 15.6 First-class callable — `(...)`

```php
array_map($this->normalize(...), $params)
```

`$this->normalize(...)` metodu **çağırmaz**, onu bir closure'a dönüştürür.
JS'teki `arr.map(this.normalize.bind(this))`'in kısası. PHP 8.1 ile geldi.

Eski yazımı `[$this, 'normalize']`'dı — string olduğu için IDE tanımıyordu.

### 15.7 Bit bayrakları — `|`

```php
json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
```

Buradaki `|` mantıksal "veya" değil, **bitsel veya**dır. Her sabit bir bite
karşılık gelir; `|` ile birleştirilerek "şu iki seçeneği aç" denir.

### 15.8 Üçlü operatör (ternary)

```php
$limit === null ? null : (int) $limit
$retryAfter === null ? [] : ['retryAfter' => (int) $retryAfter]
```

TS ile birebir aynı.

### 15.9 Anonim sınıf

```php
return new class extends Migration { ... };
```

Adı olmayan, tek seferlik sınıf. Migration dosyalarında neden kullanıldığı
[`create_users_table.md`](../database/migrations/0001_01_01_000000_create_users_table.md)
§2.1'de.

---

## 16. TypeScript ↔ PHP hızlı çeviri

| TypeScript | PHP |
|---|---|
| `const x = 1` | `$x = 1` |
| `import { X } from './x'` | `use App\X;` (yol isimden hesaplanır) |
| `export default class` | `class` (namespace ile bulunur) |
| `obj.method()` | `$obj->method()` |
| `Class.staticMethod()` | `Class::staticMethod()` |
| `this.x` | `$this->x` |
| `` `sa ${x}` `` | `"sa {$x}"` |
| `"a" + "b"` | `"a" . "b"` |
| `arr.push(v)` | `$arr[] = $v` |
| `arr.length` | `count($arr)` |
| `arr.map(f)` | `array_map($f, $arr)` |
| `for (const v of arr)` | `foreach ($arr as $v)` |
| `Object.entries(o)` | `foreach ($o as $k => $v)` |
| `x ?? y` | `x ?? y` (aynı) |
| `x?.y` | `$x?->y` |
| `===` | `===` (aynı) |
| `switch` | `match` (tercih edilen) |
| `(a) => a + 1` | `fn ($a) => $a + 1` |
| `interface Shape {}` | docblock `array{...}` veya `interface` |
| `string[]` | docblock `list<string>` |
| `Record<string, unknown>` | docblock `array<string, mixed>` |
| `null` | `null` (aynı) |
| `undefined` | **yok** — PHP'de yalnızca `null` var |

---

## 17. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `$` unutmak | `Undefined constant` | Her değişken `$` ile |
| String birleştirmede `+` | `TypeError` | `.` (nokta) |
| Closure'da `use` unutmak | `Undefined variable` | `use ($x)` veya `fn` |
| Sınıf içi `use` ile üst `use` karıştırmak | Trait uygulanmaz | §11'deki uyarı |
| `->` yerine `::` (veya tersi) | `Error: using $this when not in object context` | §4 |
| `==` kullanmak | Sessiz tip dönüşümü | Her zaman `===` |
| `in_array` üçüncü parametresiz | Gevşek karşılaştırma | `in_array($x, $a, true)` |
| `match (true)` kollarını genelden özele sıralamak | Özel kol hiç çalışmaz | Özelden genele (H13) |
| Docblock'u yorum sanıp yanlış yazmak | `composer analyse` kırılır | §13 |
| Dosya sonuna `?>` koymak | `headers already sent` | Yazma |
| `?>` sonrası boş satır | Aynı hata, bulunması daha zor | Yazma |
| `namespace`'i klasörle uyumsuz bırakmak | `Class not found` (Linux'ta) | PSR-4 birebir |
| Klasörü küçük harf yazmak | Yerelde çalışır, sunucuda patlar | PascalCase |

---

## 18. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **PSR-4** | Namespace ↔ klasör eşlemesi standardı |
| **Autoload** | Sınıfı ilk kullanımda otomatik yükleme |
| **Backed enum** | Her case'in bir skaler değeri olan enum |
| **Trait** | Metot gövdelerini sınıflara paylaştıran yapı |
| **Attribute** | `#[...]` ile koda iliştirilen meta veri |
| **Closure** | Adı olmayan, taşınabilen fonksiyon |
| **Arrow function** | `fn () =>` — tek ifadelik closure |
| **Sihirli metot** | `__` ile başlayan, PHP'nin kendiliğinden çağırdığı metot |
| **First-class callable** | `$obj->m(...)` — metodu closure'a çevirme |
| **Docblock** | `/** */` içindeki, araçların okuduğu tip bildirimi |
| **Autovivification** | Olmayan dizi anahtarının erişimde kendiliğinden oluşması |
| **Strict types** | Otomatik tip dönüşümünü kapatan kip |
| **Visibility** | `public` / `protected` / `private` erişim düzeyi |

---

## 19. Fazlara göre eklenenler

| Faz | Bu dosyaya eklenen |
|---|---|
| 0-1 | §1-§18'in tamamı (enum, match, closure, attribute, docblock, trait) |
| 2 | *(devam ediyor)* mutator/accessor `Attribute` sınıfı, anonim sınıf |
| 3+ | — |

---

## 20. Bağlantılar

| İlgili | Nerede |
|---|---|
| Veritabanı ve migration mantığı | [`veritabani-ve-migration.md`](veritabani-ve-migration.md) |
| Enum'un en zengin örneği | [`app/Enums/ErrorCode.md`](../app/Enums/ErrorCode.md) |
| `match (true)` sıralama dersi | [`fazlar/FAZ-1.md`](../fazlar/FAZ-1.md) §13, ders 17 |
| Kod standartları | [`CLAUDE.md`](../../../CLAUDE.md) |
| Statik analiz neden var | [`phpstan.md`](../phpstan.md) |
