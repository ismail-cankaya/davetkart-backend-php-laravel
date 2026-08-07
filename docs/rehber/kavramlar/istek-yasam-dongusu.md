# İstek Yaşam Döngüsü — Kaynak Kod Üzerinden

> **Kapsanan dosya:** yok — bu bir **kaynak kod okuma rehberidir**.
> **Sorusu:** Bir HTTP isteği geldiğinde hangi fonksiyonlar, hangi sırayla çalışır?
> **Yöntem:** Laravel'in `vendor/` içindeki gerçek kodu okuyarak. Alıntılar
> uydurma değil, birebir kaynaktandır.
> **Bağlantılı:** [`php-dili.md`](php-dili.md) ·
> [`komutlar.md`](komutlar.md) · [`bootstrap/app.md`](../bootstrap/app.md)

> **Bu doküman dört bölümden oluşur:**
>
> | Bölüm | Kapsam | Durum |
> |---|---|---|
> | **1** | `index.php` → Kernel → Pipeline → rota eşleşmesi | ✅ |
> | 2 | Rota çalıştırma → konteyner → FormRequest doğrulama iç mekanizması | ⬜ |
> | 3 | Controller → Action → Eloquent iç mekanizması → Sanctum | ⬜ |
> | 4 | Resource → yanıt → exception yolu | ⬜ |

---

# BÖLÜM 1 — Girişten rota eşleşmesine

## 0. Çağrı yığını haritası

```
public/index.php
└─ Application::handleRequest(Request)
   └─ Kernel::handle(Request)                          ← en dıştaki try/catch
      └─ Kernel::sendRequestThroughRouter(Request)
         ├─ Kernel::bootstrap()                        ← config + provider'lar
         └─ Pipeline::then( Kernel::dispatchToRouter() )
            ├─ [9 global middleware]                   ← TrimStrings burada
            └─ Router::dispatch(Request)
               └─ Router::dispatchToRoute(Request)
                  └─ Router::findRoute(Request)
                     └─ RouteCollection::match(Request)
                        └─ AbstractRouteCollection::matchAgainstRoutes()
                           └─ Route::matches(Request)  ← her rota için tek tek
```

---

## 1. `public/index.php`

```php
define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';              // (a)

$app = require_once __DIR__.'/../bootstrap/app.php';    // (b)

$app->handleRequest(Request::capture());                // (c)
```

**(a)** Composer autoloader yüklenir. Bundan sonra `new App\Models\User` yazıldığında
PHP dosyayı **isimden hesaplayarak** bulur (PSR-4). Faz 0'ın 1. dersi — "Laravel
klasör yapısı elle oluşturulamaz" — burada anlam kazanır: bu dosya olmadan hiçbir
sınıf bulunamaz.

**(b)** 🔴 `bootstrap/app.php` bir `return` ifadesiyle biter:

```php
return Application::configure(...)->withRouting(...)->create();
```

`require_once` bir **ifadedir** ve dosyanın `return` değerini verir. Yani `$app`,
o dosyanın ürettiği `Application` nesnesidir. `create()` çağrıldığı an rotalar,
middleware ve exception handler kayıtlıdır.

**(c)** `Request::capture()` → `$_GET`, `$_POST`, `$_SERVER` ve `php://input`
okunup tek bir `Illuminate\Http\Request` nesnesine dönüştürülür.

---

## 2. `Application::handleRequest()`

```php
public function handleRequest(Request $request)
{
    $kernel = $this->make(HttpKernelContract::class);   // (a)

    $response = $kernel->handle($request)->send();      // (b)

    $kernel->terminate($request, $response);            // (c)
}
```

**(a)** Konteynerden HTTP çekirdeği istenir. `HttpKernelContract` bir **arayüzdür**;
hangi somut sınıfın geleceğine konteyner karar verir. Bu, Faz 7'de `PaymentGateway`
ile yapacağımız şeyin framework'ün kendi içindeki örneğidir (Dependency Inversion).

**(b)** `send()` başlıkları ve gövdeyi tarayıcıya yazar.

**(c)** `terminate()` yanıt **gönderildikten sonra** çalışır — oturum yazma, log
boşaltma gibi işler. Kullanıcı bunları beklemez.

---

## 3. `Kernel::handle()` — 🔴 en dıştaki güvenlik ağı

