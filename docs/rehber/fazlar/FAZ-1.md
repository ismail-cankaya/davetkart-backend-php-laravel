# Faz 1 — İlk Nefes ve Hata Zarfı

> **Durum:** ✅ Tamamlandı · 31 Temmuz 2026
> **Yazılan dosya:** 8 (planlanan 6) · **Yazılan kılavuz:** 7
> **Bitiş ölçütü:** `/api/ping` → JSON · bilinmeyen rota → hata zarfı ·
> `composer check` yeşil

---

## 1. Fazın amacı

**Tek cümle:** Bir HTTP isteğinin Laravel içinde nereden girip nereden çıktığını
görmek ve K20 hata sözleşmesini **tek merkeze** kurmak.

### Neden hata zarfı bu kadar erken?

Faz 1 planlandığında yalnızca 4 dosyalıktı: rota, middleware, bootstrap, test.
K20 kararı üç dosya daha ekledi. Genişleme bilinçliydi.

Hata zarfı merkezî bir yerde kurulmazsa, her controller kendi biçimini üretir.
Faz 1'de yazılan `ApiExceptionRenderer` sonraki **8 fazda hiç tekrar edilmez**:
Faz 5'in `RsvpQuotaExceededException`'ı da, Faz 7'nin `PaywallViolationException`'ı
da aynı kapıdan geçecek.

Bir sözleşmeyi 1 endpoint varken kurmak ucuzdur; 20 endpoint varken kurmak
20 dosyayı düzeltmek demektir.

### Öğrenme hedefleri

| Soru | Cevabın bulunduğu kılavuz |
|---|---|
| İstek Laravel'e nereden girer, nereden çıkar? | [`bootstrap/app.md`](../bootstrap/app.md) §3 |
| Laravel 11+ ile `Kernel.php` nereye gitti? | [`bootstrap/app.md`](../bootstrap/app.md) §1 |
| Middleware nedir, `$next` ne işe yarar? | [`ForceJsonResponse.md`](../app/Http/Middleware/ForceJsonResponse.md) §1-2 |
| Exception neden yanıt döndürmekten iyidir? | [`ApiExceptionRenderer.md`](../app/Exceptions/ApiExceptionRenderer.md) §1 |
| Sihirli string neden enum'a çevrilir? | [`ErrorCode.md`](../app/Enums/ErrorCode.md) §1 |
| Sızıntı testi nasıl yazılır? | [`HealthTest.md`](../tests/Feature/HealthTest.md) §3 |
| İki repo tip paylaşmadan nasıl senkron kalır? | [`ExportErrorCodes.md`](../app/Console/Commands/ExportErrorCodes.md) §1 |

---

## 2. Hedefler ve sonuçlar

| # | Hedef | Sonuç |
|---|---|---|
| 1.1 | `ErrorCode` enum — kod kataloğu | ✅ 19 kod, 4 metot |
| 1.2 | `ForceJsonResponse` middleware | ✅ |
| 1.3a | Exception → zarf çevirisi | ✅ **Plan dışı ayrı sınıf** |
| 1.3b | `bootstrap/app.php` kablolama | ✅ `shouldRenderJsonWhen` kaldırıldı |
| 1.4a | `routes/api.php` → `GET /api/ping` | ✅ Varsayılan `/user` silindi |
| 1.4b | `HealthController` | ✅ **Plan dışı** — `route:cache` borcu |
| 1.5 | `HealthTest` | ✅ 7 test, 3'ü sızıntı testi |
| 1.6 | `errors:export` komutu | ✅ `--check` bayrağıyla |

---

## 3. Yazılan dosyalar

| Dosya | İşi | Kılavuz |
|---|---|---|
| `app/Enums/ErrorCode.php` | Hata kodu kataloğu, HTTP eşlemesi, `params` beyaz listesi | [`ErrorCode.md`](../app/Enums/ErrorCode.md) |
| `app/Http/Middleware/ForceJsonResponse.php` | API her zaman JSON döner | [`ForceJsonResponse.md`](../app/Http/Middleware/ForceJsonResponse.md) |
| `app/Exceptions/ApiExceptionRenderer.php` | Exception → K20 zarfı | [`ApiExceptionRenderer.md`](../app/Exceptions/ApiExceptionRenderer.md) |
| `bootstrap/app.php` | Middleware kaydı + handler delegasyonu | [`bootstrap/app.md`](../bootstrap/app.md) |
| `routes/api.php` | `GET /api/ping` | [`routes/api.md`](../routes/api.md) |
| `app/Http/Controllers/Api/V1/HealthController.php` | Sağlık sondası | [`HealthController.md`](../app/Http/Controllers/Api/V1/HealthController.md) |
| `tests/Feature/HealthTest.php` | Faz 1'in kanıtı | [`HealthTest.md`](../tests/Feature/HealthTest.md) |
| `app/Console/Commands/ExportErrorCodes.php` | Katalog senkronizasyonu | [`ExportErrorCodes.md`](../app/Console/Commands/ExportErrorCodes.md) |

