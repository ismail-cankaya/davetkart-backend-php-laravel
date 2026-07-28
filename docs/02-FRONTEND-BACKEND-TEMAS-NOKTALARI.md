# Frontend'in Backend'e Dokunan Her Yeri — Rehberli Gezinti

> **Kime:** Bu frontend'i yazmamış, ama backend'ini yazacak geliştiriciye.
> **Nasıl okunmalı:** Sırayla. Her bölüm bir öncekinin üstüne biniyor.
> Sonunda "kullanıcı butona bastığında hangi dosyalar sırayla çalışıyor" sorusuna
> cevap verebiliyor olacaksınız.

---

## Önce: Kafanızdaki resmi kuralım

Bu frontend'i yazan kişi **iyi bir mimari kural** uygulamış. Tek cümleyle:

> **Hiçbir arayüz bileşeni (`.tsx`) doğrudan HTTP isteği atmaz.**

Bunun yerine 5 katmanlı bir zincir var. Veri bu zincirden geçerek akar:

```
┌─────────────────────────────────────────────────────────────┐
│ 5. UI (pages/, components/)   "Butona basıldı"              │
│         ↓ store fonksiyonunu çağırır                        │
├─────────────────────────────────────────────────────────────┤
│ 4. STORE (stores/)            "Uygulama durumu"             │
│         ↓ service fonksiyonunu çağırır                      │
├─────────────────────────────────────────────────────────────┤
│ 3. SERVICE (services/)        "Backend sınırı" ← SİZİN ALAN │
│         ↓ api instance'ını kullanır                         │
├─────────────────────────────────────────────────────────────┤
│ 2. HTTP CLIENT (services/api.ts)  "Token ekle, 401 yakala"  │
│         ↓ axios → tarayıcı                                  │
├─────────────────────────────────────────────────────────────┤
│ 1. KONFİGÜRASYON (.env, vite.config.ts)  "Nereye gidilecek" │
└─────────────────────────────────────────────────────────────┘
                            ↓
                    LARAVEL (yazacağımız yer)
```

**Neden bu kadar önemli?** Çünkü siz backend'i yazarken **sadece 3. katmana**
dokunacaksınız. `services/` klasöründeki 7 dosyanın içini doldurunca, üstteki
40+ bileşen hiç değişmeden çalışmaya başlayacak. Buna **Boundary (Sınır) Deseni**
denir — sistemin dış dünyayla temas ettiği noktaları tek bir klasörde toplamak.

Şimdi katmanları tek tek gezelim.

---

# KATMAN 1 — Konfigürasyon: "İstek nereye gidiyor?"

### 📄 `.env` ve `.env.development`

```bash
VITE_API_BASE_URL=/api
```

Tek bir değişken. **Mutlak URL değil, göreli yol** olması bilinçli bir tercih.

- `https://api.davetkart.com/api` yazsaydı → tarayıcı bunu **farklı origin** sayar,
  her istekten önce bir `OPTIONS` preflight isteği atar, CORS başlıkları doğru
  değilse istek başlamadan ölür.
- `/api` yazınca → tarayıcı için istek **kendi sitesine** gidiyor. CORS diye bir
  kavram devreye girmez.

> `VITE_` öneki zorunlu: Vite sadece bu önekli değişkenleri tarayıcı paketine
> gömer. Bu bir güvenlik önlemi — yanlışlıkla `DB_PASSWORD`'ü frontend'e sızdırmayı
> engeller. **Sonuç: buraya asla gizli anahtar koyulamaz.** Gemini anahtarının
> neden backend'e taşınması gerektiğinin teknik sebebi tam olarak budur.

### 📄 `src/vite-env.d.ts`

```ts
interface ImportMetaEnv {
  readonly VITE_API_BASE_URL?: string;
}
```

TypeScript'e "`import.meta.env.VITE_API_BASE_URL` diye bir şey var ve `string`"
demek. Fonksiyonel etkisi yok, sadece tip güvenliği. Yeni bir env değişkeni
eklerseniz **buraya da eklemezseniz** TypeScript hata verir.

### 📄 `vite.config.ts` — sizi en çok ilgilendiren dosya

```ts
server: {
  proxy: {
    '/api':     { target: 'http://localhost:8000', changeOrigin: true },
    '/storage': { target: 'http://localhost:8000', changeOrigin: true },
  }
}
```

