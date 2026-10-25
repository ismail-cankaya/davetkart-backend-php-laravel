# `deploy/cron/davetkart`

> **Ne işe yarar:** Laravel'in zamanlayıcısını (`schedule:run`) **dakikada bir**
> çalıştırır. Zamanlanmış işlerin hepsi bu tek satırdan tetiklenir. Sunucuda
> `/etc/cron.d/davetkart` olarak durur.

## 1. Neden tek bir satır?

Laravel'de zamanlanmış işler **kodda** tanımlanır (`routes/console.php`), cron
dosyasında değil:

| İş | Ne zaman (UTC) | Türkiye saati | Ne yapar |
|---|---|---|---|
| `orders:expire` | her saat | — | Ödenmemiş eski siparişleri kapatır |
| `media:prune-orphans` | 03:15 | 06:15 | Hiçbir davetiyeye bağlı olmayan yüklemeleri siler |
| `sanctum:prune-expired --hours=24` | 00:00 | 03:00 | Süresi dolmuş oturum anahtarlarını siler |
| `data:purge` | 03:45 | 06:45 | KVKK saklama süresi dolan verileri siler (K98) |

Cron yalnızca "dakikada bir Laravel'e sor" der. Laravel o dakikada hangi işin
zamanı geldiğine kendisi karar verir. Yeni bir iş eklemek **kod değişikliğidir**:
depoya girer, gözden geçirilir, test edilir; sunucuda cron'a dokunulmaz.

Uygulamanın saat dilimi `UTC` (`artisan about`). Saatler bu yüzden UTC yazılır.
Lightsail'in günlük snapshot'ı 01:00 UTC'de alınıyor (Y4.4); işler onunla çakışmaz.

## 2. Satır satır

```
SHELL=/bin/sh
PATH=/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
```
- Cron, kullanıcının oturum ortamını **yüklemez**. `PATH` boş ya da kısıtlı
  olabilir; o yüzden açıkça yazılır ve komutlar zaten tam yolla çağrılır.
- `MAILTO=""`: Cron çıktıyı varsayılan olarak yerel posta kutusuna yollamaya
  çalışır. Sunucuda posta sistemi yok; çıktıyı biz yönlendiriyoruz.

```
* * * * * www-data cd /var/www/davetkart/backend && /usr/bin/php artisan schedule:run 2>&1 >/dev/null | /usr/bin/logger -t davetkart-schedule
```
- `* * * * *`: dakika, saat, gün, ay, haftanın günü. Hepsi `*` = her dakika.
- **`www-data`**: `/etc/cron.d/` dosyalarında kullanıcı adı **altıncı alan**dır
  (kullanıcı crontab'ında bu alan yoktur). İşler PHP-FPM ve kuyruk işçisiyle
  aynı kullanıcıyla çalışır; `withoutOverlapping()` kilitleri ve log dosyaları
  tek sahibe ait olur.
- **`2>&1 >/dev/null`**: Sıra önemli. Önce "hata çıkışını (2) şu anki standart
  çıkışa, yani boruya bağla", sonra "standart çıkışı (1) çöpe at". Sonuç:
  - Her dakika yazılan "No scheduled commands are ready to run" **atılır**.
  - PHP'nin ölümcül hatası (ör. `.env` okunamadı) borudan **`logger`'a** gider.
- **`logger -t davetkart-schedule`**: Satırı sistem günlüğüne (journal) yazar:
  `journalctl -t davetkart-schedule`. Laravel standardı olan `>> /dev/null 2>&1`
  her şeyi atar; zamanlayıcı Laravel'e ulaşamadan ölürse bunu hiç öğrenemezdin.
- İşlerin **kendi** hataları Laravel log'una ve Sentry'ye gider; Sentry Crons,
  işler zamanında koşmazsa ayrıca uyarır (K103, DSN Y11'de girilince).

## 3. Neden systemd timer değil?

Kuyruk işçisi için systemd kullandık (sürekli çalışan bir süreç). Dakikada bir
kısa bir komut için systemd timer da olurdu, ama her çalıştırma journal'a
"Started/Finished" yazar: günde ~2900 satır gürültü. Cron burada daha sade ve
Laravel belgelerinin standart yolu.

## 4. Kurulum (sunucuda, `ubuntu` ile)

```bash
sudo install -m 644 -o root -g root deploy/cron/davetkart /etc/cron.d/davetkart
sudo -u www-data /usr/bin/php artisan schedule:list   # işler doğru okunuyor mu?
```
Cron, `/etc/cron.d/` klasörünü kendisi izler; yeniden başlatma gerekmez.
🔴 Dosya adında **nokta olmamalı** (`davetkart.cron` sessizce yok sayılır) ve
dosya root'a ait, başkası yazamaz olmalı; yoksa cron güvenlik gereği okumaz.

## 5. Sık hatalar

| Belirti | Sebep |
|---|---|
| İşler hiç çalışmıyor, iz yok | Dosya adında nokta var ya da izin/sahip yanlış: `ls -l /etc/cron.d/` |
| `journalctl -t davetkart-schedule` dolu | PHP Laravel'e ulaşamadan ölüyor: satırdaki hatayı oku |
| `withoutOverlapping` hiç bırakmıyor | Bir iş yarıda öldü, kilit kaldı: `artisan schedule:clear-cache` |
| İş "geç" çalışıyor | Saatler UTC; Türkiye saatiyle karıştırılmış |

## 6. Sözlük

- **cron:** Zamanlanmış komutları çalıştıran sistem servisi.
- **`/etc/cron.d/`:** Paketlerin ve yöneticinin sistem geneli cron dosyaları; her satırda kullanıcı alanı var.
- **stdout / stderr (1 / 2):** Bir programın normal çıktısı ve hata çıktısı.