```php
public function handle($request)
{
    $this->requestStartedAt = Carbon::now();

    try {
        $request->enableHttpMethodParameterOverride();

        $response = $this->sendRequestThroughRouter($request);
    } catch (Throwable $e) {
        $this->reportException($e);                        // (a)
        $response = $this->renderException($request, $e);  // (b)
    }

    $this->app['events']->dispatch(new RequestHandled($request, $response));

    return $response;
}
```

**Bu `try/catch` uygulamanın tamamını sarar.** `RegisterUserAction` içinde
fırlatılan bir exception; Action'dan → Controller'dan → Router'dan → Pipeline'dan
yukarı kabarır ve **burada** yakalanır.

**(a)** `reportException` → log'a yazar. H8'in ("yığın izi yanıta girmez")
uygulandığı yer: iz log'a gider.

**(b)** `renderException` → `bootstrap/app.php`'de kaydettiğimiz render closure'ına
ulaşır → `ApiExceptionRenderer`.

> **Sonuç:** Action ve Controller'da `try/catch` yazmıyoruz (H10) çünkü zaten en
> dışta bir tane var ve **tek** bir biçim üretiyor. Kendi `try/catch`'imiz o tekliği
> bozardı.

---

## 4. `Kernel::sendRequestThroughRouter()`

```php
protected function sendRequestThroughRouter($request)
{
    $this->app->instance('request', $request);   // (a)

    Request::clearResolvedInstance();

    $this->bootstrap();                          // (b)

    return (new Pipeline($this->app))
        ->send($request)                         // (c)
        ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
        ->then($this->dispatchToRouter());
}
```

**(a)** İstek konteynere kaydedilir. Bundan sonra herhangi bir sınıf imzasına
`Request $request` yazarsa **aynı** nesneyi alır.

**(b)** 🔴 `bootstrap()` burada çalışır: `.env` okunur, `config/` yüklenir,
service provider'lar `register()` ve `boot()` edilir.

**`AppServiceProvider::boot()` tam olarak bu satırda çalışır** — katı model kipi,
`CarbonImmutable` ve yıkıcı komut engeli burada devreye girer. Faz 0'da yazdığımız
üç ayar, her isteğin başında yeniden kurulur.

**(c)** Pipeline kurulur → §5.

---

## 5. `Pipeline` — soğan modeli

Laravel'in en zarif ve en çok yanlış anlaşılan parçası.

```php
public function then(Closure $destination)
{
    $pipeline = array_reduce(
        array_reverse($this->pipes()),              // middleware'ler TERSTEN
        $this->carry(),                             // birleştirici
        $this->prepareDestination($destination)     // en içteki çekirdek
    );

    return $pipeline($this->passable);              // dıştan başlat
}
```

`array_reduce` burada **iç içe closure'lar** üretir. Üç middleware (A, B, C) ile
somutlaştıralım:

```
array_reverse:  [C, B, A]

adım 0:  $stack = çekirdek
adım 1:  $stack = fn($req) => C($req, çekirdek)
adım 2:  $stack = fn($req) => B($req, adım1)
adım 3:  $stack = fn($req) => A($req, adım2)
```

Sonuç:

```
A( B( C( çekirdek ) ) )
```

Her katman kendi `$next`'ini **içinde taşır**. `carry()`'nin gövdesi:

```php
return function ($stack, $pipe) {
    return function ($passable) use ($stack, $pipe) {
        // $pipe bir string ise konteynerden çözülür:
        $pipe = $this->getContainer()->make($name);
        $parameters = array_merge([$passable, $stack], $parameters);
        //                          ↑ $request    ↑ $next

        return $pipe->handle(...$parameters);
    };
};
```

🔴 **`ForceJsonResponse`'un imzası buradan geliyor:**

```php
public function handle(Request $request, Closure $next): Response
//                     ↑ $passable      ↑ $stack
```

`$next` sihirli bir şey değil — **bir sonraki katmanı sarmalayan closure**.
`return $next($request)` yazmak zinciri içeri doğru bir adım ilerletir.

Ve `return $next(...)` yazmazsan zincir **orada kesilir**: sonraki katmanlar
çalışmaz, controller hiç çağrılmaz. Faz 5'te rate limit middleware'i tam olarak
bunu yapacak.

> Bu desenin adı **Chain of Responsibility**'dir: her halka ya işi üstlenir ya
> bir sonrakine devreder.

---

## 6. 🔴 Global middleware — 9 tane var

`Configuration\Middleware::getGlobalMiddleware()`:

