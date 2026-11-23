# `deploy/smoke/check-security-headers.sh`

> **Ne işe yarar:** Yayındaki sitenin her yanıt türünün güvenlik başlıklarını taşıdığını ve API
> yanıtlarının onları **iki kez** taşımadığını `curl` ile denetler. Eksik ya da yinelenen bir başlık
> varsa çıkış 1. Dağıtımdan ve her Nginx değişikliğinden sonra çalıştırılır. Faz 18.6 · `SEC-04` ·
> K178. Başlıkların kendisi: [`../nginx/snippets/davetkart-security-headers.md`](../nginx/snippets/davetkart-security-headers.md).

```bash
bash deploy/smoke/check-security-headers.sh https://davetkart.com                                   # kendi bilgisayarından
bash deploy/smoke/check-security-headers.sh --resolve davetkart.com:443:127.0.0.1 https://davetkart.com  # sunucunun içinden
```

Beklenen çıktı:

```
Guvenlik basliklari: https://davetkart.com
OK     /                                  200
OK     /__duman-testi/spa-yolu            200
OK     /assets/index-CgNoz2oc.js          200
OK     /favicon.svg                       200
OK     /.env                              404
OK     /api/ping                          200
SONUC: tamam
```

## 1. Neden gerekli? `nginx -t` yetmez mi?

`nginx -t` "Nginx bu dosyayı okuyabilir mi?" sorusunu cevaplar, "her yanıt başlık taşıyor mu?"
sorusunu cevaplamaz. Nginx'te `add_header` üst bloktan miras alınmaz: bir `location`'dan `include`
satırı düşerse yapılandırma hâlâ geçerlidir, `nginx -t` yeşildir, site çalışır. O konumdan çıkan
yanıtlar sessizce korumasız kalır. Bunu görmenin tek yolu yanıtın kendisine bakmaktır.

İkinci neden **K85'in uyarısı**: yapılandırma sunucuda yaşar ve sunucu taşınınca ya da biri elle bir
şey değiştirince depoyla arası açılabilir. Duman testi depoya değil, **yayındaki** siteye bakar.

## 2. Neyi denetler?

Her `location` türünden bir yol seçilir, çünkü başlıklar konum konum `include` edilir. Bir konumun
unutulması ancak o konumdan bir yanıtla görülür.

| Yol | Nginx'te düştüğü konum | Ne aranır |
|---|---|---|
| `/` | `= /index.html` | SPA başlıkları |
| `/__duman-testi/spa-yolu` | `location /` → `try_files` → `= /index.html` | SPA başlıkları (React yönlendiricisinin yolları) |
| `/assets/<ana sayfadan okunan>.js` | `^~ /assets/` | SPA başlıkları |
| `/favicon.svg` | `location /` (diskte var olan dosya) | SPA başlıkları |
| `/fonts/templates.css` | `= /fonts/templates.css` | SPA başlıkları (SEC-12) |
| `/fonts/inter/inter-latin-opsz-normal.woff2` | `^~ /fonts/` | SPA başlıkları (SEC-12) |
| `/.env` | `~ /\.` (404) | SPA başlıkları (`always` sayesinde hata yanıtında da) |
| `/api/ping` | `^~ /api/` → `= /index.php` → Laravel | Laravel'in başlıkları, **her biri tam bir kez**; SPA'ya özgü `Permissions-Policy` ve `-Report-Only` **yok** |

**SPA yanıtında** CSP'de `fonts.googleapis.com`/`fonts.gstatic.com` geçmemeli (SEC-12). Ayrıca şunların her biri tam bir kez olmalı: `Strict-Transport-Security` (`max-age=`),
`X-Content-Type-Options` (`nosniff`), `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`,
`Content-Security-Policy` (`frame-ancestors` içermeli). Tam politika için iki durumdan biri
kabul edilir: `Content-Security-Policy-Report-Only` var ve `default-src` içeriyor (gözlem dönemi,
K175) ya da zorlanan CSP `default-src` içeriyor (zorlamaya geçildikten sonra). Böylece geçiş günü
betiği değiştirmek gerekmez.

**Neden "tam bir kez"?** Aynı başlığın iki kez gelmesi iki katmanın birden yazdığını gösterir.
Snippet `server` seviyesine taşınırsa API yanıtı Laravel'in ve Nginx'in başlıklarını birlikte taşır.
`Referrer-Policy` iki kez gelirse tarayıcı **sonuncuyu** uygular ve API'nin `no-referrer`'ı sessizce
zayıflar. Sayım bu yanlışı yakalar (Faz 18.6, mutant N5).

## 3. Seçenekler ve çıkış kodları

