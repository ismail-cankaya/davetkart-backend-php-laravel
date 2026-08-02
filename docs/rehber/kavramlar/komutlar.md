# Terminal Komutları — Referans

> **Kapsanan dosya:** yok — bu bir **komut referansıdır**.
> **Kime göre yazıldı:** Komutu çalıştıran ama ne yaptığını tam bilmeyen birine.
> **Kapsamı:** Bu projede fiilen kullandığımız ve kullanacağımız komutlar.
> Laravel'in 100+ komutunun tamamı değil.
> **Yaşayan doküman:** Her fazda yeni komut geldikçe büyür (§12).
> **Bağlantılı:** [`php-dili.md`](php-dili.md) ·
> [`veritabani-ve-migration.md`](veritabani-ve-migration.md) ·
> [`fazlar/FAZ-0.md`](../fazlar/FAZ-0.md) §6

---

## 1. Üç ayrı program — kim ne yapar?

Terminale yazdığın her şey bu üçünden birine gider. Karıştırmamak kritik:

```
php ............ dilin kendisi.       PHP dosyasını çalıştırır.
composer ....... paket yöneticisi.    Kütüphane indirir, script çalıştırır.
php artisan .... Laravel'in aracı.    Uygulamanı yükler, sonra iş yapar.
```

| | Neye benzer (JS dünyası) | Ne bilir |
|---|---|---|
| `php` | `node` | Hiçbir şey — sadece dosya çalıştırır |
| `composer` | `npm` | `composer.json`, `vendor/` klasörü |
| `php artisan` | proje içi CLI (`nest`, `next`) | **Senin uygulamanı**: config, rotalar, veritabanı, modeller |

🔴 **`php artisan` aslında bir PHP dosyasıdır.** Proje kökündeki `artisan`
dosyasını `php` çalıştırır; o da `bootstrap/app.php`'yi yükleyip uygulamayı ayağa
kaldırır, sonra komutu koşar.

Bunun iki önemli sonucu var:

1. `php artisan` **her zaman proje kökünden** çalıştırılır. Başka klasörden
   çalışmaz — `artisan` dosyasını bulamaz.
2. Uygulama yüklenirken bir hata varsa (bozuk config, `.env` eksik) **hiçbir**
   artisan komutu çalışmaz. "Komut çalışmıyor" derdinin en sık sebebi budur.

---

## 2. Bir komutun anatomisi

```
php artisan errors:export --path=contracts/error-codes.json --check
└┬┘ └──┬──┘ └─────┬─────┘ └──────────────┬──────────────┘ └──┬──┘
 │     │          │                      │                   │
program araç   komut adı            değerli seçenek      bayrak (flag)
```

| Parça | Kural |
|---|---|
| **Komut adı** | `grup:eylem` biçiminde (`make:model`, `migrate:fresh`) |
| **Argüman** | Tırnaksız, sırayla: `make:model Invitation` |
| **Seçenek** | `--ad=değer` |
| **Bayrak** | `--ad` — değeri yok, "açık" demek |

Bizim komutumuzun tanımı (`ExportErrorCodes.php`):

```php
protected $signature = 'errors:export
                        {--path=contracts/error-codes.json : Cikti dosyasi}
                        {--check : Yazma, yalnizca guncel mi diye bak}';
```

`{--path=...}` değerli seçenek, `{--check}` bayrak. İki nokta sonrası açıklamadır
ve `php artisan help errors:export` çıktısında görünür.

---

## 3. `composer` script'leri — en sık kullandıklarımız

Bunlar Laravel'in değil, **bizim tanımladığımız** kısayollardır. Tanımları
`composer.json`'un `scripts` bölümünde durur.

### 3.1 Kalite araçları

| Komut | Gerçekte ne çalışır | Ne yapar |
|---|---|---|
| `composer lint` | `pint` | Kod biçimini **DÜZELTİR** (dosyaları değiştirir) |
| `composer analyse` | `phpstan analyse --memory-limit=1G` | Tip/mantık hatası **raporlar** |
| `composer test` | `config:clear` + `artisan test` | Davranışı doğrular |
| `composer check` | **dördü sırayla** | 🔴 Faz bitiş kapısı |

### 3.2 🔴 `composer check` — zincirin sırası anlamlıdır

```
1. pint --test              biçim doğru mu?        (BAKAR, düzeltmez)
        ↓ geçerse
2. phpstan analyse          tip/mantık hatası?
        ↓ geçerse
3. errors:export --check    katalog güncel mi?
        ↓ geçerse
4. php artisan test         davranış doğru mu?
```

**Her adım bir öncekini geçmeye bağlıdır.** İlki kırılırsa gerisi hiç koşmaz.

