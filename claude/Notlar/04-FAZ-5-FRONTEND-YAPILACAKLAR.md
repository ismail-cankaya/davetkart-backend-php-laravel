# Faz 5 — Frontend'e Düşen İş (LCV / RSVP)

> **Depo:** `davetkart_frontend` (backend'den ayrı)
> **Kaynak:** `docs/rehber/fazlar/FAZ-5.md` §8
> **Durum:** ⬜ hiçbiri yapılmadı — **backend sözleşmesi değişti, LCV modülü
> bugünkü frontend'le çalışmaz**

---

## 0. Neden bu iş zorunlu?

Backend `K49` ile LCV durum değerlerini değiştirdi:

```ts
// ŞU AN (yanlış)
export type RsvpStatus = 'Katılıyor' | 'Bekleniyor' | 'Katılamıyor';
```

Bu satır **gösterim metnini veri değeri olarak** kullanıyor ve üç şeyi birden
bozuyor:

| Bozulan | Nasıl |
|---|---|
| Dil | Uygulama İngilizceye çevrilince `WHERE status = 'Katılıyor'` ne yapacak? |
| Veri | Etiketi "Geliyorum" diye güzelleştirmek, **tüm satırları** güncellemek demek |
| Kodlama | `ı`, `ü` URL'de, indekste ve sıralamada sürpriz üretir |

**K21** bunu zaten yasaklamıştı: *backend tek dil konuşur, çeviri frontend'in
işidir.*

---

## 1. 🔴 Sıra önemli — önce `types.ts`

Faz 3'ün **32. dersi**: sözleşme değişince önce tip dosyasını değiştir ki
**TypeScript kalan işi derleme hatası olarak listelesin**. Elle dosya aramaya
çalışırsan birini kaçırırsın.

```powershell
cd davetkart_frontend
npm run lint     # tsc --noEmit — değişiklikten sonra bunu koştur
```

---

## F1 · `src/types.ts`

```ts
// ÖNCE
export type RsvpStatus = 'Katılıyor' | 'Bekleniyor' | 'Katılamıyor';

// SONRA
export type RsvpStatus = 'attending' | 'pending' | 'declined';
```

Ayrıca `RSVPResponse.id` artık **ULID** (26 karakter string) — tip zaten
`string`, değişiklik yok ama yorumu güncelle.

🔴 **`message` alanı:** backend `null` **göndermiyor**, alanı tamamen düşürüyor
(`whenNotNull`). Yani `message?: string` doğru; `message: string | null`
**yazma**.

🔴 **`photoUrl` / `videoUrl`:** backend bunları **hiç göndermiyor** (medya Faz
6). Opsiyonel kalsınlar, ama bugün her zaman `undefined` gelecek.

---

## F2 · `src/data.ts`

```ts
// ~satır 2804
export const INITIAL_RSVP_DRAFT: RsvpDraft = {
  ...
  status: 'attending',     // 'Katılıyor' değil
  ...
};
```

---

## F3 · `src/components/preview/RsvpModal.tsx`

**~satır 119** — değer ile etiketi ayır:

```tsx
const STATUS_LABELS: Record<RsvpStatus, string> = {
  attending: 'Katılıyorum',
  pending: 'Belirsiz',
  declined: 'Katılamıyorum',
};

{(['attending', 'pending', 'declined'] as const).map(st => (
  <button key={st} onClick={() => updateDraft({ status: st })}>
    {STATUS_LABELS[st]}
  </button>
))}
```

Bu, **K20'nin frontend tarafının küçük bir provası**: kod ayrı, metin ayrı.
İleride `toDisplayError()` çeviri katmanı geldiğinde bu eşleme `locales/`
altına taşınacak.

### 🔴🔴 Honeypot alanı — bu olmadan savunma HİÇ çalışmaz

Forma **görünmez** bir input ekle:

```tsx
{/* Honeypot — insan göremez, bot doldurur. Backend: StoreRsvpRequest::HONEYPOT_FIELD */}
<input
  type="text"
  name="website"
  tabIndex={-1}
  autoComplete="off"
  aria-hidden="true"
  style={{ position: 'absolute', left: '-9999px' }}
  value=""
  onChange={() => {}}
/>
```

Ve gönderimde `website: ''` yolla. Boş string, backend'in global
`ConvertEmptyStringsToNull` middleware'i tarafından `null` yapılır — yani
dürüst kullanıcı **elenmez**.

> ⚠️ Bu eksikliği **hiçbir backend testi söylemez**, çünkü backend testleri
> alanı kendileri gönderiyor. Kanıt yalnızca elle doğrulamada:
> `FAZ-5-ELLE-DOGRULAMA.md` adım 16.2.

---

## F4 · `src/components/templates/shared/RSVPForm.tsx`

**~satır 31, 55, 233** — aynı değişiklikler. Bu form yalnızca **iki** seçenek
sunuyor (`attending` / `declined`); backend üçünü de kabul ediyor, hangisinin
gösterileceği bir **sunum** kararı, sorun değil.

**~satır 55:**

```tsx
menuPreference: invitation.askMenuPreference ? menuPreference : ''
// 'Belirtilmedi' YAZMA — o bir GÖSTERİM metnidir.
// Backend null'ı '' olarak döndürüyor; etiketi panel koyuyor.
```

Honeypot alanı buraya da eklenecek.

---

## F5 · `src/components/rsvp/LiveRsvpPanel.tsx`

**~satır 30-32:**

```tsx
const countAttending = rsvpList.filter(r => r.status === 'attending')
  .reduce((sum, r) => sum + r.guestCount, 0);
const countPending   = rsvpList.filter(r => r.status === 'pending').reduce(...);
const countDeclines  = rsvpList.filter(r => r.status === 'declined').reduce(...);
```

**~satır 143-144** — rozet renkleri de değer üzerinden.

🔴 `reduce((s, r) => s + r.guestCount, 0)` **doğru ve korunmalı**: backend
kotayı `SUM(guest_count)` ile ölçüyor (`COUNT(*)` ile değil). İki taraf **aynı
metriği** kullanmak zorunda — `docs/09` §Faz 5'in açık uyarısı.

---

## F6 · `src/services/rsvps.ts` — 🔴 uçlar değişti

| İşlem | ÖNCE | SONRA |
|---|---|---|
| Gönderim | `POST /rsvps` | `POST /public/invitations/{invitationId}/rsvps` |
| Liste | `GET /rsvps` | `GET /invitations/{invitationId}/rsvps` |
| Silme | `DELETE /rsvps/{id}` | ✅ aynı |

**Neden iç içe?** Bir LCV yanıtı **her zaman** bir davetiyeye aittir. Düz uçta
bu aidiyet gövdeden gelirdi — yani istemcinin sözüne kalırdı. İç içe URL'de
aidiyet **yapısaldır** (backend kuralı **N1**).

Gönderim ucu `/public/` altında ve **auth istemiyor** — `api.ts` interceptor'ı
token eklese de backend bakmıyor.

```ts
async create(invitationId: string, payload: RsvpCreatePayload): Promise<RSVPResponse> {
  const { data } = await api.post<unknown>(
    `/public/invitations/${invitationId}/rsvps`,
    { ...payload, website: '' },      // honeypot
  );
  return toRsvp(data);
}

async list(invitationId: string): Promise<RSVPResponse[]> {
  const { data } = await api.get<unknown>(`/invitations/${invitationId}/rsvps`);
  return toRsvpArray(data);
}
```

> `src/services/persistence.ts` de bu imza değişikliğini yansıtmalı — store
> doğrudan `rsvpService`'i değil, `persistenceService`'i çağırıyor.

---

## F7 · `src/stores/useRsvpStore.ts`

`fetchRsvps()` ve `submitDraft()` artık **davetiye kimliği** istiyor:

```ts
fetchRsvps: (invitationId: string) => Promise<void>;
submitDraft: (invitationId: string) => Promise<RSVPResponse | null>;
```

Kimlik nereden gelir?

- **Misafir sayfası** (`InvitePage.tsx`): URL'deki `/invite/{id}` parametresi
- **Sahibin paneli** (`LiveRsvpPanel`): `useInvitationStore`'daki `recordId`
  (Faz 3'te F4 ile eklenmişti)

### ETag / polling notu

Liste ucu `config('davetkart.rsvp.poll_interval_seconds')` = **15 saniye**
aralıkla çağrılmak üzere tasarlandı ve backend'de `SetEtag` middleware'i var.
`axios` `If-None-Match` başlığını **otomatik göndermez**; `304`'ten faydalanmak
istiyorsan ETag'i saklayıp elle göndermen gerekir.

> Yapmasan da çalışır — sadece her istekte gövde iner. Ölçüp karar ver.

---

## 2. Ayrı bir borç: `.gitattributes`

Frontend deposunda `.gitattributes` **yok**; backend'de var
(`* text=auto eol=lf`). Sonuç: `git status` **491 dosyayı** "değişmiş"
gösteriyor ve farkın tamamı satır sonu (CRLF/LF) — içerik farkı **sıfır**
(`git diff --ignore-cr-at-eol` boş).

İki bilgisayar arasında çalışırken bu her pull/push'ta sahte çakışma üretir.

```powershell
cd davetkart_frontend
# backend'dekiyle aynı içerik
Copy-Item ..\davetkart-backend-php-laravel\.gitattributes .
git add --renormalize .
git commit -m "chore: normalize line endings with .gitattributes"
```

Bu, `FAZ-4.md` §9.3'te açık madde olarak duruyordu.

---

## 3. Ayrı bir borç: Faz 4'ün frontend işi push edilmemiş

`origin/main` **21 Ağustos**'ta (`c2b8ec7`). Faz 4'ün `publicInvitation.ts` ve
`InvitePage.tsx` işi yalnızca ev bilgisayarında duruyor.

Faz 5'in frontend işine başlamadan önce o commit **push edilmeli**, yoksa iki
makinede ayrışmış iki gerçek olur.

---

## 4. Kontrol listesi

- [ ] F1 `types.ts` → `npm run lint` ile kalan işi listele
- [ ] F2 `data.ts`
- [ ] F3 `RsvpModal.tsx` + 🔴 honeypot
- [ ] F4 `RSVPForm.tsx` + 🔴 honeypot
- [ ] F5 `LiveRsvpPanel.tsx`
- [ ] F6 `rsvps.ts` + `persistence.ts`
- [ ] F7 `useRsvpStore.ts`
- [ ] Elle doğrulama: misafir sayfasından gerçek LCV gönder
- [ ] Elle doğrulama: DevTools'tan `website` alanını doldur → `201` gelmeli
      ama **kayıt düşmemeli**
- [ ] `.gitattributes` (§2)
- [ ] Faz 4'ün frontend commit'i push edildi (§3)
