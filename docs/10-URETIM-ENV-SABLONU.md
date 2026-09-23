# Üretim `.env` Şablonu

> **Faz:** 9 — Üretim hazırlığı, dosya 9.13
> **Kılavuz:** [`rehber/env.md`](rehber/env.md) — her satırın gerekçesi orada
> **Kurallar:** **Y1** · **E6** · **B10** · **K80**
> **Güncelleme:** 23 Eylül 2026 — Sentry, `GEMINI_MODEL`, PHP ^8.5, TrustProxies'in
> hız sınırlarına etkisi ve ödeme sağlayıcısı notu (Shopier) eklendi.

---

🔴 Bu bir **şablondur**, bir yapılandırma değil. Gerçek `.env` **sunucuda**
yaşar ve repoya asla girmez. Şablonun işi, hangi değişkenlerin var olması
gerektiğini ve her birinin üretimde **neden** farklı olduğunu söylemektir.

Dosya olarak değil doküman olarak duruyor — böylece bir dağıtım betiği onu
yanlışlıkla `.env` diye kopyalayamaz. Kurulumda aşağıdaki bloğu sunucuda
`.env` olarak yapıştır, boş bırakılan sırları doldur, sonra
`php artisan key:generate` çalıştır.

```dotenv
# =============================================================================
# DavetKart — URETIM .env SABLONU
# =============================================================================
#
# Bu dosya bir SABLONDUR ve repoda durur. Gercek .env SUNUCUDA yasar, repoya
# ASLA girmez. Sablonun isi, hangi degiskenlerin var olmasi gerektigini ve her
# birinin uretimde NEDEN farkli olmasi gerektigini soylemektir.
#
# 🔴 K80 (Faz 9): altyapi EN DUSUK ORTAK PAYDAYA yazilir. Asagidaki degerler
# paylasimli hostingde de calisir; Redis ve S3 birer YUKSELTMEDIR, varsayim
# degil. Ilgili satirlarda "VPS'te" notu var.
#
# Kurulum: bu dosyayi sunucuda `.env` olarak kopyala, bos birakilan sirlari
# doldur, sonra `php artisan key:generate` calistir.

# --- Uygulama ----------------------------------------------------------------
APP_NAME=DavetKart
APP_ENV=production
APP_KEY=
# 🔴 false. Bu bayrak ilk kez GERCEK ortamda calisacak: docs/08 §2.2'deki
# `debug` blogu uretimde HIC uretilmez. Ayrica APP_ENV=production,
# AppServiceProvider'da Model::shouldBeStrict(false) yapar — yani N+1
# korumasi KAPANIR. Bu Faz 0'da bilincli alinmis bir karardi: uretimde
# sessizce yavaslayan bir sorgu artik hata vermeyecek.
APP_DEBUG=false

# 🔴 https ZORUNLU. Uretilen tum mutlak URL'ler (medya, odeme donus yollari)
# bunu taban alir. Reverse proxy arkasindaysa TrustProxies de ayarlanmali,
# yoksa X-Forwarded-Proto guvenilmez ve uretilen URL'ler http kalir.
#
# 🔴 TrustProxies YALNIZCA URL meselesi DEGIL (23 Eylul notu): istek bir yuk
# dengeleyiciden (ALB, CloudFront, Cloudflare) geliyorsa ve proxy guvenilir
# isaretlenmemisse $request->ip() DENGELEYICININ IP'sini doner. Sonuc: butun
# IP anahtarli kovalar (throttle:api 60/dk, auth, rsvp, media, contact) TUM
# ziyaretciler icin TEK kova olur ve ip_hash kolonlari anlamsizlasir. Bu ayar
# .env'de degil bootstrap/app.php'de yapilir ($middleware->trustProxies(...)).
# Ayni sunucuda nginx + php-fpm (AWS Yol A) bundan etkilenmez.
APP_URL=https://api.davetkart.com

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=tr_TR
APP_MAINTENANCE_DRIVER=file

# --- Veritabani --------------------------------------------------------------
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=davetkart
DB_USERNAME=davetkart
DB_PASSWORD=

# --- Parola hash'i (K32) -----------------------------------------------------
# 🔴 BU DEGERLER OLCULMEDEN AYARLANMAZ. Argon2id her giriste ARGON_MEMORY
# kadar RAM ister: 65536 = 64 MB. Paylasimli hostingde PHP memory_limit
# cogu zaman 128 MB'dir ve 64 MB'lik bir hash, PHP'nin kendi kullanimiyla
# birlikte siniri zorlar.
#
# Hedef: tek bir hash ~250 ms surmeli. Olcum:
#   php -r "$s=microtime(true); password_hash('x', PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>4,'threads'=>1]); echo (microtime(true)-$s)*1000;"
# Daha hizliysa ARGON_TIME'i artir; memory_limit'e carpiyorsa ARGON_MEMORY'yi dusur.
HASH_DRIVER=argon2id
ARGON_MEMORY=65536
ARGON_TIME=4
ARGON_THREADS=1

# --- Cache / kuyruk / dosya --------------------------------------------------
# 🔴 K80: varsayilanlar HER YERDE calisir.
#
# CACHE_STORE=file      -> VPS'te `redis`. Q2 sayesinde bu gecis KOTAYI
#                          SIFIRLAMAZ: asistan kotasi veritabaninda sayiliyor.
#                          Dikkat: redis'e gecince hiz siniri sinifi
#                          ThrottleRequestsWithRedis'e doner; kovalar ayni
#                          kalir ama davranis birebir ayni degildir —
#                          throttle:assistant ve throttle:contact yeniden sinanmali.
# QUEUE_CONNECTION=database -> VPS'te `redis` + supervisor. Paylasimli hostingde
#                          daemon yoktur; cron'dan dakikada bir:
#                            php artisan queue:work --stop-when-empty --max-time=50
#                          Bedeli: OptimizeUploadedImage gecikmesi 60 sn'ye
#                          kadar cikar. 15 saniye kurali KORUNUR (istek yine
#                          aninda doner), ama "kuyruk" artik "her dakika" demek.
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=file

# 🔴 K55: 'public' = yuklenenler WEB KOKU ALTINDA, dogrudan URL ile cagrilabilir.
# S3'e gecis bunu YAPISAL olarak kapatir. media.disk kolonda saklandigi icin
# (F4) gocten sonra eski satirlar hala kendi diskinden cozulur — gec de olsa
# guvenli bir gecis.
DAVETKART_MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# VPS'te Redis acilirsa:
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# --- Log ---------------------------------------------------------------------
# 🔴 single DEGIL daily: tek dosya sinirsiz buyur ve diski doldurur.
# `daily` gunluk dosya acar, LOG_DAILY_DAYS kadarini saklar.
# LOG_LEVEL=debug uretimde HEM gurultu HEM sizinti riskidir (H8: saglayici
# hatalari log'a gidiyor); 'warning' esik degeri.
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
LOG_DEPRECATIONS_CHANNEL=null

# --- CORS (9.12) -------------------------------------------------------------
# 🔴 Frontend'in GERCEK origin'i. Sondaki '/' yazilmaz.
CORS_ALLOWED_ORIGINS=https://davetkart.com

# --- Odeme -------------------------------------------------------------------
# 🔴 23 Eylul notu: saglayici buyuk olasilikla SHOPIER (Eylul 2026'da hesap
# acildi). O gun asagidaki IYZICO_* satirlari SHOPIER_* karsiliklariyla
# degisir; `payment.webhook.signature_header` Shopier'in klasik akisinda
# kullanilmaz (imza form govdesinde gelir). Ayrinti:
# claude/GOZDEN-GECIRME-RAPORU.md §4.
# 🔴 'iyzico' YAZMADAN ONCE IyzicoGateway yazilmis olmali (9.x, anahtarlar
# geldiginde). Bugun 'fake' birakmak, bu satiri 'iyzico' yapip surucusuz
# birakmaktan IYIDIR: K70 geregi bilinmeyen surucu sessizce fake'e DUSMEZ,
# odeme uclari 503 verir.
PAYMENT_PROVIDER=fake
IYZICO_API_KEY=
IYZICO_SECRET_KEY=
IYZICO_BASE_URL=https://api.iyzipay.com
IYZICO_WEBHOOK_SECRET=
PAYMENT_SUCCESS_URL=https://davetkart.com/odeme/basarili
PAYMENT_FAILURE_URL=https://davetkart.com/odeme/hata

# --- AI asistan --------------------------------------------------------------
AI_PROVIDER=gemini
GEMINI_API_KEY=
# Model adi SABITLENIR ('latest' takma adi yok). config/ai.php varsayilani
# gemini-2.5-flash ve `thinking_budget: 0` bu modele gore olculdu.
# ⚠️ Google, 2.5 modellerine erisimi "daha once kullanmis" projelerle
# sinirladigini duyurdu: YENI bir uretim anahtariyla bu modeli ilk gun dene.
# 3.x bir modele gecilirse `thinking_budget` yerine `thinkingLevel` gerekir.
GEMINI_MODEL=gemini-2.5-flash

# --- Hata izleme (Sentry, 21 Eylul 2026) ------------------------------------
# DSN bos ise SDK sessizce kapalidir. DSN bir YAZMA anahtaridir: repoya girmez.
SENTRY_LARAVEL_DSN=
SENTRY_ENVIRONMENT=production
# Performans izleme (tracing) KAPALI kalir; acilirsa once dusuk oran (0.1).
SENTRY_TRACES_SAMPLE_RATE=
# 🔴 false KALMALI (varsayilan): true yapilirsa IP, cerez ve kullanici
# bilgisi Sentry'ye gider — K14 (KVKK) ile celisir.
SENTRY_SEND_DEFAULT_PII=false
# ⚠️ Bugun 4xx is istisnalari (yanlis parola, 402, kota) de Sentry'ye
# raporlaniyor — kota ve gurultu riski. Bkz. rapor §2 ve rehber/config/sentry.md.

# --- Posta -------------------------------------------------------------------
# 🔴 K79 hala acik: bildirim kanali secilmedi. 'log' birakmak, gonderilmeyen
# bir e-postanin en azindan IZINI birakir — sessizce kaybolmasindan iyidir.
MAIL_MAILER=log
MAIL_FROM_ADDRESS="bilgi@davetkart.com"
MAIL_FROM_NAME="${APP_NAME}"

# --- Yerel ayarlar -----------------------------------------------------------
DAVETKART_DEFAULT_TIMEZONE=Europe/Istanbul
BROADCAST_CONNECTION=log
```

