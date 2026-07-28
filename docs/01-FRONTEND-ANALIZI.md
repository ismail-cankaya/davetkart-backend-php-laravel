# DavetKart Backend — Frontend Gereksinim Analizi

> **Amaç:** Backend'in tek satır kodu yazılmadan önce, frontend'in bize *dayattığı* sözleşmeyi
> eksiksiz çıkarmak. Bir backend'i "hayal ederek" değil, **tüketicisinin gerçek ihtiyacından**
> türeterek tasarlarız. Bu doküman o türetmenin kaydıdır.
>
> Tarih: 2026-07-27 · Karar: **PHP 8.3 + Laravel 12** (hızlı geliştirme önceliği)

---

## 0. Yönetici Özeti

Frontend, bir backend'e bağlanmak üzere **zaten mimari olarak hazırlanmış** durumda. Bu bizim
için büyük bir avantaz: API sözleşmesinin yaklaşık %40'ı kodda yazılı hâlde duruyor.

| Bulgu | Sonuç |
|---|---|
| `vite.config.ts` `/api` ve `/storage` isteklerini `http://localhost:8000`'e proxy'liyor | Laravel kararı frontend'e zaten işlenmiş, CORS ile uğraşmayacağız |
| `services/` klasöründe 7 adet **boundary (sınır) dosyası** var | Her biri bir backend modülüne birebir karşılık geliyor |
| 4 endpoint fiilen çağrılıyor, 6+ endpoint `TODO(backend)` olarak işaretli | İş listemiz kodda yazılı |
| Ödeme, medya, AI ve RSVP katmanları **mock** | Bunlar bizim asıl inşa alanımız |
| Paywall iş kuralı **sadece tarayıcıda** | 🔴 Kritik güvenlik açığı — sunucuda tekrar doğrulanmalı |

---

## 1. Frontend Teknoloji Envanteri

