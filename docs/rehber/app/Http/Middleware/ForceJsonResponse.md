# `app/Http/Middleware/ForceJsonResponse.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Http/Middleware/ForceJsonResponse.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.2
> **Bağlantılı:** [`ErrorCode.md`](../../Enums/ErrorCode.md) ·
> [`docs/08-HATA-SOZLESMESI.md`](../../../../08-HATA-SOZLESMESI.md)

---

## 0. Bir dakikalık özet

Dört satırlık bir dosya, tek iş yapıyor: gelen isteğin `Accept` başlığını
`application/json` olarak **ezer**.

Sebep: Laravel, yanıtın HTML mi JSON mu olacağına bu başlığa bakarak karar verir.
Tarayıcı adres çubuğundan gelen istek `text/html` gönderir. Önlem alınmazsa
`localhost:8000/api/olmayan` adresine giden bir istek, JSON yerine Laravel'in
**HTML hata sayfasını** alır — ve o sayfa yığın izi, dosya yolları, ortam
değişkenleri gösterir.

---

## 1. Middleware nedir?

Middleware, isteğin controller'a **varmadan önce** ve yanıtın tarayıcıya
**dönmeden önce** geçtiği ara katmandır.

```
İstek  →  [Middleware 1]  →  [Middleware 2]  →  Controller
                                                    │
Yanıt  ←  [Middleware 1]  ←  [Middleware 2]  ←──────┘
```

Soğan zarı gibi düşün: istek dışarıdan içeri, yanıt içeriden dışarı geçer. Her
katman ikisini de görebilir.

### Tipik kullanım alanları

| Middleware | İşi |
|---|---|
| `auth:sanctum` | Token yoksa isteği içeri hiç alma |
| `throttle` | Dakikada 60'tan fazla istek geldiyse durdur |
| **`ForceJsonResponse`** | İsteği "JSON istiyorum" diye işaretle |

### Neden burada, controller'da değil?

Bu bir **kesişen ilgi** (cross-cutting concern): tek bir endpoint'in değil, **tüm**
API'nin ortak ihtiyacı. Controller'a yazsaydık 8 controller × N metot kere tekrar
ederdi ve biri unutulduğunda sessizce HTML dönerdi.

> Kesişen ilgiler middleware'e; **kaynağa özel** kararlar (bu davetiye bu
> kullanıcının mı?) Policy'ye gider. Middleware kaynağı henüz göremez — istek
> router'dan geçmemiş, model yüklenmemiştir.

---

## 2. Kodun satır satır okunması

```php
public function handle(Request $request, Closure $next): Response
{
    $request->headers->set('Accept', 'application/json');

    return $next($request);
}
```

### 2.1 `handle` metodu ve imzası

Laravel her middleware'de `handle` adında bir metot arar. İki parametre alır:

| Parametre | Ne |
|---|---|
| `Request $request` | Gelen istek nesnesi — başlıklar, gövde, URL |
| `Closure $next` | **Zincirin geri kalanı**, bir fonksiyon olarak |

### 2.2 `Closure` nedir?

Closure = **anonim fonksiyon**, yani adı olmayan ve değişkende taşınabilen bir
fonksiyon. JavaScript'teki `(x) => {...}` ile aynı fikirdir.

`$next` burada "bu middleware'den sonra çalışacak her şey" anlamına gelir —
sonraki middleware'ler, router, controller ve dönüş yolu. `$next($request)`
çağrıldığı anda kontrol içeri geçer, iş biter ve `Response` geri gelir.

```php
$response = $next($request);   // içeri git, işi yaptır, yanıtı al
// buraya yazılan kod, controller ÇALIŞTIKTAN SONRA işler
return $response;
```

Bizim dosyamızda dönüş yolunda yapılacak iş olmadığı için doğrudan
`return $next($request);` yazıldı.

🔴 **`$next($request)` çağrılmazsa istek orada ölür.** `auth` middleware'i tam
olarak bunu yapar: token geçersizse `$next`'i hiç çağırmaz, doğrudan 401 döner.

### 2.3 `$request->headers->set(...)`

`$request->headers` bir `HeaderBag` nesnesidir (Symfony'den gelir; Laravel HTTP
katmanını Symfony üzerine kurar). `set()` metodu **varsa üzerine yazar**, yoksa
ekler.

Yani tarayıcı `Accept: text/html` göndermiş olsa bile, bu satırdan sonra Laravel
için o istek `Accept: application/json` göndermiştir. İsteğin kendisi değişmez —
**bizim kopyamız** değişir.

### 2.4 Dönüş tipi neden `Symfony\...\Response`?

```php
use Symfony\Component\HttpFoundation\Response;
```

