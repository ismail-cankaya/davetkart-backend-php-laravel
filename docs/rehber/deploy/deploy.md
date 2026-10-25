# `deploy/deploy.sh`

> **Ne işe yarar:** Sunucuda yeni kodu canlıya alan **tek komut**. YD-1 kararı:
> önce elle çalıştırılan, anlaşılır bir betik; GitHub Actions gibi otomasyon
> **sonra**. Otomatikleştirilen şey önce anlaşılmış olmalı.

```bash
# sunucuda, 'deploy' kullanıcısıyla
cd /var/www/davetkart/backend
bash deploy/deploy.sh
```

`bash deploy/deploy.sh` yazıyoruz, `./deploy/deploy.sh` değil: Windows'ta
geliştirdiğimiz için Git dosyaya "çalıştırılabilir" işaretini koymaz, `bash ile`
çağırınca buna gerek kalmaz.

## 1. Bash betiğine giriş

| Satır | Anlamı |
|---|---|
| `#!/usr/bin/env bash` | Bu dosyayı Bash çalıştırsın |
| `set -e` | Herhangi bir komut hata verirse betik **durur** (yarım deploy olmasın) |
| `set -u` | Tanımsız değişken kullanılırsa hata ver (yazım hatası sessiz geçmesin) |
| `set -o pipefail` | `a \| b` zincirinde `a` bozulduysa zincir de bozuk sayılsın |
| `"$(...)"` | Komutun çıktısını değer olarak kullan |
| `trap '...' ERR` | Bir hata olursa bu mesajı da yaz |

`set -euo pipefail` tek satırda "ilk hatada dur" güvencesidir. Deploy betiğinde bu
şarttır: `migrate` hata verip betik devam etse, yeni kod eski veritabanıyla
canlıya çıkardı.

## 2. Sıra ve gerekçesi

| # | Adım | Neden bu sırada |
|---|---|---|
| 0 | Güvenlik kontrolleri | root değil, `.env` var, çalışma ağacı temiz |
| 1 | `git pull --ff-only` | Yeni kod |
| 2 | `composer install --no-dev -o --no-scripts` + `package:discover` | Bağımlılıklar, üretimde test araçları yok |
| 3 | `config:cache` → `route:cache` → `chmod 640` | Önbellekler, **migrate'ten önce** |
| 4 | `migrate --force` | Veritabanı şeması |
| 5 | `systemctl reload php8.5-fpm` | OPcache'i temizle, yeni kod yüklensin |
| 6 | `queue:restart` | Kuyruk işçisi yeni kodu alsın |
| 7 | `curl /api/ping` | Uygulama gerçekten ayakta mı? |

### Adım 0: Güvenlik kontrolleri
- **root ile çalışmaz:** Root ile oluşturulan dosyalar `deploy` ve `www-data` için
  erişilemez olur, sonraki deploy'lar izin hatasıyla patlar.
- **Çalışma ağacı temiz olmalı:** Biri sunucuda dosyayı elle düzenlediyse
  `git pull` ya çakışır ya da değişikliği sessizce ezer. Betik, düzenleme varsa
  neyin değiştiğini gösterip durur. Sunucuda kod **düzenlenmez**, yalnızca depodan gelir.
- **sudo yetkisi var mı?** `deploy`'un parolası yok; betik parola sormaz. Gereken
  üç komut `deploy/sudoers/davetkart-deploy` ile parolasız izinlidir. Betik başta
  `sudo -n -u www-data /usr/bin/php -v` ile bunu sınar, kural yoksa anlaşılır bir
  mesajla durur. Tüm `sudo` çağrıları `-n` ile: eksik yetki parola isteminde
  asılı kalmaz, hemen hata verir. (İlk sürümdeki `sudo -v` 26 Ekim'de kaldırıldı:
  parolasız kullanıcıda hiç çalışmazdı.)

### Adım 1: `git pull --ff-only`
`--ff-only` "yalnızca ileri sar" demek: sunucudaki dal ile depo arasında ayrışma
varsa **birleştirme (merge) commit'i üretmez, hata verir**. Sunucuda hiç yerel
commit olmaması gerektiği için, bu durumda bir şey ters gitmiş demektir.

### Adım 2: Composer
- `--no-dev`: PHPUnit, Pint gibi geliştirme paketleri üretime gitmez (daha az
  yüzey, daha az disk).
- `--optimize-autoloader`: Sınıf haritasını önceden çıkarır, her istekte dosya aranmaz.
- `--no-scripts` + ayrı `package:discover`: Composer normalde `artisan` komutlarını
  kendi kullanıcısıyla (`deploy`) çalıştırır; `bootstrap/cache` içine `deploy`
  sahibi dosyalar yazar. Bu komutu `www-data` ile biz çalıştırınca dosya
  sahipleri tutarlı kalır.

