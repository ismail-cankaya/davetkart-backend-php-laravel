# DavetKart — Devir Dosyası (Faz 6 sonu)

> **Tarih:** 29 Ağustos 2026
> **Kimin için:** Bu projede **ilk kez** çalışacak bir AI asistanı
> **Ne kadar sürer:** Bu dosya + `CLAUDE.md` = ~15 dakika. Sonra çalışabilirsin.
> **Öncekiler:** `FAZ-5-DEVIR.md` (hâlâ geçerli bağlam taşır)

---

## 0. Otuz saniyede proje

**DavetKart**, dijital davetiye SaaS'ı. Kullanıcı davetiye tasarlar, yayınlar,
linkini paylaşır; misafirler linkten davetiyeyi görür ve "katılıyorum /
katılamıyorum" der (LCV = RSVP), isterse fotoğraf/video ekler.

| | |
|---|---|
| **Backend** | PHP 8.3+ · Laravel 13 · PostgreSQL 18 · Sanctum · Modüler Monolit |
| **Frontend** | React 19 · TypeScript · Vite · Zustand · **ayrı depo** |
| **Geliştirici** | İsmail — bilgisayar mühendisliği 3. sınıf öğrencisi |
| **Amaç** | Kod üretmek değil, **mimari vizyon öğretmek** |
| **Yöntem** | 9 fazlık dikey dilimler; her faz uçtan uca çalışan bir özellik |

**Şu an:** Faz 0-4 ✅ · Faz 5 kod ✅ / elle doğrulama ⬜ · **Faz 6 kod ✅ /
6.15+ doğrulanmadı** · Sıradaki: **Faz 6'yı kapat, sonra Faz 7.**

---

## 1. 🔴 İsmail'in çalışma kuralları

| # | Kural |
|---|---|
| 1 | **Tek dosya:** bir cevapta asla birden fazla dosya yazma |
| 2 | **Gerekçe anlat:** neden bu yaklaşım, hangi desen, güvenlik/performans kazancı ne |
| 3 | **Onay bekle:** dosyayı yazıp anlattıktan sonra DUR |
| 4 | **Onun yerine geçme:** komutları İsmail çalıştırır (Windows + Laravel Herd) |
| 5 | **Plandan sapma:** yanlış olduğunu düşünüyorsan **önce söyle ve tartış** |
| 6 | SOLID, Clean Code, Laravel standartları |
| 7 | **Türkçe**, öğrenciye açıklar gibi |
| 8 | **Açıklama nereye:** koda kısa yorum; detay `docs/rehber/<kod-yolu>.md` |
| 9 | **Ritim:** komut ver → kod → kılavuz → `composer check` → DUR |
| 10 | **Her adım yeşil bitmeli:** var olmayan sınıfa referans verme |
| 11 | **Tahmin yürütme, kaynağa bak:** `vendor/` okunabilir |
| 12 | **Her faz sonunda:** `FAZ-N.md` + `FAZ-N-ELLE-DOGRULAMA.md`; `docs/07`, `docs/09`, `claude/` güncellenir |
| 13 | **"Yeşil gördüm" için zincirin tamamı koşmalı.** `composer check` fail-fast — **SON** satıra bak |
| 14 | **Beklediğin yanıtı almak, beklediğin sebeple aldığın anlamına gelmez.** Mutasyon sor |

