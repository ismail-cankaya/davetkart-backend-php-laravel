# DavetKart Backend — Mimari Plan

> **Durum:** Onay bekliyor · **Tarih:** 2026-07-27
> **Girdi:** `01-FRONTEND-ANALIZI.md` + `02-FRONTEND-BACKEND-TEMAS-NOKTALARI.md`
>
> Bu doküman, kod yazmadan önce **neyi neden yapacağımızın** sözleşmesidir.
> Onaylandıktan sonra dosyaları tek tek, sırayla yazacağız.

---

## 0. Kilitlenen Kararlar

| Konu | Karar | Gerekçe |
|---|---|---|
| Dil / Framework | **PHP 8.3+ & Laravel 13** | Hızlı geliştirme önceliği; frontend zaten Laravel'e göre yapılandırılmış. Laravel 13 (Mart 2026) minimum PHP 8.3 istiyor |
| Mimari stil | **Modüler Monolit + Action katmanı** | Tek deploy, ayrılabilir sınırlar |
| Kimlik doğrulama | **Sanctum** *(onay bekliyor)* | `{user, token}` + Bearer sözleşmesine birebir uyum, iptal edilebilir token |
| Veri modeli | **Hibrit** (kolon + JSON + ilişki) | Paywall'ı SQL ile doğrulayabilmek + esneklik |
| Canlı LCV | **Polling** (ETag'li) | Sıfır ek altyapı, düşük RAM |
| Ödeme | **`PaymentGateway` arayüzü + `FakeGateway`** | Sağlayıcı anlaşması beklenmeden doğru akış kurulur |
| Veritabanı | **MySQL 8** *(öneri)* | TR hosting yaygınlığı; PostgreSQL'e geçiş migration seviyesinde kalır |

---

## 1. Mimari Stil: Neden Modüler Monolit?

`CLAUDE.md` "Microservices backend" diyor. Bunu **olduğu gibi uygulamak yanlış olur.**

Mikroservisin çözdüğü problemler: bağımsız ölçekleme, bağımsız deploy, ekipler
arası bağımsızlık. Sizin durumunuz: **tek geliştirici, tek sunucu, hız önceliği.**
Mikroservise geçince kazandığınız hiçbir şey yok; kaybettikleriniz: dağıtık
transaction, servisler arası ağ gecikmesi, 5 kat deploy karmaşıklığı, dağıtık
loglama, servis keşfi.

**Doğru okuma:** *Modülleri, ileride mikroservise ayrılabilecek şekilde sınırlandır —
ama tek uygulama olarak deploy et.* Buna **Modüler Monolit** denir ve 2020'lerin
baskın mimari yaklaşımıdır (Shopify, Basecamp, GitHub bu modeli kullanır).

**Nasıl uygulayacağız:** Her bounded context (Auth, Invitation, RSVP, Media,
Payment, Assistant, Contact) kendi Action, Model, Policy ve Resource'larına sahip
olacak; modüller arası iletişim **doğrudan model çağrısıyla değil**, Action veya
Event üzerinden kurulacak. Böylece yarın `Payment` modülünü ayrı servise taşımak
istersek, kesilecek bağlantılar sayılabilir olur.

### Katman modeli

```
HTTP İsteği
   ↓
[Route]           → routes/api.php — sadece eşleme, mantık yok
   ↓
[Middleware]      → auth:sanctum, throttle, dil algılama
   ↓
[FormRequest]     → doğrulama + yetkilendirme  (controller'da if yok)
   ↓
[Controller]      → 3-8 satır: action'ı çağır, resource döndür
   ↓
[Action]          → ⭐ İŞ KURALI BURADA (tek sınıf, tek iş)
   ↓
[Model/Eloquent]  → veri erişimi + ilişkiler + scope'lar
   ↓
[Resource]        → snake_case → camelCase, dış sözleşmeyi koru
   ↓
JSON Yanıtı
```

**Her katmanın tek bir sebebi olmalı değişmek için** (Single Responsibility).
Kontrolcüde iş kuralı yazmak, bu prensibin en yaygın ihlalidir: HTTP değişince de,
iş kuralı değişince de aynı dosyayı açarsınız.

---

## 2. Klasör Mimarisi

```
davetkart-backend/
├── app/
│   ├── Actions/                          ⭐ İş kuralları — tek sınıf, tek iş
│   │   ├── Auth/
│   │   │   ├── RegisterUserAction.php
│   │   │   ├── LoginUserAction.php
│   │   │   └── RevokeTokenAction.php
│   │   ├── Invitation/
│   │   │   ├── CreateInvitationAction.php
│   │   │   ├── UpdateInvitationAction.php
│   │   │   ├── PublishInvitationAction.php     🔴 paywall doğrulaması burada
│   │   │   ├── SyncTimelineEventsAction.php
│   │   │   └── ResolvePublicInvitationAction.php
│   │   ├── Rsvp/
│   │   │   ├── SubmitRsvpAction.php            🔴 kota + deadline kontrolü
│   │   │   └── DeleteRsvpAction.php
│   │   ├── Media/
│   │   │   └── StoreUploadedMediaAction.php
│   │   └── Payment/
│   │       ├── StartCheckoutAction.php
│   │       └── HandlePaymentCallbackAction.php
│   │
│   ├── Enums/                            ⭐ Sihirli string yok
│   │   ├── InvitationStatus.php          draft | saved | published
│   │   ├── RsvpStatus.php                attending | pending | declined (+ TR çeviri)
│   │   ├── SubscriptionTier.php          standart | gold | elit (+ rank, price, limit)
│   │   ├── OrderStatus.php               pending | paid | failed | refunded
│   │   ├── MediaKind.php                 gallery | rsvp_photo | rsvp_video
│   │   └── ContactSubject.php            general | support | pricing | partnership | kvkk
│   │
│   ├── Http/
│   │   ├── Controllers/Api/V1/           namespace'te versiyon, URL'de DEĞİL (bkz. §4.1)
│   │   │   ├── AuthController.php
│   │   │   ├── InvitationController.php
│   │   │   ├── PublicInvitationController.php   ← auth'suz, cache'li
│   │   │   ├── RsvpController.php
│   │   │   ├── MediaController.php
│   │   │   ├── PaymentController.php
│   │   │   ├── AssistantController.php
│   │   │   └── ContactController.php
│   │   ├── Requests/                     Doğrulama + yetki
│   │   │   ├── Auth/{RegisterRequest, LoginRequest}.php
│   │   │   ├── Invitation/{StoreInvitationRequest, UpdateInvitationRequest}.php
│   │   │   ├── Rsvp/StoreRsvpRequest.php
│   │   │   └── ContactRequest.php
│   │   ├── Resources/                    ⭐ snake_case → camelCase DÖNÜŞÜMÜ BURADA
│   │   │   ├── UserResource.php
│   │   │   ├── InvitationResource.php          → {id, status, updatedAt, invitation:{...}}
│   │   │   ├── InvitationPayloadResource.php   → 28 alanlı tasarım nesnesi
│   │   │   ├── TimelineEventResource.php
│   │   │   └── RsvpResource.php
│   │   └── Middleware/
│   │       ├── SetLocaleFromHeader.php    Accept-Language → app()->setLocale()
│   │       └── ForceJsonResponse.php      API her zaman JSON döner
│   │
│   ├── Models/
│   │   ├── User.php · Invitation.php · TimelineEvent.php
│   │   ├── Media.php · Rsvp.php · Order.php · ContactMessage.php
│   │
│   ├── Policies/                         ⭐ "Bu kaynak bu kullanıcının mı?"
│   │   ├── InvitationPolicy.php
│   │   └── RsvpPolicy.php
│   │
│   ├── Services/                         ⭐ Dış dünya adaptörleri (Ports & Adapters)
│   │   ├── Payment/
│   │   │   ├── PaymentGateway.php        (interface)
│   │   │   ├── FakeGateway.php           (şimdi)
│   │   │   └── IyzicoGateway.php         (sonra)
│   │   ├── Ai/
│   │   │   ├── AiProvider.php            (interface)
│   │   │   └── GeminiProvider.php        🔴 API anahtarı SADECE burada
│   │   └── Pricing/
│   │       └── TierResolver.php          🔴 getRequiredTier()'ın sunucu ikizi
│   │
│   ├── Jobs/                             Kuyruk (15 sn timeout'a takılmamak için)
│   │   ├── OptimizeUploadedImage.php
│   │   └── SendRsvpNotification.php
│   │
│   ├── Events/ · Listeners/
│   │   ├── Events/InvitationPublished.php
│   │   └── Listeners/ClearInvitationCache.php
│   │
│   └── Exceptions/
│       ├── PaywallViolationException.php
│       └── RsvpQuotaExceededException.php
│
├── bootstrap/app.php                     Laravel 11+: middleware + exception kaydı burada (Kernel.php yok)
├── config/
│   ├── davetkart.php                     ⭐ plan fiyatları, kotalar, modül→tier haritası
│   ├── payment.php · ai.php
├── database/
│   ├── migrations/
│   ├── factories/                        Test verisi üretimi
│   └── seeders/
├── routes/
│   ├── api.php                           Tüm API rotaları
│   └── console.php
├── tests/
│   ├── Feature/                          ⭐ Asıl güvencemiz: endpoint testleri
│   └── Unit/                             TierResolver gibi saf mantık
└── storage/app/public/                   php artisan storage:link
```

### Neden `Repositories/` yok?

Laravel'de Eloquent zaten **Active Record** desenidir; modelin kendisi veri
erişim katmanıdır. Üstüne bir Repository koymak, çoğu projede sadece
`findById($id) { return Model::find($id); }` gibi anlamsız aracılar üretir.

Gerçek soyutlama ihtiyacı **dış sistemler** için var (ödeme, AI, depolama) —
onları `Services/` altında arayüzle soyutladık. Veritabanı için soyutlama, ORM'i
değiştirmeyi planlamıyorsanız **YAGNI** ihlalidir.

> Bu bir tercih, dogma değil. Karmaşık sorgular çoğalırsa `Models/Scopes/` veya
> query object'lere geçeriz — ama peşinen katman eklemeyiz.

---

## 3. Veri Modeli

### 3.1 Hibrit stratejinin uygulanışı

| Veri | Nerede | Neden |
|---|---|---|
| `show_gallery`, `show_gift`, `show_envelope`, `show_timeline`, `show_timer`, `show_rsvp` | **Ayrı boolean kolon** | 🔴 Paywall'ı `WHERE show_gallery = 1` ile sunucuda doğrulayacağız |
| `status`, `event_at`, `published_at`, `rsvp_deadline` | **Kolon** | Sorgu/sıralama/filtre |
| `title, subtitle, names, venue, map_url, iban…` | **Kolon** | Sabit şema, arama ihtimali |
| `gift_options: number[]` | **JSON kolon** | Sorgulanmayacak küçük dizi |
| `timeline_events[]` | **Ayrı tablo** | Sıralı, düzenlenebilir, ileride tekil erişim |
| `gallery_images[]` | **Ayrı tablo (`media`)** | Dosya meta verisi taşıyor (boyut, mime, disk) |

### 3.2 Tablolar

```sql
users
  id, full_name, email UNIQUE, password, email_verified_at, remember_token, timestamps

invitations
  id
  user_id            FK → users, INDEX
  public_slug        UUID/ULID, UNIQUE, INDEX        ← /invite/{slug}
  status             ENUM(draft, saved, published), INDEX
  category_id        VARCHAR(32)      dugun|kina|nisan|sunnet|dogum-gunu|mezuniyet|baby-shower|parti
  preset_id          VARCHAR(48)      frontend'deki imageTheme
  palette            ENUM(midnight, stone)
  title, subtitle, names, venue      VARCHAR
  map_url            TEXT NULL
  event_at           DATETIME NULL
  show_envelope, show_timer, show_timeline,
  show_gallery, show_gift, show_rsvp  BOOLEAN DEFAULT 0
  bank_name, account_holder, iban     VARCHAR NULL
  gift_options       JSON NULL
  rsvp_deadline      DATE NULL
  ask_menu_preference BOOLEAN DEFAULT 0
  published_at       DATETIME NULL
  timestamps, softDeletes
  INDEX (user_id, status)                            ← dashboard sorgusu

timeline_events
  id, invitation_id FK CASCADE, time VARCHAR(16), title, description TEXT NULL,
  sort_order SMALLINT, timestamps
  INDEX (invitation_id, sort_order)

media
  id, user_id FK NULL, invitation_id FK NULL INDEX,
  kind ENUM(gallery, rsvp_photo, rsvp_video),
  disk VARCHAR(32), path VARCHAR(255), mime VARCHAR(64), size INT,
  sort_order SMALLINT DEFAULT 0, timestamps

rsvps
  id, invitation_id FK CASCADE INDEX,
  guest_name VARCHAR(120), guest_count TINYINT UNSIGNED DEFAULT 1,
  menu_preference VARCHAR(64) NULL,
  status ENUM(attending, pending, declined),
  message TEXT NULL,
  photo_media_id FK NULL, video_media_id FK NULL,
  ip_hash CHAR(64) NULL,              ← ham IP değil, hash (KVKK)
  timestamps
  INDEX (invitation_id, status)       ← kota hesabı: SUM(guest_count)

orders
  id, user_id FK, invitation_id FK NULL,
  tier ENUM(standart, gold, elit),
  amount DECIMAL(10,2), currency CHAR(3) DEFAULT 'TRY',
  status ENUM(pending, paid, failed, refunded),
  provider VARCHAR(32), provider_ref VARCHAR(128) UNIQUE NULL,   ← idempotans
  paid_at DATETIME NULL, timestamps
  INDEX (user_id, status)

contact_messages
  id, name, email, subject ENUM(...), message TEXT,
  ip_hash CHAR(64) NULL, handled_at DATETIME NULL, timestamps

personal_access_tokens   ← Sanctum'un kendi tablosu
```

### 3.3 Üç tasarım detayının gerekçesi

**`public_slug` neden UUID/ULID?**
Frontend `id: string` bekliyor. Ardışık integer kullanırsak misafir `/invite/1`,
`/invite/2` diye gezip başkalarının davetiyelerini okur (*enumeration attack*).
ULID tercih ediyorum: UUID gibi tahmin edilemez ama **zaman sıralı**, dolayısıyla
indekste UUID v4'ün yol açtığı sayfa parçalanmasını yaşatmaz.

**`ip_hash` neden ham IP değil?**
Ham IP, KVKK/GDPR kapsamında kişisel veridir. Spam tespiti için ihtiyacımız olan
şey "aynı kişi mi" sorusunun cevabı — bunun için `hash(ip + app_key)` yeter.
Veriyi minimize etmek bir güvenlik ilkesidir (*data minimization*).

**`provider_ref` neden UNIQUE?**
Ödeme sağlayıcıları webhook'u **birden fazla kez** gönderir (ağ hatası, retry).
Unique kısıt, aynı ödemenin iki kez işlenmesini veritabanı seviyesinde imkânsız
kılar. Buna **idempotans** denir ve ödeme sistemlerinde pazarlık konusu değildir.

---

## 4. API Sözleşmesi

### 4.1 Versiyonlama: URL'de yok, namespace'te var

Frontend `baseURL = '/api'`. Rotayı `/api/v1/auth/login` yaparsak **frontend anında
kırılır.** Bu yüzden:

- **URL:** `/api/auth/login` (düz)
- **Namespace:** `App\Http\Controllers\Api\V1\AuthController`

Kod organizasyonu bugünden v2'ye hazır; URL sözleşmesi bozulmuyor. İleride v2
gerekirse `/api/v2/...` eklenir, `/api/...` v1 olarak yaşamaya devam eder.

### 4.2 Yanıt zarfı politikası — dikkat, ikili kural

| Endpoint grubu | Zarf | Sebep |
|---|:---:|---|
| `POST /auth/login`, `/auth/register` | ❌ **YOK** | `services/auth.ts` `data.user` bekliyor |
| Diğer tüm endpoint'ler | ✅ `{data: ...}` | `toRecordArray` her ikisini kabul ediyor; Laravel varsayılanı |

```php
// Auth — düz yanıt
return response()->json([
    'user'  => new UserResource($user),   // ::make değil, ->resolve() ile zarfsız
    'token' => $token,
]);

// Diğerleri — Resource'un doğal zarfı
return InvitationResource::collection($invitations);   // {"data": [...]}
```

### 4.3 Endpoint tablosu

| # | Method | Path | Auth | Açıklama |
|---|---|---|:---:|---|
| 1 | POST | `/api/auth/register` | — | `{fullName,email,password}` → `{user,token}` |
| 2 | POST | `/api/auth/login` | — | `{email,password}` → `{user,token}` |
| 3 | POST | `/api/auth/logout` | ✅ | Aktif token'ı sil |
| 4 | GET | `/api/auth/me` | ✅ | *(yeni)* Token doğrulama |
| 5 | GET | `/api/invitations` | ✅ | Kullanıcının tüm davetiyeleri |
| 6 | POST | `/api/invitations` | ✅ | Yeni taslak kaydet |
| 7 | GET | `/api/invitations/{id}` | ✅ | Sahibin düzenleme için okuması |
| 8 | PUT | `/api/invitations/{id}` | ✅ | Güncelle (debounce'lu autosave) |
| 9 | DELETE | `/api/invitations/{id}` | ✅ | Soft delete |
| 10 | POST | `/api/invitations/{id}/publish` | ✅ | 🔴 Paywall doğrula → yayınla |
| 11 | **GET** | **`/api/public/invitations/{slug}`** | **—** | 🔥 Misafir sayfası, cache'li |
| 12 | POST | `/api/public/invitations/{slug}/rsvps` | — | 🔴 Rate limit + kota + deadline |
| 13 | GET | `/api/invitations/{id}/rsvps` | ✅ | Sahibin listesi (ETag → polling) |
| 14 | DELETE | `/api/rsvps/{id}` | ✅ | Sahibi siler |
| 15 | POST | `/api/media` | ✅ | Galeri yüklemesi |
| 16 | POST | `/api/public/media` | — | LCV foto/video (sıkı limitli) |
| 17 | POST | `/api/payments/checkout` | ✅ | Order oluştur → ödeme URL'i |
| 18 | POST | `/api/payments/webhook` | — | 🔒 İmza doğrulamalı, CSRF muaf |
| 19 | POST | `/api/assistant/chat` | ✅ | AI proxy, kotalı |
| 20 | POST | `/api/contact` | — | İletişim formu |

> **`/api/public/...` öneki neden?** Auth gerektirmeyen rotaları tek bir grup
> altında toplamak, `auth:sanctum` middleware'ini yanlışlıkla unutma riskini
> ortadan kaldırır. Varsayılan **kapalı**, istisna **açıkça işaretli**.
> Bu bir *fail-safe* tasarımdır.

### 4.4 Alan adı dönüşümü

DB `snake_case` ↔ API `camelCase`. Dönüşüm **sadece Resource katmanında**:

```php
// InvitationPayloadResource.php
return [
    'title'        => $this->title,
    'mapUrl'       => $this->map_url,           // ← dönüşüm
    'showGallery'  => (bool) $this->show_gallery,
    'rsvpDeadline' => $this->rsvp_deadline?->format('Y-m-d'),
    'giftOptions'  => $this->gift_options ?? [],
    'timelineEvents' => TimelineEventResource::collection($this->whenLoaded('timelineEvents')),
];
```

Gelen istekte ters yön: FormRequest `camelCase` doğrular, Action `snake_case`
kolona yazar. **Ara katmanda otomatik dönüştürücü kullanmayacağız** — açık ve
okunabilir eşleme, "sihirli" dönüşümden daha iyi hata ayıklanır.

### 4.5 Hata formatı

Laravel varsayılanı frontend için yeterli (`catch` blokları jenerik mesaj
gösteriyor):

```json
422 → { "message": "...", "errors": { "email": ["..."] } }
401 → { "message": "Unauthenticated." }
403 → { "message": "This action is unauthorized." }
429 → { "message": "Too Many Requests" }
```

**Kritik kural (tekrar):** Yetki hatası **403**, kimlik hatası **401**.
`401` frontend'de oturumu düşürür.

---

## 5. Güvenlik Mimarisi

### 5.1 🔴 Paywall — `TierResolver`

Frontend'deki `getRequiredTier()`'ın sunucu ikizi. Kaynak: `config/davetkart.php`.

```php
final class TierResolver
{
    public function requiredFor(Invitation $invitation): SubscriptionTier
    {
        if ($invitation->show_gallery || $invitation->show_gift)     return SubscriptionTier::Elit;
        if ($invitation->show_envelope || $invitation->show_timeline) return SubscriptionTier::Gold;
        return SubscriptionTier::Standart;
    }
}
```

`PublishInvitationAction` akışı:

```
1. Policy: bu davetiye bu kullanıcının mı?          → değilse 403
2. TierResolver::requiredFor($invitation)            → SUNUCUDA hesapla
3. Kullanıcının paid order'ı bu tier'ı kapsıyor mu?  → değilse PaywallViolationException (402)
4. public_slug üret, status = published, published_at = now()
5. InvitationPublished event → cache temizle
```

**İstemciden gelen `tier` bilgisi asla kullanılmaz.** Frontend'in `activeTier`'ı
sadece arayüz kararı içindir.

### 5.2 Public LCV endpoint'i — en çok saldırıya açık nokta

Bu endpoint auth'suz ve dosya kabul ediyor. Katmanlı savunma:

| Katman | Önlem |
|---|---|
| Rate limit | IP başına `10/dakika`, davetiye başına `60/saat` |
| Honeypot | Formda gizli alan; doluysa bot → sessizce 200 dön, kaydetme |
| İş kuralı | `rsvp_deadline` geçmiş mi? `show_rsvp` açık mı? `status = published` mi? |
| Kota | Standart plan: `SUM(guest_count) < 100` (kayıt sayısı değil!) |
| Dosya | MIME **içerikten** doğrula (uzantıya güvenme), boyut limiti, rastgele isim |
| Depolama | Yüklenen dosyalar **asla** çalıştırılabilir dizinde durmaz |
| Veri | IP hash'lenir, ham saklanmaz |

> **`guest_count` detayı:** `LiveRsvpPanel` toplamları `reduce((s,r) => s + r.guestCount, 0)`
> ile hesaplıyor. Backend kotasını `COUNT(*)` ile kurarsak 100 kayıt × 4 kişi = 400
> misafir geçer. Aynı metriği kullanmak zorundayız.

### 5.3 Sır yönetimi

| Sır | Nerede | Frontend erişimi |
|---|---|---|
| `GEMINI_API_KEY` | `.env` → `config/ai.php` → `GeminiProvider` | ❌ imkânsız |
| Ödeme anahtarları | `.env` → `config/payment.php` | ❌ imkânsız |
| `APP_KEY` | `.env` | ❌ |

Vite yalnızca `VITE_` önekli değişkenleri paketler — mimari olarak sızma yolu yok.

### 5.4 Diğer

- **Mass assignment:** Model'lerde `$fillable` beyaz liste. `$guarded = []` **yasak**
  (kullanıcı `"user_id": 1` gönderip başkasının hesabına yazar).
- **IDOR:** Her sahip-kaynak erişiminde `Policy`. Route model binding tek başına
  yetmez — bulur ama yetki sormaz.
- **Şifre:** Laravel varsayılanı bcrypt; **Argon2id**'ye geçeceğiz (daha yüksek
  bellek maliyeti = GPU saldırısına direnç).
- **Auth rate limit:** `/auth/login` → IP+email başına 5/dakika (brute-force).
- **Webhook:** CSRF muaf ama **imza doğrulaması zorunlu** — aksi hâlde herkes
  "ödeme başarılı" POST'u atar.

---

## 6. Performans

### 6.1 🔥 Public davetiye — asıl yük noktası

Davetiye linki WhatsApp grubuna düşer, 500 kişi 2 dakikada açar. Veri ise
**neredeyse hiç değişmez**. Kitap gibi bir okuma yükü.

```php
Cache::remember("invitation:{$slug}", now()->addHours(6), fn () =>
    Invitation::with(['timelineEvents', 'galleryMedia'])
        ->where('public_slug', $slug)
        ->where('status', InvitationStatus::Published)
        ->firstOrFail()
);
```

- Cache temizleme: `InvitationPublished` / `InvitationUpdated` event'leri
- Ek olarak **ETag** → değişmemişse `304 Not Modified`, gövde hiç gitmez

### 6.2 Polling optimizasyonu

`GET /invitations/{id}/rsvps` 15 saniyede bir çağrılacak. ETag ile:
liste değişmediyse `304` + boş gövde. Maliyeti neredeyse sıfır.

### 6.3 N+1 sorgu problemi

Dashboard 20 davetiye listeler; her biri için `timelineEvents` sorgusu atılırsa
**41 sorgu** olur. Çözüm: `with(['timelineEvents', 'galleryMedia'])` eager loading.
Geliştirmede `Model::preventLazyLoading()` ile bunu **exception'a** çevireceğiz —
hata üretimde değil, laptop'ta yakalanmalı.

### 6.4 15 saniye kuralı

`api.ts` timeout'u 15 sn. Bundan uzun sürebilecek her iş **kuyruğa**:
görsel optimizasyonu, e-posta, bildirimler. Endpoint sadece "kabul edildi" der.

---

## 7. Test Stratejisi

**Ağırlık Feature testlerinde.** Gerçek HTTP isteği atıp gerçek DB'ye (SQLite
in-memory) yazan testler, birim testlerinden daha fazla gerçek hata yakalar.