Sıra rastgele değil, **hızdan yavaşa**: Pint saniyeler, PHPStan on saniyeler,
testler dakikalar sürer. Ucuz kontrolü öne almak *fail fast* ilkesidir — K34
kararının gerekçesi budur.

> 🔴 **En sık düşülen tuzak (Faz 1, ders 12):** `pint --test` **düzeltmez**,
> sadece bakar ve bozuksa çıkış kodu `1` döner. Kırıldığında yapılacak:
>
> ```powershell
> composer lint      # düzelt
> composer check     # tekrar doğrula
> ```
>
> Bu bilinçli bir tasarımdır: kalite kapısının işi *değiştirmek* değil
> *doğrulamaktır*. Kapı kendi kendine düzeltirse, hatalı kod sessizce geçer.

### 3.3 Diğer script'ler

| Komut | Ne yapar |
|---|---|
| `composer install` | `composer.lock`'a göre paketleri indirir (`vendor/`) |
| `composer update` | ⚠️ Paketleri yükseltir ve `.lock`'u değiştirir — dikkatli |
| `composer dump-autoload` | PSR-4 haritasını yeniden üretir |
| `composer setup` | Sıfırdan kurulum: install + key + migrate + npm |
| `composer dev` | Sunucu + kuyruk + log + vite'ı **aynı anda** başlatır |

> `composer dump-autoload` ne zaman lazım olur? Yeni bir klasör/sınıf ekleyip
> "Class not found" alırsan. `make:*` komutları bunu genelde kendisi yapar.

---

## 4. `php artisan` — bilgi ve keşif komutları

Bunlar hiçbir şeyi değiştirmez; **güvenle** çalıştırılır. En çok bunları
kullanacaksın.

| Komut | Cevapladığı soru |
|---|---|
| `php artisan` | Hangi komutlar var? (liste) |
| `php artisan list make` | `make:` ile başlayan komutlar neler? |
| `php artisan help migrate:fresh` | Bu komut ne yapar, hangi seçenekleri var? |
| `php artisan about` | Hangi sürümler, hangi sürücüler, cache açık mı? |
| `php artisan route:list` | Hangi rotalar tanımlı? |
| `php artisan route:list --path=api` | Yalnızca API rotaları |
| `php artisan db:show` | Veritabanında hangi tablolar, kaç satır var? |
| `php artisan db:table users` | Bu tablonun kolonları ve indeksleri neler? |
| `php artisan model:show User` | Modelin alanları, cast'leri, ilişkileri |
| `php artisan config:show database` | Çözülmüş config değerleri |

> **Alışkanlık önerisi:** Bir şey beklediğin gibi çalışmıyorsa, tahmin etmeden
> önce bu komutlardan biriyle **gerçeği gör**. `route:list` rota kaydını,
> `db:table` şemayı, `config:show` çözülmüş ayarı gösterir. Faz 1'in bitiş
> ölçütü bu üçüyle doğrulanmıştı.

---

## 5. `php artisan make:*` — dosya üreten komutlar

**K16:** Klasörler elle açılmaz, `make:*` ile üretilir. İki sebebi var: klasörü
oluşturur **ve namespace'i doğru yazar (PSR-4)**.

| Komut | Üretilen yer | Hangi fazda |
|---|---|---|
| `make:model Invitation` | `app/Models/` | 3 |
| `make:model Invitation -mf` | + migration + factory | 3 |
| `make:migration create_invitations_table` | `database/migrations/` | 3 |
| `make:factory InvitationFactory` | `database/factories/` | 2-3 |
| `make:seeder DatabaseSeeder` | `database/seeders/` | 3 |
| `make:controller Api/V1/AuthController` | `app/Http/Controllers/Api/V1/` | **2** |
| `make:request Auth/RegisterRequest` | `app/Http/Requests/Auth/` | **2** |
| `make:resource UserResource` | `app/Http/Resources/` | **2** |
| `make:class Actions/Auth/RegisterUserAction` | `app/Actions/Auth/` | **2** |
| `make:policy InvitationPolicy --model=Invitation` | `app/Policies/` | 3 |
| `make:enum InvitationStatus` | `app/Enums/` | 3 |
| `make:middleware ForceJsonResponse` | `app/Http/Middleware/` | ✅ Faz 1 |
| `make:command ExportErrorCodes` | `app/Console/Commands/` | ✅ Faz 1 |
| `make:test AuthTest` | `tests/Feature/` | **2** |
| `make:job SendRsvpNotification` | `app/Jobs/` | 5 |
| `make:event InvitationPublished` | `app/Events/` | 4 |
| `make:listener ClearInvitationCache` | `app/Listeners/` | 4 |

