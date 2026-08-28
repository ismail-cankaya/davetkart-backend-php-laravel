# `app/Exceptions/ApiExceptionRenderer.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Exceptions/ApiExceptionRenderer.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.3a
> **Bağlantılı:** [`ErrorCode.md`](../Enums/ErrorCode.md) ·
> [`ForceJsonResponse.md`](../Http/Middleware/ForceJsonResponse.md) ·
> [`docs/08-HATA-SOZLESMESI.md`](../../../../08-HATA-SOZLESMESI.md)

---

## 0. Bir dakikalık özet

Bu sınıf, uygulamanın **her hatasının geçtiği tek kapıdır**.

Nereden fırlarsa fırlasın — doğrulama, kimlik doğrulama, veritabanı, senin yazdığın
bir `TypeError` — hepsi buraya gelir ve aynı zarfa çevrilir:

```json
{ "error": { "code": "VALIDATION_FAILED", "fields": { ... } } }
```

Sonuç: controller'lar ve action'lar **hata yanıtı üretmez**, sadece exception
fırlatır. Biçim kararı tek yerde verilir.

---

## 1. Exception nedir ve neden yanıt döndürmek yerine fırlatıyoruz?

### 1.1 Temel fikir

Exception ("istisna"), bir fonksiyonun *"ben bu işi yapamam"* demesinin yoludur.
Normal `return` ile fark şurada: exception, çağrı zincirini **atlayarak** yukarı
fırlar.

```php
function a() { b(); echo "buraya gelinmez"; }
function b() { c(); }
function c() { throw new RuntimeException('patladı'); }
```

`c()` fırlattığında `b()` ve `a()` yarıda kesilir. Exception, onu **yakalayan** ilk
yere kadar yükselir. Kimse yakalamazsa Laravel'in en dış katmanı yakalar — ve oradan
bu sınıfa gelir.

### 1.2 Neden Action'da `response()->json(...)` yazmıyoruz?

`CLAUDE.md` §1.3 diyor ki: *"Action sınıfları asla HTTP yanıtı dönmez."* Gerekçesi
şu üç maddede:

| Sorun | Açıklama |
|---|---|
| **Test edilebilirlik** | Action HTTP yanıtı dönerse, iş kuralını test etmek için HTTP isteği kurmak gerekir |
| **Yeniden kullanım** | Aynı Action bir `artisan` komutundan çağrılabilir; orada HTTP yoktur |
| **Tutarlılık** | 8 controller × N metot kere zarf yazılırsa biri er ya da geç farklı yazılır |

Action *"kota aşıldı"* der (exception fırlatır). *"Bu 403 ile şu JSON olarak
anlatılır"* kararını bu sınıf verir. **Ne olduğu** ile **nasıl anlatıldığı**
ayrılır — K20'nin frontend'e karşı yaptığı ayrımın, backend içindeki yansıması.

---

## 2. Kod okuması

### 2.1 `render()` — dört adım

```php
$code = $this->resolveCode($e);      // 1. hangi hata kodu?
$payload = ['code' => $code->value];  // 2. zorunlu alan
// 3. duruma göre fields / params
// 4. yalnızca yerelde debug
return response()->json(['error' => $payload], $code->status());
```

`code` **tek zorunlu alandır** (`08` §2.1). `fields` yalnızca doğrulama
hatalarında, `params` yalnızca beyaz listeden geçen bir şey kaldıysa eklenir.

### 2.2 `match (true)` deyimi

```php
return match (true) {
    $e instanceof ValidationException => ErrorCode::ValidationFailed,
    $e instanceof AuthenticationException => ErrorCode::Unauthenticated,
    ...
};
```

`match` normalde bir değeri sabitlerle karşılaştırır (`match ($this)`). `match (true)`
ise bir **numaralandırılmış `if/elseif` zinciri** kurar: her kol bir boolean ifadedir,
ilk `true` olan kazanır.

`instanceof` operatörü *"bu nesne şu sınıftan mı (veya alt sınıfından mı)?"* sorusunu
sorar.

🔴 **Sıra burada anlamlıdır** — normal `match`'ten farklı olarak. `ThrottleRequestsException`
aslında bir `HttpException`'dır. `HttpExceptionInterface` kolu yukarıda olsaydı,
throttle exception'ı ona takılır ve `retryAfter` parametresi asla üretilmezdi.
**Özelden genele** sıralanır.

### 2.3 `fromStatus()` — neden var?