### Silinenler

| Ne | Neden |
|---|---|
| `routes/api.php` → varsayılan `/user` rotası | Ham model döndürüyordu; `full_name` snake_case sızıyordu. Karşılığı Faz 2'de `auth/me` |
| `bootstrap/app.php` → `shouldRenderJsonWhen(...)` | Aynı kararı iki yerde veren mekanizma |
| `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | İskelet örnekleri |
| `app/Actions/Invitation/PublishInvitationAction.php` | Boş iskeletti; Faz 7'de `make:class` ile üretilecek. Klasör `.gitkeep` ile korundu |

---

## 4. Kurulan kurallar

Faz 0'ın 31 kuralına ek olarak, bundan sonraki her fazda geçerli:

### 4.1 Hata yolu

| # | Kural | Gerekçe |
|---|---|---|
| **H10** | Action ve Controller **hata yanıtı üretmez**, exception fırlatır | Biçim kararı tek yerde; iş kuralı HTTP'siz test edilebilir |
| **H11** | Her yeni exception `ApiExceptionRenderer::resolveCode()`'a eklenir | Eklenmezse `SERVER_ERROR` (500) döner — istemci hatası sunucu hatası gibi görünür |
| **H12** | `params` **her zaman** `ErrorCode::filterParams()`'tan geçirilir | Beyaz liste belgeye değil koda bağlı (H9'un uygulaması) |
| **H13** | `match (true)` kolları **özelden genele** sıralanır | `ThrottleRequestsException` genel `HttpExceptionInterface` kolunun altında kalırsa `retryAfter` kaybolur |

### 4.2 Rotalar

| # | Kural | Gerekçe |
|---|---|---|
| **R1** | Rota dosyasına **closure yazılmaz**, controller referansı yazılır | `route:cache` closure'ları serileştiremez (Faz 9) |
| **R2** | Her rota **isimlendirilir** (`->name('modul.eylem')`) | Testler URL'e değil isme bağlanır |
| **R3** | Rota dosyasında iş mantığı, `if`, sorgu **bulunmaz** | Dosya okunduğunda API yüzeyi anlaşılmalı |
| **R4** | Framework iskeletinden gelen örnek rotalar **silinir** | "Zararsız, dursun" denen satır bir gün sürpriz kaynağıdır |
| **R5** | Sağlık/sonda yanıtlarına **sürüm bilgisi konmaz** | Sürüm taraması için hedef olur (08 §3.3) |

### 4.3 Middleware

| # | Kural | Gerekçe |
|---|---|---|
| **M1** | Yalnızca `Accept` ezilir; **`Content-Type` asla** | `Content-Type` bir olgudur; ezilirse Faz 6 dosya yüklemesi kırılır |
| **M2** | Middleware **gruba** kaydedilir, global listeye değil | Kapsam açıkça sınırlanır |
| **M3** | Koruyucu middleware `prepend` ile **başa** konur | Zincirin ortasında fırlayan hata, sonraki middleware'leri atlar |
| **M4** | Kaynağa bağlı yetki kararı middleware'de **verilmez** | Middleware model yüklenmeden çalışır → Policy'nin işi |

### 4.4 Test

| # | Kural | Gerekçe |
|---|---|---|
| **T6** | Bir davranışın hem **varlığı** hem **yokluğu** test edilir | Yalnızca "yok" testi, özellik tamamen silinse de yeşil kalır |
| **T7** | Bir kararın **sınırı** da test edilir | `web_routes_are_not_forced_to_json` — global kayda kaçışı yakalar |
| **T8** | `RefreshDatabase` yalnızca veritabanına dokunan testlerde | T1'in lafzı değil amacı takip edilir |
| **T9** | `#[Test]` özniteliği kullanılır | `/** @test */` PHPUnit 11+ ile kaldırıldı |

