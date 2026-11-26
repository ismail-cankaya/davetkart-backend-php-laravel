# `deploy/php-fpm/davetkart.conf` — PHP-FPM havuzu

> Faz 21.5 · `OPS-07` · K269. Sunucu: AWS Lightsail, 2 GB bellek, tek makine (PHP-FPM + kuyruk + PostgreSQL + Nginx).

## 1. Neden depoda bir havuz dosyası?

PHP-FPM, Nginx'ten gelen PHP isteklerini bir **süreç havuzuyla** karşılar. Ubuntu'nun hazır havuzu (`www.conf`)
`pm.max_children = 5` ile gelir ve depoda değildi: sunucudaki değer bilinmiyordu, denetimde görülmedi (`OPS-07`).
5 × 256 MB (`memory_limit`) + kuyruk 768 MB = 2 048 MB: tavanlar birlikte dolarsa makine biter ve çekirdek bir
süreci öldürür, çoğu zaman en büyüğünü, yani PostgreSQL'i.

## 2. Ayarlar

| Ayar | Değer | Neden |
|---|---|---|
| `listen` | `/run/php/php8.5-fpm.sock` | `deploy/nginx/davetkart.conf` `fastcgi_pass` ile aynı; Nginx'e dokunmak gerekmez |
| `pm` | `dynamic` | Boşta 1–3 süreç, yükte 4'e kadar; `static` boşta da 4 süreç tutardı |
| `pm.max_children` | **4** (§A-1) | Bellek tablosu (§3) |
| `pm.start_servers` / `min_spare` / `max_spare` | 2 / 1 / 3 | İlk istek soğuk süreç beklemesin, boşta bellek dursun |
| `pm.max_requests` | 500 | Uzun ömürlü süreçte küçük sızıntılar birikmesin; süreç 500 istekte bir yenilenir |
| `request_terminate_timeout` | 60s | Nginx'in en uzun `fastcgi_read_timeout`'u 60s (`/index.php`). Nginx 504 verdikten sonra PHP boşuna çalışmasın |
| `request_slowlog_timeout` / `slowlog` | 5s / `/var/log/php8.5-fpm-davetkart.slow.log` | 5 sn'yi aşan isteğin yığını yazılır (`OPS-09` ölçümü) |
| `pm.status_path` / `ping.path` | `/fpm-status` / `/fpm-ping` | Kuyruk (`listen queue`), aktif süreç sayısı. Dışarıdan erişilemez: Nginx yalnızca `index.php`'yi geçirir |

`memory_limit` (256M) havuzda değil, `deploy/php/davetkart.ini`'de: CLI ve FPM aynı değeri görür.

## 3. Bellek tablosu (2 048 MB)