Bazı exception'lar tür bilgisi taşımaz. `abort(409)` çağrısı jenerik bir
`HttpException` üretir; sınıfına bakarak "çakışma" olduğunu anlayamayız, ama
**durum kodunu** biliriz.

Bu kol olmasaydı `abort(409)` `default`'a düşer ve **500** dönerdi. Yani
istemcinin hatası sunucu hatası gibi görünürdü — izleme alarmları yanlış yere
bakardı ve istemci "sende sorun var" mesajı alırdı.

### 2.4 `$e->validator->failed()` — kural adları nereden geliyor?

Doğrulama hatasının **metnini** değil **kuralını** döndürmemiz gerekiyor (K20).
Laravel bunu `failed()` metodunda saklar:

```php
$e->validator->failed();
// [
//   'guestCount' => ['Max' => ['10'], 'Numeric' => []],
//   'email'      => ['Required' => []],
// ]
```

Yapı üç katmanlı: **alan → kural (StudlyCase) → konumlu parametreler**.

`errors()` metodu ise çevrilmiş metinleri verir — bize **lazım değil**, çünkü metin
frontend'in işi. `failed()`, K20'yi mümkün kılan API'dir.

Dönüşüm iki adım:

```php
Str::snake('Max')            // 'max'          — sözleşmedeki kural adı
Str::snake('DigitsBetween')  // 'digits_between'
```

### 2.5 `nameRuleParams()` — konumdan ada

Laravel parametreleri **konumlu** verir: `max:10` → `['10']`. Sözleşme (`08` §2.1)
ise **adlı** istiyor: `{ "max": 10 }`.

```php
private const RULE_PARAM_NAMES = [
    'between' => ['min', 'max'],    // between:1,10 → ['min' => 1, 'max' => 10]
    'max' => ['max'],
    ...
];
```

Neden ada çeviriyoruz? Frontend çevirisi için:

```
t('validation.between', { min: 1, max: 10 })   ✅ okunur
t('validation.between', { 0: 1, 1: 10 })       ❌ anlamsız
```

Haritada olmayan kurallar (`in`, `mimes` gibi değişken sayıda parametre alanlar)
`values` altında toplanır — bilgi kaybolmaz, sadece adlandırılmaz.

### 2.6 `normalize()` — `'10'` neden `10` oluyor?

```php
return is_string($value) && is_numeric($value) ? $value + 0 : $value;
```

Laravel kural parametrelerini **her zaman string** verir (`'10'`), çünkü onları
`max:10` metninden ayrıştırır. Sözleşme örneği ise sayı gösteriyor: `{ "max": 10 }`.

`$value + 0` PHP'nin deyimsel sayıya çevirme yöntemidir ve tipi korur:
`'10' + 0 === 10` (int), `'2.5' + 0 === 2.5` (float). `(int)` yazsaydık `2.5` → `2`
olur, ondalık bilgi kaybolurdu.

**Neden önemli?** JSON'da `"10"` ile `10` farklı tiplerdir. Frontend
`max` değerini sayısal karşılaştırmada kullanırsa (`value > max`), string gelmesi
sessiz bir hataya yol açar.

### 2.7 `$this->normalize(...)` — first-class callable

```php
array_map($this->normalize(...), $params);
```

`(...)` PHP 8.1 ile geldi ve *"bu metodu çağırma, onu bir fonksiyon değeri olarak
ver"* demektir. Eskiden `[$this, 'normalize']` veya `fn ($v) => $this->normalize($v)`
yazılırdı; bu biçim hem kısa hem de IDE/PHPStan tarafından tip olarak çözülebilir.

---

## 3. Tasarım kararları

### 3.1 Neden `bootstrap/app.php`'ye inline yazılmadı?

`bootstrap/app.php`'nin işi **kablolama**dır: "şu middleware şu gruba, şu exception
şu handler'a." Yaklaşık 130 satırlık eşleme mantığı oraya konsaydı dosya hem
yapılandırma hem iş mantığı taşırdı.

Ayrıca ayrı sınıf olmasının somut faydaları var:

| Fayda | Nasıl |
|---|---|
| Birim testi | `new ApiExceptionRenderer()` ile HTTP kurmadan test edilir |
| PHPStan | Kapalı bir closure yerine tipli metotlar analiz edilir |
| Faz 5/7 | Yeni exception eklemek `resolveCode()`'a tek satır — bootstrap'a dokunulmaz |

### 3.2 🔴 `debug` bloğu neden `if` içinde?

```php
if (config('app.debug') === true) {
    $payload['debug'] = $this->debug($e);
}
```