| Katman | Teknoloji | Backend'e Etkisi |
|---|---|---|
| Framework | React 19 + TypeScript (strict) | Yanıt tipleri **birebir** tutmalı; `any` yok |
| Build | Vite 6 | Dev'de proxy → aynı origin, CORS derdi yok |
| Routing | react-router-dom 7 (SPA, BrowserRouter) | Prod'da SPA fallback gerekiyor (`/invite/:id` sunucuda 404 vermemeli) |
| State | Zustand (6 ayrı store) | Store'lar `services/` üzerinden konuşuyor → temiz entegrasyon noktası |
| HTTP | axios + interceptor | **Bearer token** bekliyor, 401'de otomatik logout |
| i18n | i18next — **10 dil** (tr, en, ar, de, es, fr, hi, pt, ru, zh) | Hata mesajları ve e-postalar `Accept-Language` duyarlı olmalı; `ar` → RTL |
| AI | `@google/genai` (frontend'de duruyor) | 🔴 Anahtar taşınmalı → backend proxy şart |

---

## 2. Frontend'in Beklediği API Sözleşmesi

### 2.1 Hâlihazırda ÇAĞRILAN endpoint'ler (kırmazsak çalışır)

| Method | Path | İstek | Yanıt | Kaynak |
|---|---|---|---|---|
| POST | `/api/auth/register` | `{fullName, email, password}` | `{user:{id,fullName,email}, token}` | `services/auth.ts` |
| POST | `/api/auth/login` | `{email, password}` | `{user, token}` | `services/auth.ts` |
| POST | `/api/auth/logout` | — (Bearer) | 200/204 | `services/auth.ts` |
| GET | `/api/invitations` | — (Bearer) | `{data: InvitationRecord[]}` | `services/invitations.ts` |
| POST | `/api/contact` | `{name, email, subject, message}` | 200/204 | `services/contact.ts` |

**Dikkat edilecek 3 nokta:**

1. **`AuthSession` düz bir nesne** — `{user, token}`. Laravel'in alışıldık `{data: {...}}` zarfı
   burada **kullanılamaz**; `services/auth.ts` doğrudan `data.user` bekliyor.
2. **`/api/invitations` ise zarflı olabilir** — `toRecordArray()` hem düz dizi hem `{data: [...]}`
   kabul ediyor. Yani Laravel `ResourceCollection` varsayılanı sorunsuz çalışır.
3. **Alan adları `camelCase`** — `fullName`, `guestName`, `showEnvelope`, `mapUrl`, `updatedAt`.
   Laravel/DB tarafı `snake_case` olacak. **Bu dönüşümü API Resource katmanı yapacak**;
   frontend'e tek satır dokunmayacağız. (Bkz. Bölüm 5.2)

### 2.2 Kodda `TODO(backend)` ile İŞARETLİ, henüz olmayan endpoint'ler

| Method | Path | Neden gerekli | Kod kanıtı |
|---|---|---|---|
| GET | `/api/invitations/{id}` | `InvitePage` şu an `id`'yi kullanmıyor (`void id`), herkese lokal davetiyeyi gösteriyor | `pages/InvitePage.tsx:16` |
| POST | `/api/invitations` | Davetiye kaydetme yok | `useInvitationStore` → localStorage |
| PUT/PATCH | `/api/invitations/{id}` | Düzenlemeye devam etme yok | `useDashboardData` |
| POST | `/api/invitations/{id}/publish` | Yayınlama = ödeme + status geçişi | `useSubscriptionStore.purchase()` |
| DELETE | `/api/invitations/{id}` | Dashboard'da silme | — |
| POST | `/api/invitations/{slug}/rsvps` | **Public** LCV gönderimi | `useRsvpStore.submitDraft()` → localStorage |
| GET | `/api/invitations/{id}/rsvps` | Sahibin LCV listesi | `LiveRsvpPanel.tsx` |
| DELETE | `/api/rsvps/{id}` | LCV silme | `useRsvpStore.deleteRsvp` |
| POST | `/api/media/upload` (veya presign) | 🔴 Şu an `URL.createObjectURL` — sekme kapanınca dosya yok oluyor | `services/media.ts` |
| POST | `/api/payments/checkout` | 🔴 Tamamen sahte (`setTimeout` + `mock-order-...`) | `services/payments.ts` |
| POST | `/api/payments/webhook` | Sağlayıcı geri bildirimi (frontend bilmez ama zorunlu) | — |
| POST | `/api/assistant/chat` | 🔴 `generateReply()` sabit metin döndürüyor | `components/assistant/useAssistantChat.ts:16` |

---

## 3. Frontend'den Türetilen Veri Modeli

`src/types.ts` bizim için **hazır bir domain sözlüğü**. Aşağıda her tipin veritabanı karşılığı:

### 3.1 `Invitation` — sistemin kalbi (28 alan)

Alanları doğal olarak **4 gruba** ayrılıyor. Bu gruplama tesadüf değil; normalizasyon
kararımızın temeli:

| Grup | Alanlar | Karakter |
|---|---|---|
| **A. Skaler içerik** | `title, subtitle, names, date, venue, mapUrl, categoryId, imageTheme, phoneBackground, palette` | Sorgulanabilir, sabit şema → **kolon** |
| **B. Modül bayrakları** | `showEnvelope, showTimer, showTimeline, showGallery, showGift, showRSVP` | Paywall'ı belirliyor → **kolon** (sunucu doğrulaması için sorgulanabilir olmalı) |
| **C. Modül ayarları** | `bankName, accountHolder, iban, giftOptions[], rsvpDeadline, askMenuPreference` | Yarısı skaler, `giftOptions` dizi |
| **D. Koleksiyonlar** | `timelineEvents[]`, `galleryImages[]` | Sıralı, ilişkisel → **ayrı tablo** |

> **Mimari not:** `giftOptions: number[]` gibi "sorgulanmayacak diziler" için JSON kolonu
> makul; ama `timelineEvents` sıralı, düzenlenebilir ve ileride tekil erişim isteyeceğimiz
> bir koleksiyon → normalize tablo. Bu **hibrit yaklaşım** (kolon + JSON + ilişki) modern
> PostgreSQL/MySQL uygulamalarında standarttır. Kararı Bölüm 6'da soruyorum.

### 3.2 Tablo taslağı

```
users                  id, full_name, email(unique), password, email_verified_at, timestamps
invitations            id, user_id(FK), public_slug(unique,index), status['draft','saved','published'],
                       category_id, preset_id, palette, title, subtitle, names, event_at,
                       venue, map_url, show_* (6x boolean), bank_name, account_holder, iban,
                       gift_options(json), rsvp_deadline, ask_menu_preference,
                       published_at, timestamps, soft_deletes
timeline_events        id, invitation_id(FK), time, title, description, sort_order
media                  id, invitation_id(FK,null), user_id, disk, path, mime, size, kind['gallery','rsvp_photo','rsvp_video']
rsvps                  id, invitation_id(FK,index), guest_name, guest_count, menu_preference,
                       status['attending','pending','declined'], message,
                       photo_media_id, video_media_id, ip_hash, created_at
orders                 id, user_id, invitation_id, tier['standart','gold','elit'], amount,
                       currency, status['pending','paid','failed','refunded'],
                       provider, provider_ref(unique), paid_at, timestamps
contact_messages       id, name, email, subject, message, ip_hash, handled_at, timestamps
```

**Kritik çeviri — `RsvpStatus`:**
Frontend enum'u **Türkçe metin**: `'Katılıyor' | 'Bekleniyor' | 'Katılamıyor'`.
Veritabanına Türkçe enum yazmak yanlıştır (i18n, dil değişimi, raporlama kırılır).
Çözüm: DB'de `attending|pending|declined`, Resource katmanında çeviri. 10 dilli bir
üründe bu **zorunlu**, tercih değil.

---

## 4. İş Kuralları (Frontend'den okunan, sunucuda ZORUNLU)

### 4.1 Paywall matrisi — 🔴 En kritik güvenlik açığı

`stores/useSubscriptionStore.ts` içindeki kural:

```
showGallery || showGift            → 'elit'   (549 ₺)
showEnvelope || showTimeline       → 'gold'   (399 ₺)
diğer                              → 'standart' (249 ₺)
```

**Açık:** Bu mantık **sadece tarayıcıda**. Kullanıcı DevTools'tan Zustand state'ini değiştirip
`showGallery: true` yapıp `standart` satın alabilir. Ayrıca `activeTier` sadece bellekte —
sayfa yenilenince kayboluyor, yani sahiplik kalıcı bile değil.

**Çözüm:** `PublishInvitationAction` içinde sunucu, davetiyenin bayraklarına bakarak gerekli
tier'ı **yeniden hesaplar** ve kullanıcının ödenmiş `order`'ı ile karşılaştırır. Frontend'in
gönderdiği tier bilgisine **asla güvenilmez**. (Design rule: *never trust the client*.)

### 4.2 Plan limitleri

| Plan | LCV limiti | Kaynak |
|---|---|---|
| Standart | **max 100 kişi** | `data.ts` → `SUBSCRIPTION_PLANS` |
| Gold / Elit | Sınırsız | aynı |

Bu limit de sunucuda uygulanmalı: LCV kabul endpoint'i, davetiyenin planına bakıp 100'üncüden
sonrasını reddetmeli.

### 4.3 Diğer kurallar

- `rsvpDeadline` geçmişse LCV kabul edilmez (sunucu tarafı tarih kontrolü — istemci saati güvenilmez).
- `showRSVP: false` ise LCV endpoint'i 403 döner.
- Sadece `status = 'published'` davetiyeler `/invite/:slug` üzerinden **anonim** görülebilir.
- `askMenuPreference: false` ise `menuPreference` alanı yok sayılır.

---

## 5. Tespit Edilen Riskler ve Boşluklar

| # | Risk | Şiddet | Çözüm yönü |
|---|---|:---:|---|
| 1 | Paywall istemci tarafında (bkz. 4.1) | 🔴 Kritik | Sunucu tarafı yeniden hesaplama |
| 2 | Gemini API anahtarı frontend bağımlılığında | 🔴 Kritik | `/api/assistant/chat` proxy; anahtar `.env`'de |
| 3 | `media.ts` → `URL.createObjectURL` | 🔴 Kritik | Kalıcı depolama (local disk → S3/R2 adaptörü) |
| 4 | Ödeme tamamen sahte | 🔴 Kritik | Gerçek sağlayıcı + **webhook** (istemci "ödendi" diyemez) |
| 5 | LCV public endpoint → spam/bot | 🟠 Yüksek | Rate limit + honeypot + IP hash + dosya doğrulama |
| 6 | `InvitePage` anlık trafik patlaması (link WhatsApp'ta paylaşılır, 500 kişi aynı anda açar) | 🟠 Yüksek | Yayınlanmış davetiye JSON'ı cache'le + ETag |
| 7 | `id` alanları frontend'de `string` | 🟡 Orta | Public tarafta **UUID/slug** kullan; ardışık integer ID sızdırma (IDOR/enumeration) riski |
| 8 | `LiveRsvpPanel` "canlı" iddiasında | 🟡 Orta | Polling mi WebSocket mi — Bölüm 6'da soruluyor |
| 9 | Prod'da SPA fallback | 🟡 Orta | `/invite/*` için Nginx/Laravel fallback route |
| 10 | `davetkart-contracts` paketi **boş** (`src/` içi boş) | 🟡 Orta | PHP tarafı TS tipini import edemez; sözleşmeyi OpenAPI ile senkron tutmayı öneriyorum |

---

## 6. Önerilen Laravel Mimarisi (özet — detay bir sonraki dokümanda)

**Desen:** Modüler Monolit + Action/Service katmanı. Mikroservis **değil** — CLAUDE.md
"microservices" diyor, ancak tek geliştirici + hız önceliği ile mikroservis operasyonel
intihardır. Doğru okuma: *modüller mikroservise ayrılabilecek şekilde sınırlandırılır*
(bounded context), ama tek deploy edilir.

```
app/
├── Http/
│   ├── Controllers/Api/V1/     # sadece HTTP: istek al, action çağır, resource döndür
│   ├── Requests/               # FormRequest = doğrulama + yetki (controller'da if yok)
│   ├── Resources/              # snake_case → camelCase dönüşümü BURADA
│   └── Middleware/
├── Actions/                    # tek iş yapan sınıflar (PublishInvitationAction gibi)
├── Models/                     # Eloquent + ilişkiler + scope'lar
├── Policies/                   # yetkilendirme (bu davetiye bu kullanıcının mı?)
├── Services/
│   ├── Storage/                # StorageDriver arayüzü → Local, S3 (Strategy)
│   ├── Payment/                # PaymentGateway arayüzü → Iyzico, PayTR (Strategy)
│   └── Ai/                     # AiProvider arayüzü → Gemini (Adapter)
├── Enums/                      # RsvpStatus, InvitationStatus, SubscriptionTier (PHP 8.1 enum)
├── Events/ · Listeners/ · Jobs/  # e-posta, görsel işleme → kuyruk
└── Exceptions/                 # tek tip hata zarfı
```

**Uygulanacak desenler ve nedenleri:**

| Desen | Nerede | Neden |
|---|---|---|
| **Action (Single Responsibility)** | `PublishInvitationAction` | Controller şişmez; iş kuralı test edilebilir olur (SRP) |
| **Strategy** | Ödeme, Depolama | Iyzico→PayTR, Local→S3 geçişi tek satır (OCP) |
| **Adapter** | AI sağlayıcı | Gemini→OpenAI değişimi domain'i etkilemez (DIP) |
| **DTO/Resource Mapper** | API Resource | İç model ≠ dış sözleşme; DB kolonu değişince API kırılmaz |
| **Policy** | Yetkilendirme | Yetki mantığı tek yerde, `authorize()` ile deklaratif |
| **Repository** | ❌ **Kullanmıyoruz** | Eloquent zaten Active Record; üstüne repository koymak Laravel'de gereksiz soyutlama (YAGNI) |

---

## 7. Önerilen İnşa Sırası

Bağımlılık grafiğine göre — her adım bir öncekinin üstüne bina ediliyor:

| # | Adım | Öğrenilecek konu |
|---|---|---|
| 1 | Proje kurulumu + `.env` + `config/` | 12-Factor, ortam ayrımı |
| 2 | Enum'lar + migration'lar | Veri modelleme, indeks stratejisi, enum kullanımı |
| 3 | Model'ler + ilişkiler + factory/seeder | Eloquent ilişkileri, test verisi üretimi |
| 4 | Auth modülü (Sanctum) | Token mimarisi, hash, rate limit, `{user, token}` sözleşmesi |
| 5 | API Resource katmanı | snake_case↔camelCase, sözleşme koruması |
| 6 | Invitation CRUD + Policy | Yetkilendirme, FormRequest, nested koleksiyon yazımı |
| 7 | Public invite endpoint + cache | Anlık trafik, ETag, cache invalidation |
| 8 | RSVP modülü (public + owner) | Rate limit, spam koruması, iş kuralı doğrulama |
| 9 | Media modülü | Dosya güvenliği, disk soyutlaması, kuyruk |
| 10 | Payments + webhook | İdempotans, para birimi, sunucu tarafı paywall |
| 11 | AI proxy | Sır yönetimi, prompt injection, kota |
| 12 | Test + OpenAPI | Feature test, sözleşme dokümantasyonu |

---

## 8. Karara Bağlanması Gereken 4 Konu

Bunlar mimariyi kökten etkiler, ilerlemeden önce netleşmeli:

1. **Auth:** Sanctum personal access token *(önerim)* vs. JWT paketi
2. **Invitation saklama:** Hibrit (kolon + JSON + ilişki) *(önerim)* vs. tam normalize vs. tek JSON kolon
3. **Canlı LCV:** Polling *(önerim — 1 GB RAM'de en ucuzu)* vs. Laravel Reverb WebSocket vs. SSE
4. **Ödeme sağlayıcısı:** Iyzico vs. PayTR vs. şimdilik arayüz + sahte sürücü

---

*Sonraki adım: yukarıdaki 4 karar netleşince `02-MIMARI-PLAN.md` ve ardından 1. dosyanın kodu.*