```php
$middleware = $this->global ?: array_values(array_filter([
    \Illuminate\Http\Middleware\ValidatePathEncoding::class,
    \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
    \Illuminate\Http\Middleware\TrustProxies::class,
    \Illuminate\Http\Middleware\HandleCors::class,
    \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
    \Illuminate\Http\Middleware\ValidatePostSize::class,
    \Illuminate\Foundation\Http\Middleware\TrimStrings::class,                // ←
    \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,  // ←
]));
```

Bunlar **her istekte**, rota eşleşmesinden **önce** çalışır. İkisi bizi doğrudan
ilgilendiriyor.

### 6.1 `TrimStrings`

```php
class TrimStrings extends TransformsRequest
{
    protected $except = ['current_password', 'password', 'password_confirmation'];
}
```

Tüm string girdileri kırpar — **parolalar hariç**. Parolada baştaki/sondaki boşluk
kasıtlı olabilir; kırpmak kullanıcının parolasını sessizce değiştirmek olurdu.

⚠️ **Bu bizim kodumuzda bir tekrar demek.** `RegisterRequest::prepareForValidation()`:

```php
$normalized[$key] = trim($value);    // ← HTTP yolunda GEREKSİZ
```

`TrimStrings` bunu zaten yapmıştır. `mb_strtolower` gereksiz **değildir** (hiçbir
global middleware küçültme yapmaz), ama `trim` ölü ağırlıktır.

| Kalsın diyen | Kalksın diyen |
|---|---|
| Global middleware kaldırılırsa savunma sürer | Ölü kod; okuyanı "burada trim gerekiyor" diye yanıltır |
| Niyeti belgeliyor | Framework'ün garantisini bilmemek gibi görünüyor |

> **Asıl ders:** Savunma kodu yazmadan önce **framework'ün zaten ne yaptığını oku.**
> Bu satır yazılırken okunmamıştı.

### 6.2 `ConvertEmptyStringsToNull`

`firstName: ""` gönderilirse `null`'a çevrilir. Bu önemlidir: boş string PHP'de
"dolu" sayılır ve `required` kuralını **geçerdi**. Bu middleware sayesinde
`required` onu yakalar.

---

## 7. `dispatchToRouter()` — soğanın çekirdeği

```php
protected function dispatchToRouter()
{
    return function ($request) {
        $this->app->instance('request', $request);   // (a)

        return $this->router->dispatch($request);
    };
}
```

**(a)** İstek konteynere **tekrar** kaydedilir — çünkü `TrimStrings` gibi
middleware'ler onu değiştirmiş olabilir. Buradan sonrası **temizlenmiş** isteği
görür.

---

## 8. `Router::dispatch()` → `findRoute()`

```php
public function dispatch(Request $request)
{
    $this->currentRequest = $request;

    return $this->dispatchToRoute($request);
}

public function dispatchToRoute(Request $request)
{
    return $this->runRoute($request, $this->findRoute($request));
    //                                    ↑ ÖNCE bu çalışır
}

protected function findRoute($request)
{
    $this->events->dispatch(new Routing($request));

    $this->current = $route = $this->routes->match($request);

    $route->setContainer($this->container);
    $this->container->instance(Route::class, $route);

    return $route;
}
```

🔴 **PHP argümanları soldan sağa değerlendirir.** `runRoute($request, $this->findRoute($request))`
çağrısında `findRoute` **önce** koşar; eşleşme yoksa exception fırlar ve `runRoute`
hiç çağrılmaz.

