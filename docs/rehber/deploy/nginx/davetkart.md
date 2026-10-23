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
www.davetkart.com/xyz           -> 301 -> https://davetkart.com/xyz
85.159.x.x (doğrudan IP)        -> 444 (bağlantıyı sessizce kes)
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

Depodaki dosya **yalnızca 80 numaralı kapıyı** içerir (sertifika henüz yok). Y7.2'de
`certbot --nginx` sunucudaki kopyayı değiştirir: `443 ssl`, sertifika yolları ve
`http → https` yönlendirmesi eklenir. Bu değişiklik depoya **otomatik dönmez**.
Y7 bitince sunucudaki son hâli depoya geri alıp commit'leyeceğiz.

## 5. Sunucuya kurulum (Y7'de, özet)

```bash
sudo cp deploy/nginx/davetkart.conf /etc/nginx/sites-available/davetkart.conf
sudo ln -s /etc/nginx/sites-available/davetkart.conf /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default      # ikinci default_server olmasın
sudo nginx -t                                  # sözdizimi ve dosya yolları kontrolü
sudo systemctl reload nginx                    # kesintisiz yeniden yükle
```

`nginx -t` **her zaman** `reload`'dan önce çalışır: hata varsa reload yapmazsın,
site çalışmaya devam eder.

## 6. Sık hatalar

| Belirti | Sebep |
|---|---|
| **502 Bad Gateway** | PHP-FPM çalışmıyor ya da `fastcgi_pass` soket yolu yanlış (`ls /run/php/`) |
| **413 Request Entity Too Large** | `client_max_body_size` düşük ya da `post_max_size` aşıldı |
| **422 "dosya yüklenemedi"** | PHP `upload_max_filesize` düşük (Nginx değil, `docs/10` §1) |
| **403 Forbidden** | `www-data` kullanıcısı `dist/` ya da `public/` klasörünü okuyamıyor (izin) |
| **Sayfa yenileyince 404** | `location /` içindeki `try_files` satırı eksik |
| `duplicate default server` | `sites-enabled/default` silinmedi |
| Sertifika doğrulaması başarısız | `www` bloğunda `return` `location` dışına taşınmış |
| Deploy sonrası eski site görünüyor | `index.html` önbelleğe alınmış (`no-cache` kuralı bozulmuş) |

## 7. Sözlük

- **SPA:** Tek sayfalık uygulama. Tarayıcı bir kez yüklenir, sayfalar tarayıcıda çizilir.
- **Reverse proxy / FastCGI:** Nginx isteği alıp başka bir sürece (PHP-FPM) iletir, yanıtı geri taşır.
- **HSTS:** "Bu siteye bundan sonra yalnızca https ile gel" diyen tarayıcı kuralı.
- **Same-origin:** Sayfa ve API aynı alan adından gelir, CORS gerekmez.
- **Immutable:** "Bu dosya asla değişmeyecek, tekrar sormana gerek yok."
