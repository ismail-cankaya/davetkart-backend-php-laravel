# `deploy/php/davetkart.ini`

> **Ne işe yarar:** PHP'nin üretimdeki davranışını ayarlar: dosya yükleme sınırları,
> bellek tavanı ve hata gösterimi. Hem web (PHP-FPM) hem komut satırı (`artisan`,
> kuyruk işçisi) bu ayarları okur.

## 1. Neden `php.ini`'yi düzenlemiyoruz?

PHP'nin ana ayar dosyası `/etc/php/8.5/fpm/php.ini`. Onu elle düzenlemek iki sorun çıkarır:

- **Paket güncellemesi** o dosyayı değiştirmek isterse `apt` "senin değişikliğin var, hangisini tutayım?" diye sorar ve otomatik güncellemeyi (`unattended-upgrades`) **durdurabilir**.
- Elle düzenlenen ayar **depoda görünmez**: sunucu bozulup yeniden kurulduğunda hatırlanmaz.

Bunun yerine **ek ayar dosyası** kullanıyoruz: PHP, `conf.d/` klasöründeki dosyaları ana ayardan **sonra** okur ve sonradan okunan değer kazanır. Dosya adındaki `99` önceliği ("en son oku"), ilk satırdaki `; priority=99` yorumu `phpenmod` aracının bunu bilmesi içindir.

## 2. Ayarlar

### Yükleme zinciri
```
Tarayıcı -> Nginx (30m) -> PHP post_max_size (30M) -> upload_max_filesize (25M) -> Uygulama (15 MB foto, 20 MB video)
```
Her halka kendinden içtekinden **büyük** olmalı. Yoksa uygulamanın kendi anlamlı hatası
(422 `FILE_TOO_LARGE`) yerine dış katman, sessizce reddeder:

| Aşılan sınır | Belirti |
|---|---|
| Nginx `client_max_body_size` | 413, Laravel logunda iz yok |
| `post_max_size` | 413 `FILE_TOO_LARGE` |
| `upload_max_filesize` | Dosya boş gelir, 422 "dosya yüklenemedi" (sebep görünmez) |

### `memory_limit = 256M`
Bir isteğin kullanabileceği en çok bellek. Aşan istek ölür. 2 GB'lık sunucuda her
istek çok bellek isterse bütün sunucu boğulur; 256M normal bir Laravel isteği için
bol. Görsel küçültme işi (`OptimizeUploadedImage`) bunu **geçici olarak** 512M'a
çıkarır ve bitince geri döner (`config/davetkart.php`), yani normalde tavanı düşük tutarız.

### `display_errors = Off`, `log_errors = On`
Hata mesajları kullanıcıya **gösterilmez** (dosya yolları, SQL ifadeleri sızabilir),
log dosyasına yazılır.

### `expose_php = Off`
Her yanıtta `X-Powered-By: PHP/8.5.12` başlığı olmaz. Saldırgana "hangi sürüm?" diye
bedava bilgi vermeyiz.

### `session.upload_progress.enabled = Off`
PHP'nin **yerel** oturum tabanlı yükleme ilerleme özelliği. Laravel kendi oturum
mekanizmasını kullanır ve bunu hiç kullanmaz. **CVE-2026-109619** gibi PHP açıklarının
saldırı yollarından biri bu özellik ile ilişkili olduğu için, ihtiyaç olmayan yüzeyi
kapatıyoruz (savunma derinliği). Asıl koruma PHP'yi **8.5.12 ve üstünde** tutmak.

## 3. Kurulum

Depodaki dosya (Y8'de sunucuya kod gelince):

```bash
sudo cp deploy/php/davetkart.ini /etc/php/8.5/mods-available/davetkart.ini
sudo phpenmod -v 8.5 davetkart          # fpm ve cli conf.d'ye 99-davetkart.ini bağlantısı koyar
sudo systemctl restart php8.5-fpm       # ayarları web tarafı okusun
```

`phpenmod` kopyalamaz, **bağlantı (symlink)** oluşturur. Dosyayı sonradan düzenlersen
`phpenmod`'u tekrar çalıştırmana gerek yok, yalnızca `php8.5-fpm`'i yeniden başlat.

## 4. Doğrulama

```bash
php --ini | grep davetkart                     # CLI bu dosyayı okuyor mu?
php -i | grep -E '^(upload_max_filesize|post_max_size|memory_limit|session.upload_progress.enabled|expose_php)'
sudo php-fpm8.5 -i | grep -E '^(upload_max_filesize|post_max_size|memory_limit|session.upload_progress.enabled|expose_php)'
```

İkinci ve üçüncü komutlar aynı değerleri göstermeli (`25M`, `30M`, `256M`, `Off`...).
`php -i` yalnızca **komut satırı** PHP'yi gösterir, web tarafını görmek için
`php-fpm8.5 -i` gerekir.

## 5. Sık hatalar

| Belirti | Sebep |
|---|---|
| Ayar değişmedi | `php8.5-fpm` yeniden başlatılmadı |
| CLI doğru, web yanlış | Yalnızca `php -i` kontrol edilmiş, `php-fpm8.5 -i` değil |
| Ayar hiç yüklenmiyor | `; priority=99` satırı ya da `phpenmod` atlanmış (`ls /etc/php/8.5/fpm/conf.d/`) |
| 422 "dosya yüklenemedi" | `upload_max_filesize` düşük (bu dosya uygulanmamış) |

## 6. Sözlük

- **`conf.d`:** PHP'nin ana ayardan sonra okuduğu ek ayar klasörü.
- **SAPI:** PHP'nin çalışma biçimi (web için FPM, komut satırı için CLI). Ayarlar her biri için ayrı okunur.
- **Symlink:** Başka bir dosyaya işaret eden kısayol.