Alternatif, bloğu her zaman üretip yanıt gönderilmeden önce filtrelemekti. Fark
kritik: bu biçimde **üretimde kod hiç çalışmaz**. Yığın izi hiç oluşmaz, hiç
diziye konmaz, dolayısıyla bir filtre hatasıyla sızması mümkün değildir.

> Bu, tekrar eden ilkenin bir örneği daha: **güvenliği disipline değil yapıya
> bağlamak.** `ForceJsonResponse`'un grup üyeliğine bağlanması ve `filterParams()`
> beyaz listesi aynı aileden.

`env('APP_DEBUG')` değil `config('app.debug')` yazıldığına dikkat — Y1 kuralı.
`config:cache` sonrası `env()` `null` döner ve `null === true` false'tur; şans eseri
"güvenli" tarafa düşer ama bu tesadüftür, tasarım değil.

### 3.3 🔴 `AuthorizationException` neden 404?

```php
$e instanceof ModelNotFoundException,
$e instanceof AuthorizationException => ErrorCode::ResourceNotFound,
```

Bu, `08` §3.2'nin (H7) uygulanmasıdır. 403 dönmek *"bu kaynak var ama senin değil"*
demektir — kaynağın **varlığını doğrular**. Saldırgan ULID uzayını tarayıp hangi
davetiyelerin var olduğunu haritalayabilir. 404 ayrım vermez.

**"Peki gerçek 403 durumları?"** `08` §3.2'ye göre 403, *sahiplik doğrulanmış ama
işlem yasak* hâllerinde kullanılır (yayınlanmış davetiyeyi silmek gibi). Bu jenerik
handler o ayrımı yapamaz — bilgisi yoktur. Bu yüzden **güvenli tarafa** düşer.
Gerçek 403 durumları Faz 3 ve 7'de kendi exception sınıflarını kazanacak
(`InvitationLockedException` gibi) ve `resolveCode()`'a açıkça eklenecek.

Varsayılan **kapalı**, istisna **açıkça işaretli** — `/api/public/` önekiyle aynı
fail-safe deseni.

### 3.4 Loglama neden burada yok?

Bu sınıf yalnızca **render** eder. Laravel'in exception yaşam döngüsünde iki ayrı
aşama vardır:

```
report()  →  logla / Sentry'ye gönder
render()  →  kullanıcıya ne göstereceğine karar ver
```

`bootstrap/app.php`'de yalnızca `render` kancasına bağlanıyoruz; `report` kendi
varsayılan akışında çalışmaya devam eder. Yani yığın izi **log'a yazılır**, sadece
yanıta girmez — H8 tam olarak budur.

### 3.5 `params()` şimdilik neden bu kadar küçük?

Yalnızca `ThrottleRequestsException` işleniyor. `requiredTier` (Faz 7) ve
`remaining`/`limit` (Faz 5) henüz yok, çünkü onları taşıyacak exception sınıfları
henüz yazılmadı.

Şimdiden boş `if` blokları koymak YAGNI olurdu. Önemli olan **mekanizmanın**
(`filterParams()` süzgeci) hazır olması; içeriği fazlar doldurur.

---

## 4. Eşleme tablosu

| Exception | Kod | HTTP | Ne zaman |
|---|---|:---:|---|
| `ValidationException` | `VALIDATION_FAILED` | 422 | FormRequest reddettiğinde |
| `AuthenticationException` | `UNAUTHENTICATED` | 401 | `auth:sanctum` token bulamazsa |
| `ThrottleRequestsException` | `RATE_LIMITED` | 429 | `throttle` sınırı aşılınca |
| `PostTooLargeException` | `FILE_TOO_LARGE` | 413 | Gövde PHP limitini aşınca |
| `ModelNotFoundException` | `RESOURCE_NOT_FOUND` | 404 | Route model binding bulamazsa |
| `AuthorizationException` | `RESOURCE_NOT_FOUND` | 404 | 🔴 Policy reddederse (H7) |
| `NotFoundHttpException` | `RESOURCE_NOT_FOUND` | 404 | Rota eşleşmezse |
| `MethodNotAllowedHttpException` | `RESOURCE_NOT_FOUND` | 404 | Yanlış HTTP metodu |
| `abort(409)` vb. | durum koduna göre | — | `fromStatus()` |
| **Diğer her şey** | `SERVER_ERROR` | 500 | Yakalanmamış hata |

> `NotFoundHttpException` ve `MethodNotAllowedHttpException` listede ayrı satır
> olarak görünse de kodda `HttpExceptionInterface` kolundan `fromStatus()` ile
> çözülürler — ikisi de zaten durum kodu taşır.

