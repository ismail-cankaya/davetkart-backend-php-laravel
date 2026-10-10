# DavetKart Backend — Gözden Geçirme (9 Ekim 2026)

> **Tarih:** 9 Ekim 2026 · **Dal:** `faz-10` @ `3d301e5`
> **Yöntem:** Salt okunur kod okuması ve statik kapılar (pint, phpstan L8, `errors:export --check`).
> PHPUnit koşturulmadı (nedeni son bölümde).
> **Eş rapor:** `claude/GOZDEN-GECIRME-2026-10-09-FRONTEND.md`. API sözleşmesi bulguları iki tarafta da kodla doğrulandı.

## Genel değerlendirme

Backend iyi durumda:
- **Yetki:** Her sahip ucunda Policy var; red 404'e çevriliyor ve sahiplik sorgu seviyesinde de zorlanıyor.
- **Mass assignment:** Beyaz listeler sıkı (`Order` ve `Media` için `#[Fillable([])]`).
- **Yarışlar:** Kota, yayın, silme ve ödeme bildirimi akışlarında satır kilidi (`lockForUpdate`) ve durum makinesi doğru yerlerde.
- **Önceki kritikler:** 23 Eylül raporunun iki kritik bulgusu (yayından sonra paywall atlatma ve geç gelen ödeme) kodda kapatılmış. Bunu `UpdateInvitationAction.php:62-110` ve `HandlePaymentCallbackAction.php:73-109`'u okuyarak doğruladım.

Yeni sorunlar tek bir dosyada değil, kuralların birleştiği yerlerde:
- Kotalar hesap başına sayılıyor, ama hesap açmak sınırsız.
- Kotalar davetiye başına sayılıyor, ama davetiye linki herkese açık.
- Ödenen hakkın geri alınma penceresi siparişe değil davetiyeye bağlı.

Yeni Kritik bulgu yok. Faz 5 kodu artık statik kalite kapılarından geçiyor; test kapısı bu oturumda doğrulanamadı.

## Bulgular

### Yüksek

**Y1. Ücretsiz bir hesap diski ve kuyruğu doldurabiliyor**
- **Nerede:**
  - `app/Policies/InvitationPolicy.php:26-29` (davetiye oluşturma her zaman serbest)
  - `app/Http/Controllers/Api/V1/MediaController.php:42` (taslağa galeri yüklemek için yalnızca sahiplik soruluyor)
  - `app/Actions/Invitation/DeleteInvitationAction.php:69` (soft delete dosyaları silmiyor)
  - `app/Console/Commands/PruneOrphanMedia.php:105` (temizlik komutu galeri dosyalarını kapsamıyor)
- **Senaryo:** Saldırgan kayıt olur (e-posta doğrulaması yok), taslak açar, 30 × 15 MB galeri yükler, taslağı siler ve tekrarlar.
  - Galeri kotası davetiye başına olduğu için her yeni taslak boş kotayla başlıyor.
  - Silinen davetiyenin dosyalarını hiçbir komut temizlemiyor.
  - Tek IP, genel 60 istek/dk sınırıyla dakikada yaklaşık 900 MB yazabiliyor.
  - Kuyruk da tıkanabiliyor: 8192×8192 düz renkli bir PNG dosyası yüzlerce KB tutar ama kuyrukta yaklaşık 270 MB'lık bir çözme işi açar (`StoreUploadedMediaAction.php:126`). Tek işçi saatlerce dolu kalır ve misafir fotoğraflarının EXIF temizliği gecikir.
- **Düzeltme:**
  - Kullanıcı başına taslak sayısı ve toplam depolama tavanı.
  - Galeri yüklemesine kullanıcı anahtarlı ayrı bir hız sınırı.
  - Silinen davetiyelerin medyasını N gün sonra silen bir komut (10.43 ile birleşebilir).
  - Görsel optimizasyonu için ayrı bir kuyruk.

**Y2. Yapay zekâ maliyet kotası yeni hesap açarak aşılıyor**
- **Nerede:** `app/Actions/Assistant/AskAssistantAction.php:63-65`; `app/Providers/AppServiceProvider.php:233-251` (kota kullanıcı başına) ve `:318` (kayıt sınırı IP başına 20/dk).
- **Senaryo:** Bir bot IP başına dakikada 20 hesap açar; CAPTCHA ve e-posta doğrulaması yok. Her hesap günde 30 mesaj gönderir, her yanıt 800 token'a kadar çıkabilir. Tek IP'den günde yaklaşık 28.800 hesap, yani yaklaşık 864 bin Gemini çağrısı demek. Kodda global bir harcama tavanı yok.
- **Düzeltme:**
  - Veritabanında tutulan global bir günlük çağrı tavanı.
  - Asistanı yalnızca e-postası doğrulanmış hesaplara açmak.
  - Kayıtta CAPTCHA ya da IP başına günlük kova.
  - Google tarafında bütçe alarmı.