### Adım 3: Önbellekler, neden `migrate`'ten önce?
`config:cache` `.env` dosyasını bir kez okuyup tek dosyaya yazar. `migrate` önbellek
yenilenmeden çalışırsa **eski** veritabanı ayarlarını görebilir. Önce önbellek,
sonra göç. (Plandaki ilk sıra migrate'ti; bu sapma küçük ama gerekçeli.)

- `chmod 640 bootstrap/cache/config.php`: Bu dosya `.env`'in tamamını içerir,
  AWS anahtarı dahil. Yalnızca sahibi ve grubu okuyabilmeli, "herkes" okuyamamalı.
- `route:cache` closure rotaları serileştiremez. Projede rotalar bu yüzden sınıf
  tabanlı (`HealthController` örneği).

### Adım 4: `migrate --force`
Üretimde Laravel "yanlışlıkla veritabanını değiştirme" diye onay sorar.
`--force` bu soruyu atlar, çünkü betik etkileşimsiz çalışıyor. Göç hata verirse
`set -e` betiği keser; sonraki adımlar (FPM yenileme, kuyruk) **çalışmaz**.

### Adım 5: `reload php8.5-fpm`
PHP derlenmiş kodu bellekte tutar (OPcache). Dosyalar değişse de eski sürüm
çalışmaya devam edebilir. `reload`, `restart`'ın aksine süreçleri **kesintisiz**
yeniler: devam eden istekler tamamlanır.

### Adım 6: `queue:restart`
İşçi kodu belleğe aldığı için bu sinyal olmadan 1 saat boyunca eski kodla çalışır.
Komut işçiyi öldürmez; "o anki işi bitir ve çık" der. `davetkart-queue.service`
içindeki `Restart=always` onu yeni kodla geri getirir.

### Adım 7: Sağlık kontrolü
`curl --resolve davetkart.com:443:127.0.0.1` isteği kendi sunucumuza, doğru alan
adı ve sertifikayla gönderir (dışarı çıkmadan). `/api/ping` veritabanına dokunmaz;
Nginx → PHP-FPM → Laravel zincirinin ve **önbelleğe alınmış ayarların** çalıştığını
doğrular. `-f` HTTP hatasında komutu başarısız sayar, böylece betik sonunda
kırmızı bir hata görürsün.

## 3. İzin modeli (kim ne yapar?)

| Kim | Ne yapar |
|---|---|
| `deploy` | `git pull`, `composer install`. Kod dosyalarının sahibi |
| `www-data` | `artisan` komutları, PHP-FPM, kuyruk işçisi. `storage/` ve `bootstrap/cache/` yazabilir |

`artisan()` fonksiyonu her zaman `sudo -u www-data` kullanır. Böylece
`storage/logs/laravel.log` ve dosya önbelleği her zaman `www-data`'nın olur ve
"PHP-FPM log dosyasına yazamıyor" hatası oluşmaz. Koda ise web sunucusu
**yazamaz**; saldırgan uygulamayı ele geçirse bile kaynak dosyaları değiştiremez.

`.env`: `config:cache` onu `www-data` ile okur, bu yüzden dosya `deploy` sahibi,
`www-data` grubu ve `640` izinli (`600` olursa okunamaz). Y8.3b'de `deploy`'un
`umask 027` ayarı ve klasörün setgid biti sayesinde bu izin kendiliğinden oluştu.

## 4. Hata olursa: geri alma

Betik hata verirse son satırda `Onceki surum: abc1234` yazar.

```bash
git reset --hard abc1234                       # kodu eski sürüme döndür
bash deploy/deploy.sh                          # önbellekleri/FPM'i/kuyruğu yeniden kur
```

⚠️ **Göçler (migrations) otomatik geri alınmaz.** `migrate` çalışıp sonraki adım
patladıysa veritabanı yeni şemada kalır. Eski kod yeni şemayla genelde çalışır
(sütun eklemeleri), ama silme/yeniden adlandırma içeren bir göç varsa geri almadan
önce göçü elle değerlendir. Bu yüzden göç dosyaları **geriye uyumlu** yazılır.

## 5. Bilerek eklenmeyenler

- **`artisan down` (bakım modu):** Tek sunucuda göçler saniyeler sürer. Uzun bir
  göç olursa o gün elle açılır.
- **Frontend ayrı betikte:** `deploy.sh` backend'i bitirince `deploy/deploy-frontend.sh`'ı
  çağırır (Y10.3). Sıra bilinçli: önce backend (göçler), sonra frontend; yeni
  frontend yeni API'ye güvenebilsin. Yalnızca frontend değiştiyse betik tek
  başına çalıştırılabilir. Ayrıntı: `docs/rehber/deploy/deploy-frontend.md`.
- **Otomatik tetikleme:** GitHub Actions sonraya (YD-1).

## 6. Sık hatalar

| Belirti | Sebep |
|---|---|
| `Sunucuda commit'lenmemis degisiklik var` | Biri sunucuda dosya düzenlemiş: `git status` |
| `fatal: Not possible to fast-forward` | Sunucuda yerel commit var ya da dal çakışması |
| `sudo yetkisi yok: /etc/sudoers.d/...` | Sudoers kuralı kurulu değil (rehber: `deploy/sudoers`) |
| `sudo: a password is required` | Betiğe kuralda olmayan bir `sudo` komutu eklenmiş (yol ya da argüman birebir aynı olmalı) |
| `Permission denied` (`storage/`) | `storage/` ya da `bootstrap/cache/` grup yazma izni yok (`chmod -R g+w`) |
| `curl: (60)` / `(35)` | Sertifika yok ya da Nginx 443'te dinlemiyor (Y7) |
| `curl: (22) ... 5xx` | Laravel hata veriyor: `storage/logs/laravel.log` |
| Değişiklik görünmüyor | `.env` değişti ama `config:cache` çalışmadı |

## 7. Sözlük

- **Fast-forward:** Dalı, aradaki farkları birleştirmeden depo ile aynı noktaya ilerletmek.
- **OPcache:** PHP'nin derlenmiş kodu bellekte tutan önbelleği.
- **Smoke test:** Deploy sonrası "temel şey ayakta mı?" diye yapılan en küçük kontrol.
- **Idempotent:** Betik art arda iki kez çalışsa da aynı sonucu verir. Bu betik öyle.