`Illuminate\Http\Response` değil. Sebep: bir middleware'den geçen yanıt her zaman
Laravel'in `Response` sınıfı olmayabilir — `JsonResponse`, `StreamedResponse`
(dosya indirme), `RedirectResponse` da olabilir. Hepsinin **ortak atası**
Symfony'nin `Response` sınıfıdır.

Dar tip yazsaydık PHPStan haklı olarak şikâyet ederdi: "buradan `JsonResponse` de
geçebilir, imzan yalan söylüyor."

### 2.5 `final` neden var?

```php
final class ForceJsonResponse
```

`final`, "bu sınıf miras alınamaz" demektir. Varsayılan tercihimiz budur.

Bir sınıf miras alınabilir bırakıldığında, onu değiştirirken *"acaba birisi bunu
genişletmiş ve davranışıma bel bağlamış mı?"* diye düşünmek zorunda kalırsın.
`final` bu soruyu ortadan kaldırır. Gerçekten genişletilmesi gereken bir sınıf
çıkarsa `final` o zaman kaldırılır — geri almak kolay, sonradan eklemek kırıcıdır.

> Laravel'in `make:middleware` iskeleti `final` yazmaz. Biz ekliyoruz; bu bir
> Clean Code tercihidir, framework kısıtı değil.

---

## 3. Tasarım kararları

### 3.1 🔴 İki çözüm vardı, neden bu seçildi?

Aynı problemi çözmenin iki yeri var:

| | **Yol A** — çıkışta düzelt | **Yol B** — girişte düzelt ✅ |
|---|---|---|
| Nerede | `bootstrap/app.php` → `shouldRenderJsonWhen()` | Bu middleware |
| Nasıl | "URL'i `api/*` ile eşleşiyorsa JSON bas" | İsteğin başlığını değiştir |
| Neye bağlı | **URL deseni** (string eşleşmesi) | **Middleware grubu** |
| Kimi etkiler | Sadece exception handler | Handler + paketler + kendi kodun |

**Yol B seçildi.** Belirleyici fark URL desenidir.

Yol A bir *tahmine* dayanır: "API rotalarımın hepsi `/api/` ile başlar." Bu tahmin
bugün doğru. Yarın ödeme sağlayıcısı kök seviyede bir webhook URL'i dayattığında
(`/webhooks/iyzico` gibi) tahmin sessizce yanlışlanır ve o rota HTML hata sayfası
dönmeye başlar. Kimse fark etmez, çünkü ortada hata mesajı yoktur — sadece yanlış
biçim vardır.

Yol B'de rota hangi URL'de olursa olsun `api` middleware grubuna kayıtlıysa
korumalıdır. Koruma bir string eşleşmesine değil, **yapısal üyeliğe** bağlıdır.

> Bu, Faz 0'dan beri tekrarlanan ilkenin aynısı: **güvenliği disipline değil
> yapıya bağlamak.** `debug` bloğunun `APP_DEBUG`'a bağlanması, `ErrorCode`
> beyaz listesinin `filterParams()` ile zorlanması ve bu karar aynı ailedendir.

Sonuç olarak `bootstrap/app.php` içindeki `shouldRenderJsonWhen(...)` satırı
**1.3'te kaldırılacak** — iki mekanizma yerine tek mekanizma, tek okuma noktası.

### 3.2 Neden koşulsuz ezme?

Alternatif, istemcinin başlığına saygı göstermekti:

```php
// ❌ Yapmadığımız
if (! $request->expectsJson()) {
    $request->headers->set('Accept', 'application/json');
}
```

Bu koşul hiçbir şey kazandırmaz: `expectsJson()` zaten true ise atama zararsızdır,
false ise atama gerekir. Koşul yalnızca okunacak bir satır ekler.

Asıl gerekçe ise şudur: bizim API'miz **yalnızca** JSON konuşur. PDF veya CSV
dönen bir endpoint'imiz yok. İstemcinin tercihi diye bir şey olmadığı için
müzakere edilecek bir şey de yok.

### 3.3 🔴 `Content-Type`'a neden dokunulmuyor?

Sık yapılan bir hata, bu middleware'de şunu da yazmaktır:

```php
// ❌ ASLA
$request->headers->set('Content-Type', 'application/json');
```

İki başlık **zıt yönleri** anlatır:

| Başlık | Anlamı | Yönü |
|---|---|---|
| `Accept` | "Bana şu biçimde cevap ver" | İstemci → sunucu **beklentisi** |
| `Content-Type` | "Sana gönderdiğim gövde şu biçimde" | İstemci → sunucu **gerçeği** |