---

## Kurulum sırası

```bash
# 1. Yukarıdaki bloğu sunucuda .env olarak kaydet, sırları doldur
php artisan key:generate

# 2. Şema
php artisan migrate --force        # --force: production onay sormaz

# 3. 🔴 Önbellekler — SIRA ÖNEMLİ
php artisan config:cache           # bundan sonra .env HİÇ okunmaz
php artisan route:cache
# view:cache YOK — 9.1'de welcome.blade.php silindi, derlenecek Blade kalmadı

# 4. Sırlar artık bootstrap/cache/config.php içinde DÜZ METİN
chmod 640 bootstrap/cache/config.php

# 5. Depolama
php artisan storage:link           # yalnızca DAVETKART_MEDIA_DISK=public ise

# 6. Zamanlayıcı (cron, dakikada bir)
# * * * * * cd /var/www/davetkart && php artisan schedule:run >> /dev/null 2>&1

# 7. Kuyruk
# VPS + supervisor:      php artisan queue:work --tries=3 --max-time=3600
# Paylaşımlı (cron):     php artisan queue:work --stop-when-empty --max-time=50
```

🔴 **Her `php artisan config:cache` sonrası `.env` değişikliği etkisizdir.**
Bir değişkeni güncellediğinde `config:cache`'i **tekrar** çalıştır, yoksa
değişiklik sessizce uygulanmaz — ve bu, üretimde en çok zaman kaybettiren
hata sınıflarından biridir.

