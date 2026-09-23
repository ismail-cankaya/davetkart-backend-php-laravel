# DavetKart Backend — Gözden Geçirme Raporu

> **Tarih:** 23 Eylül 2026
> **Kapsam:** backend kodu (`app/`, `routes/`, `config/`, `bootstrap/`, migration'lar, testler),
> bütün plan ve devir dokümanları (`docs/`, `claude/`, bağlam deposundaki `PHP-LARAVEL-SETUP.md`)
> ve frontend'in backend'le konuştuğu her yer (`src/services`, `types.ts`, ilgili sayfalar).
> **Durum:** Backend "bitti" ilan edildi → `main` = `c85dbc9` (21 Eylül).

---

## Önce sınırlar (dürüst liste)

| | |
|---|---|
| **Kod dosyası** | Hiçbirine dokunulmadı. Bulgular raporlandı, düzeltme İsmail'in kararı |
| **`composer check`** | Koşturulamadı: bu oturumun ortamında PHP/Composer yok. Aşağıdaki her bulgu **kod okumasıyla** bulundu; test ya da mutasyonla doğrulanmadı (**B7**) |
| **Test sayısı** | `#[Test]` sayımı: **274** (268 Feature + 6 Unit). Son **kayıtlı** yeşil koşu 238 test (11 Eylül). Faz 9 sonrası 8 commit için kayıt yok. GitHub Actions sonucu buradan görülemedi |
| **Dokümanlar** | Güncellendi, commit'lenmedi — liste §8'de. `git diff` ile bakıp sen commit'le |

---

## 0. Özet

| Seviye | Adet | En önemlisi |
|---|:---:|---|
| 🔴 Kritik | 2 | Yayından sonra paywall aşılıyor · geç gelen ödeme sessizce kayboluyor |
| 🟠 Yüksek | 4 | Ödeme dönüş akışı yarım · token'lar hiç sona ermiyor · TrustProxies · parola sıfırlama/KVKK yok |
| 🟡 Orta | 8 | Sentry gürültüsü · fiyat sayfası vaatleri · PHP 8.5 kararı · hız sınırları · mükerrer LCV · Gemini · ai varsayılanı · kayıtsız kalite kapısı |
| 🟢 Düşük | 10 | Ölü dosya/config · `mapUrl` şeması · test güvenlik ağları · git hijyeni |

Genel tablo iyi: katman kuralları, sözleşme (zarf, hata kodu, 404, ULID), kilitler,
idempotans ve telafi desenleri kod genelinde tutarlı. Frontend yakalama fazı gerçekten
yapılmış: frontend'in çağırması gereken 21 ucun 20'si doğru çağrılıyor (eksik olan
`/auth/me`; webhook'u sağlayıcı çağırır). Kritik bulguların ikisi de **iki
ayrı doğru parçanın birleştiği yerde** duruyor, tek bir dosyada değil. Bu yüzden
testlerin hiçbiri onları görmüyor.

---

## 1. 🔴 Kritik

### 1.1 Yayından sonra paywall aşılıyor

**Nerede:** `app/Actions/Invitation/UpdateInvitationAction.php:29` · `app/Http/Resources/PublicInvitationResource.php:83-101` ·
frontend `pages/DashboardPage.tsx` ("Düzenle" düğmesi yayındaki kartta da var)

**Senaryo:**

```
1. Kullanıcı Standart (249 ₺) alır, galerisiz/hediyesiz bir davetiye yayınlar  → 200
2. Dashboard → "Düzenle" → editörde Galeri, Hediye/IBAN, Zaman çizelgesi, Zarf açılır
3. Autosave: PUT /api/invitations/{id} {showGallery:true, showGift:true, ...}   → 200
4. ClearInvitationCache → misafir sayfası artık Elit modüllerini gösteriyor
```

Paywall yalnızca **yayın anında** soruluyor (`PublishInvitationAction`). Güncelleme yolu
`TierResolver`'ı hiç çağırmıyor, `PublicInvitationResource` de modülü yalnızca `show_*`
bayrağına bakarak açıyor (C6), sahip olunan plana bakmıyor. DevTools gerekmiyor:
normal arayüz akışı yetiyor. 249 ₺ ödeyen biri 549 ₺'lik ürünü alıyor.

Testlerde yok: `PaywallTest`'in 51 testinin hiçbiri yayından sonra `PUT` atmıyor.

**Öneri (iki katman, ucuzdan pahalıya):**

1. **Yazma anında (asıl savunma):** `UpdateInvitationAction` içinde, davetiye
   `published` ise `fill()`'den sonra `TierResolver::requiredFor()` ile
   `PublishEntitlementResolver::highestTierFor()` karşılaştırılır. Yetmiyorsa
   `PaywallViolationException::insufficientTier()` (402) fırlatılır. Transaction geri
   alınır, kayıt değişmez. Frontend'in 402 → paywall akışı (`EditorWorkspace.tsx:71`)
   zaten var, yalnızca autosave yolunda da tetiklenmeli ve açılan anahtar geri alınmalı.
2. **Okuma anında (derinlemesine savunma, isteğe bağlı):** `PublicInvitationResource`
   modülü `show_x && sahip olunan plan kapsıyor` koşuluyla açar. Bu katman iadeyi de
   kapatır (açık karar #7: *"iade yayını geri çekmiyor"*). Bedeli: public okuma yolunda
   sipariş sorgusu, ve sipariş değişince cache'in temizlenmesi gerekir.

**Test önerisi:** `a_published_invitation_cannot_enable_a_module_above_its_tier` (402 +
DB'de bayrak `false` kalmalı, **T14**: yanıtı değil etkiyi doğrula) ve
`upgrading_the_order_lets_the_owner_enable_the_module`.

---

### 1.2 Geç gelen ödeme sessizce kayboluyor

**Nerede:** `app/Enums/OrderStatus.php:88` (`Failed, Refunded => false`) ·
`app/Actions/Payment/HandlePaymentCallbackAction.php:68` · `app/Console/Commands/ExpireStaleOrders.php`

**Senaryo:**

```
12:00  checkout → order pending, expires_at 12:30
12:29  kullanıcı 3D Secure'u bitirir, sağlayıcı parayı çeker
12:29  webhook denemesi başarısız (ağ, deploy, 429 — throttle:api 60/dk)
13:00  orders:expire → status = failed
13:05  sağlayıcı webhook'u tekrar dener: paid
       canTransitionTo(Failed → Paid) = false → return $order → 204
```

Kullanıcıdan para çekildi, yayın hakkı açılmadı ve **hiçbir yerde iz yok**: log
yazılmıyor, sağlayıcıya 204 dönüldüğü için retry de bitiyor. `ExpireStaleOrders`'ın
kılavuzu eşzamanlı yarışı (§2) doğru çözüyor ama bu **ardışık** durumu kapsamıyor.

Bu, Faz 9'un kendi dersinin (**E12**: *"bir kolonun anlamı, ona yazan tüm yolların
toplamıdır"*) ikinci örneği: `failed` bugün iki farklı gerçeği anlatıyor. Biri
*"sağlayıcı reddetti"* (kesin), diğeri *"biz beklemekten vazgeçtik"* (tahmin).
`orders.scope`'u doğuran sorunun aynısı.

**Öneri:**

- **Tercih edilen:** `OrderStatus::Expired` ekle (`orders:expire` bunu yazar), ve
  `Expired → Paid` geçişine izin ver. `Failed` sağlayıcının kararı olarak final kalır.
  CHECK kısıtı enum'dan beslendiği için (K39) migration küçük.
- **En az:** geçiş reddedildiğinde ve gelen durum `paid` ise `Log::critical(...)` bas
  (Sentry'ye de düşer), elle iade/eşleme yapılabilsin.

**Test önerisi:** `a_paid_webhook_after_expiry_still_grants_the_order` ve mutasyonu:
`Expired → Paid` kolunu sil → test kırılmalı.

---

## 2. 🟠 Yüksek

### 2.1 Ödeme dönüş akışı yarım

- `FakeGateway` kullanıcıyı `PAYMENT_SUCCESS_URL` = `/odeme/basarili?order=…`
  adresine yolluyor (`FakeGateway.php:59`). Frontend'de bu rota **yok**
  (`App.tsx` → `path: '*'` ana sayfaya atıyor). Kullanıcı ödemeden sonra hiçbir geri
  bildirim almadan ana sayfaya düşüyor.
- Backend'de sipariş durumunu soran bir uç **yok**. Dönüş sayfası yazılsa bile
  *"ödemen onaylandı, şimdi yayınla"* diyemez; webhook'un gelip gelmediğini bilemez.
  Aynı eksik, silmede (K82) *"hak serbest mi kaldı, yandı mı?"* sorusunu da cevapsız
  bırakıyor (Faz 9 açık kararı #9).

**Öneri:** `GET /api/orders/{order}` (sahip; `OrderResource` zaten var) ya da
`GET /api/orders` (*"siparişlerim"*). Frontend'e `/odeme/basarili` ve `/odeme/hata`
rotaları eklenir, sayfa `pending → paid` geçişini kısa süre yoklar, sonra yayın
düğmesini gösterir (K67: ödeme yayınlamaz). Aynı turda `InvitationResource`'a
`publishedAt` eklenmeli (frontend F7.2 bunu istiyor).

### 2.2 Token'lar hiç sona ermiyor, temizlik işi hiçbir şey silmiyor

**Nerede:** `config/sanctum.php:55` (`'expiration' => null`) · `routes/console.php:69`

`sanctum:prune-expired` iki sorgu çalıştırır: `expires_at < …` (bizim token'larımız
`expires_at` olmadan üretiliyor → hep `NULL`) ve `created_at < now − (expiration + saat)`
(yalnızca `expiration` doluysa). İkisi de hiçbir satıra eşleşmiyor. Sonuç: Faz 9'da
*"kapandı"* yazan tablo büyümesi sürüyor **ve** `localStorage`'dan çalınan bir token
sonsuza kadar geçerli. Ayrıca `console.php`'deki *"iptal edilen token bir ay saklanır"*
yorumu da doğru değil, çünkü `RevokeTokenAction` token'ı hemen siliyor.

**Öneri:** `'expiration' => 60 * 24 * 30` (30 gün, dakika). Frontend 401'de oturumu
zaten düşürüyor. İstenirse kayan pencere için `last_used_at`'e bakan bir kontrol.

### 2.3 `TrustProxies` ayarlı değil (barındırma yoluna bağlı)

`bootstrap/app.php`'de `trustProxies()` yok. İstek bir yük dengeleyiciden (AWS Yol B/C'de
ALB, CloudFront ya da Cloudflare) geliyorsa `$request->ip()` **dengeleyicinin IP'sini**
döner. Bu durumda:

- IP anahtarlı **bütün** kovalar (`throttle:api` 60/dk, `auth` 20/dk, `rsvp`, `media`,
  `contact`) tüm ziyaretçiler için **tek kovaya** düşer: site dakikada 60 istekte kilitlenir.
- `ip_hash` kolonları anlamsızlaşır.

`docs/10` bu ayarı yalnızca *"URL'ler http kalır"* gerekçesiyle anıyordu. Aynı sunucuda
nginx + php-fpm (Yol A) etkilenmez. **Öneri:** barındırma seçilince
`$middleware->trustProxies(at: [...])` ve bir `ip()` testi.

### 2.4 Hesap yaşam döngüsü eksik: parola sıfırlama, hesap silme, saklama süresi

Dokuz fazın hiçbirinde yoktu, açık listelerde de yazmıyor:

- **Parola sıfırlama yok.** Parolasını unutan kullanıcı, parasını ödediği davetiyeye
  bir daha erişemiyor. `docs/08` §3.1 davranışı tasarlamış (`202`, enumeration yok);
  uç yazılmamış. Mail kanalı kararına (K79) bağlı.
- **E-posta doğrulama yok.** Başkasının adresiyle kayıt olunabiliyor.
- **Hesap silme yok, saklama süresi yok (KVKK).** Davetiye yalnızca soft delete ediliyor;
  misafirlerin adları, mesajları, fotoğraf/videoları ve `contact_messages`'taki e-postalar
  **süresiz** kalıyor. Kodda `forceDelete()` yok. Lansmandan önce bir saklama politikası
  (ör. etkinlikten N ay sonra sil) ve hesap silme ucu gerekiyor.

---

## 3. Frontend ↔ backend sözleşmesi

`davetkart-frontent/src` okunarak kontrol edildi. `contracts/error-codes.json` iki depoda
**birebir aynı** (21 kod).

| Uç | Frontend | Durum |
|---|---|:---:|
| `POST /auth/register` · `/auth/login` · `/auth/logout` | `services/auth.ts` | ✅ |
| `GET /auth/me` | **çağrılmıyor**. Oturum yalnızca `localStorage`'dan | ⚠️ |
| `GET/POST /invitations` · `GET/PUT/DELETE /invitations/{id}` | `services/invitations.ts` | ✅ |
| `POST /invitations/{id}/publish` | `invitations.ts` + iki ayrı 402 ekranı | ✅ |
| `POST /invitations/{id}/checkout` · `/payments/checkout` | `services/payments.ts` | ✅ (paket için arayüz yok) |
| `POST /invitations/{id}/media` · `DELETE …/media/{media}` · `POST /public/…/media` | `services/media.ts` | ✅ |
| `GET /invitations/{id}/rsvps` (ETag) · `POST /public/…/rsvps` · `DELETE /rsvps/{id}` | `services/rsvps.ts` + `conditionalGet.ts` | ✅ |
| `GET /public/invitations/{id}` (ETag) | `publicInvitation.ts` | ✅ |
| `POST /assistant/chat` | `assistant.ts` (giriş duvarlı) | ✅ |
| `POST /public/contact` (honeypot) | `contact.ts` · `ContactPage` | ✅ |
| `GET /public/places/search` | `places.ts` | ❌ **backend'de yok**. `VITE_PLACES_SEARCH_ENABLED=false` ile kapalı |

**Açık kalan uyumsuzluklar:**

| Konu | Backend | Frontend | Ne yapılmalı |
|---|---|---|---|
| Ödeme dönüşü | `/odeme/basarili` | Rota yok | §2.1 |
| Sipariş durumu, `publishedAt` | Uç/alan yok | Tarihsiz silme uyarısı | §2.1 |
| Yayındaki davetiyeyi düzenleme | Kontrol yok | "Düzenle" serbest | §1.1 |
| Galeri kotası | 30 | `GalleryUploader.tsx:13` → 8 | Tek kaynak seç. Bugün frontend daha dar, zararsız |
| "Logosuz özel yayın" (Elit) | Sinyal yok | `InvitationComposition.tsx:171` her zaman *"DavetKart ile hazırlandı"* | §5.2 |
| LCV fotoğrafı | `image/jpeg,png,webp` | `accept="image/*"` (HEIC gelebilir) | Sıkıştırma katmanı JPEG'e çeviremezse 422. Kabul listesi eşitlenmeli |
| LCV videosu | 20 MB, `mp4/quicktime` | `accept="video/*"`, sıkıştırma yok | Telefonda 15-20 sn'lik 1080p video bu sınırı aşar. §5.4 |
| F8 betiği #11 | LCV honeypot'u **kasıtlı olarak 201** döner, gerçek yanıttan ayırt edilemeyen sahte bir kayıtla (`RsvpTest::honeypot_submission_looks_successful`) | `F8-DOGRULAMA.md` #11 beklentiyi *"204; 201 dönüyorsa tuzak kurulmamış"* diye yazmış ve sonuç tablosuna **✅ 204** kaydetmiş | Kayıt kodla çelişiyor: ya başka bir uç denendi ya sonuç gözlenmeden yazıldı (**B7**). Beklenti *"201 + kayıt oluşmaz"* olarak düzeltilip adım yeniden koşulmalı |

---

## 4. Ödeme sağlayıcısı: Shopier ve `PaymentGateway`

Bütün plan dokümanları `IyzicoGateway` diyor. Eylül 2026'da **Shopier** hesabı açıldı.
Mimari (K8, Strategy) bu değişikliği kaldırır, ama arayüzün üç yeri bugünkü hâliyle
Shopier'in **klasik** akışına uymuyor:

| Bugünkü varsayım | Shopier (klasik ödeme formu akışı) |
|---|---|
| `startCheckout()` bir **`redirectUrl`** döner | Ödeme sayfası, imzalı alanlar taşıyan bir **form POST**'u ile açılır. `CheckoutSession` "URL" yerine "form alanları + hedef" taşıyabilmeli |
| İmza bir **HTTP başlığında** (`payment.webhook.signature_header = X-Signature`), `parseNotification(string $payload, string $signature)` | Geri dönüşte imza **form gövdesinde** gelir (`signature` alanı). `random_nr`, `platform_order_id`, tutar ve para biriminin birleşimi API sırrıyla HMAC-SHA256'lanıp base64'lenir. Arayüz `Request` almalı ya da imzayı çıkarmak sürücünün işi olmalı |
| Bildirim sunucudan sunucuya bir **webhook** | Klasik geri dönüş kullanıcının **tarayıcısı** üzerinden callback URL'ine POST edilir. Uç 204 yerine kullanıcıyı frontend'e yönlendirmeli. Tarayıcı kapanırsa bildirim hiç gelmeyebilir (§1.2 daha da önemli olur) |

Ek notlar:

- Shopier'in daha yeni REST API'si webhook'ları `Shopier-Signature` başlığıyla imzalıyor,
  ama bu API mağaza/sipariş yönetimi için. Harici site ödemesi için hangisinin
  kullanılacağı **Shopier panelindeki güncel dokümanla doğrulanmalı**. Yukarıdaki tablo
  topluluk örneklerinden derlendi, resmi dokümandan değil.
- `platform_order_id` olarak bizim `orders.id` (ULID) verilebilir. `provider_ref` UNIQUE
  kuralı (M8) aynen korunur.
- Shopier alıcı adı/e-posta/telefon/adres alanları isteyebilir. `users` tablosunda telefon
  ve adres yok.
- `hash_equals` (W2) aynen korunur. W1'in (*"ham gövde"*) karşılığı *"alanların belgelenmiş
  birleşim sırası"* olur.
- `config/payment.php`, `.env.example` ve `docs/10`'daki `IYZICO_*` satırları o gün
  değişecek (`docs/10`'a not düşüldü).

---

## 5. 🟡 Orta

### 5.1 Sentry'ye 4xx iş istisnaları da gidiyor

`bootstrap/app.php:57` → `Integration::handles()` Laravel'in raporladığı her şeyi
Sentry'ye yollar. `HasErrorCode` uygulayan 11 istisnanın hiçbiri `dontReport` listesinde
değil. Yani **her yanlış parola** (`InvalidCredentialsException`), her 402, her kota
aşımı ve webhook'u tarayan her bot bir Sentry olayı ve `laravel.log`'da yığın izli bir
`ERROR` satırı demek. Kota yer, gerçek 500'leri gömer.
**Öneri:** `$exceptions->dontReportWhen(fn ($e) => $e instanceof HasErrorCode && $e->errorCode()->status() < 500);`
(`PaymentProviderException` ve `AiProviderException` raporlanmaya devam eder.)

### 5.2 Fiyat sayfası, kodda olmayan özellik satıyor

`data.ts`'teki plan kartları ile sunucudaki kurallar (`module_tiers`) karşılaştırıldı:

| Vaat | Durum |
|---|---|
| Elit: *"Logosuz özel yayın"* | Uygulanmamış. Alt bilgi her planda görünüyor, public yanıtta sinyal yok |
| Gold/Elit: *"Premium tema koleksiyonu"* | Sunucuda şablon (`preset_id`) plana bağlı değil. Standart ile her tema yayınlanabiliyor |
| Elit: *"Fotoğraf & Video galerisi"* | Galeri yalnızca görsel kabul ediyor (`gallery.mimes`) |

Parası alınan ama verilmeyen özellik tüketici şikâyeti riski taşır. Ya uygulanmalı ya da
fiyat sayfasından kaldırılmalı. Logosuz yayın için en küçük adım: public yanıta
`showBranding` gibi bir bayrak (sahip olunan plandan türetilir).

### 5.3 PHP `^8.5` kararı kayda geçmedi

20 Eylül'de `composer.json` ve CI `^8.5`'e çıktı. Gerekçe yok (K1 hâlâ *"8.3+"*),
`phpstan.neon` hâlâ `phpVersion: 80300`, CI yorumu hâlâ *"^8.3 ile eşleşen"* diyor. Kodda
8.4/8.5'e özgü bir özellik bulunamadı (pipe operatörü, asimetrik görünürlük,
`array_find`/`array_any`, `new X()->y()` gibi kalıplar grep'le arandı). Yani yükseltme bugün yalnızca barındırma
seçeneklerini daraltıyor (AWS Yol C / Elastic Beanstalk platformunun 8.5 desteği
doğrulanmalı). **Karar:** hedef 8.5 ise `phpVersion: 80500` + K1 güncellemesi, değilse
`^8.3`'e dönüş.

### 5.4 Hız sınırları gerçek kullanımda dar olabilir (ölçülmeli)

- **LCV:** davetiye başına saatte **60** (`davetkart.rsvp.rate_limit`). Link büyük bir
  aile WhatsApp grubunda paylaşıldığında ilk saatte 60'tan fazla yanıt gelmesi olası.
  Aynı kova, IP değiştiren kötü niyetli birinin tek bir düğünün LCV'sini bir saat
  kilitlemesine de izin veriyor.
- **Misafir medyası:** IP başına dakikada **5**, davetiye başına saatte **40**. Düğün
  salonunun Wi-Fi'ı ya da mobil operatör CGNAT'ı arkasında onlarca misafir **tek IP**'dir.
  Asistan için bu gerekçe açıkça yazılmıştı (CGNAT), misafir medyası için uygulanmamış.
- **Video:** 20 MB ve sunucuda sıkıştırma yok. Telefonla çekilen kısa bir 1080p video bile
  sınırı aşar.

Bunlar tasarım gereği tavizler olabilir; ama hiçbiri ölçülmedi. Lansmandan önce gerçek
bir etkinlik senaryosuyla denenmeli.

### 5.5 Aynı misafirin ikinci LCV'si ayrı satır olarak sayılıyor

Misafir fikrini değiştirip formu tekrar gönderirse yeni bir satır oluşur. `SUM(guest_count)`
kotası ve paneldeki toplam **ikisini de** sayar. Misafir tarafında düzenleme yok, sahip
tek tek siliyor. Ürün kararı: misafire bir *"yanıt kodu"* verip güncelleme mi, yoksa en
azından panelde aynı adı gruplama mı?

### 5.6 Gemini: model erişimi ve düşünme bütçesi modele bağlı

- Google'ın deprecation sayfası `gemini-2.5-flash` için kapanış tarihi vermiyor, ama 2.5
  modellerine erişimi *"daha önce kullanmış"* kullanıcılarla sınırladığını ve yeni projeler
  için 3.x modellerini önerdiğini yazıyor. Yeni bir üretim anahtarıyla ilk gün denenmeli.
- `thinking_budget: 0` Flash'a özgü. Pro'da 0 geçersiz, 3.x `thinkingLevel` bekliyor.
  Model adı değişirse bu satır sessizce 400 üretir.

### 5.7 `AI_PROVIDER` varsayılanı ile belgesi ayrışmış

`config/ai.php:15` → `env('AI_PROVIDER', 'gemini')`. `.env.example` ise *"varsayılan
`null`'dır, yapılandırma eksiği para harcamasın"* diyor. Anahtar yoksa `GeminiProvider`
zaten 503 verdiği için para harcanmıyor, ama iki kaynak farklı şey söylüyor (B4). Kod
varsayılanı `null` yapılmalı ya da yorum düzeltilmeli.

### 5.8 Faz 9 sonrası 8 commit kalite kapısından geçmiş görünmüyor

Galeri silme, `OptimizeUploadedImage`'in 600 satırlık yeniden yazımı, PHP 8.5, Sentry ve
Gemini commit'leri için `composer check` sonucu hiçbir dokümana yazılmamış. Sentry ve
Gemini commit'leri **kılavuzsuz** eklenmişti (K18). Kılavuzlar bu gözden geçirmede
yazıldı. **İlk iş:** `composer check` → son satırı kayda geçir.

---

## 6. 🟢 Düşük / temizlik

| # | Bulgu | Öneri |
|---|---|---|
| 1 | `routes/web.php` ve `resources/views/welcome.blade.php` **silinmemiş**. 9.1 yalnızca yüklenmesini kapattı, ama dört doküman *"silindi"* diyor | `git rm` (dokümanlar o zaman doğru olur). Laravel'in frontend iskeleti de ölü: `resources/css`, `resources/js`, `vite.config.js`, `package.json`, `composer.json`'daki `setup`/`dev` betiklerinin npm adımları |
| 2 | Ölü config: `davetkart.auth.*` (`token_name`, `login_rate_limit_per_minute`, kodda sabitler kullanılıyor), `payment.webhook.tolerance_seconds` (bilinen), `rsvp.poll_interval_seconds` (yalnızca yorumlarda) | Ders 26: ya kullan ya sil |
| 3 | `invitation.mapUrl` için `url` kuralı `http/https` dışında ~200 şema kabul ediyor (`file://`, `data://`…). Değer misafir sayfasında `<a href>` oluyor | `url:http,https` |
| 4 | `phpunit.xml` `SENTRY_LARAVEL_DSN`'i boşaltmıyor. Yerel `.env`'e DSN yazılırsa test istisnaları Sentry'ye gider | `<env name="SENTRY_LARAVEL_DSN" value=""/>` |
| 5 | `TestCase`'te `Http::preventStrayRequests()` yok. Sağlayıcıyı bağlamayı unutan bir test gerçek Gemini'ye gidebilir | `setUp()`'a tek satır |
| 6 | `composer.json` `ext-pdo_pgsql` istemiyor (`ext-gd`, `ext-exif` var) | Platform gereksinimine ekle |
| 7 | LCV'de misafir, **aynı davetiyedeki başka bir misafirin** yüklediği medya kimliğini kendi yanıtına iliştirebilir (yalnızca davetiye + tür kontrol ediliyor). ULID tahmin edilemediği için pratik risk düşük | İstersen "henüz bir LCV'ye bağlanmamış" koşulu |
| 8 | K18: kılavuzsuz dosyalar. `tests/Feature/HardeningTest.php`, `MaintenanceTest.php`, küçük DTO'lar (`CheckoutResult`, `CheckoutSession`, `PaymentNotification`, `OptimizedImage`) | Testler için kılavuz yaz. DTO'lar üreticinin kılavuzunda anlatılıyor, yeterli |
| 9 | Git hijyeni: bağlam deposu (`claude/`) 4 Eylül'den beri commit'siz ve master'da commit'lenmemiş değişiklik vardı. Backend'de 4 ajan worktree'si, `Enums` gibi 178 commit geride dallar, `payment-servide` yazım hatası. Frontend'de ~530 dosya yalnızca CRLF farkıyla "değişmiş" görünüyor | Bağlam deposunu commit'le. Eski dal/worktree'leri temizle. Frontend için `.gitattributes` kararı |
| 10 | `D:\Projects\davetkart\Claude outputs\FRONTEND-YAKALAMA-PLANI.md` frontend'deki planın **eski** kopyası (24 KB ↔ 32 KB) | Sil. Güncel olan `davetkart-frontent/docs/` altında |

---

## 7. Plan ve dokümanlarda eskiyen yerler (bulunan)

| Doküman | Eskiyen | Düzeltildi mi |
|---|---|:---:|
| `claude/FAZ-9-DEVIR.md` | Frontend *"altı faz geride"*, 21 uç, dal `faz-9`, PHP 8.3, Iyzico | ✅ |
| Bağlam `PHP-LARAVEL-SETUP.md` | Başlık durumu, §4 harita (FAZ-7-DEVIR *"en güncel"*), §7 K1/K14, §9 Faz 9 sonrası yok, §10 *"Sıradaki: Faz 7'yi kapat"*, §12, §14, §15 | ✅ |
| `docs/07` | PHP 8.3, §5 *"composer check koşmadı"*, §6 *"yetki hatası 403"* (H7 ile çelişki), §7 *"Son güncelleme 4 Eylül"*, §8'de ikinci bir **K18** (Pest), mükerrer Faz 6 satırı | ✅ |
| `docs/09` | *"7 tablo · 20 uç"*, faz tablosunda Faz 6 *"SIRADAKİ"*, 7-9 ⬜ | ✅ |
| `docs/10` | Sentry, `GEMINI_MODEL`, PHP 8.5, TrustProxies'in IP etkisi, Shopier yok | ✅ |
| `docs/11` | PHP 8.3, 238 test, 21 uç (galeri silme yok), §5.2 frontend riski, webhook *"her zaman 204"* | ✅ |
| `docs/03` · `docs/04` · `docs/06` | *"Onay bekliyor"*, MySQL, PHP 8.3 | ✅ banner/satır |
| `CLAUDE.md` | PHP 8.3+ | ✅ |
| `README.md` | Laravel'in varsayılan README'si | ✅ proje README'si yazıldı |
| `rehber/fazlar/FAZ-9.md` · `fazlar/README.md` | *"composer check koşmadı"*, *"web.php silindi"*, Faz 6-9 ⬜ | ✅ |
| `rehber/bootstrap/app.md` | Faz 1'den beri güncellenmemiş: `throttleApi`, `SecurityHeaders`, `web:` kaldırılması, Sentry | ✅ §2.6 |
| `rehber/config/ai.md` · `GeminiProvider.md` | timeout 10 sn (kod 6), `thinking_budget` yok | ✅ |
| `rehber/config/sentry.md` | **Yoktu** | ✅ yazıldı |
| `rehber/routes/console.md` | `sanctum:prune-expired` işe yarıyor sanılıyor | ✅ uyarı |
| `rehber/phpstan.md` · `config/payment.md` | 8.5 / Shopier notu | ✅ |
| Frontend `F8-DOGRULAMA.md`, `FRONTEND-YAKALAMA-PLANI.md` başlığı | LCV honeypot 201, *"238 test"* | ⬜ **dokunulmadı** (CRLF: dosyayı yeniden yazmak bütün satırları değiştirirdi) |
| `claude/PHP-LARAVEL-SETUP-EK-FAZ-5…9.md` | Master'a işlenmeyi bekliyor (bilinen) | ⬜ ayrı iş |
| Proje hafızası `davetkart-git-ve-ortam` | *"device_bash çalışmıyor (13 Eylül)"*. Bugün çalışıyor | ⬜ |

---

## 8. Değiştirilen dosyalar (commit'lenmedi)

**`davetkart-backend-php-laravel`:**
`CLAUDE.md` · `README.md` · `claude/FAZ-9-DEVIR.md` · `claude/GOZDEN-GECIRME-RAPORU.md` (yeni) ·
`docs/03-MIMARI-PLAN.md` · `docs/04-KURULUM-VE-KLASOR-YAPISI.md` · `docs/06-PHP-LARAVEL-HERD-NASIL-CALISIR.md` ·
`docs/07-GELISTIRME-YOL-HARITASI.md` · `docs/09-TUM-FAZLAR-PLANI.md` · `docs/10-URETIM-ENV-SABLONU.md` ·
`docs/11-PROJE-TARIHCESI-VE-IS-AKISLARI.md` · `docs/rehber/bootstrap/app.md` · `docs/rehber/config/ai.md` ·
`docs/rehber/config/payment.md` · `docs/rehber/config/sentry.md` (yeni) · `docs/rehber/app/Services/Ai/GeminiProvider.md` ·
`docs/rehber/phpstan.md` · `docs/rehber/routes/console.md` · `docs/rehber/fazlar/FAZ-9.md` · `docs/rehber/fazlar/README.md`

**`claude` (bağlam deposu):** `PHP-LARAVEL-SETUP.md`

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
git status ; git diff --stat
cd ..\claude ; git diff --stat
```

---

## 9. Senin kararın gereken sorular

1. **§1.1** Paywall yazma anında mı (önerilen), okuma anında mı, ikisi birden mi?
2. **§1.2** `OrderStatus::Expired` eklensin mi, yoksa `Failed → Paid` açılıp log mu yeter?
3. **§2.2** Token ömrü kaç gün? (öneri 30)
4. **§5.3** Hedef gerçekten PHP 8.5 mi? Neden?
5. **§5.2** "Logosuz yayın", "premium tema", "video galerisi" yazılacak mı, fiyat sayfasından mı kalkacak?
6. **§4** Shopier'in hangi API'si (klasik form mu, REST mi)?
7. Eski açıklar hâlâ cevapsız: paket alım kaç yayın açar (K43), bildirim kanalı (K79),
   `SubscriptionTier::label()` silinsin mi, asistan kotası UTC mi İstanbul mu.

---

## 10. Önerilen sıra

```
1. composer check              ← Faz 9 sonrası ilk kayıtlı koşu (PHP 8.5 + Sentry)
2. §1.1 paywall + §1.2 expired ← ikisi de gerçek ödeme açılmadan kapanmalı
3. §2.1 sipariş durumu ucu + publishedAt + frontend dönüş sayfası
4. §2.2 token ömrü · §5.1 Sentry dontReport · §6 temizlik (tek commit'lik işler)
5. Faz 5-9 elle doğrulama betikleri + frontend F8 (17 senaryo)
6. Shopier entegrasyonu (§4) → parola sıfırlama + mail kanalı (K79) → KVKK saklama/silme
```

---

### Kaynaklar (web, 23 Eylül 2026)

- Shopier klasik callback doğrulaması (topluluk örneği): [benfiratkaya/shopier-bakiye — callback.php](https://github.com/benfiratkaya/shopier-bakiye/blob/master/callback.php)
- Shopier REST API ve `Shopier-Signature` webhook'ları: [AdisGroup/shopier-go](https://github.com/AdisGroup/shopier-go)
- Gemini model kapanışları ve 2.5 erişim sınırı: [Gemini API — Deprecations](https://ai.google.dev/gemini-api/docs/deprecations)