**Ne yapıyor:** Geliştirme sırasında tarayıcı `http://localhost:3000` (Vite) ile
konuşur. `/api/...` ile başlayan her isteği Vite yakalar ve arka planda
`http://localhost:8000` (Laravel) adresine iletir. Tarayıcı bunu hiç bilmez.

```
Tarayıcı ──"/api/auth/login"──> Vite :3000 ──iletir──> Laravel :8000
   (tek origin görüyor, CORS yok)        (sunucu-sunucu, CORS kuralı yok)
```

**Sizin için 3 pratik sonuç:**

1. Laravel'i **mutlaka `php artisan serve` ile 8000 portunda** çalıştırın. Başka
   port kullanacaksanız bu dosyayı düzenlemeniz gerekir.
2. `/storage` de proxy'lenmiş → yüklenen medyayı **Laravel'in `public` diskinden**
   servis etmemiz bekleniyor (`php artisan storage:link`).
3. Dev'de CORS ile hiç uğraşmayacaksınız. **Ama production'da** frontend ve backend
   ayrı domain'deyse `config/cors.php` gerekecek.

---

# KATMAN 2 — Sözleşme: `src/types.ts`

Bu dosya kod değil, **sözlük**. Frontend'in "bir davetiye şudur" tanımı. Backend'in
döndürdüğü JSON bu tiplere uymazsa TypeScript derlenir ama uygulama çalışma
zamanında kırılır (TypeScript sadece derleme zamanında var, JSON'u doğrulamaz).

**Sizin ezberlemeniz gereken 6 tip:**

| Tip | Ne temsil ediyor | Backend karşılığı |
|---|---|---|
| `AuthUser` | `{id, fullName, email}` | `UserResource` |
| `AuthSession` | `{user, token}` — login/register yanıtı | `LoginController` yanıtı |
| `Invitation` | 28 alanlı tasarım nesnesi | `invitations` tablosu + ilişkiler |
| `InvitationRecord` | `{id, status, updatedAt, invitation}` — **sarmalayıcı** | `InvitationResource` |
| `RSVPResponse` | Tek bir LCV kaydı | `rsvps` tablosu |
| `SubscriptionTier` | `'standart'\|'gold'\|'elit'` | `orders.tier` |

### ⚠️ Dikkat: `Invitation` ile `InvitationRecord` farkı

Bu ayrım tesadüf değil, iyi bir tasarım:

```ts
Invitation        → sadece TASARIM (başlık, tarih, renk, modüller...)
InvitationRecord  → tasarım + SUNUCU META VERİSİ (id, status, updatedAt)
```

Editör `Invitation` ile çalışır (id'si yoktur, henüz kaydedilmemiş olabilir).
Dashboard `InvitationRecord` ile çalışır (sunucudan geldi, id'si var). Backend'de
de bu ayrımı koruyacağız: `InvitationResource` dış katmanı, içine `invitation`
anahtarıyla tasarımı gömecek.

### ⚠️ İki tane "tuzak" alan

```ts
export type RsvpStatus = 'Katılıyor' | 'Bekleniyor' | 'Katılamıyor';
```

**Türkçe metin**, kod değil. Veritabanına bunu yazamayız (10 dil desteği var,
`ar` dilinde bu ne olacak?). DB'de `attending|pending|declined` tutup Resource
katmanında çevireceğiz.

```ts
id: string;   // Invitation ve RSVP id'leri
```

`number` değil `string`. Yani backend `1, 2, 3` gibi ardışık sayılar yerine
**UUID veya slug** dönebilir — ki dönmelidir. Ardışık ID'de bir misafir
`/invite/1`, `/invite/2` diye gezip başkalarının davetiyelerini bulur
(*enumeration attack*).

---

# KATMAN 3 — HTTP İstemcisi: `src/services/api.ts`

**Tüm uygulamada tek bir axios örneği var.** Her istek buradan geçer. 3 parçası var:

### Parça 1 — Kurulum

```ts
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
  timeout: 15_000,
  headers: { 'Content-Type': 'application/json' }
});
```

`timeout: 15000` → **backend 15 saniyede cevap vermezse istek iptal edilir.**
Bunu aklınızda tutun: uzun süren işleri (görsel işleme, e-posta gönderimi,
PDF üretimi) senkron endpoint'te yapamayız. Onlar **kuyruğa (queue)** gidecek.

### Parça 2 — Request Interceptor (token enjeksiyonu)

```ts
api.interceptors.request.use((config) => {
  const { token } = useAuthStore.getState();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});
```

**Interceptor nedir:** isteğin ortasına giren bir kanca. İstek axios'tan çıkmadan
önce bu fonksiyon çalışır ve isteği değiştirebilir.

**Ne yapıyor:** Oturum varsa her isteğe `Authorization: Bearer <token>` başlığını
otomatik ekliyor. Bu yüzden hiçbir yerde manuel token yazılmamış.

**Sizin için anlamı:** Laravel tarafında `auth:sanctum` middleware'i tam da bu
başlığı okur. Sanctum önerimin somut sebebi bu — sözleşme birebir uyuyor.

### Parça 3 — Response Interceptor (401 yönetimi)

```ts
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (axios.isAxiosError(error) && error.response?.status === 401) {
      useAuthStore.getState().logout();
    }
    return Promise.reject(error);
  }
);
```

**Ne yapıyor:** Backend `401 Unauthorized` dönerse token geçersizdir → oturumu
temizler, kullanıcı anonim moda düşer.

**🔴 Sizin için kritik uyarı:**
Laravel'de yanlış yetki (`403`) ile geçersiz token (`401`) karıştırılırsa,
"bu davetiye senin değil" hatası kullanıcıyı sistemden **atar**. Kural:

| Durum | Kod |
|---|---|
| Token yok / geçersiz / süresi dolmuş | `401` |
| Token geçerli ama bu kaynağa yetkin yok | `403` |
| Doğrulama hatası | `422` |

Laravel'in `Policy` mekanizması varsayılan olarak `403` döner — doğru davranış.

---

# KATMAN 4 — Servis Sınırları: `src/services/` (⭐ ASIL ÇALIŞMA ALANINIZ)

7 dosya var. **4'ü gerçek, 3'ü sahte.** Bu ayrımı bilmek işinizin yarısı.

## ✅ `auth.ts` — GERÇEK (çalışır durumda)

```ts
export interface AuthService {
  login(credentials): Promise<AuthSession>;
  register(payload): Promise<AuthSession>;
  revokeSession(token): void;
  restoreSession(): AuthSession | null;
  persistSession(session): void;
  clearSession(): void;
}
```

Önce bir **arayüz (interface)** tanımlanmış, sonra `httpAuthAdapter` adında bir
uygulaması yazılmış. Bu **Adapter Deseni** — yarın "auth'u Firebase'e taşıyalım"
deseniz sadece yeni bir adapter yazarsınız, üst katmanlar değişmez.

**3 endpoint çağırıyor:**

| Fonksiyon | İstek | Beklenen yanıt |
|---|---|---|
| `login()` | `POST /api/auth/login` `{email, password}` | `{user:{id,fullName,email}, token}` |
| `register()` | `POST /api/auth/register` `{fullName, email, password}` | aynı |
| `revokeSession()` | `POST /api/auth/logout` (Bearer) | herhangi bir 2xx |

**🔴 Laravel'de en sık yapılan hata burada olacak:**

Laravel `ApiResource` kullanınca yanıtı otomatik `{"data": {...}}` içine sarar.
Ama bu kod `data.user` bekliyor — yani:

```json
✅ DOĞRU:  { "user": {...}, "token": "..." }
❌ YANLIŞ: { "data": { "user": {...}, "token": "..." } }
```

Auth endpoint'lerinde **zarf kullanmayacağız.**

**`revokeSession` neden `void` (Promise değil)?**

```ts
revokeSession(token) {
  void api.post('/auth/logout', undefined, {
    headers: { Authorization: `Bearer ${token}` }
  }).catch(() => {});
}
```

"Ateşle ve unut". Kullanıcı çıkış yaptığında arayüz sunucuyu **beklemez** — anında
çıkmış olur. Ayrıca token parametre olarak elden veriliyor, çünkü store kendi
state'ini zaten temizlemiş durumda; interceptor artık token bulamazdı.

> **Öğretici detay:** Bu tasarım bir sonsuz döngüyü de engelliyor. Token süresi
> dolmuşsa `/auth/logout` `401` döner → response interceptor `logout()` çağırır →
> o da `revokeSession` çağırırdı → yine `401`... Store önce state'i temizlediği
> için ikinci turda `token` `null`'dur ve döngü kırılır. Kodun yorumunda bu
> açıkça anlatılmış.

**localStorage'ın rolü:** `persistSession` / `restoreSession` oturumu tarayıcıda
saklar ki **F5'te kullanıcı atılmasın**. Token'ı **üreten** hâlâ sunucudur;
localStorage sadece bir kopya taşır.

---

## ✅ `invitations.ts` — GERÇEK ama sadece 1 endpoint

```ts
async list(): Promise<InvitationRecord[]> {
  const { data } = await api.get<unknown>('/invitations');
  return toRecordArray(data);
}
```

`toRecordArray` fonksiyonu **savunmacı programlama** örneği:

```ts
if (Array.isArray(payload)) return payload;              // düz dizi kabul
if (payload?.data && Array.isArray(payload.data)) ...    // {data:[...]} kabul
throw new Error('Unexpected shape');                     // gerisi hata
```

Neden? Yanlış yönlendirilmiş bir istek SPA'nın HTML'ini döndürebilir. O HTML'e
`.filter()` çağırmak tüm sayfayı çökertirdi. Bu yüzden **beklenmeyen her şey
hata sayılır** ve dashboard "backend'e ulaşılamıyor" durumuna düşer.

**Sizin için:** Bu endpoint'te Laravel'in `{data: [...]}` zarfı **serbest** —
ikisi de kabul ediliyor.

---

## ✅ `contact.ts` — GERÇEK, en basit dosya

```ts
export async function sendContactMessage(payload: ContactPayload): Promise<void> {
  await api.post('/contact', payload);
}
```

`subject` alanı sabit bir küme: `'general'|'support'|'pricing'|'partnership'|'kvkk'`.
Backend'de aynı enum ile doğrulanmalı.

> **Backend'de ilk yazacağımız endpoint muhtemelen bu olacak** — auth gerektirmiyor,
> tek tablo, karmaşık iş kuralı yok. Laravel'in istek→doğrulama→kaydet→yanıt
> zincirini öğrenmek için ideal alıştırma.

---

## 🔴 `media.ts` — SAHTE

```ts
const objectUrlAdapter: MediaService = {
  upload: (file) => Promise.resolve(URL.createObjectURL(file))
};
```

`URL.createObjectURL` **hiçbir şey yüklemez.** Tarayıcının belleğindeki dosyaya
`blob:http://localhost:3000/a1b2c3...` şeklinde geçici bir takma ad üretir.

**Sonuç:** Misafir LCV'ye fotoğraf ekler → sekmeyi kapatır → fotoğraf yok olur.
Zaten sadece o sekmede görünüyordu; davetiye sahibi o fotoğrafı **hiçbir zaman
göremezdi.**

Dosyanın yorumunda çözüm de yazılı: gerçek adapter dosyayı POST edecek ve
**kalıcı URL** döndürecek.

---

## 🔴 `payments.ts` — SAHTE

```ts
async checkout(payload) {
  await new Promise(r => setTimeout(r, 1800));   // sahte gecikme
  return { orderId: `mock-order-${Date.now()}`, tier: payload.tier, status: 'paid' };
}
```

1.8 saniye bekliyor (spinner görünsün diye) ve **her zaman "ödendi" diyor.**
Hiçbir para hareketi yok.

Dosyadaki `TODO(backend)` yorumunda kritik bir cümle var:

> *"The real endpoint will also verify **server-side** that the chosen tier
> actually covers the invitation's enabled modules."*

Frontend'i yazan kişi bu güvenlik açığının farkında ve size not bırakmış.

---

## 🔴 `persistence.ts` — SAHTE (bilinçli olarak)

`Invitation` ve `RSVPResponse` verilerini **localStorage'a** yazıyor:

```ts
const INVITATION_KEY = 'e_davetiye_invitation';
const RSVP_KEY = 'e_davetiye_rsvps';
```

Yani şu anda tüm uygulama tek bir tarayıcıda, tek bir kullanıcı için, hesapsız
çalışıyor. Bir demo/prototip modu.

`try/catch` blokları neden var? Gizli sekmede (`private mode`) veya kota dolduğunda
`localStorage.setItem` **exception fırlatır**. Yakalanmasa uygulama çöker. Yakalandığı
için sadece "veri kalıcı olmaz", uygulama çalışmaya devam eder. Ders: **yan etki
mekanizmalarının hatası ana akışı öldürmemeli.**

---

## 🔴 `components/assistant/useAssistantChat.ts` — SAHTE (services dışında!)

Bu dosya `services/` klasöründe **değil**, ama backend'e dokunuyor:

```ts
async function generateReply(_userText: string): Promise<string> {
  await new Promise(r => setTimeout(r, 1100 + Math.random() * 900));
  return 'Mesajınız için teşekkürler! ...';   // her zaman aynı metin
}
```

Parametre adı `_userText` — alt çizgi "kullanılmıyor" demek. Kullanıcı ne yazarsa
yazsın aynı cevap dönüyor.

Yorumunda: *"İleride gerçek yapay zeka servisine bağlanacak TEK nokta."*
Yani bu fonksiyonun gövdesini `api.post('/assistant/chat', ...)` ile değiştirmek
yeterli olacak.

---

## 📊 Servis katmanı özeti

| Dosya | Durum | Backend işi |
|---|:---:|---|
| `api.ts` | ✅ Altyapı | Dokunmayacağız |
| `auth.ts` | ✅ Gerçek | 3 endpoint yazılacak |
| `invitations.ts` | ⚠️ Kısmi | `list()` var, CRUD yok |
| `contact.ts` | ✅ Gerçek | 1 endpoint yazılacak |
| `media.ts` | 🔴 Sahte | Sıfırdan |
| `payments.ts` | 🔴 Sahte | Sıfırdan + webhook |
| `persistence.ts` | 🔴 Sahte | HTTP adapter'a dönüşecek |
| `useAssistantChat.ts` | 🔴 Sahte | AI proxy |

---

# KATMAN 5 — Store'lar: `src/stores/` (durumu kim tutuyor?)

Zustand store'ları, servisleri çağıran ve sonucu uygulama genelinde paylaşan katman.
6 store var; **3'ü backend'i ilgilendiriyor.**

## `useAuthStore.ts` — oturumun kalbi

```ts
login: async (credentials) => {
  const session = await authService.login(credentials);   // 1. sunucuya sor
  authService.persistSession(session);                    // 2. tarayıcıya yaz
  set({ user: session.user, token: session.token, isAuthenticated: true });  // 3. belleğe koy
  return session.user;
}
```

Üç adımın sırası önemli: sunucu onaylamadan yerel state değişmiyor.

**Dosyanın en altındaki 4 satır kritik:**

```ts
const cached = authService.restoreSession();
if (cached) {
  useAuthStore.setState({ user: cached.user, token: cached.token, isAuthenticated: true });
}
```

Bu kod **modül seviyesinde**, yani uygulama açılırken bir kez, senkron çalışır.
Sayfa yenilendiğinde kullanıcı bir an için "çıkış yapmış" görünmesin diye.

**🔴 Backend'i ilgilendiren yan etkisi:** Frontend, localStorage'daki token'ın hâlâ
geçerli olduğunu **sunucuya sormadan varsayıyor.** Token iptal edilmişse bunu ancak
ilk `401` yanıtında öğrenecek. Bu kabul edilebilir bir tasarım (istek zaten anında
gelecek), ama Sanctum tercihimizi güçlendiriyor: iptal edilebilir token sayesinde
"çalınan token" senaryosunda sunucu tarafında müdahale edebiliyoruz.

## `useInvitationStore.ts` — tasarımın kendisi

```ts
// Açılışta bir kez oku
void persistenceService.getInvitation().then((saved) => {
  if (saved) useInvitationStore.setState({ invitation: { ...INITIAL_INVITATION, ...saved } });
});

// Her değişiklikte geri yaz
useInvitationStore.subscribe((state, prev) => {
  if (state.invitation !== prev.invitation) {
    void persistenceService.saveInvitation(state.invitation);
  }
});
```

**Hydration (canlandırma) + auto-save deseni.** Kullanıcı bir input'a her harf
yazdığında `updateField` çalışır → yeni nesne üretilir → `subscribe` tetiklenir →
kaydedilir.

**🔴 Backend'e geçerken tuzak:** localStorage'da bu bedava. HTTP'de **her tuşa
basışta bir POST** demek olur. Çözüm: `use-debounce` paketi zaten `package.json`'da
var (biri bunu öngörmüş) — 800ms sessizlik sonrası tek istek atmak.

**`{ ...INITIAL_INVITATION, ...saved }` neden?**
Eski kayıtlarda `showGift` alanı yoktu. Varsayılanların üstüne yayarak eksik alanlar
otomatik dolar. Backend'de aynı sorunu **migration'da `default` değer vererek**
çözeceğiz — daha sağlam bir çözüm.

## `useRsvpStore.ts` — LCV formu

```ts
submitDraft: () => {
  const { draft } = get();
  if (!draft.guestName.trim()) return null;        // tek doğrulama
  const entry = { id: `rsvp-${Date.now()}`, ...draft, createdAt: new Date().toISOString() };
  set(state => ({ rsvpList: [entry, ...state.rsvpList], draft: INITIAL_RSVP_DRAFT }));
  return entry;
}
```

Dikkat: fonksiyon `async` **değil**. ID'yi tarayıcı üretiyor (`Date.now()`),
zaman damgasını tarayıcı üretiyor. Backend geldiğinde bu fonksiyon `async` olacak
ve **ID ile `createdAt` sunucudan gelecek** — istemci saati güvenilmez (kullanıcı
bilgisayarının saati yanlış olabilir, ya da kasten değiştirilebilir).

## `useSubscriptionStore.ts` — 🔴 paywall (en kritik dosya)

```ts
export function getRequiredTier(invitation: Invitation): SubscriptionTier {
  if (invitation.showGallery || invitation.showGift) return 'elit';
  if (invitation.showEnvelope || invitation.showTimeline) return 'gold';
  return 'standart';
}
```

**Bütün ticari model bu 4 satırda** ve tamamı tarayıcıda. Ayrıca:

```ts
activeTier: SubscriptionTier | null,   // sadece bellekte
```

Satın alınan plan **hiçbir yere kaydedilmiyor** — sayfa yenilenince kayboluyor.

Bu iki gerçek birleşince backend'in görevi netleşiyor:
1. Gerekli tier'ı **sunucuda yeniden hesapla** (istemciye güvenme)
2. Sahipliği **veritabanına kaydet** (`orders` tablosu)

---

# KATMAN 6 — Arayüz: tetikleyiciler nerede?

## 🔐 `components/auth/ProtectedRoute.tsx`

```ts
if (!isAuthenticated) {
  return <Navigate to="/login" replace state={{ from: location.pathname }} />;
}
```

`/dashboard` gibi özel rotaları koruyor. `state.from` ile kullanıcı giriş sonrası
**gitmek istediği yere** döner.

> **Bu sadece bir UX önlemi, güvenlik önlemi DEĞİL.** Frontend'de rota koruması
> kullanıcıya nazik davranmak içindir; gerçek koruma backend'in `auth:sanctum`
> middleware'idir. Bunu karıştırmak klasik bir başlangıç hatasıdır.

## 📊 `hooks/useDashboardData.ts` — dayanıklılık örneği

```ts
const [remote, draft] = await Promise.allSettled([
  invitationService.list(),          // sunucudan
  persistenceService.getInvitation() // tarayıcıdan
]);
```

**`Promise.allSettled` ≠ `Promise.all`.** `all` olsaydı biri patlayınca ikisi de
kaybolurdu. `allSettled` her ikisinin sonucunu ayrı ayrı verir → **backend çökse
bile yerel taslak görünmeye devam eder.**

`remoteError` bayrağı da buradan geliyor; dashboard "sunucuya ulaşılamıyor"
uyarısını gösteriyor ama sayfa çalışmaya devam ediyor. Bu bir **graceful
degradation** uygulamasıdır.

## 🚀 `components/create/EditorWorkspace.tsx` — yayınlama akışı

Backend açısından **en yoğun** fonksiyon:

```ts
const handlePublish = () => {
  if (!isAuthenticated) {                      // 1. giriş kontrolü
    navigate('/login', { state: { from: location.pathname } });
    return;
  }
  const requiredTier = getRequiredTier(invitation);   // 2. paywall hesabı
  const { activeTier, openPaywall } = useSubscriptionStore.getState();
  if (activeTier && TIER_RANK[activeTier] >= TIER_RANK[requiredTier]) {
    finishPublish();                           // 3a. plan yeterli → yayınla
    return;
  }
  openPaywall(requiredTier);                   // 3b. ödeme duvarı aç
};

const finishPublish = () => {
  // TODO(backend): POST the invitation and navigate to the hosted /invite/:id
  toast('Davetiyeniz yayınlandı! 🎉');
  navigate('/dashboard');
};
```

`finishPublish` **hiçbir şey yapmıyor** — sadece bildirim gösterip yönlendiriyor.
Burası backend'in en kritik entegrasyon noktası:
`POST /api/invitations` → `POST /api/invitations/{id}/publish` → dönen slug ile
`/invite/{slug}` linkini kullanıcıya ver.

## 🎫 `pages/InvitePage.tsx` — misafirin gördüğü sayfa

```ts
const { id } = useParams<{ id: string }>();
void id;   // ← "bu değişkeni bilerek kullanmıyorum"
```

`void id` satırı, TypeScript'in "kullanılmayan değişken" uyarısını susturmak için.
Yani **URL'deki id tamamen yok sayılıyor** ve herkese o tarayıcıdaki yerel davetiye
gösteriliyor.

Gerçek hâli: `GET /api/invitations/{slug}` → **auth gerektirmez** (misafir hesap
açmayacak) → sadece `published` durumundakiler döner.

> Bu endpoint sistemin **en çok yük alan** noktası olacak: davetiye linki WhatsApp
> grubuna düşer, 500 kişi 2 dakika içinde açar. Cache stratejisi burada zorunlu.

## 📡 `components/rsvp/LiveRsvpPanel.tsx`

Başlıkta "Gerçek Zamanlı Takip" yazıyor ama kodda dürüst bir itiraf var:

```tsx
Misafirlerinizin katılım yanıtları bu panele eşzamanlı yansır
<span>(*Simülasyondur)</span>
```

Şu an `useRsvpStore`'daki yerel listeyi okuyor. Aldığımız karar gereği burayı
**polling** ile besleyeceğiz.

Panel 3 metrik hesaplıyor — dikkat: **kayıt sayısı değil, kişi sayısı toplamı**:

```ts
rsvpList.filter(r => r.status === 'Katılıyor').reduce((s, r) => s + r.guestCount, 0)
```

Standart plandaki "max 100 kişi" limitini backend'de doğrularken aynı mantığı
kullanmalıyız: `SUM(guest_count)`, `COUNT(*)` değil.

## 📬 `pages/ContactPage.tsx`

Doğrulama kuralları burada görünür — backend'de **aynısı tekrarlanmalı**:

```ts
if (!name.trim())            → 'Lütfen adınızı ve soyadınızı girin.'
if (!validEmail)             → 'Lütfen geçerli bir e-posta adresi girin.'
if (message.length < 10)     → 'Mesajınız en az 10 karakter olmalı.'
```

> **İlke:** İstemci doğrulaması *kullanıcı deneyimi* içindir (anında geri bildirim).
> Sunucu doğrulaması *güvenlik* içindir. İkisi de gerekli, biri diğerinin yerine
> geçmez. `curl` ile istek atan biri istemci doğrulamasını hiç görmez.

---

# 4 Kritik Akışın Uçtan Uca Diyagramı

## Akış 1 — Giriş yapma

```
LoginPage.handleSubmit()
  └→ useAuthStore.login({email, password})
      └→ authService.login()
          └→ api.post('/auth/login')
              ├→ request interceptor: token yok, başlık eklemez
              └→ POST http://localhost:8000/api/auth/login
                  ⇐ { user: {...}, token: "..." }
          ⇐ AuthSession
      ├→ authService.persistSession()  → localStorage
      └→ set({ user, token, isAuthenticated: true })
  └→ navigate(location.state.from ?? '/dashboard')
```

## Akış 2 — Dashboard açılışı

```
DashboardPage → useDashboardData()
  └→ Promise.allSettled([
       invitationService.list()  → GET /api/invitations   (Bearer eklenir)
       persistenceService.getInvitation()  → localStorage
     ])
  └→ records.filter(status==='published') → "Yayında Olanlar" sekmesi
     records.filter(status==='saved')     → "Kaydedilenler" sekmesi
     localDraft                            → "Taslak" sekmesi
```

## Akış 3 — Yayınlama + ödeme (backend'in en çok iş yapacağı yer)

```
EditorWorkspace.handlePublish()
  ├─ giriş yok mu?  → /login'e yönlendir, dur
  ├─ getRequiredTier(invitation)      ← 🔴 sunucuda tekrarlanacak
  ├─ activeTier yeterli mi?  → finishPublish()
  └─ değilse → openPaywall(requiredTier)
                └→ PaywallModal → purchase()
                    └→ paymentService.checkout()   🔴 SAHTE
                        ⇐ { orderId: 'mock-...', status: 'paid' }
                    └→ set({ activeTier })          ← sadece bellekte
                └→ finishPublish()                  ← 🔴 hiçbir şey yapmıyor
```

**Backend'in kuracağı gerçek akış:**
```
POST /invitations              → taslağı kaydet (status: 'saved')
POST /payments/checkout        → order oluştur (status: 'pending') + ödeme URL'i
      ⇢ kullanıcı 3D Secure ekranına gider
POST /payments/webhook         → sağlayıcı "ödendi" der → order.status = 'paid'
POST /invitations/{id}/publish → tier'ı sunucuda doğrula → status='published' + slug üret
```

> **Neden webhook şart?** Kullanıcı ödeme sonrası tarayıcıyı kapatabilir. Sunucunun
> ödemeyi öğrenmesi kullanıcının geri dönmesine bağlı olamaz. Ayrıca istemcinin
> "ödedim" demesine asla güvenilmez.

## Akış 4 — Misafirin LCV göndermesi

```
InvitePage → RsvpModal
  ├─ dosya seçilirse → attachDraftMedia() → mediaService.upload()  🔴 SAHTE
  └─ Gönder → submitDraft()  (senkron, sadece isim kontrolü)
              └→ rsvpList'e ekle → localStorage
```

**Backend'in kuracağı gerçek akış:**
```
POST /invitations/{slug}/media   → dosyayı yükle, kalıcı URL döndür
POST /invitations/{slug}/rsvps   → auth YOK, ama:
                                    · rate limit (spam)
                                    · rsvp_deadline geçti mi?
                                    · show_rsvp açık mı?
                                    · Standart plansa SUM(guest_count) < 100 mü?
```

---

# Referans: Backend'e dokunan dosyaların tam listesi

| # | Dosya | Rol | Durum |
|---|---|---|---|
| 1 | `.env` / `.env.development` | API adresi | ✅ hazır |
| 2 | `vite.config.ts` | Dev proxy → :8000 | ✅ hazır |
| 3 | `src/vite-env.d.ts` | Env tipi | ✅ hazır |
| 4 | `src/types.ts` | **Sözleşme** | ✅ referansımız |
| 5 | `src/services/api.ts` | Axios + interceptor | ✅ hazır |
| 6 | `src/services/auth.ts` | Auth sınırı | ✅ gerçek |
| 7 | `src/services/invitations.ts` | Davetiye sınırı | ⚠️ sadece `list()` |
| 8 | `src/services/contact.ts` | İletişim sınırı | ✅ gerçek |
| 9 | `src/services/media.ts` | Medya sınırı | 🔴 sahte |
| 10 | `src/services/payments.ts` | Ödeme sınırı | 🔴 sahte |
| 11 | `src/services/persistence.ts` | Veri sınırı | 🔴 localStorage |
| 12 | `src/stores/useAuthStore.ts` | Oturum durumu | ✅ gerçek |
| 13 | `src/stores/useInvitationStore.ts` | Tasarım durumu | 🔴 yerel |
| 14 | `src/stores/useRsvpStore.ts` | LCV durumu | 🔴 yerel |
| 15 | `src/stores/useSubscriptionStore.ts` | **Paywall** | 🔴 istemci tarafı |
| 16 | `src/hooks/useDashboardData.ts` | Veri toplayıcı | ⚠️ kısmi |
| 17 | `src/components/auth/ProtectedRoute.tsx` | Rota koruması | ✅ (UX) |
| 18 | `src/components/create/EditorWorkspace.tsx` | Yayınlama | 🔴 `finishPublish` boş |
| 19 | `src/pages/InvitePage.tsx` | Public davetiye | 🔴 `id` yok sayılıyor |
| 20 | `src/components/preview/RsvpModal.tsx` | LCV formu | 🔴 yerel |
| 21 | `src/components/rsvp/LiveRsvpPanel.tsx` | Canlı panel | 🔴 simülasyon |
| 22 | `src/components/assistant/useAssistantChat.ts` | AI sohbet | 🔴 sahte |
| 23 | `src/pages/ContactPage.tsx` | İletişim formu | ✅ gerçek |
| 24 | `src/pages/LoginPage.tsx` · `RegisterPage.tsx` | Auth formları | ✅ gerçek |

---

## Buradan çıkarılacak tek cümle

> Frontend, backend'e bağlanmak için **doğru şekilde** hazırlanmış: her dış temas
> noktası `services/` altında izole edilmiş, arayüzle tanımlanmış ve `TODO(backend)`
> ile işaretlenmiş. Bizim işimiz yeni bir mimari icat etmek değil, **bu boşlukları
> sözleşmeye sadık kalarak doldurmak** — ve istemcide duran iş kurallarını
> (özellikle paywall'ı) sunucuda yeniden, güvenilir biçimde kurmak.
