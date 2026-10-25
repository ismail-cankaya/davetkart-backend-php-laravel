# `deploy/nginx/davetkart.conf`

> **Ne işe yarar:** Tarayıcıdan gelen her isteği karşılayan kapıdır. `davetkart.com`
> adresine gelen isteği ya **React sitesine** (hazır dosyalar) ya da **Laravel API'sine**
> (`/api/...`) yönlendirir. Sunucuda `/etc/nginx/sites-available/davetkart.conf`
> olarak durur; depoda durmasının sebebi "altyapı da kod" ilkesidir: sunucuda
> elle yazılan ayar bir dahaki kurulumda hatırlanmaz.

## 1. Nginx'i hiç görmediysen

Nginx bir **web sunucusudur**: 80 (http) ve 443 (https) numaralı kapılarda bekler,
gelen isteğe bakar, "bunu kim cevaplayacak?" sorusunu yanıtlar.

| Kavram | Anlamı |
|---|---|
| `server { }` | Bir "site". `server_name` ile istenen alan adına bakarak seçilir |
| `location { }` | Sitenin içinde, **yola** göre karar: `/api/...` başka, `/assets/...` başka |
| `listen 80;` | Hangi kapıyı dinleyecek. `[::]` IPv6 karşılığıdır |
| `root` | Dosyalar diskte hangi klasörde aranacak |
| `try_files` | Soldan sağa dene: dosya varsa onu ver, yoksa sonrakine geç |
| `return 301 ...` | "Kalıcı taşındı" yanıtı, tarayıcı yeni adrese gider |
| `fastcgi_pass` | İsteği PHP'ye (PHP-FPM) devret. PHP kendi başına web sunucusu değildir |

## 2. İstek nereye gider?

```
davetkart.com/                  -> frontend/dist/index.html          (React)
davetkart.com/giris             -> dosya yok -> index.html           (React kendi yönlendiricisiyle açar)
davetkart.com/assets/app-3f9a.js-> frontend/dist/assets/...          (1 yıl önbellek)
davetkart.com/api/v1/...        -> backend/public/index.php          (Laravel)
davetkart.com/.env              -> 404
http://davetkart.com/xyz        -> 301 -> https://davetkart.com/xyz   (tek adım)
http://www.davetkart.com/xyz    -> 301 -> https://davetkart.com/xyz   (tek adım)
https://www.davetkart.com/xyz   -> 301 -> https://davetkart.com/xyz
http://63.182.x.x (doğrudan IP) -> 444 (bağlantıyı sessizce kes)
https://63.182.x.x              -> TLS el sıkışması reddedilir (sertifika gösterilmez)
```

**Aynı alan adı (same-origin)** olduğu için tarayıcı API'ye "başka site" gözüyle
bakmaz. CORS ve preflight isteği yoktur; frontend `VITE_API_BASE_URL=/api` ile
bunu zaten bekliyor.

## 3. Satır satır, neden böyle?

### `return 444` (varsayılan sunucu)
Sunucuya IP ile ya da yanlış `Host` başlığıyla gelen istekler (tarayıcılar,
tarayıcı botları, hatalı DNS kayıtları) bu bloğa düşer. 444 Nginx'e özel bir
koddur: **hiç yanıt vermeden bağlantıyı keser**. Böylece rastgele bir isteğe
sitenin içeriği sızmaz. `default_server`, "hiçbir ada uymayan istek buraya"
demektir. Ubuntu'nun kurduğu `sites-enabled/default` dosyasında da bir
`default_server` var; **ikisi birlikte olamaz**, o yüzden kurulumda silinir (bkz. §5).

### `server_tokens off;` (dosyanın en üstünde, `server` bloklarının dışında)
Nginx varsayılan olarak her yanıta ve hata sayfasına sürümünü yazar
(`Server: nginx/1.24.0 (Ubuntu)`). Saldırgan için bu bir hedef listesidir:
"bu sürümün bilinen açıkları neler?" sorusunu ücretsiz cevaplar. `off` ile başlık
yalnızca `nginx` olur. Ubuntu yamaları sürüm numarasını değiştirmeden getirdiği
için numara zaten yanıltıcıdır. Gizlemek tek başına güvenlik değildir, ama bedava
bir bilgi sızıntısını kapatır.

**Neden `server` içinde değil de en üstte?** `sites-enabled/` altındaki dosyalar
Nginx'in `http { }` bloğunun **içine** eklenir. Yani dosyanın en üstündeki bir
yönerge `http` seviyesinde çalışır ve **bütün** `server` bloklarına uygulanır.
İlk sürümde bu satır yalnızca `davetkart.com` bloğundaydı. O halde `www`
yönlendirmesinin 301 yanıtı sürümü sızdırmaya devam ederdi. (25 Ekim, Y7.2a'da
düzeltildi.)