| Bileşen | Tavan | Tipik | Kaynak |
|---|---|---|---|
| PHP-FPM | 4 × 256 = **1 024 MB** | 4 × 40–60 MB | `pm.max_children`, `memory_limit` |
| OPcache (paylaşılan, bir kez) | 128 MB | 30–50 MB | `opcache.memory_consumption` |
| Kuyruk işçisi | **768 MB** (systemd `MemoryMax`, swap'sız) | 60 MB; görsel işlerken 300–512 MB | `deploy/systemd/davetkart-queue.service` |
| PostgreSQL | ~200 MB | ~150 MB | Öneri: `shared_buffers = 128MB` (varsayılan; bu makinede artırma), `max_connections = 20` |
| İşletim sistemi + Nginx + cron | ~150 MB | ~150 MB | — |
| **Toplam** | **~2 270 MB** | **~500–1 000 MB** | |

"Tipik" sütunu ölçüm değil, Laravel isteğinin olağan bellek kullanımından tahmin; sunucuda ölçülmedi.

🔴 **Tavanlar toplamı 2 048'i ~220 MB aşıyor.** Bu, dört FPM sürecinin **aynı anda** 256 MB'a çıktığı ve kuyruğun 768'e
dayandığı an demek; görsel işleme FPM'de değil kuyrukta. `max_children = 5` ile aşım ~470 MB olurdu. Kalan pay için iki
yol var, İsmail'in kararı:

1. **Takas dosyası (1 GB):** son çare tamponu; OOM yerine yavaşlama. Kuyruğun `MemorySwapMax=0`'ı kalır (o kendi
   tavanında ölür, PostgreSQL'i değil).
2. **Havuza `php_admin_value[memory_limit] = 192M`:** FPM tavanı 768'e iner, toplam ~2 014 MB. Bedeli: 192 MB'ı aşan
   nadir bir web isteği 500 verir (bugün böyle bir istek bilinmiyor; ölçülmedi).

## 4. Kurulum (sunucu adımı, koşulmadı)

```bash
cd /var/www/davetkart/backend
sudo cp deploy/php-fpm/davetkart.conf /etc/php/8.5/fpm/pool.d/davetkart.conf
# Aynı soketi dinleyen dağıtım havuzu kapanır (silinmez):
sudo mv /etc/php/8.5/fpm/pool.d/www.conf /etc/php/8.5/fpm/pool.d/www.conf.dist
sudo touch /var/log/php8.5-fpm-davetkart.slow.log
sudo chown www-data:www-data /var/log/php8.5-fpm-davetkart.slow.log
sudo php-fpm8.5 -t                         # "configuration file ... test is successful"
sudo systemctl reload php8.5-fpm
curl -fsS https://davetkart.com/api/ping   # zincir ayakta mı?
```

Durum sayfası (yalnızca sunucuda):

```bash
sudo apt-get install -y libfcgi-bin        # cgi-fcgi
sudo -u www-data env SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET \
  cgi-fcgi -bind -connect /run/php/php8.5-fpm.sock | grep -E 'listen queue|active processes|max children reached'
```

`max children reached` sıfırdan büyükse havuz dolmuş, istekler kuyrukta beklemiş demektir (`OPS-09` eşiği).

## 5. Geri alma

```bash
sudo rm /etc/php/8.5/fpm/pool.d/davetkart.conf
sudo mv /etc/php/8.5/fpm/pool.d/www.conf.dist /etc/php/8.5/fpm/pool.d/www.conf
sudo php-fpm8.5 -t && sudo systemctl reload php8.5-fpm
```

## 6. OPcache ve `validate_timestamps = 0`

Ayarlar `deploy/php/davetkart.ini`'de ([`../php/davetkart.md`](../php/davetkart.md) "OPcache"). Kısaca: PHP her istekte
dosyanın değişip değişmediğine bakmaz; kod değişikliği ancak **FPM reload** ile görülür. `deploy.sh` bunu bakım kipi
içinde yapar (K264): `git pull` → `config:cache`/`route:cache` → `migrate` → `reload` → `up`. Arada gelen istekler
yalnızca `index.php` + `maintenance.php` çalıştırır; karışık sürüm yok. Derlenmiş Blade görünümleri reload'dan sonraki
ilk istekte yeniden derlenir ve OPcache onları boş önbelleğe ilk kez alır.

## 7. Yerel doğrulama

- `php-fpm8.5 -t`: **koşmadı**. Windows'ta PHP-FPM yok; Herd yalnızca `php-cgi.exe` taşıyor.
- `davetkart.ini`: Herd PHP 8.5 ile `PHP_INI_SCAN_DIR` üzerinden yüklendi; `opcache.memory_consumption='128'`,
  `opcache.max_accelerated_files='20000'`, `opcache.validate_timestamps='0'` okundu, OPcache yüklü (Faz 21.5).

## 8. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| `www.conf`'u kapatmadan ikinci havuz eklemek | İki havuz aynı soketi ister; FPM açılmaz |
| `request_terminate_timeout`'u boş bırakmak | Nginx 504 verdikten sonra PHP uzun süre çalışabilir |
| `pm.max_children`'ı yükseltip bellek tablosuna bakmamak | Yükte OOM; çekirdek PostgreSQL'i öldürür |
| `validate_timestamps=0` iken dosyayı elle düzeltip reload etmemek | Sunucu eski kodu çalıştırmaya devam eder |
