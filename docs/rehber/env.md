# `.env.example` ve üretim şablonu

> **Faz:** 9 — Üretim hazırlığı, dosya 9.13
> **İlgili:** [`config/cors.md`](config/cors.md) · [`config/davetkart.md`](config/davetkart.md) ·
> [`config/payment.md`](config/payment.md) · [`config/ai.md`](config/ai.md)
> **Kurallar:** **Y1** (kodda `env()` yok) · **E6** (ortam farkı env'e) ·
> **B10** (kurulumun bağımlı olduğu her şey depoda) · **K80**

---

## 1. 🔴 `.env.example` sekiz faz boyunca eksikti

Bugüne kadar `.env.example` yalnızca Laravel'in kendi değişkenlerini
taşıyordu. Projenin **kendi** değişkenlerinin hiçbiri yoktu:

```
PAYMENT_PROVIDER · IYZICO_* · AI_PROVIDER · GEMINI_API_KEY
DAVETKART_MEDIA_DISK · DAVETKART_DEFAULT_TIMEZONE · CORS_ALLOWED_ORIGINS
```

Ve **hiçbir şey bozulmadı**, çünkü her `config()` satırı bir varsayılan
taşıyor:

```php
'default' => env('PAYMENT_PROVIDER', 'fake'),
'disk'    => env('DAVETKART_MEDIA_DISK', 'public'),
```

İşte sessiz hata tam burada: varsayılanlar **geliştirme için** seçilmişti.
Temiz bir klon alan biri — ya da yeni bir sunucu — bu değişkenlerin **var
olduğunu bile bilemezdi** ve üretimde sessizce yanlış tarafa düşerdi:
ödemeler sahte, medya web kökü altında, CORS localhost'a kilitli.

> **B10'un ikinci uygulaması.** 9.2'de kuralı şöyle yazmıştık: *kalite
> kapısının bağımlı olduğu her şey sürümlenir.* Aynısı kurulum için de
> geçerli: **kurulumun bağımlı olduğu her şey depoda durur.** İlkinde
> eksik olan bir dizindi, burada bir değişken listesi.

---

## 2. İki dosya, iki iş

| Dosya | Ne der | Nerede yaşar |
|---|---|---|
| `.env.example` | *"Bu projede şu değişkenler var"* | Repo |
| [`docs/10-URETIM-ENV-SABLONU.md`](../10-URETIM-ENV-SABLONU.md) | *"Üretimde şunlar neden farklı"* | Repo |
| `.env` | Gerçek değerler, sırlar | 🔴 Yalnızca sunucu — repoya **asla** |

İkincisi bir **şablon**, bir yapılandırma değil. Değeri, her satırın yanındaki
gerekçede: neden `LOG_LEVEL=warning`, neden `CACHE_STORE=file`, neden
`PAYMENT_PROVIDER` hâlâ `fake`.

---

## 3. Üretim şablonundaki beş kritik satır

### `APP_DEBUG=false` — ilk kez gerçek ortamda

`docs/08` §2.2'deki `debug` bloğu üretimde **hiç üretilmez**; güvenlik
disipline değil **yapıya** bağlıydı. Bu bayrak sekiz fazdır `true`'ydu ve
`false` hâli yalnızca `HealthTest`'te sınandı.

🔴 Yan etkisi var ve bilinçli: `APP_ENV=production`, `AppServiceProvider`'da
`Model::shouldBeStrict(false)` yapar — yani **N+1 koruması kapanır**. Faz
0'da alınmış bir karar: üretimde sessizce yavaşlayan bir sorgu artık hata
vermeyecek, yalnızca yavaşlatacak.

### `DAVETKART_MEDIA_DISK=s3` — K55'in kapandığı yer

`'public'` demek, yüklenenlerin `public/storage` sembolik bağıyla **web kökü
altında** durması: yüklenen bir dosya doğrudan URL ile çağrılabilir. S3'e
geçiş bunu **yapısal olarak** kapatır.

Göç güvenli, çünkü **F4**: `media.disk` kolonda saklanıyor. Eski satırlar
göçten sonra da kendi diskinden çözülür — Faz 6'da ödenen bedelin karşılığı
tam burada alınıyor.

### `CACHE_STORE=file` / `QUEUE_CONNECTION=database` — K80

🔴 Varsayılanlar **her yerde** çalışır; Redis bir **yükseltmedir**, varsayım
değil.

| | Paylaşımlı hosting | VPS |
|---|---|---|
| Cache | `file` | `redis` |
| Kuyruk | `database` + cron'dan `queue:work --stop-when-empty --max-time=50` | `redis` + supervisor |

Kuyruk kararının bedeli açık: paylaşımlı hostingde `OptimizeUploadedImage`
gecikmesi **60 saniyeye** kadar çıkar. 15 saniye kuralı **korunur** (istek
yine anında döner), ama "kuyruk" artık "her dakika" demek.

Redis'e geçişin iki notu:
- **Q2 sayesinde kota sıfırlanmaz**: asistan kotası veritabanında sayılıyor,
  Redis restart'ı ona dokunmaz. Faz 8'de ödenen bedelin karşılığı.
- 🔴 Hız sınırı sınıfı değişir: Laravel `ThrottleRequestsWithRedis`'e geçer.
  Kovalar aynı kalır ama davranış birebir aynı değildir —
  `throttle:assistant` ve `throttle:contact` **yeniden sınanmalı**.

### `LOG_STACK=daily`, `LOG_LEVEL=warning`

`single` tek dosyaya yazar ve o dosya **sınırsız büyür**; bir gün disk dolar
ve uygulama yazamaz hâle gelir. `daily` günlük dosya açar,
`LOG_DAILY_DAYS=14` kadarını saklar.

`LOG_LEVEL=debug` üretimde hem gürültü hem **sızıntı riskidir**: **H8** gereği
sağlayıcı hataları log'a gidiyor ve log da bir depodur.

### `PAYMENT_PROVIDER=fake` — üretimde bile

🔴 Şaşırtıcı görünüyor ama doğru: `IyzicoGateway` **henüz yazılmadı**
(sağlayıcı anahtarları yok). `'iyzico'` yazıp sürücüsüz bırakmak, **K70**
gereği ödeme uçlarını 503'e düşürür — ki bu **iyi** bir davranış, ama o hâlde
`'fake'` bırakmak da aynı kapıya çıkar ve en azından akış test edilebilir
kalır.

Asıl kural değişmiyor: **bilinmeyen sürücü sessizce `fake`'e düşmez.**
Eksik bir `IYZICO_API_KEY`, her ödemeyi bedava yapmaz.

---

## 4. `env()` nerede çağrılır, neyi `env()` yaparız?

İki ayrı soru ve sık karıştırılıyor:

| Soru | Cevap | Kural |
|---|---|---|
| `env()` **nerede** çağrılır? | Yalnızca `config/` içinde | **Y1** — `config:cache` sonrası kodda `null` döner |
| **Neyi** `env()` yaparız? | Ortamlar arasında farklılaşması **gereken** şeyi | **E6** |

İkinci sorunun iki örneği bu fazda yan yana durdu:

| Değişken | `env()`? | Neden |
|---|---|---|
| `CORS_ALLOWED_ORIGINS` | ✅ Evet | Geliştirme localhost, üretim gerçek alan adı |
| `davetkart.orders.release_window_days` | ❌ Hayır | Kullanıcıya verilmiş **ticari söz**; staging'de 30, üretimde 3 olamaz |

---

## 5. 🔴 `config:cache` sonrası sırlar nerede durur?

```powershell
php artisan config:cache
```

Bu komut tüm `config/` ağacını **tek bir PHP dosyasına** serileştirir:
`bootstrap/cache/config.php`. O andan itibaren `.env` **hiç okunmaz** — Y1'in
bütün gerekçesi bu.

🔴 Ama bir sonucu var ve konuşulmalı: **o cache dosyası sunucuda sırları düz
metin taşır.** `GEMINI_API_KEY`, `IYZICO_SECRET_KEY`, veritabanı parolası —
hepsi orada. `.env` kadar korunmalı:

```bash
chmod 640 bootstrap/cache/config.php
chown www-data:www-data bootstrap/cache/config.php
```

Ve `bootstrap/cache/` zaten `.gitignore`'da olmalı — öyle.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `.env`'i repoya eklemek | Sırlar git geçmişine girer; geri alınamaz |
| 2 | `.env.example`'ı güncellememek | Yeni sunucu eksik değişkenle sessizce yanlış çalışır (§1) |
| 3 | `APP_DEBUG=true` unutmak | Yığın izi, dosya yolları ve SQL dışarı sızar |
| 4 | `LOG_STACK=single` bırakmak | Log dosyası diski doldurur |
| 5 | `config:cache` sonrası dosya izinlerini bırakmak | Sırlar düz metin ve okunabilir (§5) |
| 6 | Ticari bir sabiti `env()`'e bağlamak | Ortamlar sessizce ayrışır (§4) |
| 7 | `PAYMENT_PROVIDER=iyzico` yazıp sürücü yazmamak | Ödeme uçları 503 — K70 doğru çalışıyor ama bilerek yapılmalı |

---

## 7. Kendin dene

🔴 Temiz klon tatbikatının **düzeltilmiş** hâli — bu kez `.env` kopyalamadan:

```powershell
cd $env:TEMP
git clone -b faz-9 D:\Projects\davetkart\davetkart-backend-php-laravel dk-env
cd dk-env
composer install
Copy-Item .env.example .env          # 🔴 kopyalanan artık ŞABLON, gerçek .env değil
php artisan key:generate
# DB_PASSWORD ve DB_DATABASE=davetkart_test'i elle doldur
composer check
```

Bu tatbikat artık iki soruyu birden sınıyor: depo taşınabilir mi **ve**
`.env.example` eksiksiz mi. Eksik bir değişken varsa `composer check` değil,
**uygulamanın kendisi** söyleyecek.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **12-Factor III** | Yapılandırma ortamda tutulur, kodda değil |
| **Dev/prod parity** | Geliştirme ile üretimin olabildiğince benzer olması (K19) |
| **`config:cache`** | Tüm config'i tek dosyaya serileştirip `.env` okumayı bırakma |
| **Supervisor** | Bir süreci sürekli ayakta tutan servis (kuyruk worker'ı) |
| **Log rotasyonu** | Log dosyasını periyodik olarak yenileyip eskiyi silme |