| Seçenek | Ne zaman |
|---|---|
| `--resolve HOST:PORT:ADRES` | Sunucunun içinden: DNS'e gitmeden `127.0.0.1`'e bağlan ama sertifika ve `server_name` için doğru alan adını kullan (`deploy.sh`'ın sağlık kontrolüyle aynı yöntem) |
| `-k` | Yalnızca yerel denemede, kendinden imzalı sertifikayla. Üretimde **kullanılmaz** |
| `--skip-api` | Arkasında Laravel olmayan bir sunucuda (`vite preview`) |

| Çıkış | Anlamı |
|---|---|
| 0 | Hepsi yerinde |
| 1 | En az bir yolda eksik ya da yinelenen başlık (her biri `- ad: N kez` satırıyla) |
| 2 | Kullanım hatası ya da adrese bağlanılamadı (`HATA: … alinamadi`) |

## 4. Bash notları

- `curl -sS -o /dev/null -D -`: gövdeyi at, başlıkları standart çıktıya yaz; sessiz ama hata
  mesajlarını göster. Yönlendirme **izlenmez** (`-L` yok): her yol kendi yanıtıyla sınanır.
- `tr -d '\r' | tr '[:upper:]' '[:lower:]'`: HTTP başlık satırları `\r\n` ile biter ve başlık adları
  büyük-küçük harf duyarsızdır (`X-Frame-Options` = `x-frame-options`). İkisini de normalleştirip
  `grep "^ad:"` ile sayarız.
- `grep -c … || true`: hiç eşleşme yoksa `grep` 0 yazar ama çıkış kodu 1'dir; `set -e` altında betik
  ölmesin diye.
- `${CURL_OPTS[@]+"${CURL_OPTS[@]}"}`: boş dizi `set -u` altında eski bash'lerde "tanımsız" sayılır;
  bu yazım boşsa hiçbir şey, doluysa elemanları tırnaklı verir.
- `jq` gibi ek araç kullanılmaz: sunucuda yalnızca `bash`, `curl`, `grep`, `sed`, `tr` gerekir.

## 5. Ne zaman çalıştırılır?

1. `sudo bash deploy/nginx/install.sh` sonrası (Nginx değiştiğinde).
2. Her dağıtımdan sonra: docs deposu `operations/deployment/YAYIN-SIRASI.md` §6.
3. Sunucu taşıma ya da yeniden kurulumdan sonra.

`deploy.sh` onu kendiliğinden çağırmaz (K178): başlıklar kodla değil Nginx'le değişir. Dağıtım
betiği yalnızca kod çeker ve Nginx'e dokunmaz. Duman testinin asıl yeri Nginx'in değiştiği an ve
dağıtım sonrası kontrol listesi.

## 6. Kendin dene (yerelde)

**`vite preview`'a karşı** (frontend deposunu değiştirmeden; başlıklar snippet'ten okunur):

```js
// scratch/vite.headers.config.mjs  (depoya girmez)
import { readFileSync } from 'node:fs';
const snip = readFileSync('D:/Projects/davetkart/davetkart-backend-php-laravel/deploy/nginx/snippets/davetkart-security-headers.conf', 'utf8');
const headers = Object.fromEntries([...snip.matchAll(/^add_header\s+(\S+)\s+"([^"]*)"\s+always;/gm)].map((m) => [m[1], m[2]]));
export default { root: 'D:/Projects/davetkart/davetkart-frontent', preview: { port: 4173, headers } };
```

```bash
cd D:/Projects/davetkart/davetkart-frontent
npm run build
node node_modules/vite/bin/vite.js preview --config <scratch>/vite.headers.config.mjs &
bash ../davetkart-backend-php-laravel/deploy/smoke/check-security-headers.sh --skip-api http://127.0.0.1:4173
```

Yeşil olmalı. Sonra yapılandırmadaki `headers`'ı `{}` yap ve yeniden başlat: her yol `EKSIK`, çıkış 1.

**Gerçek Nginx'e karşı:** Faz 18.6'da Herd'in `nginx.exe`'si (1.25) ile depodaki `davetkart.conf`'un
sertifika ve yolları değiştirilmiş bir kopyası koşturuldu, API Herd'in `php-cgi`'sine bağlandı. Yedi
Nginx mutantının yedisi bu betiği kırmızıya çevirdi (`FAZ-18.md` §18.6).

## 7. Neyi yakalamaz

- **Başlık değerinin doğruluğunu.** Yalnızca varlık, sayı ve birkaç anahtar parça (`nosniff`,
  `max-age=`, `frame-ancestors`, `default-src`) aranır. CSP'deki yanlış bir alan adı tarayıcıda
  görülür, burada görülmez.
- **Her şablonu ya da her rotayı.** Bir `location` türünden bir örnek yeter, çünkü Nginx başlıkları
  konuma göre verir, rotaya göre değil.
- **HTTP (80) yönlendirmesini.** `http://` istekleri 301 alır; HSTS orada anlamsızdır (tarayıcı
  yalnızca HTTPS yanıtındaki HSTS'ye uyar).

## 8. Sık hatalar

| Belirti | Sebep |
|---|---|
| `HATA: … /assets/*.js yok (SPA mi?)` | Adres yanlış ya da kök yanıt SPA değil (bakım sayfası, Nginx varsayılan sayfası) |
| Yalnızca `/assets/…` `EKSIK` | `^~ /assets/` konumunda `include` eksik |
| Yalnızca `/favicon.svg` `EKSIK` | `location /` konumunda `include` eksik |
| `/api/ping`: `x-frame-options: 2 kez` | Snippet `server` seviyesine taşınmış |
| `/api/ping`: `strict-transport-security: 0 kez` | İstek HTTP üzerinden gitti ya da Laravel `$request->secure()`'u `false` görüyor (`fastcgi_params`'ta `HTTPS`) |
| `curl: (60) SSL certificate problem` | Yerel sertifika kendinden imzalı: yalnızca yerelde `-k` |

## 9. Sözlük

- **Duman testi (smoke test):** Bir dağıtımdan sonra "temel şeyler yerinde mi?" diye bakan hızlı, yüzeysel test.
- **`--resolve`:** curl'e "bu alan adını DNS'e sormadan şu adrese bağla" demek; TLS ve `Host` doğru ad için kalır.