**Y3. Token'lar hiç sona ermiyor (bilinen, 10.10 henüz uygulanmamış; frontend doğruladı)**
- **Nerede:** `config/sanctum.php:55` (`'expiration' => null`).
- **Senaryo:** Frontend token'ı `localStorage`'da tutuyor (`services/auth.ts:76-101`), CSP tanımlı değil ve açılışta `/auth/me` ile doğrulama yapılmıyor. Tek bir XSS ile çalınan token sonsuza kadar geçerli kalır. Süresi dolan token'ları silmesi gereken `sanctum:prune-expired` da bu yüzden hiçbir satır silmiyor.
- **Düzeltme:** 30 günlük süre (K90 kararı), frontend'de açılışta `/auth/me` kontrolü ve bir CSP.

**Y4. Proxy arkasında IP tespiti yok (bilinen, 10.18)**
- **Nerede:** `bootstrap/app.php` (`trustProxies` ayarı yok).
- **Senaryo:** Uygulama bir yük dengeleyici ya da CDN arkasında çalışırsa herkes aynı IP'den geliyor görünür. Tüm hız sınırları tek kovaya düşer ve `ip_hash` anlamını yitirir.

### Orta

**O1. Linke sahip herkes LCV ve medya kotasını dakikalar içinde doldurabiliyor**
- **Nerede:** `app/Actions/Rsvp/SubmitRsvpAction.php:137-160`; `config/davetkart.php:16` (Standart kota 100 kişi), `:39` (kayıt başına en fazla 10 kişi), `:41-42`, `:132`, `:137`.
- **Senaryo (LCV):** Saldırgan Standart bir davetiyeye, bot tuzağı alanı (`website`) boş, 10 kişilik ve "katılıyorum" durumlu 10 yanıt gönderir. Bu iş IP başına 10/dk sınırına tek dakikada sığıyor. Kota dolar, gerçek misafirler `403 RSVP_QUOTA_EXCEEDED` alır ve sahip sahte kayıtları tek tek silmek zorunda kalır.
- **Senaryo (medya):** Misafir ucuna saatte 40 video yüklenebiliyor. Bu videolar "katılmıyorum" yanıtlarına bağlanabiliyor. Bu yanıtlar kişi kotasından düşmüyor ve bağlı dosyaları temizlik komutu silmiyor. Sonuçta 100 videoluk kota kalıcı olarak doluyor.
- **Düzeltme:**
  - Aynı davetiyede `ip_hash` başına kişi ya da kayıt tavanı (`ip_hash` zaten saklanıyor).
  - Sahibe `ip_hash`'e göre toplu silme imkânı.
  - Medya kotasını LCV başına sınırlamak.

**O2. Tek bir ödeme sınırsız etkinliğe yetiyor**
- **Nerede:** `app/Actions/Invitation/DeleteInvitationAction.php:51-58` ve `:83-93`; `app/Actions/Invitation/PublishInvitationAction.php:90` ve `:112`.
- **Senaryo:**
  1. Kullanıcı 249 ₺ öder ve A davetiyesini yayınlar.
  2. İkinci gün A'yı siler; 3 günlük pencere açık olduğu için sipariş serbest kalır.
  3. B davetiyesini yayınlar; serbest sipariş B'ye bağlanır ve yayın tarihi (pencerenin başlangıcı) yeniden yazılır.
  4. B'yi iki gün sonra siler ve döngü sürer.

  Parti, doğum günü ya da kurumsal etkinlik gibi kısa vadeli etkinliklerde tek sipariş sonsuz kullanılabiliyor. Mevcut test (`a_released_order_can_only_publish_one_invitation`) yalnızca aynı anda tek kullanımı kanıtlıyor, bu döngüyü yakalamıyor.
- **Düzeltme (ürün kararı gerekiyor):** Pencereyi siparişin ilk bağlandığı andan saymak ya da sipariş başına tek serbest bırakma hakkı tanımak (`released_at` gibi bir kolonla).