Yazılacak kritik testler:

| Test | Doğrulanan |
|---|---|
| `test_login_returns_unwrapped_user_and_token` | Auth zarfsız sözleşmesi |
| `test_invitation_resource_uses_camel_case` | `mapUrl`, `showGallery` doğru mu |
| `test_publish_rejects_insufficient_tier` | 🔴 Paywall aşılamıyor |
| `test_user_cannot_read_others_invitation` | 🔴 IDOR kapalı |
| `test_rsvp_rejected_after_deadline` | İş kuralı |
| `test_rsvp_quota_uses_guest_count_sum` | 🔴 Kota doğru metrikte |
| `test_webhook_is_idempotent` | Çift ödeme yok |
| `test_public_invitation_hides_drafts` | Yayınlanmamış sızmıyor |

---

## 8. İnşa Sırası — 12 Adım

Her adım bir dosya veya küçük mantıksal modül. Her birinden sonra **duracağım.**

| # | Ne yazacağız | Öğreneceğiniz konu |
|---|---|---|
| 1 | Proje kurulumu + `.env` + `config/davetkart.php` | 12-Factor, konfigürasyonu koddan ayırma |
| 2 | `app/Enums/*` (6 enum) | PHP 8 backed enum, sihirli string'i yok etme |
| 3 | Migration'lar | Veri modelleme, indeks stratejisi, FK davranışları |
| 4 | Model'ler + ilişkiler + `$fillable` + cast | Eloquent ilişkileri, mass assignment güvenliği |
| 5 | Factory + Seeder | Test verisi üretimi, deterministik fixture |
| 6 | Sanctum kurulumu + Auth (Controller/Request/Action/Resource) | Token mimarisi, katmanların birlikte çalışması |
| 7 | `InvitationResource` ailesi | Sözleşme koruması, camelCase eşlemesi |
| 8 | Invitation CRUD + `InvitationPolicy` | Yetkilendirme, nested koleksiyon senkronizasyonu |
| 9 | Public invite endpoint + cache + ETag | Okuma-ağırlıklı yük, cache invalidation |
| 10 | RSVP modülü (public submit + owner list) | Rate limit, spam savunması, kota kuralı |
| 11 | Media modülü + Job | Dosya güvenliği, disk soyutlaması, kuyruk |
| 12 | `TierResolver` + `PublishInvitationAction` + Payment | 🔴 Sunucu tarafı paywall, Strategy, idempotans |

*(AI proxy ve Contact, ilgili adımlara serpiştirilecek — ikisi de tek dosyalık işler.)*

---

## 9. Açık Kalan Tek Karar

**Auth: Sanctum onaylanıyor mu?**

Hatırlatma: JWT'nin tek avantajı DB'siz doğrulama; ama `useAuthStore.logout()`
sunucu tarafında token iptali bekliyor ve JWT bunu karşılayamıyor. Sanctum'un
maliyeti istek başına ~0.2ms indeksli sorgu.

Onaylarsanız **1. adımdan** başlıyoruz.