> ⚠️ **Faz 6'nın son bölümünde (6.15+) kural 1 ve 3 İsmail'in açık talebiyle
> askıya alındı** — tek oturumda fazı bitirmek istedi. Varsayılan yine
> yukarıdaki hâlidir.
>
> ⚠️ **`CLAUDE.md` §1'in *"controller'da `if` bulunamaz"* kuralı Faz 6'da
> GEVŞETİLDİ** (İsmail'in kararı). Gerektiğinde `if` yazılabilir. 🔴 Ama dosyaya
> **henüz işlenmedi** — B4 borcu.

---

## 2. Okuma sırası

```
1. claude/PHP-LARAVEL-SETUP.md          ← ANA GİRİŞ: kararlar, dersler, harita
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-5.md   (K49-K53, L1-L4 — master'a işlenmediyse)
   + claude/PHP-LARAVEL-SETUP-EK-FAZ-6.md   (K54-K63, F1-F5 — master'a işlenmediyse)
2. CLAUDE.md                            ← bağlayıcı kod standartları
3. docs/08-HATA-SOZLESMESI.md           ← API hata sözleşmesi (K20)
4. docs/rehber/fazlar/FAZ-0.md … FAZ-6.md
5. docs/07-GELISTIRME-YOL-HARITASI.md · docs/09-TUM-FAZLAR-PLANI.md
```

> 🔴 `docs/04-KURULUM...` §1 ve §4 **GEÇERSİZ** (MySQL diyor; K9'/K19 ile
> PostgreSQL 18). `docs/03-MIMARI-PLAN.md` §8 **GEÇERSİZ** (12 adım → 9 faz).

---

## 3. Mimarinin özeti

**Repository Pattern ve Fat Service YASAK.** Yerine **Action-Based
Architecture**:

```
rota → FormRequest → Controller → Action → Model → Resource → yanıt
```

| Katman | Sorumluluk | Yasak |
|---|---|---|
| `app/Http/Requests/` | Doğrulama, camelCase→snake_case | İş kuralı |
| `app/Http/Controllers/Api/V1/` | Action'a yönlendir, Resource döndür (3-8 satır) | İş mantığı |
| `app/Actions/` | Tek eylem, iş kuralı, DB + yan görevler | HTTP yanıtı, doğrulama |
| `app/Models/` | `#[Fillable]` beyaz listesi, cast, ilişki | İş kuralı |
| `app/Http/Resources/` | snake→camel, **beyaz liste** | Sihirli dönüşüm |
| `app/Policies/` | Sahiplik / IDOR | Sorgu |
| `app/Enums/` | Sihirli string yasağı | — |

**Sözleşme (ihlal edilirse frontend kırılır):**

- Rotalar `/api/...` — **`/api/v1/...` değil**
- Auth yanıtları **zarfsız** `{user, token}`; diğerleri `{data: ...}`
- Hata: `{error: {code, fields?, params?}}` — **metin yok** (K20/K21)
- Alan adları camelCase; dönüşüm **yalnızca** Resource'ta
- Sahiplik yoksa **404**, 403 değil (H7)
- `id` alanları **string** (ULID)

---

## 4. Bugünkü teknik durum

| | |
|---|---|
| Dal | `faz-5` — Faz 6'nın 25 commit'i bu dalda |
| Uç nokta | **15** |
| Test | **123** (95 doğrulanmış + 28 doğrulanmamış) |
| PHPStan | level **8** (Faz 6'da ilk kez gerçekten koştu) |
| Kural | **117** · **Karar** 63 · **Ders** 49 |
| Kalite | `pint` · `phpstan` · `errors:export --check` · `phpunit` → `composer check` |

### Çalışan uçlar

| Method | Path | Auth | Faz |
|---|---|:---:|:---:|
| GET | `/api/ping` | — | 1 |
| POST | `/api/auth/register` · `/login` | — | 2 |
| POST | `/api/auth/logout` · GET `/api/auth/me` | ✅ | 2 |
| GET/POST/PUT/DELETE | `/api/invitations[/{id}]` | ✅ | 3 |
| GET | `/api/public/invitations/{id}` | — | 4 |
| POST | `/api/public/invitations/{id}/rsvps` | — | 5 |
| GET | `/api/invitations/{id}/rsvps` | ✅ | 5 |
| DELETE | `/api/rsvps/{id}` | ✅ | 5 |
| **POST** | **`/api/invitations/{id}/media`** | ✅ | **6** |
| **POST** | **`/api/public/invitations/{id}/media`** | — | **6** |

---

## 5. 🔴 İlk iş: Faz 6'yı kapat

**Faz 6'nın 6.15–6.24 adımları `composer check` koşmadan yazıldı.** Sebep:
o oturumun yardımcı ortamında PHP ve Composer yoktu.

### Yeni asistanın ilk yapacağı

1. `php artisan migrate`
2. 🔴 **`php artisan storage:link`** — hiç çalıştırılmadı; onsuz her medya
   URL'i 404 verir ve **hiçbir test bunu söylemez** (`Storage::fake()`)
3. `composer check` — **son satıra** baktır
4. `docs/rehber/fazlar/FAZ-6-ELLE-DOGRULAMA.md` (18 adım)
5. `FAZ-6.md` §11 kapanış listesini işaretlet ve **durum alanını güncellet**
6. Ancak ondan sonra Faz 7

> Faz 6'nın ilk `composer check` koşusu **üç gerçek hata** buldu (`FAZ-6.md`
> §6). İkinci koşunun da bulacağını varsay.

---

## 6. Faz 6 ne inşa etti?

**Amaç:** sistemin **dosya kabul eden** yolunu açmak.

### İki uç, iki tehdit modeli

```
Sahip:   auth:sanctum → Gate::authorize('update') → gallery
Misafir: auth YOK     → ResolveOpenRsvpInvitationAction → rsvp_photo | rsvp_video
```

### Katmanlı savunma (misafir yolu)

```
0. Hız sınırı   → throttle:media (5/dk, 40/saat) — rsvp'den DAR
1. Biçim/boyut  → StorePublicMediaRequest (mimetypes = İÇERİKTEN)
2. Tür izni     → StoreGuestMediaAction (DEĞİŞMEZ, doğrulama değil)
3. Hedef açık mı→ ResolveOpenRsvpInvitationAction (yayın + modül + son tarih)
4. Kota+saklama → StoreUploadedMediaAction (kilit, rastgele ad, telafi)
```

🔴 **Honeypot YOK ve olamaz** — dosya yüklemede görünmez alan diye bir şey yok.
Faz 5'in en ucuz katmanı burada eksik; hız sınırı onun işini de üstleniyor.

### Bilinmesi gereken dört ince nokta

1. **Dosya sistemi transaction'a dâhil değildir.** `DB::transaction()` diski
   geri almaz → `try/catch` + `Storage::delete()` telafisi (**F3**).
   ⚠️ Süreç ölürse (`kill -9`) telafi çalışmaz; yetim dosya kalır.
2. **Metriği sınırın tanımı belirler.** Medya `COUNT(*)`, LCV
   `SUM(guest_count)`. Faz 5'in kuralını kopyalamak hata olurdu (**ders 42**).
3. **Geçersiz medya kimliği sessizce düşer**, reddedilmez. `403` dönmek
   kimliğin *gerçek* olduğunu doğrular ve `media` tablosunu taranabilir yapar
   (**K59/L6**).
4. **Şema kimlik tutar, sözleşme URL taşır** (**F5**). `photo_media_id` kolonda,
   `photoUrl` yanıtta — S3 göçü `types.ts`'i hiç değiştirmeyecek.

### Yeni kalıcı yapılar

| Yapı | Ne işe yarar | Sonraki fazlarda |
|---|---|---|
| `ResolveOpenRsvpInvitationAction` | "Misafir buraya yazabilir mi?" — tek yer | LCV **ve** medya uçları paylaşıyor |
| `StoreUploadedMediaAction` | Kilitli kota + rastgele ad + telafi | Her yeni medya türü buradan geçer |
| `media.disk` kolonu | Göç ucuzlatıcı | Faz 9'da S3'e geçiş |
| `MediaKind` | Tür → config anahtarı bağlaması | Yeni tür = 1 case + 1 config bloğu |
| Mutasyon tablosu (`MediaTest.md` §7) | 20 satır | **T16**: faz kapanış ölçütü |

---

## 7. 🔴 Bekleyen işler

### 7.1 Hemen (Faz 6'yı kapatmak için)

- [ ] `php artisan storage:link` — **atlanırsa hiçbir medya görünmez**
- [ ] `composer check` yeşil
- [ ] `FAZ-6-ELLE-DOGRULAMA.md` 18 adım
- [ ] **Frontend uyarlaması — 6 dosya** → `FAZ-6.md` §8
- [ ] `CLAUDE.md` §1'in `if` kuralı güncellensin (B4 borcu)
- [ ] `PHP-LARAVEL-SETUP-EK-FAZ-5.md` **ve** `-EK-FAZ-6.md` master'a işlensin

### 7.2 🔴 Faz 5'ten devralınan, hâlâ onay bekleyen üç karar

| Konu | Soru |
|---|---|
| `app/Contracts/` klasörü | `CLAUDE.md` §1 `app/Services/` diyordu; doğru mu? |
| `rsvps.id` ULID (K52) | K40 uygulandı ama plan bir şey demiyordu |
| `hash('sha256', $ip.$key)` | `hash_hmac()` kriptografik olarak daha doğru |

### 7.3 Faz 6'nın 🟡 üç sapması

| Konu | Not |
|---|---|
| `RsvpPolicy` soft-delete düzeltmesi | Faz 5 koduna dokundu — gerekliydi (gerçek 500) |
| `PublicInvitationTest` flaky test düzeltmesi | Faz 4 koduna dokundu — gerekliydi |
| `CLAUDE.md` `if` kuralının gevşetilmesi | İsmail'in kararı, dosyaya işlenmeli |

### 7.4 Sonraki fazlara

| Konu | Ne zaman |
|---|---|
| 🔴 `invitations.timezone` (K63) | **Faz 7** — Faz 4'ten **üçüncü** kez erteleniyor |
| 🔴 **Yetim medya temizliği** | Faz 7/9 — kota sınırlar, temizlemez |
| 🔴 Yüklenenler web kökü altında (K55) | Faz 9 |
| `touch()` cache invalidation saniye altı kör | Faz 7 — K48'i yeniden tartışmak demek |
| `DeleteInvitationAction` + dosya temizliği | Faz 7 |
| `Jobs/SendRsvpNotification` (K62) | Faz 8 |
| `PublishInvitationAction` boş iskeleti | Faz 7 |
| Kuyruk işçisi izolasyonu (GD zararlı görsel açıyor) | Faz 9 |
| `routes/web.php` closure'ı | Faz 9 |

---

## 8. Ortam ve depo

```
D:\Projects\davetkart\
├─ claude\                            bağlam repo'su (ayrı git)
├─ davetkart-backend-php-laravel\     git: faz-5 dalı
└─ davetkart-frontent\                git: main — 🔴 GERİDE
```

**Ortam:** Windows + Laravel Herd + PostgreSQL 18 (pgAdmin 4).
İki veritabanı zorunlu: `davetkart` ve `davetkart_test` (**V2**).

| Uyarı | Ayrıntı |
|---|---|
| 🔴 Frontend deposu geride | Faz 4/5/6 uyarlamaları yapılmadı |
| 🔴 Frontend'de `.gitattributes` yok | 491 dosya sahte "değişmiş" görünüyor |
| 🔴 `public/storage` sembolik bağı yok | `php artisan storage:link` |
| `faz-5` dalı push edilmemiş olabilir | `git push origin faz-5` |

---

## 9. Sık düşülen tuzaklar (bu projede yaşandı)

| Tuzak | Nerede |
|---|---|
| Elle yazılan rota kısıtı sessizce yanlış olabilir | Faz 3 ULID regex'i → 3 IDOR testi **boş yeşil** |
| `create()` sonrası DB varsayılanı bellekte yok | `CreateInvitationAction` → 500 |
| Bir aracın kurulu olması, işini yaptığı anlamına gelmez | Larastan `casts()`'i hiç okumuyordu |
| `actingAs()` guard'ı atlar | `withToken()` + `forgetAuthState()` (T13) |
| Doğrulama kuralı **nesnesi** sınıf adı sızdırır | `Password::min(8)` (D6) |
| `composer check` fail-fast | phpstan kırılırsa testler **hiç koşmaz** |
| 🆕 **Soft delete ilişkiyi `null` yapar** | `RsvpPolicy` → `TypeError` → 500 (Faz 6) |
| 🆕 **Zaman damgası saniye hassasiyetinde** | `touch()` aynı saniyede olay fırlatmaz (Faz 6) |
| 🆕 **Koddaki gerekçe de yanlış olabilir** | `store()` "taşır" diyordu, kopyalıyor (Faz 6) |
| 🆕 **`Storage::fake()` gerçek diski hiç görmez** | `storage:link` testlerde görünmez |

---

## 10. Sıradaki iş

Faz 6 doğrulandıktan sonra **Faz 7 — Ödeme ve paywall**.
Hazır başlangıç mesajı: [`PROMPT-FAZ-7.md`](PROMPT-FAZ-7.md)
