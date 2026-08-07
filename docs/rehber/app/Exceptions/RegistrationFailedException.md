# `app/Exceptions/RegistrationFailedException.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Exceptions/RegistrationFailedException.php`
> (+ `ApiExceptionRenderer.php`'ye eklenen bir kol)
> **Yol haritasındaki yeri:** Faz 2, dosya 2.5a — `RegisterUserAction`'ın ön koşulu
> **Bağlantılı:** [`ApiExceptionRenderer.md`](ApiExceptionRenderer.md) ·
> [`RegisterRequest.md`](../Http/Requests/Auth/RegisterRequest.md) §3.1 ·
> `docs/08-HATA-SOZLESMESI.md` §3.1

---

## 0. Bir dakikalık özet

Bu sınıf tek bir şey söyler: **"kayıt tamamlanamadı."** Sebebini söylemez.

```php
throw RegistrationFailedException::emailTaken();
        ↓
{ "error": { "code": "REGISTRATION_FAILED" } }      HTTP 422
```

Kodda sebep **açık** (`emailTaken`), yanıtta **gizli**. Bu asimetri dosyanın
varlık sebebidir.

---

## 1. Neden ayrı bir exception sınıfı gerekti?

`RegisterUserAction` bir sorunla karşılaşacak: e-posta zaten kayıtlı. Ne yapmalı?

| Seçenek | Neden olmaz |
|---|---|
| `return response()->json(...)` | 🔴 H10 ihlali: Action HTTP yanıtı üretmez |
| `return null` döndürüp controller'da kontrol | Controller'a `if` girer — `CLAUDE.md` §1 yasaklıyor |
| `throw new Exception('email taken')` | Renderer tanımaz → `SERVER_ERROR` (500). İstemci hatası sunucu hatası gibi görünür |
| **Kendi exception sınıfı** ✅ | Renderer onu tanır, doğru koda eşler |

**H10'un derin gerekçesi:** Bir Action *ne olduğunu* bilir, *nasıl anlatılacağını*
bilmez. Aynı `RegisterUserAction` yarın bir konsol komutundan veya bir kuyruk
işinden çağrılabilir — orada "HTTP 422" diye bir kavram yoktur. Exception
fırlatmak, iş kuralını taşıma biçiminden bağımsız kılar.

```
Action  →  "kayıt olamadı" (olay)
             ↓
Renderer →  "422 REGISTRATION_FAILED" (HTTP'ye çeviri)
```

---

## 2. PHP temelleri

### 2.1 Exception hiyerarşisi

```
Throwable  (arayüz)
├── Error            ← PHP'nin kendi hataları (TypeError, ParseError)
└── Exception
    ├── RuntimeException      ← ancak çalışma anında anlaşılabilen durumlar
    │   └── RegistrationFailedException   ← biz buradayız
    └── LogicException        ← programcı hatası (yanlış argüman vb.)
```

`RuntimeException` seçildi çünkü bu bir **programlama hatası değil**: kod doğru,
sadece o anda veritabanında o e-posta vardı. `LogicException` "geliştirici yanlış
kullandı" demektir.

> Faz 1'de `bootstrap/app.php`'nin `fn (Throwable $e, ...)` yazmasının sebebi
> hiyerarşinin en tepesi olmasıdır: hem `Error` hem `Exception` yakalanır.

### 2.2 `final` ve boş gövde

```php
final class RegistrationFailedException extends RuntimeException
{
    public static function emailTaken(): self { ... }
}
```

Sınıfın kendi property'si veya davranışı yok — tüm işlevselliği `RuntimeException`'dan
geliyor. Değeri **tipinde**: `instanceof` ile ayırt edilebilmesi.

Buna bazen *marker (işaretçi) sınıf* denir. Az kod, çok anlam.

### 2.3 Adlandırılmış kurucu (named constructor)

```php
public static function emailTaken(): self
{
    return new self('Registration failed: email already exists.');
}
```

`new RegistrationFailedException('...')` yerine `RegistrationFailedException::emailTaken()`
yazıyoruz. Kazançları:

| Kazanç | Açıklama |
|---|---|
| **Okunurluk** | Çağrı yerinde niyet cümle gibi okunur |
| **Tek yer** | Mesaj metni tek bir yerde; her `throw`'da elle yazılmaz |
| **Genişleme** | Faz sonrası `passwordRejected()` eklenirse aynı kalıp sürer |

`self` dönüş tipi "bu sınıfın örneği" demektir (bkz.
[`php-dili.md`](../../kavramlar/php-dili.md) §3.3).

---

## 3. Alınan kararlar

### 3.1 🔴 Mesaj var ama dışarı çıkmıyor — nasıl?

```php
new self('Registration failed: email already exists.');
```

"Metin döndürmüyoruz" diyorduk (K20). Peki bu mesaj ne oluyor?

`ApiExceptionRenderer`'ın akışını izle:

```php
$payload = ['code' => $code->value];              // ← dışarı giden

if (config('app.debug') === true) {
    $payload['debug'] = $this->debug($e);         // ← mesaj YALNIZCA burada
}
```

| Ortam | `APP_DEBUG` | Mesaj yanıtta mı? |
|---|---|---|
| Yerel | `true` | ✅ `error.debug.message` içinde |
| **Üretim** | `false` | ❌ **Kod hiç çalışmaz** |

Yani mesaj **geliştirici için** vardır. Üretimde istemci yalnızca
`REGISTRATION_FAILED` görür; sebep log'da kalır.

> **Genel ilke:** Bilgiyi yok etmek ile yaymak arasında üçüncü bir yol vardır:
> **yerini seçmek.** Sebep bilinmeli (destek talebi geldiğinde log'a bakılır),
> ama istemciye söylenmemeli.

### 3.2 Neden `422`? — kod eşlemesi nereden geliyor

Bu dosyada HTTP durum kodu **hiç geçmiyor**. Eşleme `ErrorCode` enum'unda:

```php
self::ValidationFailed,
self::RegistrationFailed => 422,
```

Bu, Faz 1'in tasarımının meyvesi: her kodun **tek ve değişmez** bir HTTP
karşılığı var ve o bilgi tek yerde duruyor. Exception sınıfı durum kodu
bilmez, bilmemeli.

**Neden 422, 409 değil?** 409 (Conflict) mantıklı görünür — ve tam olarak bu
yüzden kullanılamaz. `409` istemciye *"bir çakışma var"* der; hangi alanda
olduğunu tahmin etmek kolaydır. `422` diğer doğrulama hatalarıyla **aynı
kutuya** düşer, böylece "e-posta kayıtlı" ile "parola çok kısa" durumları
dışarıdan ayırt edilemez hâle gelir.

> Durum kodunun kendisi de bir bilgi sızıntısı kanalıdır. Enumeration savunması
> yalnızca gövdeyi değil, **durum kodunu da** aynı tutmayı gerektirir.

### 3.3 `ApiExceptionRenderer`'a eklenen kol (H11)

```php
return match (true) {
    $e instanceof ValidationException => ErrorCode::ValidationFailed,

    // H6: kayit hatasi ASLA `fields` tasimaz — enumeration savunmasi.
    $e instanceof RegistrationFailedException => ErrorCode::RegistrationFailed,

    $e instanceof AuthenticationException => ErrorCode::Unauthenticated,
    // ...
    default => ErrorCode::ServerError,
};
```

**H11:** *"Her yeni exception `resolveCode()`'a eklenir. Eklenmezse
`SERVER_ERROR` (500) döner."*

Konum önemli mi? **Evet** — H13 gereği `match (true)` kolları yukarıdan aşağı
denenir ve **özelden genele** sıralanmalıdır. Bizim sınıfımız bir
`RuntimeException`'dır, `HttpExceptionInterface` değildir; bu yüzden aşağıdaki
genel kollarla çakışmaz. Yine de ilgili olduğu yere — diğer istemci hatalarının
yanına — konuldu.

**`fields` neden otomatik olarak yok?** Renderer'a bak:

```php
if ($e instanceof ValidationException) {
    $payload['fields'] = $this->fields($e);
}
```

`fields` **yalnızca** `ValidationException` için üretilir. Bizim exception'ımız
o tipte olmadığı için `fields` anahtarı hiç oluşmaz. H6 burada **hatırlanarak
değil, yapısal olarak** sağlanıyor — unutulması mümkün değil.

### 3.4 Neden `HasErrorCode` gibi bir arayüz yazmadık?

Cazip bir alternatif vardı: her exception kendi kodunu taşısın, renderer tek bir
`instanceof` ile çözsün. O zaman yeni exception eklemek renderer'a hiç
dokunmazdı.

**Şimdilik yazılmadı.** Gerekçe K15'in bütçe mantığı:

| Lehte | Aleyhte |
|---|---|
| Faz 5 ve Faz 7'de iki exception daha gelecek | Şu an **tek** uygulama var — tek uygulamalı arayüz spekülatiftir |
| H11'i hatırlanan kural olmaktan çıkarır | H11'in ihlali zaten **test tarafından yakalanıyor**: 2.10'daki mükerrer kayıt testi, kol eklenmemişse 500 alıp kırılır |

İkinci satır belirleyici: H11 "hatırlanması gereken" bir kural değil, **test
edilen** bir kural. Soyutlamanın çözeceği sorun zaten çözülmüş.

> Bu karar **Faz 5'te yeniden değerlendirilecek**. Üçüncü exception geldiğinde
> tekrar üç kez görülmüş olacak ve arayüz gerçek bir tekrarı ortadan
> kaldıracak — spekülatif değil, gözlemlenmiş bir tekrarı.

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `resolveCode()`'a kol eklemeyi unutmak | 500 `SERVER_ERROR` — istemci hatası sunucu hatası görünür | H11 |
| Exception mesajına kullanıcıya gösterilecek metin yazmak | Yerelde `debug`'a düşer, alışkanlık üretir | Mesaj **geliştirici** içindir |
| Action'da `response()->json()` döndürmek | H10 ihlali; Action HTTP'ye bağlanır | `throw` |
| Durum kodunu exception'a gömmek | İki doğruluk kaynağı | `ErrorCode::status()` |
| `409 Conflict` kullanmak | Enumeration sızıntısı — durum kodu da bir kanaldır | `422` |
| `ValidationException` fırlatmak | `fields` üretilir, e-posta ifşa olur | Kendi exception'ın |
| Genel `Exception` fırlatmak | `instanceof` ile ayırt edilemez | Adlandırılmış sınıf |

---

## 5. Kendin dene

Rota henüz yok; exception'ı ve eşlemeyi doğrudan sınayabilirsin.

```powershell
php artisan tinker
```

**1. Renderer doğru kodu üretiyor mu?**

```php
$e = App\Exceptions\RegistrationFailedException::emailTaken();
$r = app(App\Exceptions\ApiExceptionRenderer::class)->render($e);
$r->getStatusCode();      // 422
$r->getContent();
```

Yerelde (`APP_DEBUG=true`) beklenen:

```json
{"error":{"code":"REGISTRATION_FAILED","debug":{"message":"Registration failed: email already exists.","exception":"App\\Exceptions\\RegistrationFailedException","file":"...","line":...}}}
```

**2. `fields` üretiliyor mu?** (üretilmemeli)

```php
str_contains($r->getContent(), 'fields');    // false
```

**3. Üretim kipinde mesaj sızıyor mu?** (sızmamalı)

```php
config(['app.debug' => false]);
app(App\Exceptions\ApiExceptionRenderer::class)->render($e)->getContent();
// {"error":{"code":"REGISTRATION_FAILED"}}      ← debug bloğu YOK
```

Üçüncüsü H3'ün somut kanıtı: aynı kod, farklı ortam, farklı ifşa düzeyi.

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Exception** | Olağandışı durumu çağrı yığınında yukarı taşıyan nesne |
| **`RuntimeException`** | Ancak çalışma anında anlaşılabilen durum |
| **`LogicException`** | Programcı hatası — kodla düzeltilir |
| **Marker sınıf** | Davranışı olmayan, değeri tipinde olan sınıf |
| **Adlandırılmış kurucu** | Niyeti adında taşıyan `static` üretici metot |
| **User enumeration** | Yanıt farkından kayıtlı hesapları tespit etme açığı |
| **Yan kanal** | Gövde dışındaki bilgi sızıntısı yolu (durum kodu, süre) |

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| Zarfı üreten sınıf | [`ApiExceptionRenderer.md`](ApiExceptionRenderer.md) |
| `unique` kuralının neden olmadığı | [`RegisterRequest.md`](../Http/Requests/Auth/RegisterRequest.md) §3.1 |
| Enumeration kuralı (H6) | `docs/08-HATA-SOZLESMESI.md` §3.1 |
| Kod → durum eşlemesi | [`ErrorCode.md`](../Enums/ErrorCode.md) |
| Sıradaki dosya | `app/Actions/Auth/RegisterUserAction.php` (2.5b) |
