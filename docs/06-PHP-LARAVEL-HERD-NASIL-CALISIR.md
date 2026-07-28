# PHP, Composer, Laravel ve Herd — Kim Nerede Yaşıyor?

> **Bu doküman şu üç soruyu cevaplar:**
> 1. Laravel'i bilgisayara mı yoksa projeye mi yüklüyoruz?
> 2. Herd tam olarak ne işe yarıyor, olmadan olmaz mı?
> 3. Bir istek geldiğinde bu parçalar birbirine nasıl bağlanıyor?

---

# 1. En kısa cevap

| Şey | Ne? | Nereye kurulur? |
|---|---|---|
| **PHP** | Bir **program** (yorumlayıcı) | 💻 **Bilgisayara** — bir kez |
| **Composer** | Bir **program** (paket yöneticisi) | 💻 **Bilgisayara** — bir kez |
| **Herd** | Bir **ortam paketi** (PHP + Composer + nginx) | 💻 **Bilgisayara** — bir kez |
| **Laravel** | Bir **kütüphane** (PHP dosyaları yığını) | 📁 **Her projeye ayrı ayrı** |

**Yani:** Laravel bir uygulama değil, indirilip kullanılan bir **kod paketidir.**
Bilgisayarınıza "Laravel kurulmaz" — her projenin içine ayrı ayrı **indirilir.**

---

# 2. Zaten bildiğiniz bir şeye benzetelim: Frontend

Frontend projenizde şunlar var:

```
davetkart-frontent/
├── package.json      ← "bana React, Vite, axios gerekli" listesi
├── package-lock.json ← "tam olarak şu sürümler" kilidi
└── node_modules/     ← indirilmiş paketler (React BURADA yaşıyor)
```

`npm install` çalıştırdığınızda React bilgisayarınıza kurulmadı — **bu projenin
`node_modules/` klasörüne indirildi.** Yan taraftaki başka bir projede farklı bir
React sürümü olabilir, birbirlerini etkilemezler.

**Backend'de tam olarak aynısı oluyor:**

```
davetkart-backend-php-laravel/
├── composer.json     ← "bana Laravel, Sanctum gerekli" listesi
├── composer.lock     ← "tam olarak şu sürümler" kilidi
└── vendor/           ← indirilmiş paketler (LARAVEL BURADA yaşıyor)
```

### Birebir karşılıklar

| Frontend (bildiğiniz) | Backend (yeni) | Ne işe yarıyor |
|---|---|---|
| **Node.js** | **PHP** | Kodu çalıştıran motor — bilgisayara kurulur |
| **npm** | **Composer** | Paket yöneticisi — bilgisayara kurulur |
| `package.json` | `composer.json` | Tarif: hangi paketler gerekli |
| `package-lock.json` | `composer.lock` | Kilit: tam olarak hangi sürümler |
| `node_modules/` | `vendor/` | İndirilen paketler — projeye özel |
| **React** | **Laravel** | Framework — `node_modules`/`vendor` içinde yaşar |
| `npm install` | `composer install` | Tarifi okuyup paketleri indir |
| `npm run dev` | `php artisan serve` | Geliştirme sunucusunu başlat |

> **`vendor/` klasörü git'e girmez** — tıpkı `node_modules/` gibi. Çünkü
> `composer.json` + `composer.lock` varken herkes `composer install` diyerek
> **birebir aynı** paketleri indirebilir. 100 MB'lık klasörü depoda taşımanın
> anlamı yok.

---

# 3. Peki `composer create-project` ne yaptı?

Tek komut, üç iş:

```
composer create-project laravel/laravel _gecici
                          │
    ┌─────────────────────┴──────────────────────┐
    │                                            │
1. Laravel'in "iskelet projesi"ni indirdi        │
   (app/, config/, routes/, public/ klasörleri   │
    ve örnek dosyalar — bunlar SİZİN kodunuz     │
    olacak, düzenlemeniz beklenir)               │
                                                 │
2. composer.json'ı okudu ────────────────────────┘
   ve içindeki paketleri vendor/ klasörüne indirdi
   (laravel/framework, symfony bileşenleri,
    monolog, carbon... ~30 paket, ~100 MB)

3. vendor/autoload.php dosyasını üretti
   (sınıf isimlerini dosya yollarına çeviren harita)
```

