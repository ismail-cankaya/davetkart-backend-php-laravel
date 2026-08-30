# `app/Http/Resources/MediaResource.php`

> **Kod dosyası:** `app/Http/Resources/MediaResource.php`
> **Faz:** 6 — Medya dilimi, dosya 6.10
> **Kardeş dosyalar:** [`RsvpResource.md`](RsvpResource.md) ·
> [`PublicTimelineEventResource.md`](PublicTimelineEventResource.md)
> **Birlikte okunur:** [`../../Models/Media.md`](../../Models/Media.md) ·
> [`../../Actions/Media/StoreUploadedMediaAction.md`](../../Actions/Media/StoreUploadedMediaAction.md)

---

## 1. Resource nedir, neden var?

Bir **API Resource**, veritabanı satırı ile HTTP yanıtı arasındaki **çeviri
katmanıdır**. `CLAUDE.md` §1'e göre bu, `snake_case` → `camelCase` dönüşümünün
yapıldığı **tek** yerdir.

Ama asıl işi çeviri değil, **sınır çizmek**:

```
Veritabanı satırı                    HTTP yanıtı
─────────────────                    ───────────
id            ────────────────────→  id
invitation_id      ✗
kind               ✗
disk               ✗
path               ✗                 url        ← türetilmiş, kolonda YOK
mime_type          ✗
size_bytes         ✗
optimized_at       ✗
created_at         ✗
updated_at         ✗
```

Dokuz kolondan **biri** çıkıyor, bir alan da **yoktan türetiliyor**.

**C1**: *Resource bir beyaz listedir.* Varsayılan kapalı; yalnızca açıkça
sayılanlar dışarı çıkar. Ters yaklaşım (`$this->resource->toArray()` gibi bir
"sihirli dönüşüm") bir gün eklenen bir kolonu **sessizce** yayına sokardı.

### PHP/Laravel temeli: `@mixin` ne işe yarıyor?

```php
/** @mixin Media */
final class MediaResource extends JsonResource
```

`JsonResource` sınıfının `id` diye bir özelliği **yoktur**. `$this->id`
çalışıyor çünkü Laravel `DelegatesToResource` trait'iyle sihirli metotlar
tanımlamış:

```php
// vendor/laravel/framework/src/Illuminate/Http/Resources/DelegatesToResource.php
public function __get($key)
{
    return $this->resource->{$key};        // ← özellik erişimi modele gider
}

public function __call($method, $parameters)
{
    if (static::hasMacro($method)) { ... }
    return $this->forwardCallTo($this->resource, $method, $parameters);
}                                          // ← metot çağrısı da modele gider
```

Bu **çalışma anında** işe yarar ama **statik analiz** için görünmezdir: PHPStan
`$this->id`'yi görünce "böyle bir özellik yok" der. `@mixin Media` docblock'u
ona *"bu sınıfın bilinmeyen üyeleri `Media`'dan gelir"* der.

🔴 PHPStan **level 8**'de bu docblock olmadan dosya kırılır. Yani burada bir
yorum satırı, kalite kapısının bir parçası.

---

## 2. 🔴 `disk` ve `path` neden dışarı çıkmıyor?

Bu, fazın en önemli sözleşme kararı.

### `path` bir sızıntıdır

`media/gallery/aB3xK9...q7.jpg` iki şey söyler:

1. **Dizin düzeni** — dosyaların `media/<tür>/` altında toplandığı
2. **Ad üretim deseni** — 40 karakter + içerikten türetilmiş uzantı

İkisi de saldırgana sistemin iç yapısını anlatır. Faz 4'te
`server metadata is not exposed` testi tam bu aileden yazılmıştı: **iç durumun
ifşası, bayt sayısından bağımsız bir maliyettir** (`docs/08` §1.3).

### `disk` bir sözleşme borcudur

Bugün `'public'`. Yarın `'s3'` olacak. `disk` alanı sözleşmede olsaydı:

- Frontend ona bakan bir kod yazabilirdi (`if (media.disk === 'public')`)
- Ve göç günü **frontend kırılırdı**

