# `deploy/systemd/davetkart-queue.service`

> **Ne işe yarar:** Sunucuda `php artisan queue:work` komutunu **sürekli ayakta**
> tutar. Bu işçi çalışmazsa parola sıfırlama maili **hiç gitmez** ve yüklenen
> fotoğraflar küçültülmez, üstelik hiçbir hata da görünmez.

## 1. Kuyruk neden var?

Parola sıfırlama isteği geldiğinde mail göndermek 1-3 saniye sürebilir. Kullanıcıyı
bu süre boyunca bekletmemek için Laravel mail işini **kuyruğa yazar** (`jobs`
tablosu, `QUEUE_CONNECTION=database`) ve kullanıcıya hemen cevap döner. Kuyruğu
boşaltan ayrı bir süreç gerekir: **işçi** (`queue:work`). Fotoğraf küçültme
(`OptimizeUploadedImage`) ve eski dosyayı 24 saat sonra silme
(`DeleteReplacedMediaFile`) işleri de aynı yoldan gider.

```
Kullanıcı --> API --> jobs tablosuna iş yaz --> hemen cevap
                              |
                    queue:work (işçi) --> işi al --> mail gönder / fotoğraf küçült
```

İşçi bir **daemon**'dur (sürekli çalışan süreç). Çalışmayı bırakırsa işler tabloda
birikir, kimse görmez. Onu ayakta tutmak, çökerse yeniden başlatmak ve sunucu
açılınca otomatik başlatmak **systemd**'nin işi.

## 2. systemd'ye giriş

systemd, Ubuntu'daki **servis yöneticisi**dir. Bir `.service` dosyası "şu komutu şu
kullanıcıyla çalıştır, ölürse yeniden başlat" der. Üç bölüm vardır:

| Bölüm | Anlamı |
|---|---|
| `[Unit]` | Servisin adı ve **ne zaman** başlayacağı (hangi servislerden sonra) |
| `[Service]` | **Nasıl** çalışacağı: komut, kullanıcı, yeniden başlatma politikası |
| `[Install]` | `enable` edilince hangi hedefe bağlanacağı (açılışta başlama) |

## 3. Satır satır

### `[Unit]`
- **`After=network.target postgresql.service`:** İşçi veritabanından iş okuyor.
  PostgreSQL daha kalkmadan başlarsa ilk denemeler hata verir.
- **`Wants=postgresql.service`:** "Mümkünse onu da başlat." `Requires` demedik,
  çünkü PostgreSQL yeniden başlarsa işçinin zorla durdurulmasını istemiyoruz.

### `[Service]`
- **`User=www-data` / `Group=www-data`:** 🔴 PHP-FPM ile **aynı kullanıcı**.
  İşçi `deploy` kullanıcısıyla çalışsa, `storage/logs/laravel.log` dosyasını o
  oluştururdu. Ertesi gün PHP-FPM (`www-data`) o dosyaya yazamaz ve API **500**
  döner. Laravel'de en sık görülen dağıtım hatalarından biri. Aynı sebeple
  dosya önbelleği (`CACHE_STORE=file`) klasörleri de tek sahibe ait olmalı.
- **`WorkingDirectory`:** `php artisan` komutu proje klasöründe çalışmalı.
- **`ExecStart=... queue:work --tries=3 --timeout=60 --max-time=3600`**
  - `--tries=3`: Başarısız işi 3 kez dener, sonra `failed_jobs`'a yazar. Sonsuz
    döngüye girmez.
  - `--timeout=60`: Bir iş 60 saniyeyi aşarsa işçi o işi öldürür. Bu değer
    `config/queue.php`'deki `retry_after` (**90**) değerinden **küçük** olmak
    zorunda. Tersi olursa iş hâlâ sürerken kuyruk onu "takıldı" sanıp ikinci
    işçiye verir ve iş iki kez çalışır (aynı maili iki kez gönderirsin).
  - `--max-time=3600`: İşçi 1 saat çalışınca **kendini kapatır**. PHP uzun süre
    çalışan süreçlerde bellek biriktirir. Periyodik yeniden doğuş, sızıntıyı
    sıfırlar.
- **`Restart=always`:** `on-failure` demedik. `--max-time` bittiğinde ve deploy
  sırasındaki `queue:restart`'ta işçi **hatasız (çıkış kodu 0)** kapanır. `on-failure`
  bunu "başarılı bitti" sayıp yeniden başlatmazdı ve kuyruk sessizce dururdu.
- **`RestartSec=5`:** Ölünce 5 saniye bekleyip başlar. Hata anında saniyede
  yüzlerce yeniden başlama döngüsünü önler.
- **`MemoryMax=768M`:** Sunucuda 2 GB RAM var, bunu PHP-FPM, PostgreSQL ve Argon2id
  paylaşıyor. Görsel küçültme geçici olarak 512 MB'a çıkar, normal işçi ~100 MB.
  768 MB tavanı, işçi sızarsa **işçiyi** öldürür, Linux'un OOM-killer'ının
  PostgreSQL'i öldürmesini engeller. İşçi `Restart=always` ile geri gelir.