### 5.1 🔴 Action için neden `make:class`?

Laravel'in **`Action` diye bir kavramı yoktur** — o bizim mimari kararımızdır (K3).
Bu yüzden `make:action` komutu yok. `make:class` boş bir sınıf üretir, klasörü
açar ve namespace'i doğru yazar; bize yeten budur (Faz 0, ders 5).

### 5.2 Klasör ayracı `/` yazılır

```powershell
php artisan make:controller Api/V1/AuthController     # ✅ eğik çizgi
php artisan make:controller Api\V1\AuthController     # ⚠️ PowerShell'de sorun çıkarabilir
```

Artisan `/` işaretini namespace ayracına (`\`) kendisi çevirir.

### 5.3 Var olan dosyanın üstüne yazma tehlikesi

`make:*` dosya zaten varsa sorar veya hata verir. Ama emin olmadığın durumda
`--force` **kullanma** — yazdığın kodu geri dönülemez biçimde siler.

Faz 2'de `User.php` ve `UserFactory.php` için `make:*` çalıştırmadık; ikisi de
iskeletle geldiği için **düzenledik**.

---

## 6. Veritabanı komutları

| Komut | Ne yapar | Tehlike |
|---|---|---|
| `php artisan migrate` | Yalnızca **koşmamış** migration'ları uygular | Güvenli |
| `php artisan migrate:status` | Hangisi koştu, hangisi bekliyor | Güvenli |
| `php artisan migrate:rollback` | Son grubu geri alır (`down()`) | Veri gider |
| `php artisan migrate:fresh` | **Her tabloyu düşürür**, baştan koşar | 🔴 Her şey gider |
| `php artisan migrate:fresh --seed` | + seeder'ları çalıştırır | 🔴 Aynı |
| `php artisan db:seed` | Seeder'ları çalıştırır | Duruma bağlı |
| `php artisan db:wipe` | Tüm tabloları düşürür, migration koşmaz | 🔴 Yıkıcı |

### 6.1 `migrate` neden bazen "hiçbir şey yapmadı"?

Laravel koşan migration'ları veritabanındaki `migrations` tablosunda tutar ve
**dosya adına** bakar, içeriğine değil. Var olan bir migration'ı düzenlersen
`migrate` "bu zaten koştu" der. Çözüm `migrate:fresh`.

Ayrıntısı: [`veritabani-ve-migration.md`](veritabani-ve-migration.md) §4.

### 6.2 Yıkıcı komutlar üretimde çalışmaz

Faz 0'da `AppServiceProvider` içine konan satır:

```php
DB::prohibitDestructiveCommands($this->app->isProduction());
```

`migrate:fresh`, `migrate:reset` ve `db:wipe` üretimde **yapısal olarak**
engellenir (V3). Kural bir belgeye değil, koda yazılmıştır.

---

## 7. Çalıştırma ve inceleme

| Komut | Ne yapar |
|---|---|
| `php artisan serve` | Geliştirme sunucusu → `http://127.0.0.1:8000` |
| `php artisan tinker` | Etkileşimli PHP kabuğu (REPL) — uygulama yüklü |
| `php artisan pail` | Log akışını canlı izler |
| `php artisan queue:work` | Kuyruktaki işleri çalıştırır (Faz 5) |
| `php artisan queue:listen` | Aynısı, ama kod değişikliğini görür (geliştirme) |

### 7.1 `serve` gerekli mi?

Laravel Herd zaten projeyi bir alan adından yayınlar. `php artisan serve`
ise **8000 portunda** çalışır — frontend'in `vite.config.ts` proxy'si oraya
baktığı için Faz 2'de bunu kullanacağız.

### 7.2 `tinker` — en çok işine yarayacak araç

```powershell
php artisan tinker
```

Uygulamayı yükler ve sana bir PHP konsolu verir. Model, config, enum — hepsi
elinin altında:

```php
App\Enums\ErrorCode::ValidationFailed->status();     // 422
config('davetkart.tiers.gold.price');
App\Models\User::count();
App\Models\User::factory()->create();
```

Çıkmak için `exit`.

> ⚠️ `tinker` **gerçek** veritabanına yazar (`davetkart`). Deneme kayıtları
> orada kalır.

### 7.3 `queue:work` ile `queue:listen` farkı

`queue:work` uygulamayı bir kez yükler ve bellekte tutar — hızlıdır ama **kod
değişikliğini görmez**. `queue:listen` her iş için yeniden yükler — yavaştır ama
geliştirmede doğru olanıdır. Üretimde `queue:work` + süpervizör kullanılır (Faz 9).

---

## 8. Önbellek komutları

| Komut | Ne yapar | Ne zaman |
|---|---|---|
| `php artisan config:clear` | Config önbelleğini siler | `.env` değişince |
| `php artisan config:cache` | Config'i tek dosyaya derler | Üretim (Faz 9) |
| `php artisan route:clear` / `route:cache` | Aynısı rotalar için | Üretim |
| `php artisan optimize` | config + route + event, hepsini önbelleğe alır | Üretim |
| `php artisan optimize:clear` | Tüm önbellekleri temizler | 🔧 "Bir şey tuhaf" |

### 8.1 🔴 `config:cache` ve `env()` tuzağı

Faz 0'ın **Y1** kuralı: *kod içinde `env()` çağrılmaz, `config()` çağrılır.*

Sebebi tam olarak bu komuttur. `config:cache` sonrası `.env` dosyası **hiç
okunmaz** — tüm değerler derlenmiş dosyadan gelir. Kodun içinde kalmış bir
`env('GEMINI_API_KEY')` çağrısı sessizce **`null`** döner.

Hata Faz 9'da, üretimde, ilk kez ortaya çıkar ve nedeni bulunması zordur. Kuralı
Faz 0'da koymanın ödemesi orada alınır.

> `composer test` script'inin başında neden `config:clear` var? Aynı sebep:
> önbelleğe alınmış bir config, `phpunit.xml`'deki test ortamı değerlerini
> gölgeleyebilir.

---

## 9. Bizim yazdığımız komut

```powershell
php artisan errors:export             # contracts/error-codes.json üretir
php artisan errors:export --check     # yazmaz, güncel mi diye bakar
```

`ErrorCode` enum'undan makine okunabilir katalog üretir (K31). Frontend bu
dosyadan çeviri anahtarlarını türetir.

`--check` bayrağı `composer check` zincirinde (K34) — katalog kodla uyumsuzsa
zincir kırılır ve sessizce eskimesi imkânsızlaşır.

Ayrıntısı:
[`app/Console/Commands/ExportErrorCodes.md`](../app/Console/Commands/ExportErrorCodes.md)

---

## 10. Test komutları

| Komut | Ne yapar |
|---|---|
| `php artisan test` | Tüm testler |
| `php artisan test --filter=HealthTest` | Yalnızca o sınıf |
| `php artisan test --filter=ping_returns_ok` | Yalnızca o metot |
| `php artisan test --testsuite=Feature` | Yalnızca bir takım |
| `php artisan test --stop-on-failure` | İlk hatada dur |

Testler `phpunit.xml`'deki ortamla koşar: ayrı veritabanı (`davetkart_test`,
K19), `array` cache, `sync` kuyruk, `APP_DEBUG=false` (T4).