**C5**: *gövdeye giden alanlar da beyaz listedir — sözleşmede yeri olmayan
alan, ona bağlanılmasına davetiye çıkarır.* Bir alanı bugün göndermek, yarın
onu kaldıramamak demektir.

---

## 3. `url` — kolonda olmayan alan

```php
'url' => $this->url(),
```

Bu satır bir **metot çağrısı**, özellik erişimi değil. Zincir şöyle:

```
MediaResource::toArray()
   ↓  __call (DelegatesToResource)
Media::url()
   ↓
Storage::disk($this->disk)->url($this->path)
   ↓
config('filesystems.disks.public.url') . '/' . $path
   ↓
'http://localhost:8000/storage/media/gallery/aB3x...q7.jpg'
```

### Neden URL veritabanında saklanmıyor?

**E1**: *türetilebilen veri saklanmaz.* Ham URL yazsaydık `APP_URL` veritabanına
gömülürdü ve alan adı değiştiği gün **her satır** kırılırdı — üstelik hiçbir
migration onları düzeltemezdi, çünkü hangi parçanın alan adı olduğunu bilmek
için metin ayrıştırmak gerekirdi.

### Neden `disk` satırdan okunuyor, config'ten değil?

`Media::url()` `config('davetkart.media.disk')` **kullanmıyor**, `$this->disk`
kullanıyor. Fark ince ama kritik:

| Kaynak | Hangi soruyu cevaplıyor |
|---|---|
| `config('davetkart.media.disk')` | *"ŞU AN nereye yazıyoruz?"* |
| `$this->disk` (kolon) | *"O DOSYA nereye yazılmıştı?"* |

Yerel diskten S3'e geçildiği gün eski satırlar hâlâ `'public'` taşır ve
URL'leri doğru çözülür. Yeni satırlar `'s3'` taşır. **Göç, tek bir config
satırıyla ve geriye dönük kırılma olmadan yapılabilir.**

6.2'de `disk` kolonunu saklama kararının karşılığını tam olarak burada
alıyoruz. Bir kararın değeri, onu alan dosyada değil **onu kullanan dosyada**
görünür.

---

## 4. Neden `id` gönderiliyor? (frontend bugün okumuyor)

`services/media.ts` yalnızca `url` okuyor:

```ts
function toHostedUrl(payload: unknown): string {
  const body = unwrapEnvelope(payload);
  if (body && typeof body === 'object' && typeof (body as { url?: unknown }).url === 'string') {
    return (body as { url: string }).url;
  }
  throw new Error('Unexpected /media/upload response shape');
}
```

Yani `id` bugün **kullanılmıyor**. Ders 26 (*çağıranı olmayan kod, doğru olduğu
varsayılan koddur*) buna karşı bir argüman gibi görünüyor. Yine de gönderiliyor,
üç sebeple:

1. **Bir yazma ucunun yanıtı, yarattığı kaynağın kimliğini taşır.** REST'te
   `201 Created` bunun için vardır. Kimliksiz bir yanıt, istemcinin kendi
   oluşturduğu satıra **hiçbir zaman** referans verememesi demektir.
2. **Faz 3'te aynı karar verilmişti (K44).** Program adımlarının kimliğini
   backend üretiyor ve frontend `adoptServerIds()` ile geri yazıyor. Aynı desen.
3. **Ders 26 kod için geçerli, veri için değil.** Ölü kod yanlış varsayımlar
   taşır; sözleşmedeki bir kimlik alanı taşımaz — yalnızca kullanılmayı bekler.