`Accept` bir tercihtir, ezilebilir. `Content-Type` ise bir **olgudur** — gövdenin
gerçekte ne olduğunu söyler. Ezmek, gerçeği değiştirmez, sadece yalan söyler.

**Somut sonuç:** Faz 6'da galeri fotoğrafı `multipart/form-data` olarak gelecek.
`Content-Type`'ı `application/json` diye ezersek Laravel gövdeyi JSON olarak
ayrıştırmaya çalışır, başarısız olur ve **dosya yüklemesi tamamen kırılır**. Üstelik
hata mesajı "geçersiz JSON" der; asıl sebebi bu middleware'de aramak günler alır.

### 3.4 Neden bu middleware `api` grubuna kayıtlı, global değil?

1.3'te `api` middleware grubuna eklenecek, `append()` ile global listeye değil.

Sebep: proje ileride bir web arayüzü (admin paneli, ödeme dönüş sayfası)
kazanabilir. Global kayıt o sayfaları da JSON'a zorlar ve tarayıcıda ham JSON
gösterir. Grup kaydı, kapsamı **açıkça** sınırlar.

---

## 4. Ne kazandık? Somut karşılaştırma

`GET /api/olmayan-rota` isteğine tarayıcıdan gidildiğinde:

**Bu middleware olmadan** (`Accept: text/html`, `APP_DEBUG=true`):

```html
<!DOCTYPE html>
<html>... Symfony hata sayfası ...
  NotFoundHttpException
  /vendor/laravel/framework/src/.../RouteCollection.php:44
  DB_PASSWORD ... APP_KEY ...
```

Yığın izi, dosya yolları ve ortam değişkenleri ekranda. `08` §3.3'ün
"asla yanıta girmeyecekler" listesinin tamamı sızmış durumda.

**Bu middleware ile:**

```json
{ "error": { "code": "RESOURCE_NOT_FOUND" } }
```

> ⚠️ Bu ikinci çıktı 1.3'teki exception handler yazıldıktan **sonra** oluşur. Bu
> middleware tek başına yalnızca "JSON olsun" der; JSON'un **içeriğine** handler
> karar verir. İkisi birlikte çalışır.

---

## 5. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `Content-Type`'ı da ezmek | Faz 6'da dosya yükleme kırılır | Sadece `Accept` |
| `$next($request)` çağırmayı unutmak | İstek sessizce ölür, boş yanıt döner | Her zaman `return $next(...)` |
| Dönüş tipini `Illuminate\Http\Response` yazmak | PHPStan hatası — `JsonResponse` de geçer | Symfony `Response` |
| Global middleware olarak kaydetmek | Gelecekteki web sayfaları da JSON'a zorlanır | `api` grubuna |
| Bu middleware'e yetki kontrolü eklemek | Middleware kaynağı göremez | Policy (Faz 3) |
| `shouldRenderJsonWhen`'i de bırakmak | İki mekanizma, belirsiz sorumluluk | 1.3'te kaldırılıyor |

---

## 6. Kendin dene

Bu dosya tek başına gözle görülür bir çıktı üretmez — 1.3 ve 1.4 yazıldıktan sonra
denenebilir. O noktada:

```powershell
# Tarayıcı taklidi: HTML isteyen istek
curl.exe -H "Accept: text/html" http://localhost:8000/api/olmayan-rota

# Beklenen: middleware Accept'i ezdiği için yine de JSON döner
# {"error":{"code":"RESOURCE_NOT_FOUND"}}
```

**Kasten kır:** `$request->headers->set(...)` satırını yorum satırına al ve aynı
`curl` komutunu tekrar çalıştır. Bu kez HTML döneceğini göreceksin — middleware'in
tek satırının ne koruduğu böylece somutlaşır. Sonra yorumu kaldır.

---

## 7. Sözlük

| Terim | Anlamı |
|---|---|
| **Middleware** | İstek/yanıt zincirinde controller'dan önce ve sonra çalışan ara katman |
| **Kesişen ilgi** (cross-cutting concern) | Tek bir modüle değil, sistemin tamamına ait ihtiyaç |
| **Closure** | Adı olmayan, değişkende taşınabilen fonksiyon |
| **`Accept` başlığı** | İstemcinin "şu biçimde cevap ver" tercihi |
| **`Content-Type` başlığı** | Gönderilen gövdenin gerçek biçimi |
| **İçerik pazarlığı** (content negotiation) | Sunucunun `Accept`'e bakarak biçim seçmesi |
| **HeaderBag** | Symfony'nin HTTP başlıklarını tutan nesnesi |
| **`final`** | Sınıfın miras alınmasını engelleyen anahtar kelime |
