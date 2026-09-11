# Üretim `.env` Şablonu

> **Faz:** 9 — Üretim hazırlığı, dosya 9.13
> **Kılavuz:** [`rehber/env.md`](rehber/env.md) — her satırın gerekçesi orada
> **Kurallar:** **Y1** · **E6** · **B10** · **K80**

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