---

## 🆕 Faz 9 — `.env`'de olmayan üç ayar

Aşağıdaki üç şey `.env`'de **yaşamaz** ama üretimde ayarlanmadığında fotoğraf
yükleme çalışmaz. Sunucu kurulum kontrol listesinin parçasıdır.

### 1. PHP yükleme sınırları (php-fpm)

Uygulama tek fotoğraf için **15 MB**, LCV videosu için **20 MB** kabul ediyor
(`config/davetkart.php` → `media`). PHP'nin varsayılanları bunun **altındadır**:

| Ayar | PHP varsayılanı | Gereken |
|---|---|---|
| `upload_max_filesize` | 2M | **25M** |
| `post_max_size` | 8M | **30M** |

> 🔴 `upload_max_filesize` aşıldığında PHP dosyayı **istek başlarken atar**:
> Laravel'e boş bir dosya nesnesi ulaşır, yanıt 422 *"dosya yüklenemedi"*
> olur ve **sebebi hiçbir yerde görünmez**. Faz 9'da bu yüzden bir teşhis logu
> eklendi: `MediaRequest` böyle bir istek gördüğünde php.ini değerlerini loga
> yazar. Üretimde 422 `uploaded` hatası görürsen **ilk bakılacak yer burasıdır**.
>
> `post_max_size` (gövdenin tamamı) `upload_max_filesize`'dan büyük olmalı;
> aşılırsa yanıt 413 `FILE_TOO_LARGE` olur.