> ⚠️ Bu, `docs/09`'un *"Yanıt `{url}`"* notundan küçük bir sapma. Süperset
> olduğu için frontend **kırılmaz** (`toHostedUrl` yalnızca `url`'e bakıyor),
> ama plandan sapma olarak `FAZ-6.md` §7'ye yazılacak.

---

## 5. Neden `kind`, `mimeType`, `sizeBytes`, `optimizedAt` YOK?

Her biri ayrı bir gerekçeyle elendi — ve gerekçeleri farklı:

| Alan | Neden yok |
|---|---|
| `kind` | İstemci onu **kendisi gönderdi**. Geri vermek bilgi eklemez, yalnızca sözleşmeyi genişletir |
| `mimeType` | Bugün okuyanı yok. Gerekirse eklenir — **alan eklemek kolay, çıkarmak imkânsıza yakın** (C1) |
| `sizeBytes` | Aynı. Ayrıca kuyruk işi dosyayı küçültünce **değişiyor**, yani yanıttaki değer anında bayatlıyor |
| `optimizedAt` | 🔴 En ilginci: yükleme anında **her zaman `null`**. Kuyruk işi henüz koşmadı. Her zaman aynı değeri taşıyan bir alan **bilgi taşımaz** |

`optimizedAt`'in gerekçesi genelleştirilebilir: *bir alanın tek bir değer
alabildiği bağlamda, o alan sözleşmeye girmez.* Girseydi frontend'de
`if (media.optimizedAt)` gibi **hiçbir zaman doğru olmayacak** bir dal
doğururdu.

---

## 6. Zarf: `{ data: { id, url } }`

**K11 / C2**: Auth uçları zarfsız `{user, token}` döner; **diğer her yanıt**
`{data: ...}` zarfıyla döner. Medya yüklemesi auth ucu değil, yani zarflı.

Frontend bunu zaten biliyor:

```ts
const body = unwrapEnvelope(payload);   // { data: X } ise X döner
```

Yani sözleşme:

```json
{
  "data": {
    "id": "01k3n8...q7",
    "url": "http://localhost:8000/storage/media/gallery/aB3x...q7.jpg"
  }
}
```

⚠️ `MediaResource::collection()` kullanılırsa zarf yine `{data: [...]}` olur —
liste ucu geldiği gün ek bir karar gerekmez.

---

## 7. Bu Resource iki okuyucuya birden hizmet ediyor — C4 gerekmez mi?

**C4**: *aynı veri, farklı okuyucular için farklı Resource'a çıkar.* Faz 4'te
`PublicTimelineEventResource` bu yüzden doğmuştu (misafirin sürümünde `id` yok).

Burada gerekmiyor, ve gerekçesi **ders 42**'nin (*bir kuralı uygulamak,
gerekçesini kontrol etmeden kopyalamak değildir*) doğrudan uygulaması:

| | Faz 4 (davetiye) | Burada (medya) |
|---|---|---|
| Okuyucular | Sahip **ve** yabancı misafir | Sahip **veya** yükleyen misafir |
| Veri kime ait | Sahibin verisi, misafire gösteriliyor | 🔴 **Okuyucunun kendi az önce yüklediği dosya** |
| Gizlenecek bir şey var mı | ✅ Var (`id`, kapalı modüller) | ❌ Yok |

Faz 5'te `RsvpResource` için de aynı sonuca varılmıştı: misafir kendi yazdığı
veriyi geri alıyorsa ayrı bir Resource, **hiçbir şeyi gizlemeyen** bir kopya
olurdu.

🔴 Ama bu **bugünün** cevabı. Bir gün "davetiyenin galerisini listele" ucu
gelirse, orada misafir **başkalarının** yüklediği dosyaları görecek — ve o gün
soru yeniden sorulmalı.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `'url' => $this->url` (parantezsiz) | `__get` çalışır, `url` diye bir **kolon yok** → `null` döner. Sessiz bir bozukluk |
| 2 | `path`'i "işe yarar" diye eklemek | İç dizin düzeni sızar; ve bir kez gönderilen alan geri alınamaz (C5) |
| 3 | `disk`'i eklemek | S3 göçü günü frontend kırılır |
| 4 | `@mixin Media` docblock'unu unutmak | PHPStan level 8 kırılır — zincir testlere hiç ulaşmaz |
| 5 | `Media::url()`'ü accessor yapıp `$media->url` demek | Alan `toArray()`/JSON çıktısına **sızabilir**; türetilmiş değer kolon gibi görünür |
| 6 | Zarfı elle `['data' => ...]` diye yazmak | Laravel zaten sarıyor → `{data: {data: ...}}` |
| 7 | `optimizedAt` eklemek | Yükleme anında her zaman `null`; frontend'de hiç doğru olmayan bir dal doğar |
| 8 | Sözleşmeyi `Media` modelinde `$hidden`/`$visible` ile kurmaya çalışmak | İki doğruluk kaynağı; Resource'un varlık sebebi ortadan kalkar |

