# `tests/Feature/HealthTest.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `tests/Feature/HealthTest.php`
> **Yol haritasındaki yeri:** Faz 1, dosya 1.5
> **Bağlantılı:** [`phpunit.md`](../../phpunit.md) ·
> [`ApiExceptionRenderer.md`](../../app/Exceptions/ApiExceptionRenderer.md)

---

## 0. Bir dakikalık özet

Faz 1'de yazdığımız dört dosyanın **gerçekten çalıştığının kanıtı**. Yedi test,
üç soruyu cevaplıyor:

| Soru | Test sayısı |
|---|---|
| API ayakta mı, JSON dönüyor mu? | 2 |
| Hata zarfı sözleşmeye uyuyor mu? | 2 |
| 🔴 Bilgi sızıyor mu? | 3 |

Son grup bu dosyanın asıl değeridir. "Çalışıyor mu" sorusunu tarayıcıda da
görebilirsin; "sızıyor mu" sorusunu **yalnızca test** sürekli sorabilir.

---

## 1. Test türleri: Unit ve Feature

`phpunit.xml` iki test paketi tanımlar:

| | Unit | Feature |
|---|---|---|
| Neyi test eder | Tek sınıf, izole | Gerçek HTTP isteği, tüm zincir |
| Laravel ayakta mı | Genelde hayır | **Evet** |
| Hız | Çok hızlı | Yavaş (uygulama kurulur) |
| Örnek | `TierResolver` mantığı (Faz 7) | **Bu dosya** |

Ağırlığımız **Feature** testlerinde. Sebep: bizim hatalarımız çoğunlukla
katmanların **arasında** oluşur — middleware kaydı unutulur, zarf yanlış
biçimlenir, rota yanlış gruba düşer. Unit testi bunları göremez; her parça tek
başına doğrudur, birleşim yanlıştır.

> `HealthTest` bir Feature testidir ama veritabanına hiç dokunmaz. Bu yüzden
> `RefreshDatabase` trait'i **kullanılmadı** (bkz. §4.3).

---

## 2. Kod okuması

### 2.1 `#[Test]` özniteliği (attribute)

```php
use PHPUnit\Framework\Attributes\Test;

#[Test]
public function ping_endpoint_returns_ok(): void
```

PHPUnit bir metodun test olduğunu üç yoldan anlayabilir:

| Yol | Durum |
|---|---|
| Metot adı `test` ile başlar | Çalışır |
| `/** @test */` yorum etiketi | ❌ PHPUnit 11'de kaldırıldı |
| **`#[Test]` özniteliği** | ✅ Güncel yol |

Öznitelikler PHP 8 ile geldi ve **yorumda değil, dilde** yaşarlar. Bu fark önemli:
yorum yanlış yazılırsa sessizce göz ardı edilir, öznitelik yanlış yazılırsa PHP
hata verir.

Kazanç: metot adları cümle gibi okunabilir. `test_ping_endpoint_returns_ok` yerine
`ping_endpoint_returns_ok`.

### 2.2 `getJson()` ile `get()` farkı

```php
$this->getJson('/api/olmayan-rota');                          // Accept: application/json
$this->get('/api/olmayan-rota', ['Accept' => 'text/html']);   // Accept: text/html
```

`getJson()` başlığı otomatik ekler. İkinci test bunu **bilerek yapmaz** — çünkü
tam olarak `ForceJsonResponse`'un varlık sebebini sınıyor: istemci HTML istese
bile JSON almalı.

🔴 Sadece `getJson()` kullansaydık middleware'i silmek **hiçbir testi kırmazdı**.
Test, korumak istediği şeyi gerçekten koruyacak şekilde kurulmalıdır.

### 2.3 `assertJsonPath` — nokta gösterimi

```php
->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
```

`error.code`, iç içe JSON'da yol belirtir:

```json
{ "error": { "code": "RESOURCE_NOT_FOUND" } }
     └────────┴── error.code
```