### 2. nginx gövde sınırı

```nginx
client_max_body_size 30m;   # nginx'in VARSAYILANI 1 MB'dir
```

Ayarlanmazsa nginx isteği PHP'ye **hiç vermez**: 413 döner ve Laravel logunda
tek satır bile olmaz. Cloudflare arkasındaysan onun da sınırı geçerlidir
(ücretsiz planda 100 MB).

### 3. Kuyruk işçisinin belleği ve eklentiler

- **PHP sürümü:** `composer.json` **`^8.5`** istiyor (20 Eylül 2026). Sunucudaki
  PHP 8.4 veya altındaysa `composer install` platform hatasıyla durur. Ubuntu'da
  `ppa:ondrej/php`, Elastic Beanstalk'ta ise platform sürümünün 8.5'i
  desteklediği **önceden** doğrulanmalı (AWS Yol C).
- **`ext-pdo_pgsql`** `composer.json`'da listelenmiyor ama zorunludur.

- **Eklentiler:** `ext-gd` ve `ext-exif` artık `composer.json`'da **zorunlu**.
  Eksikse `composer install` açık bir hatayla durur — küçültme işinin sessizce
  hiçbir şey yapmadığı bir üretim ortamı yerine bunu tercih ediyoruz.
- **Bellek:** İş, görseli çözerken `memory_limit`'i geçici olarak
  `davetkart.media.optimize.memory_limit` (512M) değerine yükseltir ve bitince
  eski değere döner. Barındırma bu yükseltmeye izin vermiyorsa (paylaşımlı
  hostingde sıkça 256 MB'da durulur) config'te **iki değeri birlikte** indir:
  `memory_limit` ve `max_dimension_px`. İkisi bağlıdır — piksel tavanı,
  belleğin ne kadarının gerekeceğini belirler.
- **Gecikmeli iş:** Optimizasyondan sonra eski dosyayı silen
  `DeleteReplacedMediaFile` **24 saat gecikmeli** kuyruğa girer. Paylaşımlı
  hostingdeki cron tabanlı işçi (`--stop-when-empty`) bunu da alır; ama kuyruk
  hiç koşmazsa eski dosyalar diskte kalır.

Ayrıntılar: [`rehber/app/Jobs/OptimizeUploadedImage.md`](rehber/app/Jobs/OptimizeUploadedImage.md),
[`rehber/app/Jobs/DeleteReplacedMediaFile.md`](rehber/app/Jobs/DeleteReplacedMediaFile.md).
