# `deploy/cron/davetkart-backup`

> **Ne işe yarar:** Yedek betiğini her gece **02:00 UTC**'de `root` olarak çalıştırır. Sunucuda
> `/etc/cron.d/davetkart-backup` olarak durur. Faz 18.2 · `OPS-02` · K151.
> Betiğin kendisi: [`../backup/pg-dump.md`](../backup/pg-dump.md).

## 1. Neden ayrı bir dosya, neden Laravel zamanlayıcısı değil?

Projede zamanlanmış işler koddadır (`routes/console.php`) ve tek bir cron satırından tetiklenir
([`davetkart.md`](davetkart.md)). Yedek bu kuralın **bilinçli istisnasıdır**:

| | Laravel zamanlayıcısı | Bu dosya |
|---|---|---|
| Çalıştıran | `www-data` | `root` |
| Laravel bozuksa | İş **çalışmaz** | Çalışır |
| Erişebildiği sır | `.env` (uygulamanın anahtarları) | Yalnızca `/etc/davetkart/backup.env` |

Yedeğe en çok ihtiyaç duyulan an uygulamanın bozulduğu andır (hatalı göç, bozuk `.env`, kırık
deploy). Yedek, korumaya çalıştığı sisteme **bağımlı olmamalı**. Ayrıca yedek anahtarını
`www-data`'nın okuyabildiği `.env`'e koymak, web uygulamasını ele geçirene yedekleri de vermek olurdu.

Ayrı dosya olması da bir yalıtım: `davetkart` dosyasını düzenlerken yapılan bir hata yedeği durdurmaz.

## 2. Satır satır

```
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin
MAILTO=""
```

- `PATH` mevcut cron dosyasındakinden **uzun**: AWS CLI resmî kurucuyla `/usr/local/bin/aws`,
  snap ile `/snap/bin/aws` olur. Cron oturum ortamını yüklemez; `PATH`'te olmayan araç "yok"tur.
- `MAILTO=""`: sunucuda posta sistemi yok; çıktıyı aşağıda biz yönlendiriyoruz.

```
0 2 * * * root /usr/local/sbin/davetkart-pg-dump 2>&1 | /usr/bin/logger -t davetkart-backup
```

- **`0 2 * * *`**: her gün 02:00. `/etc/cron.d` **sistem saatini** kullanır; sunucu UTC (Y5.4).
  Ubuntu'nun cron'unda iş başına saat dilimi yoktur (`man 5 crontab`, "LIMITATIONS"); sunucunun
  saat dilimi değişirse bu satırın anlamı da değişir. Kontrol: `timedatectl | grep "Time zone"`.
- **Neden 02:00?** Lightsail disk görüntüsü 01:00 UTC (Y4.4), `media:prune-orphans` 03:15,
  `data:purge` 03:45. Türkiye saatiyle 05:00: trafiğin en düşük olduğu saat.
- **`root`**: `/etc/cron.d` dosyalarında altıncı alan kullanıcıdır. Root gerekli çünkü AWS anahtarı
  yalnızca root'un okuyabildiği dosyada; veritabanı adımları betiğin içinde `postgres` kullanıcısına
  düşürülür (`runuser`).
- **`/usr/local/sbin/davetkart-pg-dump`**: depodaki dosya **değil**, root'a ait kopyası. Root,
  `deploy` kullanıcısının yazabildiği bir dosyayı çalıştırırsa `deploy` fiilen root olur
  ([`../backup/pg-dump.md`](../backup/pg-dump.md) §3.4).
- **`2>&1 | logger -t davetkart-backup`**: bütün çıktı (adımlar ve hatalar) sistem günlüğüne:
  `journalctl -t davetkart-backup`. `schedule:run` satırının aksine `stdout` atılmaz: günde bir kez
  çalışan işin her adımı kayıt değeri taşır.
- **Çakışma:** kilit (`flock`) betiğin **içinde**. Böylece elle çalıştırdığın koşu ile cron'un
  koşusu da birbirini görür.

Boru (`|`) yüzünden satırın çıkış kodu `logger`'ınkidir; cron zaten çıkış koduna bakmaz. Başarı ve
hata bilgisi günlükteki `TAMAM` / `HATA` satırlarından ve kalp atışından okunur.

## 3. Kurulum (sunucuda, `ubuntu` ile)

`deploy.sh` bunu yapamaz: `deploy` kullanıcısının root yetkisi yok (ve olmamalı). Mevcut cron
dosyasıyla aynı yol:

```bash
# 'ubuntu' /var/www/davetkart'a giremez (2750); sudo ile TAM yol verilir
sudo install -m 644 -o root -g root /var/www/davetkart/backend/deploy/cron/davetkart-backup /etc/cron.d/davetkart-backup
ls -l /etc/cron.d/davetkart-backup   # -rw-r--r-- root root
```

Cron `/etc/cron.d/` klasörünü kendisi izler; yeniden başlatma gerekmez. Sıra önemli: **önce** betik
ve ayar dosyası kurulur ve elle bir kez çalıştırılır, **sonra** bu dosya. Tam sıra: docs deposu
`operations/deployment/YEDEK-VE-GERI-YUKLEME.md` §4.

## 4. Doğrulama

```bash
sudo /usr/local/sbin/davetkart-pg-dump                     # elle bir koşu: son satır TAMAM
journalctl -t davetkart-backup --since today               # cron koşusunun günlüğü (ertesi sabah)
journalctl -u cron --since today | grep davetkart-pg-dump  # cron satırı tetiklendi mi?
```

Cron'un 02:00'yi beklemeden çalıştığını görmek için dosyayı kurmadan önce saati geçici olarak
birkaç dakika sonrasına yazıp deneyebilirsin; denemeden sonra depodaki hâliyle yeniden kur.

## 5. Sık hatalar

| Belirti | Sebep |
|---|---|
| Hiç iz yok | Dosya adında nokta var, sahibi root değil ya da izin `644` değil: `ls -l /etc/cron.d/` |
| `davetkart-pg-dump: not found` | Betik `/usr/local/sbin`'e kurulmamış |
| `gerekli arac bulunamadi: aws` | `aws` bu dosyadaki `PATH` dizinlerinden birinde değil: `command -v aws` |
| Yedek 05:00'te değil 02:00'de (yerel saatle) alınıyor | Sunucu saat dilimi UTC değil: `timedatectl` |
| Satır sonu hatası (`\r`) | Dosya Windows satır sonuyla kopyalanmış. Depoda `.gitattributes` LF'ye zorlar; `scp` yerine `git pull` + `install` kullan |
| Günlük boş ama S3'te dosya var | `logger` yolu yanlış; `command -v logger` |

## 6. Sözlük

- **`/etc/cron.d/`:** Sistem geneli cron dosyaları; her satırda kullanıcı alanı vardır.
- **`logger`:** Kendisine verilen satırları sistem günlüğüne (journal) yazan komut.
- **`runuser`:** Root'un bir komutu başka bir kullanıcı olarak, parola sormadan çalıştırması.