### 4.5 Üretilen dosyalar

| # | Kural | Gerekçe |
|---|---|---|
| **G1** | Üretilen çıktı **deterministik** olur (sıralama sabit) | Anlamsız diff gürültüsü önlenir |
| **G2** | Zaman damgası karşılaştırmadan **çıkarılır** | Aksi hâlde `--check` hiç geçemez |
| **G3** | Üreteç, veriyi **kendisi bilmez** — kaynaktan türetir | İki doğruluk kaynağı tuzağı |

---

## 5. Faz boyunca alınan kararlar

| # | Karar | Gerekçe |
|---|---|---|
| **K25** | JSON zorlaması **middleware ile** (Yol B), `shouldRenderJsonWhen` ile değil | Yol A `api/*` **URL desenine** dayanır; kök seviyede bir rota açıldığı gün tahmin sessizce yanlışlanır. Yol B middleware **grup üyeliğine** bağlıdır |
| **K26** | Hata zarfı mantığı **ayrı sınıfta** (`ApiExceptionRenderer`), `bootstrap/app.php`'de değil | `bootstrap` kablolamadır. Ayrı sınıf birim testi ve PHPStan çözümlemesi kazandırır; Faz 5/7'de yeni exception eklemek bootstrap'a dokunmaz |
| **K27** | `PAYMENT_PROVIDER_ERROR` → **502**, `PROVIDER_UNAVAILABLE` → **503** | RFC 9110: yukarı akış hatalı yanıt döndüyse 502. 503 "bu sunucu çöktü" demektir ve izleme alarmlarını yanlış yönlendirir. `08` §4.1'e işlendi |
| **K28** | `RSVP_QUOTA_EXCEEDED` → **403** (429 değil) | 429 bir **hız** sınırıdır; kotamız **kapasite** sınırıdır. Misafir yavaşlayarak aşamaz, `Retry-After` yanıltıcı olur |
| **K29** | **RFC 9457 (Problem Details) kullanılmaz** | `title`/`detail` insan-okunur metin zorunlu kılar — K20'nin yasakladığı şey |
| **K30** | Sağlık rotası **closure değil controller** | `route:cache` (Faz 9) closure'ları serileştiremez. Maliyet aynıyken erken ödemek ucuz |
| **K31** | Katalog senkronizasyonu **tek yönlü üretim + `--check`** | Paylaşılan tip paketi iki repoyu bağımlı kılar. Kopya + doğrulama bağımlılık üretmez |

---

## 6. Ortaya çıkan istek yaşam döngüsü

```
1. public/index.php              tek giriş noktası
2. bootstrap/app.php             uygulamayı kurar (istek başına DEĞİL)
3. [ForceJsonResponse]           Accept: application/json      ← M3: başta
4. [api grubunun geri kalanı]    throttle vb.
5. routes/api.php                rota eşleşmesi
6. HealthController::__invoke()
   │
   ├─ başarılı ────────────────→ {"status":"ok"}
   └─ exception fırladı
        ↓
7. bootstrap/app.php render()    expectsJson() ? devret : null
        ↓
8. ApiExceptionRenderer          { error: { code, fields?, params?, debug? } }
        ↓
9. ErrorCode                     status() + filterParams()
```

