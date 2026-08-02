# `bootstrap/app.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `bootstrap/app.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.3b
> **Bağlantılı:** [`ForceJsonResponse.md`](../app/Http/Middleware/ForceJsonResponse.md) ·
> [`ApiExceptionRenderer.md`](../app/Exceptions/ApiExceptionRenderer.md)

---

## 0. Bir dakikalık özet

Bu dosya uygulamanın **elektrik panosu**dur: hangi kablo nereye gider, onu söyler.
Kendisi iş yapmaz.

Faz 1'de iki kablo bağlandı:

| Kablo | Nereye |
|---|---|
| `ForceJsonResponse` | `api` middleware grubunun **başına** |
| Hata render'ı | `ApiExceptionRenderer` sınıfına |

Ayrıca eski `shouldRenderJsonWhen(...)` satırı **kaldırıldı** — işini artık
`ForceJsonResponse` yapıyor.

---

## 1. Laravel 11+ ile ne değişti?

İnternetteki eğitimlerin çoğu `app/Http/Kernel.php` dosyasını anlatır. **O dosya
artık yok.**

| Laravel 10 ve öncesi | Laravel 11+ |
|---|---|
| `app/Http/Kernel.php` — middleware listeleri | `bootstrap/app.php` → `withMiddleware()` |
| `app/Exceptions/Handler.php` — hata yönetimi | `bootstrap/app.php` → `withExceptions()` |
| `app/Providers/RouteServiceProvider.php` | `bootstrap/app.php` → `withRouting()` |

Üç dosyanın işi tek dosyada, **akıcı arayüz** (fluent interface) ile toplandı:

```php
Application::configure(...)
    ->withRouting(...)
    ->withMiddleware(...)
    ->withExceptions(...)
    ->create();
```

Her metot `$this` döndürdüğü için zincirlenebilir — jQuery veya Laravel'in sorgu
kurucusundaki (`->where()->orderBy()->get()`) desenin aynısı.

> 🔴 Bir hata ararken "Kernel.php'de şunu değiştir" diyen bir kaynak görürsen,
> o kaynak eski. Karşılığı bu dosyadadır.

---

## 2. Kod okuması

### 2.1 `withRouting()` — dokunulmadı

```php
web: __DIR__.'/../routes/web.php',
api: __DIR__.'/../routes/api.php',
commands: __DIR__.'/../routes/console.php',
health: '/up',
```

Hangi rota dosyasının hangi gruba yükleneceğini söyler. `health: '/up'` Laravel'in
hazır sağlık kontrolü rotasıdır — Faz 1'de yazacağımız `/api/ping`'den farklıdır:
`/up` framework'ün ayakta olduğunu, `/api/ping` **bizim** API sözleşmemizin
çalıştığını doğrular.

> `api` grubuna yüklenen rotalar otomatik olarak `/api` önekini alır. Bu yüzden
> `routes/api.php` içinde `Route::get('/ping')` yazmak `/api/ping` üretir —
> K10'un (versiyon URL'de değil) mümkün olmasının sebebi budur.

### 2.2 🔴 `prependToGroup` — neden `append` değil?

```php
$middleware->prependToGroup('api', ForceJsonResponse::class);
```

Üç seçenek vardı:

| Metot | Nereye koyar | Sonuç |
|---|---|---|
| `$middleware->append(...)` | **Global** listenin sonuna | Web sayfaları da JSON'a zorlanır ❌ |
| `appendToGroup('api', ...)` | `api` grubunun **sonuna** | Erken hatalar HTML döner ❌ |
| `prependToGroup('api', ...)` | `api` grubunun **başına** | ✅ |

**Neden başa?** Middleware'ler sırayla çalışır ve zincirin ortasında bir exception
fırlarsa, kendisinden **sonraki** middleware'ler hiç çalışmaz:

```
İstek → [ForceJsonResponse] → [throttle] → Controller
              ✅ Accept ayarlandı    ↑
                                     └── burada 429 fırlarsa,
                                         Accept zaten ayarlı → JSON ✅
```

Ters sırada olsaydı:

```
İstek → [throttle] → [ForceJsonResponse] → Controller
             ↑
             └── 429 fırlar, ForceJsonResponse HİÇ ÇALIŞMAZ
                 → Accept: text/html → HTML hata sayfası ❌
```

Rate limit tam olarak saldırı anında devreye girer. O anda HTML hata sayfası
dönmek, en kötü zamanda en fazla bilgiyi sızdırmak demektir.