---

## 5. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `match (true)` kollarını genelden özele sıralamak | `ThrottleRequests` genel kola takılır, `retryAfter` kaybolur | Özelden genele |
| `$e->errors()` kullanmak | Çevrilmiş **metin** döner — K20 ihlali | `$e->validator->failed()` |
| `params`'ı `filterParams()`'sız eklemek | İç veri sızar (H9) | Her zaman süz |
| `env('APP_DEBUG')` yazmak | `config:cache` sonrası `null` | `config('app.debug')` |
| Policy reddini 403'e eşlemek | Kaynağın varlığı doğrulanır (H7 ihlali) | 404 |
| Buraya `Log::error()` eklemek | Çift loglama — `report()` zaten yapıyor | Loglama `report`'un işi |
| Yığın izini `debug` dışına koymak | H8 ihlali | Yalnızca `debug` bloğu |
| `default => ServerError` kolunu kaldırmak | Bilinmeyen exception zarfsız sızar | Kol kalmalı |

---

## 6. Kendin dene

1.3b ve 1.4'ten sonra:

```powershell
# 404 — kod ve zarf
curl.exe http://localhost:8000/api/olmayan
# {"error":{"code":"RESOURCE_NOT_FOUND","debug":{...}}}   ← APP_DEBUG=true iken

# Üretim kipini taklit et: .env içinde APP_DEBUG=false, sonra
php artisan config:clear
curl.exe http://localhost:8000/api/olmayan
# {"error":{"code":"RESOURCE_NOT_FOUND"}}                 ← debug bloğu YOK
```

Doğrulama zarfını görmek Faz 2'yi (ilk FormRequest) bekliyor. O gün beklenen çıktı:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "fields": { "email": [{ "rule": "required" }] }
  }
}
```

**Kasten kır:** `resolveCode()`'daki `$e instanceof ThrottleRequestsException` kolunu
`HttpExceptionInterface` kolunun **altına** taşı. Faz 5'te rate limit denendiğinde
`retryAfter`'ın kaybolduğunu göreceksin — `match (true)`'da sıranın neden önemli
olduğunun canlı kanıtı. Sonra geri al.

---

## 7. Sözlük

| Terim | Anlamı |
|---|---|
| **Exception** | "Bu işi yapamam" bildirimi; çağrı zincirini atlayarak yukarı fırlar |
| **`throw` / `catch`** | Fırlatma ve yakalama |
| **`instanceof`** | "Bu nesne şu sınıftan (veya alt sınıfından) mı?" |
| **`match (true)`** | Numaralandırılmış `if/elseif` zinciri; ilk `true` kazanır |
| **First-class callable** | `$this->m(...)` — metodu çağırmadan fonksiyon değeri olarak alma |
| **report / render** | Laravel'de hatayı loglama / kullanıcıya gösterme aşamaları |
| **Fail-safe** | Bilgi eksikse güvenli tarafa düşen tasarım |
| **YAGNI** | "You Aren't Gonna Need It" — ihtiyaç doğmadan kod yazma |

---

## 🆕 Faz 5 güncellemesi — `HasErrorCode` arayüzü

> **Eklendi:** 28 Ağustos 2026 · **Dosya:** 5.5

`resolveCode()` içindeki exception-başına-bir-kol düzeni kaldırıldı. Yerine tek
bir kol geldi:

```php
$e instanceof HasErrorCode => $e->errorCode(),
```

**Ne değişti:**

| Önce | Sonra |
|---|---|
| Her yeni exception için match'e kol eklenirdi | Exception `HasErrorCode`'u uygular, kod kendi üstünde durur |
| Kol eklenmezse sessizce 500 dönerdi | Arayüz uygulanmazsa yine 500 döner — ama artık tek bir yerde, belgelenmiş bir alışkanlık var |
| `RegistrationFailedException` / `InvalidCredentialsException` kolları | İkisi de arayüze taşındı; **davranış birebir aynı** |

**Ne değişmedi:**

- **H12** hâlâ yolun üzerinde: `errorParams()`'tan dönen her şey
  `ErrorCode::filterParams()` beyaz listesinden geçer.
- **H13** hâlâ geçerli: yeni kol `ValidationException`'dan **sonra**, genel
  `HttpExceptionInterface` kolundan **önce** duruyor.
- **H6** hâlâ geçerli: `fields` yalnızca `ValidationException` kolunda üretilir.

Ayrıntılı gerekçe ve mutasyon denemesi:
[`HasErrorCode.md`](HasErrorCode.md).