### İki farklı "Laravel" var — karıştırmayın

```
📁 vendor/laravel/framework/        ← FRAMEWORK'ÜN KENDİSİ
   Laravel'in motor kodu. ASLA elle düzenlemezsiniz.
   Güncellemesi: composer update

📁 app/  config/  routes/  public/  ← İSKELET (SİZİN KODUNUZ)
   Laravel'in size verdiği başlangıç yapısı.
   Buraları siz doldurursunuz.
```

Bu ayrım kritik: `vendor/` klasörünü silseniz `composer install` ile geri gelir.
Ama `app/` klasörünü silerseniz **kendi kodunuzu** kaybedersiniz.

---

# 4. `artisan` nedir?

Proje kökündeki `artisan` dosyası — uzantısı bile yok. Açarsanız içinde PHP kodu
görürsünüz.

```powershell
php artisan migrate
│    │       │
│    │       └── komut
│    └────────── bu projeye ait CLI dosyası
└─────────────── PHP yorumlayıcısı (bilgisayardaki program)
```

Yani `php artisan ...` demek: *"Ey PHP, şu `artisan` dosyasını çalıştır ve ona
`migrate` komutunu ver."*

`artisan` **projeye özeldir.** Başka klasörde çalıştıramazsınız — çünkü o proje
için özelleştirilmiş komutlara erişir.

> **Frontend karşılığı:** `npm run dev` yazarken de aslında projeye özel bir
> script çalıştırıyorsunuz.

---

# 5. Herd ne işe yarıyor?

## 5.1 Problem: PHP tek başına bir sunucu değil

Node.js'te şunu yazabilirsiniz ve iş biter:

```js
http.createServer(...).listen(3000)   // Node kendi sunucusudur
```

PHP böyle tasarlanmadı. PHP **bir web sunucusunun içinde** çalışmak üzere
yapılmıştır:

```
Tarayıcı  →  Web Sunucusu (nginx/Apache)  →  PHP  →  yanıt
              │
              └── isteği karşılar, PHP'ye devreder,
                  statik dosyaları kendisi servis eder
```

Yani PHP ile çalışmak için elinizde şunlar olmalı:
1. **PHP** yorumlayıcısı
2. Bir **web sunucusu** (nginx veya Apache)
3. İkisini birbirine bağlayan ayarlar (PHP-FPM yapılandırması)
4. (Genelde) bir **veritabanı**

Bunları tek tek kurup birbirine bağlamak, yeni başlayan biri için saatler sürer
ve her Windows makinesinde başka türlü bozulur.

## 5.2 Çözüm: Herd

**Herd = bu parçaları kurulmuş ve birbirine bağlanmış hâlde veren paket.**

Herd'i kurduğunuzda bilgisayarınıza şunlar geldi:

```
✅ PHP           (birden fazla sürüm, aralarında geçiş yapabilirsiniz)
✅ Composer      (paket yöneticisi)
✅ laravel       (yeni proje kurma yardımcısı)
✅ nginx         (web sunucusu)
✅ node, npm     (frontend tarafı için)
✅ *.test yerel alan adları  (proje.test şeklinde adres)
```

Bu yüzden PowerShell'de `php -v` yazınca cevap alıyorsunuz — Herd, PHP'yi
sisteminizin PATH'ine ekledi.

## 5.3 Herd'in alternatifleri (tarihçe)

| Araç | Dönem | Not |
|---|---|---|
| **XAMPP / WAMP** | 2005–2018 | Apache+PHP+MySQL paketi. Ağır, sürüm geçişi zor |
| **Vagrant / Homestead** | 2014–2020 | Sanal makine. Doğru ama yavaş ve hantal |
| **Docker** | 2018–bugün | Endüstri standardı. Güçlü ama öğrenme eğrisi dik |
| **Valet** (macOS) | 2016–bugün | Hafif, hızlı — Herd'in atası |
| **Herd** | 2023–bugün | Valet'in Windows'a da gelen, arayüzlü hâli |