Dizilerde indeks kullanılır: `error.fields.email.0.rule` (Faz 2'de görülecek).

### 2.4 Beklenen değer neden enum'dan geliyor?

```php
ErrorCode::ResourceNotFound->value    // ✅
'RESOURCE_NOT_FOUND'                  // ❌ yazmadık
```

Test, ürettiği kodun **aynı kaynağını** kullanır. Enum'daki değer değişirse test de
değişir — yani bu test kod adının yanlış yazılmasını yakalamaz.

Peki koruma nerede? `H5` diyor ki kod adı bir kez yayınlandıktan sonra sözleşmedir.
O sözleşmenin bekçisi bu test değil, **`errors:export` çıktısı** ve frontend'in
çeviri dosyasıdır. Burada düz string yazmak yalnızca aynı bilgiyi ikinci kez
tanımlamak olurdu.

### 2.5 `route()` yardımcısı

```php
$this->getJson(route('health.ping'));    // ✅
$this->getJson('/api/ping');             // ❌
```

Rota **ismiyle** çağırmak, URL değiştiğinde testin ayakta kalmasını sağlar. Bu
yüzden 1.4'te `->name('health.ping')` yazmıştık.

> İstisna: `/api/olmayan-rota` gibi **kasten var olmayan** adresler doğal olarak
> düz yazılır — isimleri yoktur.

### 2.6 `config([...])` ile ortam değiştirme

```php
config(['app.debug' => false]);
```

Testin **içinde** yapılandırmayı değiştirir ve yalnızca o test boyunca geçerlidir;
PHPUnit her testte uygulamayı yeniden kurar.

`phpunit.xml` varsayılanı `APP_DEBUG=false`'tur (T4). Yine de testte açıkça
yazıldı — testin neyi varsaydığı **okunduğunda anlaşılsın** diye. Yarın biri
`phpunit.xml`'i değiştirirse bu test yine doğru şeyi ölçer.

### 2.7 `assertJsonMissingPath` — yokluğun testi

```php
->assertJsonMissingPath('error.debug');
```

Çoğu test bir şeyin **var** olduğunu doğrular. Güvenlik testleri genellikle tersini
yapar: bir şeyin **yok** olduğunu doğrular.

Bu satır, üretim kipinde yığın izinin, dosya yolunun ve exception sınıf adının
yanıta girmediğini garanti eder. `ApiExceptionRenderer`'daki `if (config('app.debug'))`
bloğu yanlışlıkla kaldırılırsa bu test kırmızı yanar.

---

## 3. Yedi testin haritası

| Test | Neyi korur | Kural |
|---|---|---|
| `ping_endpoint_returns_ok` | Rota + controller + JSON dönüşümü | — |
| `unknown_route_returns_error_envelope` | Hata zarfı biçimi | H2 |
| `html_request_to_api_still_receives_json` | `ForceJsonResponse` | — |
| `wrong_http_method_does_not_reveal_route_existence` | 405 yerine 404 | H7 |
| `debug_block_is_absent_in_production_mode` | 🔴 Sızıntı savunması | H3 |
| `debug_block_is_present_in_local_mode` | Geliştirici deneyimi | H3 |
| `web_routes_are_not_forced_to_json` | Kapsam sınırı | — |

### Neden hem "yok" hem "var" testi?

`debug` bloğu için iki test yazıldı. Tek başına "üretimde yok" testi yeterli
görünür — ama o test, `debug` bloğunu **tamamen silsen de** geçer. Yani koruduğu
şeyin varlığını doğrulamaz.

İkili test, davranışın **koşula bağlı** olduğunu kanıtlar: bir yerde var, bir yerde
yok. Buna testin *"mutasyona dayanıklılığı"* denir — kodu bozan bir değişiklik en
az bir testi kırmalıdır.

### `web_routes_are_not_forced_to_json` neden var?

Bu test bir **regresyon bekçisidir**. Yarın biri `bootstrap/app.php`'de
`prependToGroup('api', ...)` yerine `append(...)` (global) yazarsa, API testlerinin
hepsi yeşil kalır ama web tarafı sessizce JSON dönmeye başlar.

Bir kararın *sınırını* test etmek, kararın kendisini test etmek kadar önemlidir.

---

## 4. Tasarım kararları

### 4.1 🔴 T5: metin değil davranış test edilir

```php
// ❌ Asla
->assertSee('Sayfa bulunamadı');
->assertJsonPath('error.message', 'Resource not found');

// ✅ Her zaman
->assertJsonPath('error.code', ErrorCode::ResourceNotFound->value);
->assertNotFound();
```

Backend zaten metin döndürmüyor (K20), dolayısıyla test edilecek metin de yok.
Bu, K20'nin beklenmedik bir yan faydası: **testler kırılganlığını kaybetti**.
Metne bağlı testler, ürün ekibi bir kelimeyi değiştirdiğinde kırılır ve
geliştiriciler zamanla onlara güvenmeyi bırakır.

### 4.2 `assertOk()` / `assertNotFound()` — neden sayı değil?

```php
->assertOk()          // ✅ okunur
->assertStatus(200)   // çalışır ama niyeti anlatmaz
```

Laravel yaygın durum kodları için adlandırılmış assertion'lar sunar. Kod okunurken
`assertNotFound()` "burada 404 bekliyoruz" der; `assertStatus(404)` okuyucudan
sayıyı çevirmesini ister.

### 4.3 `RefreshDatabase` neden yok?

T1 kuralı der ki: *"Her Feature testi `RefreshDatabase` kullanır."* Bu dosyada
kullanılmadı.

Gerekçe: bu yedi testin **hiçbiri veritabanına dokunmuyor**. `RefreshDatabase`
her testten önce migration'ları çalıştırır ve bir transaction açar — burada
karşılığı sıfır olan bir maliyet.

T1'in amacı *"testler birbirinin verisini görmesin"*dir. Veri yoksa kural
uygulanacak bir durum da yoktur. **Kuralın lafzı değil amacı takip edilir.**

> Faz 2'den itibaren her Feature testi `RefreshDatabase` kullanacak — orada
> gerçekten kullanıcı kaydı oluşturuluyor olacak.

### 4.4 Testler neden üretim kipinde koşuyor?

`phpunit.xml` → `APP_DEBUG=false` (T4). Çoğu proje testleri debug açıkken koşar;
biz tersini yaptık.

Sebep: **sızıntı testleri ancak üretim kipinde anlamlıdır.** Debug açıkken
"üretimde bu bilgi yok" iddiasını test edemezsin. Varsayılanı üretim kipi yapıp
istisnayı (`debug_block_is_present_in_local_mode`) açıkça işaretlemek, güvenliği
varsayılan davranışa yerleştirir.

---

## 5. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| Yalnızca `getJson()` kullanmak | `ForceJsonResponse` silinse test yeşil kalır | `get()` + `Accept: text/html` de test et |
| Metin doğrulamak | T5 ihlali; ürün metni değişince kırılır | Kod ve durum doğrula |
| Assertion'sız test yazmak | `failOnRisky` kırar (T2) | Her testte en az bir assertion |
| Testte `dd()` / `echo` | `beStrictAboutOutputDuringTests` kırar (T3) | Debug için `--filter` kullan |
| `/** @test */` yorumu | PHPUnit 11+ yok sayar | `#[Test]` özniteliği |
| URL'i düz yazmak | URL değişince test kırılır | `route('...')` |
| Yalnızca "yok" testi yazmak | Özellik tamamen silinse de yeşil | "Var" testini de yaz |
| Testleri debug açıkken koşmak | Sızıntı testleri anlamsızlaşır | `APP_DEBUG=false` |

---

## 6. Kendin dene

```powershell
php artisan test
php artisan test --filter=HealthTest
php artisan test --filter=debug_block_is_absent_in_production_mode
```

**Kasten kır — üç deney:**

1. `ApiExceptionRenderer`'daki `if (config('app.debug') === true)` satırını sil
   (blok her zaman üretilsin). → `debug_block_is_absent_in_production_mode`
   kırmızı yanar. Sızıntı savunmasının bekçisi budur.

2. `bootstrap/app.php`'de `prependToGroup('api', ...)` satırını yorum satırı yap.
   → `html_request_to_api_still_receives_json` kırılır.

3. `bootstrap/app.php`'de `prependToGroup('api', ForceJsonResponse::class)` yerine
   `append(ForceJsonResponse::class)` yaz (global). → API testleri yeşil kalır ama
   `web_routes_are_not_forced_to_json` kırılır. Kapsam sınırının bekçisi.

Her deneyden sonra geri al ve `php artisan test` ile yeşile döndüğünü doğrula.

---

## 7. Sözlük

| Terim | Anlamı |
|---|---|
| **Feature testi** | Gerçek HTTP isteğiyle tüm zinciri sınayan test |
| **Unit testi** | Tek sınıfı izole sınayan test |
| **Assertion** | "Şu doğru olmalı" iddiası |
| **Öznitelik (attribute)** | `#[...]` — koda iliştirilen, dil tarafından okunan meta veri |
| **Regresyon** | Daha önce çalışan bir şeyin sonradan bozulması |
| **Mutasyona dayanıklılık** | Kodu bozan değişikliğin en az bir testi kırması |
| **`RefreshDatabase`** | Her testte veritabanını temiz duruma getiren trait |
| **Sızıntı testi** | Bir bilginin yanıta **girmediğini** doğrulayan test |