Bu tek satır, `bootstrap/app.php`'deki `is('api/*')` düzeltmesinin tüm
gerekçesidir: **rota bulunamazsa grup middleware'ine sıra hiç gelmez**, dolayısıyla
`ForceJsonResponse` çalışmaz ve `expectsJson()` false döner
(bkz. [`bootstrap/app.md`](../bootstrap/app.md) §2.4).

---

## 9. `RouteCollection::match()` — asıl eşleştirme

```php
public function match(Request $request)
{
    $routes = $this->get($request->getMethod());          // (a)

    $route = $this->matchAgainstRoutes($routes, $request);

    return $this->handleMatchedRoute($request, $route);   // (c)
}
```

**(a)** Rotalar HTTP metoduna göre gruplanmış tutulur. `POST` isteğinde `GET`
rotalarına hiç bakılmaz.

```php
protected function matchAgainstRoutes(array $routes, $request, $includingMethod = true)
{
    $fallbackRoute = null;

    foreach ($routes as $route) {                 // (b) SIRAYLA
        if ($route->matches($request, $includingMethod)) {
            if ($route->isFallback) {
                $fallbackRoute ??= $route;        // fallback en sona saklanır
                continue;
            }
            return $route;                        // ilk eşleşen KAZANIR
        }
    }

    return $fallbackRoute;
}
```

**(b)** 🔴 **Rota sırası anlamlıdır.** İlk eşleşen kazanır.

Faz 3 için şimdiden not: `/invitations/{id}` rotasını `/invitations/featured`
rotasından **sonra** yazmak gerekir. Aksi hâlde `{id}` deseni `"featured"`
kelimesini yutar ve sabit rota hiç çalışmaz.

```php
public function matches(Request $request, $includingMethod = true)
{
    $this->compileRoute();                        // URI → regex

    foreach (self::getValidators() as $validator) {
        if (! $validator->matches($this, $request)) {
            return false;
        }
    }

    return true;
}
```

Dört doğrulayıcı sırayla sorulur:

| Doğrulayıcı | Sorusu |
|---|---|
| `UriValidator` | URI regex'i eşleşiyor mu? |
| `MethodValidator` | HTTP metodu doğru mu? |
| `SchemeValidator` | `https` zorunlu mu? |
| `HostValidator` | Alt alan adı kısıtı var mı? |

**(c)** `handleMatchedRoute` — `$route` `null` ise:

| Durum | Fırlatılan | Bizim kodumuz |
|---|---|---|
| Aynı URI başka metotla var | `MethodNotAllowedHttpException` (405) | `fromStatus(405)` → `RESOURCE_NOT_FOUND` |
| Hiç yok | `NotFoundHttpException` (404) | `fromStatus(404)` → `RESOURCE_NOT_FOUND` |

> Faz 1'in `wrong_http_method_does_not_reveal_route_existence` testi buradan gelir.
> 405 *"bu adres var ama metot yanlış"* der ve rotanın varlığını **doğrular** —
> H7 gereği ikisini de 404'e eşliyoruz.

---

## 10. Bölüm 1 özeti — bizim kodumuz nerede devreye girdi?

| Adım | Bizim dosyamız | Ne yaptı |
|---|---|---|
| 1(b) | `bootstrap/app.php` | Rota, middleware, exception handler kaydı |
| 4(b) | `AppServiceProvider::boot()` | Katı model kipi, `CarbonImmutable`, komut engeli |
| 6 | *(yok)* | 9 global middleware Laravel'in |
| 8-9 | `routes/api.php` | `POST api/auth/register` eşleşti ✅ |

Bu noktada elimizde bir `Route` nesnesi var ama **henüz hiçbir iş kodumuz
çalışmadı**. `ForceJsonResponse` bile çalışmadı — o bir *rota* middleware'idir ve
sırası Bölüm 2'dedir.

---

## 11. Terim sözlüğü (Bölüm 1)

| Terim | Anlamı |
|---|---|
| **Front controller** | Tüm isteklerin girdiği tek dosya (`public/index.php`) |
| **Kernel** | İsteği yanıta çeviren en üst düzey bileşen |
| **Pipeline** | İsteği katmanlardan geçiren yapı |
| **Chain of Responsibility** | Halkanın ya işi üstlendiği ya devrettiği desen |
| **`array_reduce`** | Diziyi tek değere indirgeyen fonksiyon |
| **Bootstrap** | Uygulamayı çalışmaya hazır hâle getirme aşaması |
| **Service provider** | Bileşenleri konteynere kaydeden sınıf |
| **Route compilation** | URI desenini regex'e çevirme |
| **Fallback route** | Hiçbiri eşleşmezse devreye giren rota |

---

## 12. Bağlantılar

| İlgili | Nerede |
|---|---|
| `bootstrap/app.php` ayrıntısı | [`bootstrap/app.md`](../bootstrap/app.md) |
| Middleware kuralları (M1-M4) | [`fazlar/FAZ-1.md`](../fazlar/FAZ-1.md) §4.3 |
| Rota kuralları (R1-R5) | [`fazlar/FAZ-1.md`](../fazlar/FAZ-1.md) §4.2 · [`routes/api.md`](../routes/api.md) |
| PHP closure ve `$next` | [`php-dili.md`](php-dili.md) §10 |
| Bölüm 2 | *(yazılacak)* — konteyner ve FormRequest doğrulaması |
