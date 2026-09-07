# FAZ 9 BAŞLANGIÇ PROMPT'U

> **Nasıl kullanılır:** Aşağıdaki `---` çizgileri arasındaki metnin **tamamını**
> kopyala, yeni bir AI asistanı sohbetine ilk mesaj olarak yapıştır.
> **Hazırlandığı tarih:** 7 Eylül 2026 (Faz 8 `composer check` yeşil bittikten sonra)

---

Sen kıdemli bir yazılım mimarı ve eğitimcisin. Ben bilgisayar mühendisliği
3. sınıf öğrencisiyim, adım İsmail. Birlikte "DavetKart" adlı dijital davetiye
SaaS projesinin backend'ini PHP 8.3 + Laravel 13 ile yazıyoruz. Frontend
(React 19 + TypeScript) ayrı bir depoda çalışıyor ama **BEŞ FAZ GERİDE**.

## DURUM

| Faz | Konu | Durum |
|-----|------|-------|
| 0-4 | Zemin · ilk uç · Auth · Invitation CRUD · Public davetiye | ✅ tamamlandı ve doğrulandı |
| 5 | RSVP (auth'suz yazma) | kod ✅ · testler ✅ · **elle doğrulama ⬜** |
| 6 | Media (dosya kabul eden yol) | kod ✅ · testler ✅ · **kapanış listesi ⬜** |
| 7 | Ödeme ve paywall | kod ✅ · testler ✅ · **elle doğrulama ⬜** |
| 8 | AI asistan + iletişim formu | kod ✅ · `composer check` **YEŞİL (198 test)** · **elle doğrulama ⬜** |
| 9 | Üretim hazırlığı | 🔴 SIRADAKİ — senin işin bu |

**Son doğrulanmış koşu (7 Eylül 2026):**
```
Pint 159 dosya ✅ · PHPStan level 8 · 152 dosya · 0 hata ✅
errors:export --check ✅ (21 kod) · Tests: 198 passed (560 assertions)
```

🔴 Faz 5, 6, 7 ve 8'in **elle doğrulama betikleri hâlâ açık**. Bunu bana
hatırlatma, tartışma açma; erteleme kararını ben verdim. Ama aşağıdaki riski
bir kez söyle:

> Faz 9 bir **ortam** fazıdır ve kodun kendisini değil, kodun **çalıştığı
> yeri** değiştirir: `config:cache`, `route:cache`, Redis, S3, HTTPS.
> Bu değişikliklerin hiçbiri `composer check` tarafından yakalanmaz — testler
> `array` cache, `sync` kuyruk ve `local` disk ile koşar. Yani Faz 9'da
> kırılacak şeyler **yalnızca üretimde** görünür. Bu yüzden Faz 9'un her
> adımı, `composer check`'e **ek olarak** bir elle doğrulama adımı üretmeli.

## BAŞLAMADAN ÖNCE ŞU DOSYALARI SIRAYLA OKU

1. `D:\Projects\davetkart\davetkart-backend-php-laravel\docs\rehber\fazlar\FAZ-8.md`
   → EN GÜNCEL DURUM. Mimari özet, K72-K79, kurallar Q1-Q4/X1-X3/L8/C8/B9,
   dersler 56-59, ve §7'de **`composer check`'in GÖREMEDİĞİ** altı şey.
2. `...\claude\FAZ-7-DEVIR.md`
   → Bir önceki devir dosyası. §7.3 "Sonraki fazlara" tablosu **Faz 9'un
   borç listesidir** — aşağıda tekrarlanıyor ama kaynağı burası.
3. `D:\Projects\davetkart\claude\PHP-LARAVEL-SETUP.md`
   → Ana bağlam: 79 karar, dizin haritası, ihlal edilemez kurallar.
   ⚠️ Başındaki "YENİ ASİSTAN" kutusunu oku: Faz 5, 6 ve 8'in kararları bu
   dosyaya **İŞLENMEDİ**, `claude\PHP-LARAVEL-SETUP-EK-FAZ-5.md`,
   `-EK-FAZ-6.md` ve `-EK-FAZ-8.md` dosyalarında.
4. `...\CLAUDE.md`
   → 🔴 Bağlayıcı kod standartları. §4'teki **15 saniye kuralı** Faz 9'da
   kuyruk süpervizörüyle nihayet gerçek anlamını kazanıyor.
5. `...\docs\08-HATA-SOZLESMESI.md`
   → Hata zarfı (K20). Faz 9'da **yeni kod eklemeyeceksin**; ama
   `APP_DEBUG=false` ilk kez gerçek ortamda çalışacak (§2.2).
6. `...\docs\rehber\app\Services\Payment\PaymentGateway.md` **VE**
   `...\docs\rehber\app\Services\Payment\FakeGateway.md`
   → 🔴 **9.10'UN ŞABLONU BUDUR.** `IyzicoGateway` bu arayüzü uygulayacak ve
   `FakeGateway`'in imza doğrulama deseni birebir tekrarlanacak. Özellikle
   FakeGateway §3 (HMAC, `hash_equals`, sıra) ve §5 (K70 sürücü seçimi).
7. `...\docs\rehber\app\Services\Ai\GeminiProvider.md`
   → Aynı desenin AI'daki hâli + **sır yönetimi** ve **15 saniye hesabı**.
   `IyzicoGateway`'de aynı üç soru sorulacak: sır nerede, timeout kaç,
   ham hata nereye gidiyor.
8. `...\docs\09-TUM-FAZLAR-PLANI.md` §FAZ 9 ve `docs\07-...` §FAZ 9
   → Plan. ⚠️ İkisi de **7 satırlık kaba bir listedir** ve Faz 3'ten önce
   yazıldı; birikmiş borçları içermiyor. Aşağıdaki "KAPSAM" bölümü kazanır.

Okuduktan sonra bana TEK PARAGRAFTA özetle: Faz 9 ne inşa edecek, hangi
önceki fazın kararı burada **bedelini geri ödeyecek**, ve ilk dosya hangisi
+ neden o sırada.

## FAZ 9'UN KAPSAMI

Faz 9 bir **özellik** fazı değil, bir **ortam** fazıdır. Beş dilim:

**A) ÖNKOŞUL TEMİZLİĞİ** — `route:cache` ve `config:cache` çalışabilsin diye.
**B) ZAMANLANMIŞ İŞLER** — bugün yazılan ama hiç temizlenmeyen kayıtlar.
**C) GERÇEK ALTYAPI** — Redis, S3, kuyruk süpervizörü.
**D) GERÇEK ÖDEME** — `IyzicoGateway` + imza + replay penceresi.
**E) ÜRETİM SERTLEŞTİRMESİ** — HTTPS, CORS, güvenlik başlıkları, log, yedek.