**Faz 2 bu zincire üç halka ekleyecek:** FormRequest (4.5 ile 5 arası),
Action ve Resource (6'nın içinde).

---

## 7. Bitiş ölçütü — doğrulama

```powershell
composer check                 # pint + phpstan + phpunit → yeşil
php artisan test --filter=HealthTest    # 7 test
php artisan route:list --path=api       # GET|HEAD api/ping health.ping
php artisan errors:export --check       # "Katalog guncel."
```

Çalışan sunucuda:

```powershell
curl.exe http://localhost:8000/api/ping
# {"status":"ok"}

curl.exe -H "Accept: text/html" http://localhost:8000/api/olmayan
# {"error":{"code":"RESOURCE_NOT_FOUND","debug":{...}}}      ← HTML DEĞİL

curl.exe -H "Accept: text/html" http://localhost:8000/olmayan
# <!DOCTYPE html> ...                                        ← web etkilenmedi
```

---

## 8. Faz 2'ye devir

**Hazır olanlar:** Hata zarfı · JSON zorlaması · rota grubu · ilk controller ·
test altyapısı · katalog üretimi.

**Faz 2'de yazılacaklar (Auth özellik dilimi):**

| Dosya | Katman |
|---|---|
| `app/Models/User.php` (düzenleme) | `$fillable`, `casts()`, `HasApiTokens` |
| `database/factories/UserFactory.php` | Test verisi |
| `app/Http/Resources/UserResource.php` | `full_name` → `fullName` |
| `Requests/Auth/RegisterRequest.php` · `LoginRequest.php` | Doğrulama |
| `Actions/Auth/RegisterUserAction.php` · `LoginUserAction.php` · `RevokeTokenAction.php` | İş kuralı |
| `Controllers/Api/V1/AuthController.php` | Yönlendirme |
| `routes/api.php` (ekleme) | 4 rota |
| `tests/Feature/AuthTest.php` | Zarfsız sözleşme + enumeration testi |

**Faz 2 bitiş ölçütü:** Frontend'i `npm run dev` ile açıp **gerçek hesapla giriş
yapabilmek**. Token localStorage'a düşüyor, sayfa yenilenince oturum korunuyor.

### 🔴 Faz 2'nin iki kritik güvenlik işi

1. **Kullanıcı sayımı (enumeration) savunması** — `REGISTRATION_FAILED` ve
   `INVALID_CREDENTIALS` `fields` **döndürmez** (H6). Kayıtlı ve kayıtsız e-posta
   birebir aynı yanıtı üretir.
2. **Zamanlama saldırısı savunması** — kullanıcı bulunamasa bile sahte bir hash'e
   karşı doğrulama yapılır. Aksi hâlde yanıt ~250 ms hızlı döner ve saldırgan bunu
   ölçerek e-postanın kayıtlı olduğunu anlar.

### Faz 2 girişinde bağlanan kararlar ✅

| # | Karar | Gerekçe |
|---|---|---|
| **K32** | Parola hash'i **Argon2id** (bcrypt değil) | Argon2id sadece CPU değil **bellek** de tüketir; saldırganın GPU/ASIC ile paralel deneme kapasitesini düşürür. Laravel giriş anında otomatik rehash yapabildiği için geçiş kesintisizdir |
| **K33** | Katalog **`contracts/error-codes.json`**, repoya işlenir | `--check` `composer check` zincirine girdiği için dosya git'te olmalı. `storage/app/` gitignore'lu, `docs/` ise eğitim dokümanı klasörü |
| **K34** | `errors:export --check` **`composer check` zincirinde**, testlerden önce | Katalog sessizce eskimez. Saniyelik kontrol dakikalık testlerden önce → fail fast |

Kalan takvim:

| Konu | Ne zaman |
|---|---|
| PHPStan level 5 → **6** | Faz 2 sonu (K22) |

---

## 9. Terim özeti

| Terim | Anlamı |
|---|---|
| **Middleware** | Controller'dan önce ve sonra çalışan ara katman |
| **Closure** | Adı olmayan, değişkende taşınabilen fonksiyon |
| **Facade** | Konteynerdeki nesneye statik görünümlü kısayol |
| **Chain of Responsibility** | Halkanın ya işi üstlendiği ya devrettiği desen |
| **Invokable controller** | Tek `__invoke()` metodu olan controller |
| **`match (true)`** | Numaralandırılmış `if/elseif` zinciri |
| **Beyaz liste** | "Yalnızca şunlar serbest" — varsayılan kapalı |
| **Upstream (yukarı akış)** | Bizim çağırdığımız dış servis |
| **Serileştirme** | Nesne/veriyi saklanabilir metne çevirme |
| **Deterministik çıktı** | Aynı girdiden her zaman birebir aynı çıktı |
| **Shift left** | Hatayı yaşam döngüsünde erkene çekmek |
| **Sızıntı testi** | Bir bilginin yanıta **girmediğini** doğrulayan test |
| **Teknik borç** | Sonraya bırakılan, faizi zamanla artan iş |

---

## 10. Bağlantılar

| İlgili | Nerede |
|---|---|
| Önceki faz | [`FAZ-0.md`](FAZ-0.md) |
| Tüm fazların planı | `docs/09-TUM-FAZLAR-PLANI.md` |
| Yol haritası | `docs/07-GELISTIRME-YOL-HARITASI.md` |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
| Kod standartları | `CLAUDE.md` |
| Proje devir dosyası | `claude/PHP-LARAVEL-SETUP.md` |