### 443 için ikinci varsayılan sunucu: `ssl_reject_handshake on`
80 numaralı kapıdaki `return 444` HTTPS'e etki etmez: her kapının kendi varsayılan
sunucusu vardır. 443 için varsayılan tanımlanmazsa Nginx **dosyadaki ilk 443
bloğunu** varsayılan sayar. O zaman `https://63.182.x.x` isteği gerçek sertifikamızı
görür (alan adımızı sızdırır, IP tarayıcılar bizi alan adıyla eşleştirir) ve
yönlendirilir. `ssl_reject_handshake on` TLS el sıkışmasını daha sertifika
gösterilmeden keser. Bu blok sertifika istemez (Nginx 1.19.4+).

### Tek yönlendirme bloğu (80): `davetkart.com` + `www`
`certbot --nginx` ilk kurulumda her ad için ayrı bir 80 bloğu yazar:
`if ($host = ...) { return 301 https://$host$request_uri; }`. Bu `http://www`
isteğini önce `https://www`'ya, oradan apex'e gönderir: **iki yönlendirme**,
iki ekstra gidiş-dönüş. Biz iki adı tek blokta toplayıp doğrudan
`https://davetkart.com`'a gönderiyoruz. `if` de kullanmıyoruz: `server_name`
zaten ada göre seçiyor (Nginx'te `if` yalnızca gerçekten gerektiğinde kullanılır).
`return` yine `location /` içinde, çünkü certbot yenilemede bu bloğa
`/.well-known/acme-challenge/` için daha özel bir `location` ekler ve o kazanır.

### `listen 443 ssl http2`
HTTP/2 tek bağlantı üzerinden çok sayıda dosyayı paralel taşır (Vite'ın çok sayıda
küçük JS/CSS dosyası için önemli). 1.24'te yazımı `listen ... http2`. Nginx 1.25.1+
sürümüne geçilirse bu yazım uyarı verir, yerine ayrı satır `http2 on;` gelir.
`ssl_*` satırları ve `options-ssl-nginx.conf` (TLS 1.2/1.3, güvenli şifre listesi)
certbot'un ürettiği dosyalardır; elle şifre listesi yazmıyoruz, certbot güncel tutuyor.

**Aynı kapıda seçenekler aynı olmalı.** `ssl` ve `http2` bir `server` bloğunun değil
**adres:kapı çiftinin** (ör. `0.0.0.0:443`) özelliğidir; o kapıyı dinleyen bütün
bloklar tek bir soketi paylaşır. Varsayılan 443 bloğunda `http2` unutulunca
`nginx -t` şu uyarıyı verdi: `protocol options redefined for 0.0.0.0:443`.
Uyarı test sonucunu bozmaz ama "hangisi geçerli?" belirsizliği bırakır. Bu yüzden
dört `listen 443` satırı da birebir `ssl http2` taşır (Y7.3, 26 Ekim).

### `www` bloğu ve `location /` içindeki `return`
`www.davetkart.com` ile `davetkart.com` aynı siteyi iki adres altında göstermesin
diye (SEO, çerez, `CORS_ALLOWED_ORIGINS` tek değer) tek adrese 301 yapıyoruz.
`return` doğrudan `server` içinde değil **`location /` içinde**: `certbot --nginx`
sertifika doğrulaması için bu bloğa kendi küçük `location` bloğunu ekler. `server`
seviyesindeki bir `return` ise `location`'dan **önce** çalışır ve doğrulama isteğini
de yönlendirip sertifikayı bozardı.

### `client_max_body_size 30m`
Nginx'in varsayılanı **1 MB**. Uygulama 15 MB'a kadar fotoğraf kabul ediyor (PHP
tarafında `upload_max_filesize=25M`, `post_max_size=30M`, bkz. `docs/10`).
Nginx sınırı düşük kalırsa istek **PHP'ye hiç ulaşmadan** 413 ile reddedilir ve
Laravel logunda tek satır bile olmaz.

### `gzip`
Metin dosyalarını (JS, CSS, JSON, SVG) sıkıştırarak gönderir, aktarım küçülür.
Resimleri (JPEG, PNG) bilerek eklemedik: zaten sıkışmışlardır, tekrar sıkıştırmak
CPU yakar, kazandırmaz. 2 GB RAM'li küçük sunucuda bu önemlidir.

### `location ~ /\.(?!well-known/)` → 404
React sitesi (SPA) için "dosya yoksa `index.html`'i ver" kuralı koyduk (aşağıya
bak). Bu kuralın yan etkisi: `davetkart.com/.env` gibi var olmayan bir yol da
**200 OK ve ana sayfa** döner. Dosya gerçekte orada olmasa bile tarayıcı botlarına
"burada bir şey var" izlenimi verir. Nokta ile başlayan her yol 404 olur.
`.well-known/` (sertifika doğrulaması) hariç tutuldu.

### `location ^~ /api/` ve `location = /index.php`
Laravel'in tek giriş noktası `backend/public/index.php`. İstek `/api/...` ile
başlıyorsa:

1. `try_files $uri /index.php?$query_string` önce `public/` altında aynı isimde
   dosya arar (yok), sonra isteği **içeriden** `/index.php`'ye yeniden yönlendirir.
2. `location = /index.php` bu isteği PHP-FPM'e devreder.

Adres çubuğundaki yol (`/api/v1/...`) değişmez, Laravel kendi yönlendirmesini
(`routes/api.php`) ona göre yapar.

- **`^~`:** "Bu önek eşleşirse regex konumlarına (`~`) bakma." Yoksa nokta içeren bir
  API yolu 404 bloğuna takılabilirdi.
- **`internal`:** `/index.php` yalnızca Nginx'in kendi yönlendirmesiyle çalışır.
  Dışarıdan kimse `davetkart.com/index.php` yazarak PHP'ye ulaşamaz.
- **Neden klasik `location ~ \.php$` yok?** O kalıp "her `.php` uzantılı dosyayı
  çalıştır" der. Biri bir şekilde `uploads/shell.php` yükleyebilirse çalışırdı.
  Bizim yapıda **çalışabilen tek PHP dosyası `index.php`**.
- `fastcgi_read_timeout 60s`: Gemini gibi yavaş bir dış çağrı olursa istek 60 saniye
  beklenir, sonra 504 olur.

### HTTPS ve HSTS: kim ne yapıyor?
`include fastcgi_params;` içinde `HTTPS $https` satırı var. `certbot` bu dosyaya
`listen 443 ssl` eklediğinde `$https` değeri `on` olur ve Laravel
`$request->secure()` ile isteğin güvenli olduğunu **kendisi** anlar. Araya başka
bir proxy girmediği için `TRUSTED_PROXIES` boş kalır ve istemci IP'si gerçek IP'dir
(hız sınırı bu yüzden doğru çalışır). `SecurityHeaders` HSTS'yi `includeSubDomains`
ile **Laravel** gönderir. Nginx'e `add_header Strict-Transport-Security` eklenirse
başlık iki kez gider; bu yüzden eklenmedi.

### `/assets/` ve `index.html` önbelleği
Vite derlemesi dosya adlarına içerik özeti (hash) koyar: `app-3f9a2c.js`. İçerik
değişince ad değişir, yani **bir dosya adı asla eski içeriği taşımaz**. Bu yüzden
1 yıl önbellek ve `immutable` güvenli. Ama `index.html` bu dosyalara işaret eden
listedir ve adı hep aynı. `no-cache` ("her seferinde sunucuya sor") dediğimiz için
yeni bir deploy'dan sonra kullanıcı yeni hash'leri hemen görür.
Not: `location` içinde `add_header` kullanmak, üst seviyedeki `add_header`'ları
**iptal eder**. Bu yüzden `server` seviyesinde güvenlik başlığı koymadık, onları
Y12.2'de ayrıca ele alacağız.

### `location /` → `try_files $uri /index.html`
React tek sayfalık uygulama (SPA). `/giris`, `/davetiyelerim` gibi yollar diskte
dosya değildir; tarayıcıda React yönlendiricisi onları çizer. Sayfayı yenilediğinde
Nginx dosya bulamayıp 404 vermesin diye bilinmeyen her yol `index.html`'e düşer.

## 4. `certbot` ile birlikte hayat

İlk sürüm yalnızca 80 numaralı kapıyı içeriyordu. Y7.2'de `certbot --nginx`
sunucudaki kopyaya 443 bloklarını ve yönlendirmeleri ekledi. Y7.3'te (26 Ekim)
bu çıktı okunup **temiz hâli depoya yazıldı**: depodaki dosya artık sunucudakinin
birebir aynısıdır, `# managed by Certbot` satırları yoktur.

**Yenileme bu dosyayı bozmaz.** Certbot yenilemede (`certbot renew`) dosyayı
yeniden yazmaz; yalnızca `/etc/letsencrypt/live/davetkart.com/` altındaki
bağlantıları yeni sertifikaya çevirir ve Nginx'i yeniden yükler. Ayarlar
`/etc/letsencrypt/renewal/davetkart.com.conf` içinde hatırlanır
(`authenticator = nginx`, `installer = nginx`, `renew_before_expiry = 30 days`).
Zamanlayıcı: `certbot.timer` (günde iki kez, rastgele gecikmeli).

**Kural:** Bu dosyada her değişiklikten sonra `sudo nginx -t`, `reload` ve
`sudo certbot renew --dry-run`. Let's Encrypt artık bitiş uyarı e-postası
göndermiyor; sessiz bir yenileme hatasını UptimeRobot SSL izlemesi yakalar (Y11).

## 5. Sunucuya kurulum

**Sertifika zaten varsa** (bugünkü sunucu):

```bash
sudo cp deploy/nginx/davetkart.conf /etc/nginx/sites-available/davetkart.conf
sudo ln -s /etc/nginx/sites-available/davetkart.conf /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default      # ikinci default_server olmasın
sudo nginx -t                                  # sözdizimi ve dosya yolları kontrolü
sudo systemctl reload nginx                    # kesintisiz yeniden yükle
```

`nginx -t` **her zaman** `reload`'dan önce çalışır: hata varsa reload yapmazsın,
site çalışmaya devam eder.

**Sıfırdan yeni sunucuda** (sertifika henüz yok): dosya `/etc/letsencrypt/...`
yollarını içerdiği için `nginx -t` hata verir. Tavuk-yumurta sorununu şöyle çöz:

1. Geçici olarak **yalnızca 80 bloklarını** kur (bu dosyanın 443 bloklarını
   çıkarılmış hâli ya da git geçmişindeki ilk sürüm), `nginx -t` + `reload`.
2. Sertifikayı **dosyaya dokundurmadan** al:
   ```bash
   sudo apt install -y certbot python3-certbot-nginx
   sudo certbot certonly --nginx -d davetkart.com -d www.davetkart.com \
        --deploy-hook "systemctl reload nginx"
   ```
   `certonly` yalnızca sertifikayı alır, Nginx dosyasını değiştirmez. Nginx
   eklentisi `options-ssl-nginx.conf` ve `ssl-dhparams.pem` dosyalarını da üretir,
   yenileme ayarına `authenticator = nginx` yazar. `certonly`'de "kurucu"
   (`installer`) olmadığı için yenilemeden sonra Nginx'i kimse yeniden yüklemez;
   `--deploy-hook` bu boşluğu kapatır (yoksa Nginx eski sertifikayı bellekte tutar).
3. Depodaki asıl dosyayı kur, `nginx -t`, `reload`, `certbot renew --dry-run`.

## 6. Sık hatalar

| Belirti | Sebep |
|---|---|
| **502 Bad Gateway** | PHP-FPM çalışmıyor ya da `fastcgi_pass` soket yolu yanlış (`ls /run/php/`) |
| **413 Request Entity Too Large** | `client_max_body_size` düşük ya da `post_max_size` aşıldı |
| **422 "dosya yüklenemedi"** | PHP `upload_max_filesize` düşük (Nginx değil, `docs/10` §1) |
| **403 Forbidden** | `www-data` kullanıcısı `dist/` ya da `public/` klasörünü okuyamıyor (izin) |
| **Sayfa yenileyince 404** | `location /` içindeki `try_files` satırı eksik |
| `duplicate default server` | `sites-enabled/default` silinmedi |
| Sertifika doğrulaması başarısız | 80 bloğunda `return` `location` dışına taşınmış ya da `/.well-known/` engellenmiş |
| `http://www` iki kez yönleniyor | certbot'un `if ($host ...)` blokları geri gelmiş (tek 80 bloğu yeterli) |
| IP ile https'te sitenin sertifikası görünüyor | `ssl_reject_handshake` varsayılan bloğu eksik |
| Deploy sonrası eski site görünüyor | `index.html` önbelleğe alınmış (`no-cache` kuralı bozulmuş) |

## 7. Sözlük

- **SPA:** Tek sayfalık uygulama. Tarayıcı bir kez yüklenir, sayfalar tarayıcıda çizilir.
- **Reverse proxy / FastCGI:** Nginx isteği alıp başka bir sürece (PHP-FPM) iletir, yanıtı geri taşır.
- **HSTS:** "Bu siteye bundan sonra yalnızca https ile gel" diyen tarayıcı kuralı.
- **Same-origin:** Sayfa ve API aynı alan adından gelir, CORS gerekmez.
- **Immutable:** "Bu dosya asla değişmeyecek, tekrar sormana gerek yok."