**O3. Aynı davetiye için iki kez ödeme alınabiliyor (frontend doğruladı)**
- **Nerede:** `app/Actions/Payment/StartCheckoutAction.php:61-69`.
- **Senaryo:** Kullanıcı öder ve sağlayıcı onu `/odeme/basarili` adresine döndürür. Frontend'de bu rota yok, kullanıcı ana sayfaya düşer. Ödemenin olmadığını sanıp yeniden "Satın al"a basar ve ikinci sipariş açılır.
  - Frontend yalnızca istek sürerken çift tıklamayı engelliyor; ödeme penceresi her açıldığında bekleyen siparişi sıfırlıyor.
  - Yayınlanmış ve planı zaten yeterli bir davetiye için de yeniden satın alma açık.
- **Düzeltme:**
  - Davetiyeyi zaten kapsayan ödenmiş bir sipariş varsa 409 dönmek.
  - Aynı kullanıcı, davetiye ve plan için süresi dolmamış bekleyen bir sipariş varsa yenisini açmak yerine onu döndürmek.

**O4. Üretim `.env` şablonu medya yüklemeyi kırıyor**
- **Nerede:** `docs/10-URETIM-ENV-SABLONU.md:106-110` (`DAVETKART_MEDIA_DISK=s3`); `composer.json` (S3 sürücü paketi `league/flysystem-aws-s3-v3` kurulu değil; `vendor/league` altında da yok); `app/Models/Media.php:69`.
- **Senaryo:** Şablonla deploy edilirse ilk yüklemede S3 adaptör sınıfı bulunamaz ve 500 döner. Paket kurulsa bile şablon özel bir bucket varsayıyor ("S3 bunu yapısal olarak kapatır"). Özel bucket'ta `url()` imzasız adres döner ve misafir sayfasındaki tüm görseller 403 olur.
- **Düzeltme:** Paketi eklemek, sonra iki yoldan birini seçmek:
  - Bucket'ı herkese açık okunur yapmak (ya da CloudFront) ve şablondaki ifadeyi düzeltmek.
  - İmzalı geçici URL (`temporaryUrl()`) kullanmak ve public cache süresini imza süresiyle uyumlamak.

**O5. Sahte ödeme sürücüsü üretimde engellenmiyor**
- **Nerede:** `config/payment.php:15` (varsayılan `fake`); `docs/10-URETIM-ENV-SABLONU.md:148` (üretim şablonu da `fake`); `app/Providers/AppServiceProvider.php:77`; `app/Services/Payment/FakeGateway.php:116`.
- **Senaryo:** Lansmanda sürücü değiştirilmezse her ödeme para çekmeden "başarılı" sayfasına yönlenir. Sipariş beklemede kalır ve kullanıcı yayınlayamaz. Projenin kendi kuralı (K70: "yanlış yapılandırma gürültülü olmalı") tam en pahalı noktada çiğneniyor. Ayrıca ödeme bildirimi imza sırrı olarak `APP_KEY` kullanılıyor; `APP_KEY` sızarsa herhangi bir sipariş için geçerli bir "ödendi" bildirimi üretilebilir.
- **Düzeltme:** Üretimde (`isProduction()`) `fake` sürücüsünü `PaymentProviderException::unavailable` ile reddetmek, config varsayılanını `null` yapmak ve sahte sürücüye ayrı bir sır vermek.

**O6. İade yayını geri çekmiyor (bilinen, 10.61)**
- **Nerede:** `app/Actions/Invitation/UpdateInvitationAction.php:91-110`.
- **Ek:** İadeden sonra "önceki gereksinim" kuralı, aynı plandaki diğer modüllerin açılmasına hâlâ izin veriyor.

**O7. Paket alımı sınırsız yayın açıyor (bilinen, K43 / 10.58)**
- `POST /api/payments/checkout` ucu hâlâ açık.

**O8. Türkçe `İ` harfi içeren e-postalar (bilinen, K-3 / 10.12-10.15)**
- Küçük harfe çevirme dört ayrı yerde ayrı ayrı yapılıyor (`RegisterRequest`, `LoginRequest`, `User::setEmailAttribute`, `AppServiceProvider::authLimits`) ve sorun hâlâ açık.

### Düşük