---

## 9. Kendin dene

```php
// php artisan tinker
use App\Http\Resources\MediaResource;
use App\Models\Media;

$media = Media::factory()->create();

// Resource'un ürettiği ham dizi
(new MediaResource($media))->toArray(request());
// ['id' => '01k3...', 'url' => 'http://localhost:8000/storage/media/gallery/....jpg']

// 🔴 Beyaz listeyi doğrula: hiçbir depolama detayı yok
array_keys((new MediaResource($media))->toArray(request()));
// ['id', 'url']   ← 'disk' ve 'path' YOK

// url gerçekten türetiliyor mu? Diski değiştir, URL değişsin:
$media->disk;          // 'public'
$media->url();         // .../storage/...

// Zarfı gör
(new MediaResource($media))->response()->getContent();
// {"data":{"id":"...","url":"..."}}
```

### Mutasyon denemesi (kural 14)

| Mutasyon | Kırılması gereken test (6.15'te yazılacak) |
|---|---|
| `'path' => $this->path` satırı ekle | "yükleme yanıtı depolama detayı taşımaz" |
| `'url' => $this->url` yap (parantezi sil) | "yükleme yanıtı kullanılabilir bir URL döner" |
| `'id'` satırını sil | "yükleme yanıtı oluşturulan kaydın kimliğini taşır" |
| `@mixin Media` docblock'unu sil | ⚠️ Hiçbir **test** kırılmaz — **PHPStan** kırılır. Kalite kapısının test dışı bir halkası |

Son satır önemli: bir savunmanın koruyucusu her zaman bir test değildir.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **API Resource** | Model → JSON çevirisinin ve alan beyaz listesinin yapıldığı sınıf |
| **Beyaz liste** | Varsayılan kapalı; yalnızca açıkça sayılanlar geçer |
| **Zarf (envelope)** | Yanıtı saran dış katman — burada `{data: ...}` |
| **`@mixin`** | *"Bu sınıfın bilinmeyen üyeleri şu sınıftan gelir"* diyen PHPDoc etiketi |
| **Sihirli metot** | `__get` / `__call` — tanımlı olmayan erişimleri yakalayan PHP metotları |
| **Türetilmiş alan** | Saklanmayan, her istekte hesaplanan değer (`url`) |
| **Süperset** | Beklenenin tamamını içeren, üstüne fazladan alan taşıyan yanıt |

---

## 11. Sırada ne var?

**6.11 — `MediaController`.** Sahibin yükleme ucu:
`POST /api/invitations/{invitation}/media`.

Orada üç şey birleşecek:

1. `Gate::authorize('update', $invitation)` — sahiplik (**P1**); davetiyeye
   dosya eklemek onu **değiştirmektir**, bu yüzden `view` değil `update`
2. `StoreMediaRequest` — yalnızca `gallery` türünü kabul eder
3. `StoreUploadedMediaAction` → `MediaResource` → `201`

🔴 Ve bir sözleşme borcu: frontend bugün `POST /media/upload` çağırıyor,
gövdede `kind` ve `invitationId` **yok**. Uç iç içe kaynağa taşındığı için
`services/media.ts` uyarlanacak — Faz 3 ve Faz 5'teki gibi bir frontend
listesi doğacak.

| İlgili | Nerede |
|---|---|
| Model | [`../../Models/Media.md`](../../Models/Media.md) |
| Action | [`../../Actions/Media/StoreUploadedMediaAction.md`](../../Actions/Media/StoreUploadedMediaAction.md) |
| Kardeş Resource | [`RsvpResource.md`](RsvpResource.md) |
| Sözleşme kuralları | `CLAUDE.md` §2 · `docs/08-HATA-SOZLESMESI.md` |
| Faz özeti | [`../../../fazlar/FAZ-6.md`](../../../fazlar/FAZ-6.md) |