- **`MemorySwapMax=0`** (26 Ekim, Y9.1'de eklendi): Y5.2'de sunucuya 2 GB swap
  ekledik. Swap varken `MemoryMax` **tek başına katı bir tavan değil**: sınırı
  aşan süreç önce öldürülmez, belleği diske (swap'e) itilir. Sızan bir işçi o
  zaman 768 MB'ı aşıp swap'i doldurur ve **bütün sunucu** (PostgreSQL, PHP-FPM)
  disk hızında sürünür. `MemorySwapMax=0` bu işçinin swap kullanmasını yasaklar:
  768 MB dolunca işçi gerçekten durdurulur ve 5 saniye sonra temiz bir bellekle
  geri gelir. Swap, sunucunun geri kalanı için emniyet supabı olarak kalır.
  (İlk sürümde bu satır yoktu; rehberdeki "tavan işçiyi öldürür" cümlesi swap
  eklendikten sonra eksik kalmıştı.)
- **`TimeoutStopSec=90`:** `stop`/`restart` komutunda işçi **o anki işi bitirip**
  kapanır. En uzun iş 60 saniye olduğundan 90 saniye yeter.
- **`NoNewPrivileges=true` / `PrivateTmp=true`:** İki ucuz güvenlik önlemi. Süreç
  yeni yetki kazanamaz, `/tmp` yalnızca ona özel olur. Daha sıkı yalıtım (ör.
  `ProtectSystem=strict`) sunucuda denemeden eklemedik.
- **`SyslogIdentifier`:** Loglar `journalctl -u davetkart-queue` ile bulunur.

### `[Install]`
- **`WantedBy=multi-user.target`:** Sunucu normal açılış moduna geçince servis başlar.

## 4. Kurulum (Y9.1)

```bash
sudo systemd-analyze verify deploy/systemd/davetkart-queue.service   # önce sözdizimi
sudo install -m 644 -o root -g root deploy/systemd/davetkart-queue.service /etc/systemd/system/
sudo systemctl daemon-reload                 # systemd'ye "dosyalar değişti" de
sudo systemctl enable --now davetkart-queue  # açılışta başlat + şimdi başlat
sudo systemctl status davetkart-queue        # "active (running)" görmelisin
```

Günlük komutlar:

```bash
journalctl -u davetkart-queue -f             # canlı log
sudo systemctl restart davetkart-queue       # elle yeniden başlat
php artisan queue:restart                    # deploy'da: işçiye "o anki işi bitir, yeniden doğ"
```

## 5. Deploy ile ilişkisi (Y0.5'te göreceğiz)

İşçi **kodu bellekte tutar**. Deploy edip `queue:restart` demezsen, işçi 1 saat
boyunca (ya da `--max-time` bitene kadar) **eski kodla** çalışmaya devam eder.
`.env` ya da `config:cache` değişikliği de aynı şekilde işçiye ancak yeniden
doğunca ulaşır. `queue:restart` komutu cache üzerinden sinyal bırakır, o yüzden
`deploy.sh` içinde `php artisan` komutlarını `www-data` olarak çalıştıracağız
(`sudo -u www-data php artisan ...`), böylece cache dosyalarının sahibi karışmaz.

## 6. Sık hatalar

| Belirti | Sebep |
|---|---|
| Parola sıfırlama maili gelmiyor, hata yok | İşçi çalışmıyor: `systemctl status davetkart-queue` |
| `status` → `failed`, `203/EXEC` | `/usr/bin/php` yok ya da PHP 8.5 varsayılan değil (`php -v`) |
| `status` → `failed`, `200/CHDIR` | `WorkingDirectory` yolu yanlış |
| API 500, `laravel.log` izin hatası | İşçi `www-data` dışında bir kullanıcıyla çalışmış |
| Aynı mail iki kez gidiyor | `--timeout` değeri `retry_after`'dan büyük |
| Deploy sonrası yeni kod çalışmıyor | `queue:restart` unutuldu |
| İşçi sürekli yeniden başlıyor | `journalctl -u davetkart-queue` içinde asıl hata görünür |
| Başarısız işler | `php artisan queue:failed` ile listele, `queue:retry all` ile yeniden dene |

## 7. Sözlük

- **Daemon:** Arka planda sürekli çalışan süreç.
- **Kuyruk (queue):** Yapılacak işlerin sıralı listesi, bizde `jobs` tablosu.
- **İşçi (worker):** Kuyruktan iş alıp çalıştıran süreç.
- **OOM-killer:** Bellek bitince Linux'un rastgele bir süreci öldürmesi.
- **Graceful stop:** Süreci aniden öldürmek yerine o anki işi bitirmesini beklemek.
