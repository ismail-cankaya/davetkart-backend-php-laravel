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
| `set -E` | `trap … ERR` **fonksiyonların içinde de** çalışsın (Faz 20.4). Olmadan `artisan()` içindeki bir hata (ör. `migrate`) betiği durduruyor ama hata mesajı hiç basılmıyordu |
| `"$(...)"` | Komutun çıktısını değer olarak kullan |
| `trap '...' ERR` | Bir hata olursa bu mesajı da yaz |

`set -Eeuo pipefail` tek satırda "ilk hatada dur ve söyle" güvencesidir. Ama tek başına
yeni kodun eski veritabanıyla çalışmasını **önlemez**: kod 1. adımda (`git pull`) zaten yerindedir
ve OPcache varsayılanı yeni dosyayı en geç 2 saniyede okur. Bunu bakım kipi önler (adım 0b, K264).
(Faz 20'ye kadar burada aksi yazıyordu; `AUD-2026-11-26` `OPS-06`.)

## 2. Sıra ve gerekçesi

| # | Adım | Neden bu sırada |
|---|---|---|
| 0 | Güvenlik kontrolleri | root değil, `.env` var, çalışma ağacı temiz |
| 0b | `artisan down --retry=60` | **Bakım kipi** (Faz 20.4, K264): koddan göçe kadar API 503 döner, yarım şemayla cevap vermez |
| 1 | `git pull --ff-only` | Yeni kod |
| 1c | `git describe --tags --exact-match` | Yayınlanan commit'in **etiketi** günlüğe yazılır; etiketsizse uyarı (Faz 18, K149) |
| 2 | `composer install --no-dev -o --no-scripts` + `package:discover` | Bağımlılıklar, üretimde test araçları yok |
| 3 | `config:cache` → `route:cache` → `chmod 640` | Önbellekler, **migrate'ten önce** |
| 4 | `migrate --force` | Veritabanı şeması |
| 5 | `systemctl reload php8.5-fpm` | OPcache'i temizle, yeni kod yüklensin |
| 6 | `queue:restart` | Kuyruk işçisi yeni kodu alsın |
| 6b | `artisan up` | Göç bitti, FPM yeni kodda: bakım kipi kalkar |
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

### Adım 0b: Bakım kipi (`artisan down --retry=60`)
Kod `git pull` ile göçlerden **önce** gelir. Göç bitene kadar yeni kod eski şemayla çalışırsa yerelde katı kip 500 verir;
canlıda katı kip kapalı olduğu için eksik sütun **sessizce `null`** okunur (ör. `Order::hasPublished()` → `false`,
yayın yapmış hak iade edilebilir görünür). Bakım kipi bu aralıkta her isteği 503'le reddeder.

- `artisan down` dosya sürücüsüyle `storage/framework/down` yazar; `public/index.php` her istekte buna bakar. Kipi
  **eski kod** açar (pull'dan önce), **yeni kod** kapatır (adım 6b). İkisi aynı dosyayı tanır.
- `--render` **yok**: şablon verilirse API'ye de HTML gider. Şablonsuz istek Laravel'e ulaşır ve
  `ApiExceptionRenderer` frontend'in anladığı zarfı döner: `503 {"error":{"code":"PROVIDER_UNAVAILABLE"}}`
  (`tests/Feature/MaintenanceModeTest.php`). Frontend bunu "Hizmet şu anda yanıt veremiyor" diye gösterir.
- `--retry=60`: şablonsuz yanıtta `Retry-After` başlığı yalnızca HTML yoluna gider; API zarfı başlıksız döner (bilinçli: başlık beyaz listeden üretilir).
- **Kuyruk işçisi** bakımda iş almaz (`queue:work`'te `--force` yok), `up`'tan sonra kaldığı yerden devam eder.
  **Zamanlayıcı** bakımda yalnızca `evenInMaintenanceMode()` işleri koşar; kalp atışı en çok bir dakika gecikir.
- Kesinti: `composer install` + önbellek + göç + FPM; genelde 30–60 sn. Statik site (Nginx'teki `dist/`) açık kalır,
  yalnızca API cevap vermez.
- Hata olursa `up` **çalışmaz**: site bakımda kalır ve betik bunu söyler (§4). Elle `artisan up` yarım şemayı açmak demektir.
- Betik ikinci kez koşarsa (adım 1b yeniden başlatma, ya da önceki deploy bakımda kaldıysa) `down` yalnızca
  seçenekleri yeniler ("Maintenance mode options updated").
- ⚠️ Bu değişikliği getiren **ilk** deploy eski betikle başlar: eski betik `pull`'u bakımsız yapar, 1b ile yeni betiğe
  geçer ve `down` ancak o noktada açılır. Sonraki deploy'lardan itibaren `down` pull'dan öncedir.

### Adım 1: `git pull --ff-only`
`--ff-only` "yalnızca ileri sar" demek: sunucudaki dal ile depo arasında ayrışma
varsa **birleştirme (merge) commit'i üretmez, hata verir**. Sunucuda hiç yerel
commit olmaması gerektiği için, bu durumda bir şey ters gitmiş demektir.

### Adım 1b: Betik kendini güncellediyse yeniden başla
Bash bir betiği çalışırken **parça parça** okur, ama hep başta açtığı dosyadan.
`git pull` `deploy/deploy.sh`'ı yerinde düzenlemez, yeni bir dosyayla değiştirir;
çalışmakta olan süreç eski dosyayı okumaya devam eder ve **eski** sürümün geri
kalanını koşar. 26 Ekim'de tam bu oldu: frontend
adımını ekleyen commit çekildi ama o deploy eski betikle bitti, frontend
derlenmedi. Çözüm: `git pull`'dan sonra `deploy/` klasöründe değişiklik varsa
betik kendini `exec` ile **yeni hâliyle baştan** başlatır. `DAVETKART_DEPLOY_PREV`
değişkeni iki işe yarar: ikinci turda tekrar başlatmayı engeller (sonsuz döngü
yok) ve hata mesajındaki "önceki sürüm"ün gerçek önceki sürüm olmasını sağlar.

### Adım 1c: Sürüm etiketi (Faz 18 · `OPS-01` · K149)

```bash
TAG="$(git describe --tags --exact-match HEAD 2>/dev/null || echo etiketsiz)"
step "Surum: $NEW ($TAG)"
```

Çıktı ya `==> Surum: 3f2a1bc (v1.1.0)` ya da `==> Surum: 3f2a1bc (etiketsiz)` olur; aynı
bilgi son satırda da yazar (`Backend bitti: … -> 3f2a1bc (v1.1.0)`).

**Neden?** Backend ve frontend tek ürünün iki yarısı. "Bu backend commit'i hangi frontend
commit'iyle birlikte doğrulandı?" sorusunun cevabı bir dal adı olamaz: dal her commit'te
ilerler. Cevap **etiket**tir: iki depoda aynı adı (`v1.1.0`) taşıyan iki commit birlikte
sınanmış demektir. Denetimde (`OPS-01`) iki depoda da etiket yoktu ve "yayına hazır commit"
hiçbir yerde işaretli değildi.

| Parça | Anlamı |
|---|---|
| `git describe --tags` | HEAD'e en yakın etiketi söyler |
| `--exact-match` | Yalnızca etiket **tam HEAD'deyse** yazar; "3 commit sonrası" (`v1.1.0-3-gabc`) kabul edilmez |
| `2>/dev/null \|\| echo etiketsiz` | Etiket yoksa komut hata verir; `\|\|` hem `set -e`'nin betiği kesmesini önler hem günlüğe açık bir kelime yazar |

**Etiketsiz commit uyarı verir, deploy'u durdurmaz.** İlk sürümde katı kural fazla: acil
bir düzeltme etiket beklemeden çıkabilmeli. Uyarı `stderr`'e yazılır ve günlükte kalır.
Karar ileride sıkılaştırılabilir (etiketsizse dur).

**Sık hata:** etiketi commit'ten **sonra** itmeyi unutmak. `git pull` etiketleri kendiliğinden
getirir, ama yalnızca uzakta varsa: `git push origin v1.1.0`. Etiket atıldı ama günlükte
`etiketsiz` yazıyorsa etiket yereldedir, itilmemiştir.

**Kendin dene (yerelde, Git Bash):**

```bash
git describe --tags --exact-match HEAD 2>/dev/null || echo etiketsiz
```

Çalıştırma kitabı (sıra, kontrol listesi, etiket komutları): docs deposu
`operations/deployment/YAYIN-SIRASI.md`.

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

> Faz 21.5 (K269): `opcache.validate_timestamps = 0`. Bu adım artık yalnızca "temizlik" değil, **zorunlu**: reload'suz
> kod değişmez. Sunucuda elle dosya düzeltirsen de `sudo systemctl reload php8.5-fpm` gerekir.
> Havuz ve bellek tablosu: [`php-fpm/davetkart.md`](php-fpm/davetkart.md).

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

Betik hata verirse `HATA: deploy yarida kesildi. Onceki surum: abc1234` yazar. Hata bakım kipi açıkken olduysa
(adım 0b ile 6b arası) ikinci satır **"Site BAKIM KIPINDE kaliyor (503)"** der: site, sen düzeltene kadar 503 döner.

Önce sebebi düzelt ve `bash deploy/deploy.sh`'ı yeniden çalıştır; başarılı biterse `up` kendisi yapılır. Kodu geri
alman gerekiyorsa:

```bash
git reset --hard abc1234                       # kodu eski sürüme döndür
bash deploy/deploy.sh                          # önbellekleri/FPM'i/kuyruğu yeniden kur
```

⚠️ **Bu yol yalnızca yarıda kalan bir deploy'u toparlar** (Faz 18, `OPS-01`). Betik her
çalıştığında `git pull --ff-only` yapar: `main` yeni sürümü gösterdiği sürece ikinci satır
kodu yeniden ileri sarar. Yayındaki bir sürümü **önceki etikete** döndürmek `main` üzerinden,
yeni bir commit'le yapılır ve sıra tersine döner (önce frontend, sonra backend): docs deposu
`operations/deployment/YAYIN-SIRASI.md` §5.

⚠️ **Göçler (migrations) otomatik geri alınmaz.** `migrate` çalışıp sonraki adım
patladıysa veritabanı yeni şemada kalır. Eski kod yeni şemayla genelde çalışır
(sütun eklemeleri), ama silme/yeniden adlandırma içeren bir göç varsa geri almadan
önce göçü elle değerlendir. Bu yüzden göç dosyaları **geriye uyumlu** yazılır.

## 5. Bilerek eklenmeyenler

- ~~**`artisan down` (bakım modu):** Tek sunucuda göçler saniyeler sürer. Uzun bir
  göç olursa o gün elle açılır.~~ → Faz 20.4'ten beri **her deploy bakım kipinde** (adım 0b, K264). Sebep süre değil,
  aradaki tutarsızlık: `AUD-2026-11-26` `OPS-06`.
- **Sürüm klasörü + sembolik bağ** (kesintisiz): K264'te değerlendirildi, seçilmedi; FPM kökü, Nginx ve paylaşılan
  `storage/` değişikliği ister. Trafik kesintiyi görünür kılarsa yeniden açılır.
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
| `UYARI: … etiketli bir surum degil` | Commit etiketlenmemiş ya da etiket itilmemiş (`git push origin vX.Y.Z`). Deploy devam eder (Adım 1c) |
| Değişiklik görünmüyor | `.env` değişti ama `config:cache` çalışmadı |

## 7. Sözlük

- **Fast-forward:** Dalı, aradaki farkları birleştirmeden depo ile aynı noktaya ilerletmek.
- **OPcache:** PHP'nin derlenmiş kodu bellekte tutan önbelleği.
- **Smoke test:** Deploy sonrası "temel şey ayakta mı?" diye yapılan en küçük kontrol.
- **Idempotent:** Betik art arda iki kez çalışsa da aynı sonucu verir. Bu betik öyle.
