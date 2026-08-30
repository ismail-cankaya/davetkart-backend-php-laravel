# `app/Http/Controllers/Api/V1/PublicMediaController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/PublicMediaController.php`
> **Faz:** 6 — Medya dilimi, dosya 6.15
> **Uç nokta:** `POST /api/public/invitations/{invitation}/media` (rota 6.16'da)
> **Kardeş dosyalar:** [`MediaController.md`](MediaController.md) ·
> [`PublicRsvpController.md`](PublicRsvpController.md)

---

## 1. Aynı iş, ters kararlar

Bu dosya `MediaController` (6.11) ile **aynı işi** yapıyor: bir dosya alıp
kaydediyor. Ama neredeyse her kararı ters:

| | `MediaController` | `PublicMediaController` |
|---|---|---|
| Auth | `auth:sanctum` | ❌ yok — **K12** grubu |
| Rota parametresi | `Invitation` (binding) | `string` |
| Yetki | `Gate::authorize('update')` | ❌ yok — **P5** |
| Action | `StoreUploadedMediaAction` | `StoreGuestMediaAction` |
| Kabul edilen tür | `gallery` | `rsvp_photo` \| `rsvp_video` |
| Kota aşımı `params` | `{limit: 30}` | `{}` — **H9** |

🔴 İkisini tek bir controller'da `if` ile birleştirmek mümkündü — ve **yanlış**
olurdu. Sebep `if` yasağı değil: **K12**. Auth gerektirmeyen rotalar
`/api/public/` öneki altında **ayrı** gruplanır, çünkü o ayrım
`auth:sanctum`'u unutma riskini **yapısal olarak** kaldırır. Tek controller,
iki farklı rota grubuna hizmet etseydi o fail-safe tasarım bozulurdu.

---

## 2. 🔴 Rota parametresi neden `string`?

```php
public function store(
    StorePublicMediaRequest $request,
    string $invitation,              // ← model DEĞİL
    StoreGuestMediaAction $action,
): JsonResponse
```

`Invitation $invitation` yazsaydık Laravel route-model binding'i devreye girer
ve davetiyeyi **yayın durumuna bakmadan** çözerdi. Sonuç: yayınlanmamış bir
davetiyenin kimliğini bilen biri ona dosya yükleyebilirdi.

Görünürlük bir `if` değil, **sorgunun kapsamıdır** (**P3** ailesi) — ve o kapsam
`ResolvePublicInvitationAction`'da tanımlı. Binding onu **atlar**.

Faz 5'te `PublicRsvpController` için birebir aynı karar verilmişti. Bu, C3'ün
controller katmanındaki hâli: aynı tehdide karşı aynı savunma şekli.

> ⚠️ `string` almak, kimliğin **hiç** doğrulanmadığı anlamına gelmez: rota
> katmanındaki `whereUlid()` biçimsiz kimliği veritabanına hiç ulaştırmaz
> (**O6**, 6.16'da bağlanacak).

---

## 3. `Gate::authorize` neden yok?

`MediaController`'ın ilk satırı `Gate::authorize('update', $invitation)`. Burada
yetki kontrolü **hiç yok** — ve bu bir eksiklik değil.

🔴 **Yüklenen medyanın sahibi yok.** Misafirin kimliği bilinmiyor; ne bir
`User`'ı var, ne bir token'ı. Policy'ye soracak bir "kim" yok.

**P5** (Faz 5'te doğdu): *alt kaynağın yetkisi üst kaynağın policy'sine
devredilir.* Burada devir bir adım daha ileri gidiyor: yetki sorusu bir
**erişilebilirlik** sorusuna dönüşüyor.

```
"Bu kullanıcı bu kaynağa dokunabilir mi?"      ← MediaController
"Bu davetiye herkese açık ve LCV'ye açık mı?"  ← burası
```

Cevabı `StoreGuestMediaAction` → `ResolveOpenRsvpInvitationAction` veriyor.
Faz 5'te `RsvpPolicy` de sahiplik kuralını kopyalamak yerine
`InvitationPolicy`'ye devretmişti — aynı ilke.

---

## 4. Controller yine `if` içermiyor — ama bu sefer sebebi farklı

`CLAUDE.md` §1'in `if` yasağı bu fazda gevşetildi. Yine de bu dosyada tek bir
dal yok, çünkü **koyacak bir dal kalmadı**:

| Karar | Nerede |
|---|---|
| Biçim, boyut, MIME | `StorePublicMediaRequest` |
| Tür izni (misafir galeriye yükleyemez) | `StoreGuestMediaAction` (değişmez) |
| Görünürlük / modül / son tarih | `ResolveOpenRsvpInvitationAction` |
| Kota + kilit + rastgele ad | `StoreUploadedMediaAction` |
| Hata → HTTP kodu | `ApiExceptionRenderer` |

Bu, katmanlı mimarinin ölçülebilir çıktısı: **beş savunma, sıfır `if`.**

---

## 5. Yanıt: `201` ve `{data: {id, url}}`

`MediaController` ile birebir aynı (**C3**: aynı sözleşmeyi üreten iki uç tek
biçimde davranır):

```json
{ "data": { "id": "01k3n8…q7", "url": "http://localhost:8000/storage/media/rsvp_photo/aB3x…q7.jpg" } }
```

🔴 `id` burada `MediaController`'dakinden **daha kritik**. Sahip için `id` bugün
kullanılmıyor (galeri URL dizisi tutuyor); misafir için ise zorunlu: yüklediği
dosyayı LCV formuna bağlamanın **tek yolu** o kimliği geri göndermek
(`photoMediaId`, 6.19).

URL gönderemez — istemci *"şu URL benim"* diyemez, aidiyet doğrulanamaz
(**N1**: aidiyet doğrulanacak girdi değil, yapısal garanti olmalı).

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `Invitation $invitation` (binding) kullanmak | Yayınlanmamış davetiyeye misafir dosya yükler |
| 2 | Rotayı `/api/public/` dışına koymak | **K12** fail-safe grubu delinir; `auth:sanctum` unutulursa kimse fark etmez |
| 3 | `StoreMediaRequest` (sahibin) kullanmak | Misafir `gallery` yükleyebilir hâle gelir |
| 4 | `Gate::authorize` eklemeye çalışmak | `$request->user()` `null`; her istek 403/500 |
| 5 | `StoreUploadedMediaAction`'ı doğrudan çağırmak | Tür izni **ve** açıklık kontrolü atlanır (6.14 §3) |
| 6 | Yanıtta `url` yerine yalnızca `id` dönmek | Misafir önizlemeyi gösteremez |
| 7 | Yanıtta `id`'yi atlamak | LCV'ye bağlama imkânsızlaşır (§5) |
| 8 | `throttle` middleware'ini rotaya koymayı unutmak | 🔴 Honeypot'suz bir auth'suz yazma yolu **sınırsız** kalır (6.14 §2) |

8 numaralı satır bu ucun en kritik noktası: Faz 5'te honeypot ilk savunmaydı,
burada yok. Hız sınırı **tek** ucuz katman.

---

## 7. Kendin dene

Rota 6.16'da bağlanıyor; sonrasında:

```powershell
# 1) Yayında + LCV açık bir davetiye kimliği al
php artisan tinker --execute="echo App\Models\Invitation::factory()->published()->create(['show_rsvp'=>true,'rsvp_deadline'=>null])->id;"

# 2) Misafir olarak yükle (TOKEN YOK)
curl.exe -X POST "http://127.0.0.1:8000/api/public/invitations/<ULID>/media" `
  -H "Accept: application/json" `
  -F "kind=rsvp_photo" `
  -F "file=@C:\Users\<sen>\Pictures\test.jpg"
# -> 201 {"data":{"id":"...","url":"..."}}

# 3) 🔴 Misafir galeriye yükleyebilir mi?
curl.exe -X POST "http://127.0.0.1:8000/api/public/invitations/<ULID>/media" `
  -H "Accept: application/json" -F "kind=gallery" -F "file=@...\test.jpg"
# -> 422 VALIDATION_FAILED  (FormRequest eledi, Action'a hiç gelmedi)
```

### Mutasyon tablosu (kural 14)

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 1 | `string $invitation` → `Invitation $invitation` | "yayınlanmamış davetiyeye misafir yükleyemez" |
| 2 | `StorePublicMediaRequest` → `StoreMediaRequest` | "misafir gallery yükleyemez" |
| 3 | `StoreGuestMediaAction` → `StoreUploadedMediaAction` | "LCV kapalıyken misafir yükleyemez" |
| 4 | `setStatusCode(HTTP_CREATED)` sil | "başarılı misafir yüklemesi 201 döner" |

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Fail-safe tasarım** | Bir şeyin unutulması durumunda güvenli tarafta kalan yapı |
| **Route-model binding** | Rotadaki kimliği otomatik model nesnesine çeviren Laravel özelliği |
| **P5** | Alt kaynağın yetkisinin üst kaynağa devredilmesi |
| **K12** | Auth'suz rotaların `/api/public/` altında gruplanması kuralı |

---

## 9. Sırada ne var?

**6.16 — `routes/api.php` + `throttle:media` limiter.** İki uç da rotaya
bağlanacak ve misafir ucu kendi hız sınırı kovasını alacak.

| İlgili | Nerede |
|---|---|
| Action | [`../../../../Actions/Media/StoreGuestMediaAction.md`](../../../../Actions/Media/StoreGuestMediaAction.md) |
| İstek | [`../../../Requests/Media/StorePublicMediaRequest.md`](../../../Requests/Media/StorePublicMediaRequest.md) |
| Resource | [`../../../Resources/MediaResource.md`](../../../Resources/MediaResource.md) |
| Kardeş uç | [`MediaController.md`](MediaController.md) · [`PublicRsvpController.md`](PublicRsvpController.md) |