**D1. LCV medya kolonlarında index yok**
- **Nerede:** `database/migrations/2026_08_29_100000_add_media_columns_to_rsvps_table.php:35,45`.
- **Sorun:** PostgreSQL yabancı anahtar için kendiliğinden index açmaz. Temizlik komutundaki sorgu (`PruneOrphanMedia.php:116-117`) her medya satırı için LCV tablosunu baştan sona tarıyor. Her medya silme işlemi de (`nullOnDelete`) iki tam tarama yapıyor.
- **Düzeltme:** `rsvps.photo_media_id` ve `rsvps.video_media_id` kolonlarına index.

**D2. LCV listesi sayfalamasız**
- **Nerede:** `app/Http/Controllers/Api/V1/RsvpController.php:31-44`.
- **Sorun:** Gold ve Elit planlarında LCV sayısı sınırsız. 1.000 yanıtlı bir düğünde, 15 saniyede bir gelen her istek 1.000 satırı serileştirip özetliyor; yanıt 304 olsa bile bu hesap yapılıyor.
- **Düzeltme:** Sayfalama ya da `since`/cursor; ETag'i `MAX(updated_at)` + `COUNT` üzerinden üretmek.

**D3. Girişte IP'den bağımsız hesap kovası yok**
- **Nerede:** `app/Providers/AppServiceProvider.php:317-318`.
- **Sorun:** IP değiştirerek yapılan dağıtık bir parola denemesi (ör. IPv6 /64) tek bir hesaba sınırsız deneme yapabiliyor.
- **Düzeltme:** E-posta başına saatlik bir kova (hesap kilitleme DoS'una dikkat ederek).

**D4. Yayındaki davetiyede modül açılırken serbest sipariş kullanılmıyor**
- **Nerede:** `app/Actions/Invitation/UpdateInvitationAction.php:100`.
- **Sorun:** Elinde serbest kalmış bir Elit siparişi olan kullanıcı 402 alıyor ve aynı planı yeniden satın alıyor.
- **Düzeltme:** `ensureRaisedTierIsOwned` içinde `ClaimReleasedOrderAction`'ı da denemek.

**D5. Asistan kotası sağlayıcı hatasında da düşüyor**
- **Nerede:** `app/Actions/Assistant/AskAssistantAction.php:63-65`.
- **Sorun:** Gemini 502 ya da 503 döndüğünde kullanıcı günlük hakkından birini kaybediyor.
- **Düzeltme:** Sağlayıcı hatasında sayacı geri almak (yarış korumasını bozmadan).

**D6. Yayında içerik tamlığı kontrol edilmiyor**
- **Nerede:** `app/Http/Requests/Invitation/InvitationRequest.php:76`.
- **Sorun:** Koddaki yorum "eksiksizlik yayın anında aranır (Faz 7)" diyor, ama `PublishInvitationAction` bu kontrolü yapmıyor. Başlığı, tarihi ve isimleri boş bir davetiye yayınlanabiliyor.
- **Düzeltme:** Ya yayın anında zorunlu alan kontrolü eklemek ya da yorumu düzeltmek.

## API sözleşmesi uyumsuzlukları

**Uyumlu olanlar** (iki tarafta da kodu okuyarak doğrulandı):
- Hata kodu listesi (`contracts/error-codes.json`) iki depoda birebir aynı (satır sonları hariç). Frontend 21 kodun hepsini çeviriyor ve `verify:errors` geçiyor.
- 409 `INVITATION_ALREADY_PUBLISHED` frontend'de akış olarak ele alınıyor (`EditorWorkspace.tsx:74`). 400 `MALFORMED_REQUEST` ise yalnızca çevrilmiş bir hata metni olarak gösteriliyor.
- Auth yanıtı zarfsız `{user, token}`. Asistan yanıtı (`{data: {reply}}`) ve ödeme yanıtındaki alanlar (`orderId`, `tier`, `status`, `redirectUrl?`), LCV gövdesi (bot tuzağı alanı `website` dahil) ve hata zarfı (`{error: {code, fields?, params?}}`) iki tarafta aynı.
- Sipariş durumu listesi iki tarafta da `expired` değerini içeriyor.
- Davetiye güncellemesinde (`PUT /invitations/{id}`) 402 yalnızca yayındaki davetiyede ve gereken planı yükselten bir değişiklikte dönüyor; reddedilen istek veritabanına hiçbir şey yazmıyor. Frontend'in geri alma mantığı bu yüzden güvenli. Davetiye oluşturma (`POST /invitations`) hiçbir zaman 402 döndürmüyor; 402 ayrıca `/publish` ve `/checkout` uçlarından gelebiliyor. İki kod var: hiç ödenmiş hak yoksa `PAYMENT_REQUIRED`, plan yetmiyorsa `PAYWALL_TIER_INSUFFICIENT`; ikisinde de `params.requiredTier` geliyor.

**Hâlâ açık olanlar:**
1. Ödeme sonrası dönülen `/odeme/basarili` ve `/odeme/hata` rotaları frontend'de yok (`App.tsx:31-61`, 10.27). `FakeGateway.php:59` `redirectUrl`'i `/odeme/basarili?order=<ulid>` olarak kuruyor. O3'ün tetikleyicisi bu. Hata URL'i backend'de de hiçbir yerde kullanılmıyor.
2. Siparişlerin durumunu okuyan bir uç (`GET /api/orders`, `GET /api/orders/{order}`) yok ve davetiye yanıtında yayın tarihi (`publishedAt`) yok (10.21-10.25). Panel bu yüzden 3 günlük iade penceresini hesaplayamıyor (`DashboardPage.tsx:243`).
3. Frontend açılışta `/auth/me` çağırmıyor (10.29); Y3 ile birleşince riski artırıyor.
4. Galeri kotası backend'de 30, frontend'de (`GalleryUploader`) 8.
5. Büyük harfli id backend'in format kontrolünden (`whereUlid`) geçiyor ama kayıt bulunamıyor ve 404 dönüyor (10.63). Frontend id'yi küçük harfe çevirmiyor. Paylaşım linki küçük harfle kurulduğu için sorun yalnızca elle ya da QR koduyla büyük harf girilirse çıkıyor (Düşük).
6. Frontend `/public/places/search` ucunu çağırabiliyor (`VITE_PLACES_SEARCH_ENABLED=false` ile kapalı); backend'de bu uç yok.
7. `routes/api.php:269-275`'teki "frontend bugün `/api/contact` çağırıyor" yorumu eskimiş; frontend zaten `/public/contact` kullanıyor.
8. LCV fotoğrafı backend'de yalnızca `image/jpeg, png, webp`, video `mp4/quicktime` ve 20 MB; frontend `accept="image/*"` / `video/*` kullanıyor, yani HEIC fotoğraflar 422 alabilir (bilinen).

## Çalıştırdığım komutlar ve sonuçları

Bash'te `php` komutu bulunamadı; Herd'in PHP 8.5.11 kurulumunu (`C:\Users\ismail\.config\herd\bin\php85\php.exe`) kullandım.

| Komut | Sonuç |
|---|---|
| `vendor/bin/pint --test` | Geçti |
| `vendor/bin/phpstan analyse --memory-limit=1G` | Level 8, **0 hata**. Analiz hâlâ PHP 8.3'e göre yapılıyor (`phpVersion: 80300`); 8.5'e çekme işi bilinen 10.78. |
| `artisan errors:export --check` (salt okunur) | "Katalog güncel" |
| İki deponun hata kodu dosyalarının karşılaştırması | Aynı |
| Git geçmişinde sır taraması (`APP_KEY`, Gemini anahtarı, Sentry DSN, DB parolası) | Bulgu yok; `.env` gitignore'da ve takip edilmiyor |
| `git status` (başta ve sonda) | Temiz; hiçbir proje dosyasına dokunmadım |
| PHPUnit | **Çalıştırmadım** |

PHPUnit'i çalıştırmama nedeni: `phpunit.xml` izole bir `sqlite :memory:` değil, gerçek bir PostgreSQL veritabanı kullanıyor (`DB_CONNECTION=pgsql`, `DB_DATABASE=davetkart_test`, bağlantı bilgileri `.env`'den) ve `RefreshDatabase` her koşuda tabloları silip yeniden kuruyor. Talimat gereği gerçek bir veritabanına karşı test koşmadım. Depoda 307 test metodu (`#[Test]`) var.

**Faz 5 durumu:** `FAZ-5-OKUBENI.txt`'teki "hiçbir kalite kapısından geçmedi" ifadesi artık doğru değil. Pint, PHPStan (level 8) ve `errors:export --check` kontrollerinin üçü bu oturumda PHP 8.5.11 ile yeşil geçti. Test kapısı ise hâlâ açık: `claude/FAZ-10-PLANI.md` §9.1 yalnızca kum havuzunda (PHP 8.4 + PostgreSQL 16) yeşil kaydı veriyor. İsmail'in makinesinde (PHP 8.5 + PostgreSQL 18) `composer check` kaydı hâlâ yok; bu oturumda da yukarıdaki nedenle doğrulanamadı.