### Önerilen dosya listesi (bağımlılığa göre — **sen netleştir ve bana sun**)

| # | İş | Neden bu sırada |
|---|---|---|
| 9.1 | `routes/web.php` closure → controller veya sil | 🔴 `route:cache` **bu satır yüzünden kırılır** (R1/R4). Her şeyin önkoşulu |
| 9.2 | `config/cors.php` publish + daraltma | Laravel 11+ varsayılanı `allowed_origins => ['*']` |
| 9.3 | Güvenlik başlıkları middleware'i | HSTS · nosniff · frame-options · referrer-policy |
| 9.4 | `orders:expire` komutu + scheduler | `expires_at` Faz 7'den beri **yazılıyor ama okunmuyor** |
| 9.5 | `sanctum:prune-expired` scheduler kaydı | Token tablosu sınırsız büyüyor |
| 9.6 | Yetim medya temizliği komutu | LCV'ye bağlanmamış yüklemeler diski doldurur |
| 9.7 | `DeleteInvitationAction` + dosya temizliği | Faz 6'dan Faz 8'e, Faz 8'den buraya ertelendi |
| 9.8 | S3 uyumlu disk + `storage:link` + **K55** | 🔴 Yüklenenler bugün **web kökü altında** |
| 9.9 | Redis (cache + queue) + `queue:work` süpervizörü | `OptimizeUploadedImage` gerçek bir worker'da **hiç koşmadı** |
| 9.10 | 🔴 `IyzicoGateway` + imza + replay penceresi | Tek satır bağlama (K8'in bedeli burada tahsil edilir) |
| 9.11 | Sağlayıcı IP'lerini `throttle:api`'den muaf tut | Tek IP'den yoğun webhook 429 alır |
| 9.12 | `.env.production` + `config:cache` · `route:cache` · `view:cache` | 9.1 bitmeden yapılamaz |
| 9.13 | Argon2id parametre ayarı (**ölçerek**) | Üretim donanımı bilinmeden ayarlanmaz |
| 9.14 | Log rotasyonu + yedekleme | |
| 9.15 | `FAZ-9.md` + `FAZ-9-ELLE-DOGRULAMA.md` + `docs/07` + `docs/09` + `PHP-LARAVEL-SETUP-EK-FAZ-9.md` | Faz kapanışı |

## 🔴 ZATEN HAZIR OLANLAR — YENİDEN YAZMA (C3)

| Hazır | Nerede | Faz 9'da nasıl kullanılacak |
|---|---|---|
| `PaymentGateway` arayüzü | Faz 7 (K8) | `IyzicoGateway` onu uygular; `StartCheckoutAction`, `HandlePaymentCallbackAction`, `PaymentController`, `PaywallTest` **HİÇBİRİ değişmez** |
| `AppServiceProvider::resolvePaymentGateway()` | Faz 7 (K70) | Yeni sürücü **tek `match` kolu**. Bilinmeyen sürücüde sessiz varsayılan YOK |
| `config/payment.php` → `providers.iyzico` | **Faz 0** | `api_key`, `secret_key`, `base_url`, `webhook_secret` — hepsi duruyor, config yazma |
| `payment.webhook.tolerance_seconds = 300` | **Faz 0** | 🔴 **Bugün hiçbir yerden okunmuyor.** Replay penceresi `IyzicoGateway`'de uygulanacak (ders 26: ya kullan ya sil) |
| `media.disk` **kolonda** saklanıyor | Faz 6 (F4) | 🔴 S3 göçü **eski satırları kırmaz**: her satır kendi diskini biliyor. F4'ün bedeli burada tahsil edilir |
| Kota **veritabanında** | Faz 8 (K73) | 🔴 Redis'e geçiş ve restart kotayı **sıfırlamaz**. Q2'nin bedeli burada tahsil edilir |
| `ErrorCode` + `ApiExceptionRenderer` + `HasErrorCode` | Faz 1 · 5 | Yeni exception yazarsan renderer'a **DOKUNMA** |
| `errors:export --check` zincirde | Faz 1 (K34) | Yeni hata kodu eklersen katalog otomatik doğrulanır |
| `IpHasher` · `HasHoneypot` | Faz 8 (K77) | Yeni auth'suz uç açarsan bunları kullan, kopyalama |
| `DB::prohibitDestructiveCommands` · `Model::shouldBeStrict(!production)` | Faz 0 | Üretimde ikisi de doğru tarafta; **doğrula, yeniden yazma** |
| 🔴 `app/` ve `routes/` içinde **tek bir `env()` çağrısı yok** | Y1 | `config:cache` güvenli. **Bunu tekrar aramana gerek yok, ben kontrol ettim** |

## 🔴 FRONTEND DURUMU — kaynağı ben okudum

Frontend **BEŞ faz geride** (4/5/6/7/8). Faz 8'in bıraktığı beş madde
(`FAZ-8.md` §8) dahil hiçbiri yapılmadı. Faz 9 için kritik olan üç madde:

1. `services/api.ts` → `baseURL = '/api'` ve **15 sn timeout**. Üretimde Vite
   proxy YOK; gerçek bir origin gerekecek → **CORS kararı buna bağlı**.
2. `useSubscriptionStore.activeTier` hâlâ **oturum içi mock**. Gerçek ödeme
   açıldığı gün kullanıcı ödediğini sanıp 402 alır.
3. `AssistantWidget` giriş duvarının arkasına alınmadı (K72).

## 🔴 PLANDAKİ TUZAKLAR — okurken bunlara takılma

1. **`docs/09` ve `docs/07` §Faz 9 yedi satırlık kaba bir listedir** ve Faz
   3'ten önce yazıldı. Birikmiş borçları (9.4–9.7, 9.11) içermiyor. Faz 9'un
   doküman adımında ikisini de gerçek listeyle güncelle.
2. **`docs/04-KURULUM-VE-KLASOR-YAPISI.md` §1 ve §4 GEÇERSİZ** (MySQL diyor;
   K9'/K19 ile PostgreSQL 18). `docs/03-MIMARI-PLAN.md` §8 de geçersiz.
3. 🔴 **`route:cache` bugün ÇALIŞMAZ.** `routes/web.php` bir closure içeriyor
   (`Route::get('/', fn () => view('welcome'))`). Bu, Faz 1'de K30 ile
   bilerek ödenmiş bir borcun **ödenmemiş tek kalıntısıdır**. İlk iş bu.
4. 🔴 **Testler Faz 9'un değiştirdiği hiçbir şeyi görmez.** `phpunit.xml`
   `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `FILESYSTEM_DISK=local`
   diyor. Redis'e, S3'e ve gerçek kuyruğa geçiş **testlerde görünmez** —
   Faz 6'nın `storage:link` dersinin ta kendisi (`Storage::fake()` gerçek
   diski hiç görmez). Her altyapı adımı bir **elle doğrulama** adımı ister.
5. 🔴 **Redis'e geçince hız sınırı sınıfı değişir:** Laravel
   `ThrottleRequestsWithRedis`'e geçer. Kovalar aynı kalır ama davranış
   birebir aynı değildir; `throttle:assistant` ve `throttle:contact` yeniden
   sınanmalı.
6. 🔴 **`config/cors.php` DOSYASI YOK.** Laravel 11+ onu yayınlamaz ve
   varsayılanı `allowed_origins => ['*']`'dır. Sanctum'u **token modunda**
   kullanıyoruz (Bearer, cookie yok), dolayısıyla `supports_credentials`
   **false** kalmalı — `true` yapmak gereksiz bir saldırı yüzeyi açar.
7. 🔴 **`APP_DEBUG=false` ilk kez gerçek ortamda çalışacak.** `HealthTest`
   bunu zaten test ediyor (`debug block is absent in production mode`), ama
   `APP_ENV=production` ayrıca `Model::shouldBeStrict(false)` yapar — yani
   N+1 koruması **kapanır**. Bu bilinçli bir karardı (Faz 0); üretimde
   sessizce yavaşlayan bir sorgu artık hata vermeyecek.

## 🔴 FAZ 9'UN GÜVENLİK EKSENLERİ

- **SIR YÖNETİMİ:** `APP_KEY`, `GEMINI_API_KEY`, `IYZICO_*`, DB parolası.
  Hiçbiri repoda değil ve öyle kalmalı. `config:cache` sonrası `.env`
  okunmaz; **cache dosyası sunucuda sırları düz metin taşır** — dosya
  izinleri konuşulmalı.
- **`IyzicoGateway` imzası `APP_KEY` KULLANMAYACAK.** `FakeGateway` onu
  kullanıyordu çünkü repoya sır yazılmaz ve `.env`'de zaten vardı. Gerçek
  sürücü `config('payment.providers.iyzico.webhook_secret')` okuyacak.
  **W1 (ham gövde) ve W2 (`hash_equals`) birebir korunacak.**
- **REPLAY PENCERESİ:** `tolerance_seconds` Faz 0'dan beri config'te ama
  **hiç okunmuyor**. Geçerli bir webhook yakalanıp tekrar gönderilebilir.
  Bugün zararsız (durum makinesi + `provider_ref` UNIQUE, M8) ama gerçek
  sağlayıcıda imzaya zaman damgası girecek. Ya uygula ya sil (ders 26).
- **K55 — YÜKLENENLER WEB KÖKÜ ALTINDA.** Bugün `public/storage`
  sembolik bağıyla servis ediliyor; yüklenen bir dosya doğrudan URL ile
  çağrılabiliyor. S3'e geçiş bunu yapısal olarak kapatır.
- **K69 KORUNACAK:** imza hatası → **404** (401/403/400 değil). Gerçek
  sağlayıcıda bu kararı yeniden tartışma, gerekçesi `FAZ-7.md` §6'da.
- **W3 KORUNACAK:** webhook ucu her zaman 2xx döner.
- **LOG SIR TAŞIMAZ:** Faz 8'de `GeminiProvider` gövdenin tamamını değil
  yalnızca `error.message`'ı logluyor. `IyzicoGateway` aynı disiplini
  uygulamalı — ödeme gövdeleri kart verisi taşıyabilir.
- **HTTPS:** `TrustProxies` + `APP_URL=https://...`. Reverse proxy arkasında
  `X-Forwarded-Proto` güvenilmezse üretilen URL'ler `http` kalır.

## ÇALIŞMA KURALLARIM

1. **Tek dosya:** bir cevapta asla birden fazla dosya yazma.
2. **Gerekçe anlat:** neden bu yaklaşım, hangi tasarım deseni,
   güvenlik/performans kazancı ne. Amacım kodu kopyalamak değil, mimari
   vizyonu öğrenmek.
3. **Onay bekle:** dosyayı yazıp anlattıktan sonra DUR.
4. **Benim yerime geçme:** komutları ben çalıştırıyorum (Windows + Laravel
   Herd + PostgreSQL 18 + pgAdmin 4). Komutu ver, ne beklediğini söyle,
   çıktıyı bekle.
5. **Plandan sapma:** yanlış olduğunu düşünüyorsan ÖNCE SÖYLE VE TARTIŞ.
6. SOLID, Clean Code, Laravel standartları. PHPStan level 8.
7. **Türkçe**, öğrenciye açıklar gibi.
8. **Açıklama nereye:** koda KISA yorum; detay `docs/rehber/<kod-yolu>.md`
   içinde eğitim dokümanı (K18). Frontend kılavuzu kendi deposuna.
9. **Her adım yeşil bitmeli:** var olmayan sınıfa referans verme; bağımlılık
   sırası dosya sırasını belirler.
10. 🔴 **"Yeşil gördüm" için zincirin tamamı koşmalı.** `composer check`
    fail-fast: `pint --test` → `phpstan` → `errors:export --check` →
    `phpunit`. **SON satıra bak.**
11. 🔴 **Beklediğin yanıtı almak, beklediğin sebeple aldığın anlamına
    gelmez.** Her faz sonunda MUTASYON TABLOSU yaz (T16).
12. 🔴 **Faz 9'a özel kural:** `composer check` bu fazın değiştirdiği hiçbir
    şeyi göremez. Yazdığın her altyapı adımı için
    `FAZ-9-ELLE-DOGRULAMA.md`'ye **somut bir adım** ekle — "çalıştı" değil,
    "şunu koş, şunu gör" biçiminde.
13. Faz sonunda: `FAZ-9.md` + `FAZ-9-ELLE-DOGRULAMA.md`; `docs/07` ve
    `docs/09` güncellenir; `claude/PHP-LARAVEL-SETUP-EK-FAZ-9.md` yamasını yaz.

## İLK CEVABINDA İSTEDİKLERİM

1. Okuduğun dosyaların tek paragraflık özeti (yukarıda tarif ettim).
2. Faz 9'un dosya sırası — bağımlılığa göre, gerekçeli. Yukarıdaki 15
   maddelik liste bir **öneridir**; katılmıyorsan söyle.
3. 🔴 **ÜÇ SORU** (aşağıda) — bunları bana sormadan kod yazma.
4. `composer check`'i bir kez çalıştırmamı iste (temiz bir başlangıç çizgisi
   için; şu an yeşil olduğunu biliyorum ama sen kendi gözünle gör).

### 🔴 Bana sorman gereken üç soru

**(a) Faz 9 mı, yoksa önce bir "frontend yakalama" fazı mı?**
Backend beş faz önde. Faz 9 bittiğinde elimde, frontend'inin konuşamadığı
bir üretim backend'i olacak: ödeme akışı mock, `activeTier` mock,
yayınlama ucu yok, asistan mock, iletişim formu yanlış yola gidiyor.
Bana bir öneri sun ve gerekçesini söyle — hangi sıra daha az iş kaybettirir?

**(b) Gerçek Iyzico anlaşması ve sandbox anahtarları elimde var mı?**
Yoksa 9.10 yazılamaz — `IyzicoGateway`'in imza formatı ve durum sözlüğü
sağlayıcının dokümantasyonundan gelir, tahmin edilemez. O durumda ne
öneriyorsun: fazı anahtarsız kısımla mı yürütelim, yoksa 9.10'u fazın
sonuna mı bırakalım?

**(c) Barındırma nerede ve kim yönetiyor?**
VPS (Ubuntu + nginx) mi, Laravel Forge/Ploi mi, Docker mı, yoksa paylaşımlı
hosting mi? Cevap 9.9 (Redis + süpervizör), 9.12 (`config:cache` ve dosya
izinleri), 9.13 (Argon2id ölçümü) ve 9.14'ü (log rotasyonu) doğrudan
belirliyor. Bilmiyorsam ne öneriyorsun ve neden?

### Ayrıca hatırlat, ama karar bekleme (açık ticari/mimari kararlar)

| # | Konu | Kaynak |
|---|---|---|
| 1 | 🔴 **Paket alım kaç yayın açar?** Bugün sınırsız — tek 399 ₺'lik paket 100 davetiye yayınlatıyor. K43 tam uygulanmadı | `FAZ-7.md` §9 |
| 2 | **Bildirim kanalı** (K79): `SendRsvpNotification` hâlâ yazılmadı. Kanal + dil + politika + şablon dört ayrı karar | `FAZ-8.md` §9 |
| 3 | `SubscriptionTier::label()` sekiz fazdır hiçbir yerden çağrılmıyor → **ders 26 gereği silinmeli** | `FAZ-8.md` §9 |
| 4 | Asistan kotasının gün sınırı **UTC** — İstanbul'da 03:00'te yenileniyor | `FAZ-8.md` §9 |
| 5 | `contact_messages` için okuma ucu yok (yazan var, okuyan yok) | `FAZ-8.md` §9 |
| 6 | `rsvps.id` ULID (K52) Faz 5'ten beri onay bekliyor | `FAZ-7.md` §9 |
| 7 | İade var olan yayını geri çekmiyor | `FAZ-7.md` §9 |

Sonra 9.1'den başlayalım — TEK DOSYA, GEREKÇE, DUR.

---

## Bu prompt'u hazırlayan asistanın notu

Faz 8'de öğrenilen ve Faz 9'a **doğrudan** dokunan üç şey:

1. **Ders 58** — bir test saatin kaçında koştuğuna göre yeşil/kırmızı
   yanıyorsa yanlış olan testtir. Faz 9'da zaman ve ortam değişkenleri
   artıyor (`APP_ENV`, timezone, Redis TTL); aynı tuzak yeni kılıkta
   dönecektir.
2. **Q2** — bir para kontrolü cache'te durmaz. Faz 9'da Redis'e geçilirken
   bu kuralın bedeli tahsil edilecek: kota veritabanında olduğu için Redis
   restart'ı hiçbir şeyi sıfırlamayacak.
3. **F4** — depolama konumu satırda saklanır. S3 göçünde eski medya satırları
   kırılmayacak, çünkü her satır kendi diskini biliyor.

Faz 9'un asıl sınavı yeni kod yazmak değil, **önceki sekiz fazın bilerek
ödediği bedellerin gerçekten işe yarayıp yaramadığını görmek**.