**Neden global değil?** Proje ileride bir web arayüzü (admin paneli, ödeme dönüş
sayfası) kazanabilir. Global kayıt o sayfaları da JSON'a zorlar ve tarayıcıda ham
JSON gösterir. Grup kaydı kapsamı açıkça sınırlar.

### 2.3 `$exceptions->render(...)` ve `null` dönüşü

```php
$exceptions->render(
    fn (Throwable $e, Request $request) => $request->expectsJson()
        ? app(ApiExceptionRenderer::class)->render($e)
        : null,
);
```

Laravel bu closure'ı **her** exception için çağırır ve dönüş değerine göre davranır:

| Dönüş | Laravel ne yapar |
|---|---|
| Bir `Response` nesnesi | Onu kullanır, kendi işleyişini atlar |
| **`null`** | *"Ben karar vermedim"* — varsayılan akışına devam eder |

Bu, **Chain of Responsibility** desenidir: halka ya işi üstlenir ya da bir sonrakine
devreder.

> 🔴 **`Throwable` neden `use` ile ithal edilmiyor?** Faz 2'de bu dosyada
> `use Throwable;` satırı vardı ve her `composer check` koşusunda şu uyarıyı
> üretiyordu:
>
> ```
> PHP Warning: The use statement with non-compound name 'Throwable' has no effect
> ```
>
> Sebebi: bu dosyanın **`namespace` bildirimi yok** — yani zaten global isim
> alanındayız. `Throwable` de global bir sınıf. Global bir sınıfı global alana
> ithal etmek etkisiz bir işlemdir ve PHP bunu söyler. Satır silindi;
> `Throwable` ithalsiz zaten çözülüyor.
>
> Diğer `use` satırları (`Illuminate\Http\Request` vb.) **bileşik** isimler
> olduğu için gereklidir ve uyarı üretmez. Kural: ithal, yalnızca ismin
> **kısaltılması** gerektiğinde anlamlıdır.

Bizim koşulumuz `expectsJson()`. API isteklerinde bu **her zaman true**'dur, çünkü
`ForceJsonResponse` `Accept` başlığını ezdi. Web rotalarında middleware çalışmadığı
için false kalır ve Laravel normal HTML akışını sürdürür.

> 🔴 İki parça birbirini tamamlıyor: middleware **kapsamı** belirliyor
> (hangi istekler), renderer **biçimi** üretiyor (zarf nasıl görünür). Kapsam
> kararı bir URL string'ine değil, middleware grup üyeliğine bağlı.

### 2.4 `app(...)` — servis konteyneri

```php
app(ApiExceptionRenderer::class)
```

`new ApiExceptionRenderer()` yerine `app()` kullanıldı. `app()` Laravel'in **servis
konteyneri**ne "bana bu sınıftan bir örnek ver" der.