> **Herd olmadan olur mu?** Olur — PHP'yi elle indirir, PATH'e ekler, Composer'ı
> ayrıca kurarsınız. Sonra da `php artisan serve` ile çalışırsınız (web sunucusu
> gerekmez, çünkü PHP'nin küçük bir dahili sunucusu var). Ama Herd bu işi 10
> dakikadan 2 dakikaya indiriyor. Kullanın.

## 5.4 Herd'in bizde kullanmadığımız kısmı

Herd, proje klasörünüzü "park" ederseniz otomatik olarak
`http://davetkart-backend-php-laravel.test` gibi bir adres verir (nginx üzerinden).

**Biz bunu kullanmıyoruz.** Çünkü frontend'in `vite.config.ts` dosyası şunu
söylüyor:

```ts
'/api': { target: 'http://localhost:8000' }
```

Yani frontend, backend'i **8000 portunda** arıyor. `php artisan serve` tam olarak
oraya bağlanıyor. Herd'in `.test` adresi 80 portunda çalışır, uyuşmaz.

> İleride isterseniz `vite.config.ts`'i `.test` adresine çevirebiliriz — ama şu an
> gereksiz karmaşıklık.

---

# 6. Bir istek geldiğinde ne oluyor? (Uçtan uca)

## Geliştirme ortamı (bizim kurulumumuz)

```
1. Tarayıcı: http://localhost:3000/create
             │
2. Vite dev sunucusu (Node) → React uygulamasını verir
             │
3. React → axios ile "/api/invitations" ister
             │
4. Vite proxy: "bu /api ile başlıyor, ben cevaplamam"
   → http://localhost:8000/api/invitations'a iletir
             │
5. php artisan serve (PHP'nin dahili sunucusu, port 8000)
             │
6. public/index.php çalışır
             │
7. vendor/autoload.php yüklenir  ← sınıf haritası burada
             │
8. bootstrap/app.php → uygulama kurulur
             │
9. routes/api.php → hangi controller?
             │
10. Sizin kodunuz çalışır (Controller → Action → Model)
             │
11. Veritabanı: database/database.sqlite  (tek dosya)
             │
12. JSON yanıt → Vite → React
```

**Bu zincirde nerede ne var:**

| Adım | Kim çalıştırıyor | Nerede yaşıyor |
|---|---|---|
| 2 | Node.js | 💻 bilgisayar (Herd ile geldi) |
| 5 | PHP | 💻 bilgisayar (Herd ile geldi) |
| 6 | `index.php` | 📁 proje |
| 7 | `autoload.php` | 📁 proje (`vendor/`, Composer üretti) |
| 8–9 | Laravel çekirdeği | 📁 proje (`vendor/laravel/framework/`) |
| 10 | **sizin kodunuz** | 📁 proje (`app/`) |
| 11 | SQLite | 📁 proje (`database/database.sqlite`) |

## Üretim (canlı sunucu) ortamı

```
1. Tarayıcı: https://davetkart.com/api/invitations
             │
2. nginx (sunucudaki gerçek web sunucusu)
   → PHP-FPM'e devreder
             │
3. public/index.php → ... → sizin kodunuz
             │
4. MySQL (ayrı servis)
```

Fark: `php artisan serve` yerine **nginx + PHP-FPM**, SQLite yerine **MySQL**.
Kodunuz aynı kalır — sadece `.env` dosyası değişir. İşte 12-Factor ilkesinin
pratikteki karşılığı budur.

---

# 7. Neden Laravel projeye indiriliyor da bilgisayara kurulmuyor?

Üç sağlam sebep:

### 7.1 Sürüm izolasyonu

```
D:\Projects\davetkart\     → Laravel 13  (yeni proje)
D:\Projects\eski-is\       → Laravel 10  (müşteri projesi, güncellenemiyor)
```

Laravel bilgisayara kurulsaydı ikisi çakışırdı. Her projenin kendi `vendor/`
klasörü olduğu için ikisi de mutlu çalışır.

> Frontend'de de aynısı: bir projede React 18, diğerinde React 19 olabiliyor.

### 7.2 Tekrar üretilebilirlik

Kodu GitHub'a atarsınız (`vendor/` hariç). Arkadaşınız klonlayıp
`composer install` der. `composer.lock` sayesinde **sizinle bit bit aynı**
sürümleri indirir. "Bende çalışıyordu" problemi ortadan kalkar.

### 7.3 Deploy

Sunucuya `git pull` + `composer install --no-dev` yaparsınız. Sunucu, geliştirme
makinenizle aynı paketleri kurar. Sunucuya elle Laravel kurmanız gerekmez.

---

# 8. Sık Sorulanlar

**S: Her Laravel projesi 100 MB `vendor/` mü taşıyacak?**
Evet ve bu normaldir. Frontend'inizin `node_modules/` klasörü muhtemelen daha da
büyük. Git'e girmediği için önemli değil.

**S: `vendor/` klasörünü yanlışlıkla sildim.**
`composer install` deyin, geri gelir. Hiçbir şey kaybetmediniz.

**S: Laravel'i nasıl güncellerim?**
`composer.json`'daki sürümü değiştirip `composer update`. Bilgisayara dokunmazsınız.

**S: PHP sürümünü nasıl değiştiririm?**
Herd arayüzünden. Laravel 13 için **PHP 8.3 veya üstü** gerekli.

**S: `php artisan serve` yerine Herd'in `.test` adresini kullansam?**
Kullanabilirsiniz ama `vite.config.ts`'i de güncellemeniz gerekir. Şimdilik
gereksiz iş — `serve` ile devam.

**S: MySQL'e ne zaman geçeceğiz?**
Şu an SQLite ile geliştiriyoruz (sıfır kurulum). Yayına çıkmadan önce MySQL'e
geçeriz — migration'lar taşınabilir olduğu için `.env` değişikliği yeterli olacak.

**S: `composer install` ile `composer update` farkı ne?**
`install` → `composer.lock`'taki **tam sürümleri** kurar (güvenli, herkes aynı).
`update` → `composer.json`'daki kurallara göre **yeni sürüm arar** ve lock'u
günceller. Takım hâlinde çalışırken `update`'i düşünerek yapın.

---

# 9. Zihinsel harita — tek resim

```
╔══════════════════════════════════════════════════════════════╗
║  BİLGİSAYARINIZ                                              ║
║  (bir kez kurulur, tüm projeler paylaşır)                    ║
║                                                              ║
║   ┌────────── HERD ──────────┐                               ║
║   │  PHP 8.4    (yorumlayıcı) │  ← kodu çalıştırır           ║
║   │  Composer   (paket yön.)  │  ← vendor/'ü doldurur        ║
║   │  nginx      (web sunucu)  │  ← biz kullanmıyoruz         ║
║   │  node/npm                 │  ← frontend için             ║
║   └───────────────────────────┘                               ║
╚══════════════════════════════════════════════════════════════╝
                          ↓ kullanır
╔══════════════════════════════════════════════════════════════╗
║  PROJE KLASÖRÜ  D:\Projects\davetkart\davetkart-backend-...  ║
║  (her projede ayrı ayrı)                                     ║
║                                                              ║
║   composer.json    ← "hangi paketler" tarifi                 ║
║   composer.lock    ← "tam hangi sürümler" kilidi             ║
║   vendor/          ← 📦 LARAVEL BURADA (git'e girmez)        ║
║   artisan          ← bu projenin CLI'ı                       ║
║   .env             ← 🔐 bu projenin sırları (git'e girmez)   ║
║                                                              ║
║   ┌─── SİZİN YAZACAĞINIZ KOD ───┐                            ║
║   │  app/     routes/            │                            ║
║   │  config/  database/          │                            ║
║   │  public/  tests/             │                            ║
║   └──────────────────────────────┘                            ║
╚══════════════════════════════════════════════════════════════╝
```

---

## Tek cümlelik özet

> **PHP ve Composer bilgisayarınıza kurulan araçlardır (mutfak); Laravel ise her
> projeye ayrı indirilen bir kütüphanedir (malzeme). Herd, mutfağı tek tıkla
> kurulmuş hâlde veren pakettir.**