> 🔴 **`APP_DEBUG=false` bilinçlidir.** Testler **üretim kipinde** koşar ki
> "debug bloğu sızmıyor" gibi sızıntı testleri yazılabilsin (K20 §9).

---

## 11. Windows + Herd'e özel notlar

### 11.1 `curl` değil `curl.exe`

PowerShell'de `curl`, `Invoke-WebRequest`'in takma adıdır ve `-H` gibi bayrakları
anlamaz. Gerçek curl için uzantıyı yaz:

```powershell
curl.exe http://localhost:8000/api/ping
curl.exe -H "Accept: text/html" http://localhost:8000/api/olmayan
```

### 11.2 PATH değişikliği açık pencerelere yansımaz

Ortam değişkenleri süreç doğarken kopyalanır. PHP veya Composer'ı PATH'e yeni
eklediysen **VS Code'u ve terminali yeniden başlat** (Faz 0, ders 10).

### 11.3 Komutlar proje kökünden çalıştırılır

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
```

`artisan` ve `composer.json` orada. Alt klasörden çalıştırılan komut bulunamaz.

---

## 12. Faz 2'de kullanacaklarımız

| Sıra | Komut |
|---|---|
| 2.0 ✅ | `php artisan migrate:fresh` · `php artisan db:table users` |
| 2.1 ✅ | `composer lint` |
| 2.2 | *(komut yok — dosya mevcut, düzenlenecek)* · `composer check` ← kapı açılır |
| 2.3 | `php artisan make:resource UserResource` |
| 2.4 | `php artisan make:request Auth/RegisterRequest` |
| 2.5 | `php artisan make:class Actions/Auth/RegisterUserAction` |
| 2.6 | `php artisan make:controller Api/V1/AuthController` |
| 2.7 | `php artisan route:list --path=api` |
| 2.8 | `make:request Auth/LoginRequest` · `make:class Actions/Auth/LoginUserAction` |
| 2.9 | `make:class Actions/Auth/RevokeTokenAction` |
| 2.10 | `php artisan make:test AuthTest` · `php artisan test --filter=AuthTest` |
| Kapanış | `php artisan serve` + frontend `npm run dev` (uçtan uca doğrulama) |

---

## 13. Günlük çalışma ritmi

```
1. Komut         php artisan make:*          ← sen çalıştırırsın
2. Kod           kısa yorumlarla
3. Kılavuz       docs/rehber/<kod-yolu>.md
4. Doğrulama     composer lint → composer check
5. DUR           onay bekle
```

Commit öncesi:

```powershell
composer lint      # biçimlendir (DEĞİŞTİRİR)
composer check     # dört kapıdan geçir (DOĞRULAR)
git commit
```

---

## 14. 🔴 Tehlikeli komutlar

| Komut | Ne kaybedersin | Önce sor |
|---|---|---|
| `migrate:fresh` | Veritabanındaki **her şey** | Faz 3'ten sonra asla refleksle |
| `db:wipe` | Tüm tablolar | Aynı |
| `make:* --force` | Yazdığın dosyanın içeriği | Dosya var mı diye bak |
| `composer update` | Sürüm sabitliği (`.lock` değişir) | `install` yeterli mi? |
| `git checkout .` | Kaydedilmemiş tüm değişiklik | — |

---

## 15. Sık yapılan hatalar

| Belirti | Sebep | Çözüm |
|---|---|---|
| `Could not open input file: artisan` | Yanlış klasördesin | `cd` ile proje köküne |
| `migrate` "Nothing to migrate" ama dosyayı değiştirdin | Ada bakar, içeriğe değil | `migrate:fresh` |
| `composer check` Pint'te kırılıyor | `--test` düzeltmez | `composer lint` → tekrar `check` |
| `.env`'i değiştirdin, etkisi yok | Config önbellekte | `php artisan config:clear` |
| `Class not found` (yeni dosya) | Autoload haritası eski | `composer dump-autoload` |
| `Class not found` (sunucuda, yerelde çalışıyor) | Klasör adı küçük harf | PascalCase (Faz 0, ders 6) |
| PHPStan `Allowed memory size exhausted` | 128 MB yetmez | Zaten `--memory-limit=1G` var (K6) |
| `curl: -H bulunamadı` | PowerShell takma adı | `curl.exe` |
| Yeni komut PATH'te görünmüyor | Süreç eski ortamı taşıyor | Terminali yeniden başlat |
| Testler geliştirme verisini siliyor | Yanlış veritabanı | `phpunit.xml` → `davetkart_test` |

---

## 16. Fazlara göre eklenenler

| Faz | Eklenen komutlar |
|---|---|
| 0 | `composer lint/analyse/check/test` · `migrate` · `tinker` |
| 1 | `errors:export` (+`--check`) · `route:list` · `test --filter` |
| 2 | `make:resource/request/class/controller/test` · `migrate:fresh` · `db:table` |
| 3+ | *(gelecek)* `make:policy` · `make:enum` · `db:seed` |

---

## 17. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **CLI** | Komut satırı arayüzü |
| **Argüman** | Komuta sırayla verilen değer |
| **Seçenek / bayrak** | `--ad=değer` / `--ad` |
| **Çıkış kodu (exit code)** | `0` başarı, `≠0` hata. Zincirleri bu belirler |
| **Fail fast** | Ucuz kontrolü öne alıp erken kırılma |
| **REPL** | Yazdığın ifadeyi anında çalıştıran kabuk (`tinker`) |
| **Autoload** | Sınıfı ilk kullanımda otomatik yükleme (PSR-4) |
| **Süpervizör** | Kuyruk işçisini ölürse yeniden başlatan program |
| **Scaffold** | İskelet dosya üretimi (`make:*`) |

---

## 18. Bağlantılar

| İlgili | Nerede |
|---|---|
| PHP dili referansı | [`php-dili.md`](php-dili.md) |
| Migration mantığı | [`veritabani-ve-migration.md`](veritabani-ve-migration.md) |
| Araç zinciri ve iş bölümü | [`fazlar/FAZ-0.md`](../fazlar/FAZ-0.md) §6 |
| Pint yapılandırması | [`pint.md`](../pint.md) |
| PHPStan yapılandırması | [`phpstan.md`](../phpstan.md) |
| Test ortamı | [`phpunit.md`](../phpunit.md) |
| `errors:export` ayrıntısı | [`ExportErrorCodes.md`](../app/Console/Commands/ExportErrorCodes.md) |