Şu an fark küçük çünkü sınıfın constructor'ı boş. Ama yarın renderer'a bir bağımlılık
eklenirse (örneğin Faz 8'de bir `Logger` veya çeviri servisi), konteyner onu
**otomatik olarak** çözer — bu dosyaya dokunmak gerekmez:

```php
// Yarın böyle olursa, bootstrap/app.php AYNI kalır
public function __construct(private readonly SomeService $service) {}
```

Buna **Dependency Injection** denir. `new` yazmak, bağımlılık listesini çağıran
tarafın sorumluluğuna verirdi.

### 2.5 Kaldırılan satır

```php
// ❌ Artık yok
$exceptions->shouldRenderJsonWhen(
    fn (Request $request) => $request->is('api/*'),
);
```

Bu satır aynı problemi **URL deseniyle** çözüyordu. Gerekçesi
[`ForceJsonResponse.md`](../app/Http/Middleware/ForceJsonResponse.md) §3.1'de:
`api/*` bir *tahmindir*. Kök seviyede bir rota açıldığı gün (ödeme sağlayıcısı bir
webhook URL'i dayatırsa) tahmin sessizce yanlışlanır ve o rota HTML döner.

İkisini birden bırakmak da yanlıştı: aynı kararı iki yerde veren kod, biri
değiştiğinde diğerinin unutulmasına yol açar. **Tek mekanizma, tek okuma noktası.**

---

## 3. Şu anki istek yaşam döngüsü

Faz 1 sonunda bir isteğin izlediği yol:

```
1. public/index.php            ← tek giriş noktası
2. bootstrap/app.php           ← BU DOSYA: uygulamayı kurar
3. [ForceJsonResponse]         ← Accept: application/json
4. [api grubunun geri kalanı]  ← throttle vb.
5. routes/api.php              ← rota eşleşmesi
6. Controller / closure
   │
   ├─ başarılı → JSON yanıt
   └─ exception fırladı
        ↓
7. $exceptions->render(...)    ← BU DOSYA: kararı devreder
8. ApiExceptionRenderer        ← { error: { code, ... } }
```

Adım 2 yalnızca **bir kez** çalışır (uygulama kurulurken); 3-8 her istekte.

---

## 4. Tasarım kararları

### 4.1 Neden mantık burada değil?

Bu dosya **yapılandırmadır**. `ApiExceptionRenderer`'daki ~130 satırlık eşleme
mantığı buraya konsaydı:

| Sorun | Açıklama |
|---|---|
| Test edilemezdi | Closure'ı çağırmak için tüm uygulamayı ayağa kaldırmak gerekir |
| PHPStan zorlanırdı | Kapalı closure yerine tipli metotlar daha iyi çözümlenir |
| Faz 5/7'de büyürdü | Her yeni exception bu dosyayı şişirirdi |

Şimdiki hâlinde Faz 5'te yeni bir exception eklemek `resolveCode()`'a **tek satır**
demek; bu dosyaya hiç dokunulmaz. Buna **Open/Closed Principle** denir: genişlemeye
açık, değişikliğe kapalı.

### 4.2 `withMiddleware`'de `//` yorumu neden kalmadı?

Laravel iskeleti boş bir gövde ile gelir. Artık gerçek bir kayıt var; ama ileride
Faz 5'te rate limit tanımları ve Faz 9'da güvenlik başlıkları da buraya gelecek.
Dosyanın büyümesi beklenen ve normaldir — **kablolama** büyür, mantık büyümez.

---

## 5. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `appendToGroup` kullanmak | Erken fırlayan hatalar (429) HTML döner | `prependToGroup` |
| `append()` (global) kullanmak | Gelecekteki web sayfaları JSON'a zorlanır | Grup bazlı |
| `render` closure'ında `null` dönmemek | Web rotaları da JSON alır | Koşullu `null` |
| `new ApiExceptionRenderer()` yazmak | Yarın bağımlılık eklenince kırılır | `app(...)` |
| `shouldRenderJsonWhen`'i de bırakmak | İki mekanizma, belirsiz sorumluluk | Tek mekanizma |
| Mantığı bu dosyaya inline yazmak | Test edilemez, PHPStan zorlanır | Ayrı sınıf |
| `Kernel.php` aramak | Laravel 11+ ile kaldırıldı | Bu dosya |

---

## 6. Kendin dene

1.4 (`/api/ping`) yazıldıktan sonra:

```powershell
php artisan serve
```

```powershell
# Tarayıcı taklidi — HTML istiyor ama JSON almalı
curl.exe -H "Accept: text/html" http://localhost:8000/api/olmayan
# {"error":{"code":"RESOURCE_NOT_FOUND","debug":{...}}}

# Web tarafı etkilenmemeli — HTML dönmeli
curl.exe -H "Accept: text/html" http://localhost:8000/olmayan
# <!DOCTYPE html> ...
```

İkinci komut önemli: `render` closure'ının `null` dönüşü sayesinde web rotaları
kendi akışını koruyor. Kapsam sınırının çalıştığının kanıtı.

**Kasten kır:** `prependToGroup`'u `appendToGroup` yap ve Faz 5'te rate limit
testine geldiğinde 429'un HTML döndüğünü gör. Şimdilik sadece not et — farkı
gösteren senaryo o fazda oluşacak.

---

## 7. Sözlük

| Terim | Anlamı |
|---|---|
| **Akıcı arayüz** (fluent interface) | `$this` döndürerek zincirlenebilen metotlar |
| **Middleware grubu** | Belirli rotalara toplu uygulanan middleware listesi |
| **`prepend` / `append`** | Listenin başına / sonuna ekleme |
| **Chain of Responsibility** | Halkanın ya işi üstlendiği ya devrettiği desen |
| **Servis konteyneri** | Nesneleri ve bağımlılıklarını çözen Laravel bileşeni |
| **Dependency Injection** | Bağımlılığı içeride `new`'lemek yerine dışarıdan alma |
| **Open/Closed Principle** | Genişlemeye açık, değişikliğe kapalı (SOLID'in O'su) |
| **İstek yaşam döngüsü** | İsteğin girişten yanıta kadar izlediği yol |
