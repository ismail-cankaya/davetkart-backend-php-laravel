# `app/Http/Controllers/Api/V1/MediaController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/MediaController.php`
> **Faz:** 6 — Medya dilimi, dosya 6.11
> **Uç nokta:** `POST /api/invitations/{invitation}/media` (rota 6.13'te bağlanacak)
> **Kardeş dosyalar:** [`InvitationController.md`](InvitationController.md) ·
> [`RsvpController.md`](RsvpController.md) · [`PublicRsvpController.md`](PublicRsvpController.md)

---

## 1. Controller neden bu kadar ince?

Gövde **üç ifade**: yetki sor, Action'ı çağır, Resource döndür.

`CLAUDE.md` §1 bunu bir üst sınır olarak koyuyor: *"Controller'lar sadece gelen
isteği ilgili Action'a yönlendirmekten ve Resource dönmekten sorumludur
(maksimum 3-8 satır). İçerisinde `if` blokları veya iş mantığı bulunamaz."*

Bu bir stil tercihi değil, **test edilebilirlik kararı**. İnce controller demek,
iş kuralının HTTP olmadan test edilebilmesi demek. Bu uçtaki her karar başka
bir katmanda duruyor:

| Soru | Kim cevaplıyor | Dosya |
|---|---|---|
| İstek sahibi kim? | Sanctum | `auth:sanctum` middleware |
| Bu davetiye onun mu? | `InvitationPolicy` (**P1**) | `Gate::authorize()` |
| Dosya kabul edilebilir biçimde mi? | `StoreMediaRequest` | `mimetypes:` + `max:` |
| Bu tür bu uçta yüklenebilir mi? | `StoreMediaRequest::allowedKinds()` | yalnızca `gallery` |
| Kota doldu mu? | `StoreUploadedMediaAction` (**E9**) | kilitli transaction |
| Nasıl saklanacak? | Action + `Storage` | rastgele ad, içerikten MIME |
| Hangi alanlar dışarı çıkacak? | `MediaResource` (**C1**) | `{id, url}` |
| Hata hangi HTTP koduna dönüşecek? | `ApiExceptionRenderer` (**H10**) | `HasErrorCode` |

Controller'ın işi bunları **sıraya dizmek**, hiçbirini yapmak değil.

---

## 2. 🔴 Neden `update`, `view` değil?

```php
Gate::authorize('update', $invitation);
```

`InvitationPolicy`'de `view()` ve `update()` **bugün aynı şeyi yapıyor**:

```php
public function view(User $user, Invitation $invitation): bool   { return $this->owns(...); }
public function update(User $user, Invitation $invitation): bool { return $this->owns(...); }
```

O hâlde hangisini yazdığımız fark etmez mi? **Eder** — ama bugün değil, yarın.

Faz 7'de `INVITATION_LOCKED` (403) kuralı gelecek: *yayınlanmış davetiye
kilitlenir.* O gün `update()` ek bir koşul kazanacak, `view()` kazanmayacak. Ve
o gün şu soru cevaplanacak: **yayınlanmış bir davetiyeye yeni fotoğraf
eklenebilir mi?**

`view` yazsaydık o kural **sessizce atlanırdı** — kod çalışmaya devam eder,
kimse fark etmez, ve paywall'da bir delik açılır.

> Aynı gerekçe Faz 5'te `RsvpPolicy::delete()` için kullanılmıştı: bir misafirin
> yanıtını silmek de davetiyenin verisini **değiştirmektir**.

🔴 Genel ilke: **bir yetki sorusu, bugünkü cevabına göre değil, sorduğu soruya
göre seçilir.** İki metot aynı sonucu veriyorsa doğru olanı seçmek bedava;
ayrıştıkları gün yanlış olanı bulmak pahalı.

---

## 3. Rota parametresi neden **model**, `PublicRsvpController`'da neden **string**?

| | `MediaController` (bu dosya) | `PublicRsvpController` (Faz 5) |
|---|---|---|
| İmza | `Invitation $invitation` | `string $invitation` |
| Çözüm | Route-model binding | `SubmitRsvpAction` içinde sorgu |
| Cevaplanan soru | *"Bu kayıt senin mi?"* | *"Bu kayıt herkese açık mı?"* |

Faz 5'te binding **bilerek** kullanılmamıştı: binding çalışsaydı
**yayınlanmamış** bir davetiye de çözülürdü ve görünürlük kararı Action'ın
dışına kaçardı. Görünürlük bir `if` değil, bir **sorgu kapsamıdır** (**P3**
ailesi).

Burada o sorun yok. Sahibin ucunda görünürlük diye bir soru yok — taslak
davetiyesine de fotoğraf yükleyebilmeli. Tek soru sahiplik, ve onu Policy
cevaplıyor.

### Yan kazanç: soft delete zaten kapıda duruyor

`Invitation` `SoftDeletes` kullanıyor. Route-model binding varsayılan olarak
`SoftDeletingScope` uygular, yani **silinmiş bir davetiye hiç çözülmez** ve
Laravel `ModelNotFoundException` fırlatır → `RESOURCE_NOT_FOUND` → **404**.

Bu, **H7**'nin (*sahiplik yoksa 404*) bedava gelen ikizi: silinmiş davetiye ile
hiç var olmamış davetiye **ayırt edilemez**.

> 🔴 `withTrashed()` eklemek bu kapıyı açardı. Faz 6'da buna ihtiyaç yok ve
> olmamalı: silinmiş bir davetiyeye dosya yüklemenin anlamı yok.

---

## 4. Metot enjeksiyonu — Action neden kurucuda değil?

```php
public function store(
    StoreMediaRequest $request,
    Invitation $invitation,
    StoreUploadedMediaAction $action,      // ← burada
): JsonResponse
```

### PHP/Laravel temeli: servis konteyneri

Laravel bir **dependency injection container** taşır. Bir metot parametresinde
tip belirtimi görürse, o sınıfın bir örneğini **kendisi üretip geçirir**. Sen
`new StoreUploadedMediaAction()` yazmazsın.

Peki neden kurucuda değil?

| | Kurucu enjeksiyonu | Metot enjeksiyonu |
|---|---|---|
| Ne zaman üretilir | Controller'ın **her** metodunda | Yalnızca **o metot** çağrılınca |
| Okunabilirlik | Bağımlılık sınıfın tepesinde | Bağımlılık **kullanıldığı yerde** |
| `index()` çağrılırsa | `StoreUploadedMediaAction` yine üretilir | Üretilmez |

Bu projede seçim **metot enjeksiyonu** (Faz 3'ten beri): bir bağımlılık, onu
**kullanan** metodun imzasında durur. Bir controller'a ikinci bir uç eklendiği
gün, o ucun bağımlılığı diğerine bulaşmaz.

### Parametre sırası önemli mi?

Hayır. Laravel rota parametrelerini **isimle**, servisleri **tiple** çözer.
`{invitation}` rota parametresi `$invitation` adına bakarak eşleşir; diğer ikisi
konteynerden gelir. Sıra yalnızca okunabilirlik için: *istek → kaynak → araç*.

---

## 5. `$request->kind()` ve `$request->uploadedFile()`

Controller `$request->input('kind')` yazmıyor. İki metot çağırıyor ve ikisi de
**tiplenmiş** değer döndürüyor:

```php
public function kind(): MediaKind          // string değil ENUM
public function uploadedFile(): UploadedFile   // null olamaz
```

Bu, **D5**'in (*Action'a giden veri `validated()`'ten gelir*) bir adım
ilerisi. `validated()` bir `array` döndürür — anahtarları ve tipleri
belirsizdir. FormRequest'e tiplenmiş erişimciler koymak üç şey kazandırır:

1. **Action'ın imzası dürüst olur.** `handle(Invitation, MediaKind, UploadedFile)`
   — üç parametre de kendi kendini anlatır.
2. **Dönüşüm tek yerde kalır.** `MediaKind::from()` yalnızca FormRequest'te
   çağrılıyor; Action bir string'i enum'a çevirmek zorunda değil.
3. **PHPStan işini yapabilir.** `array` içinden gelen değerin tipi
   `mixed`'dir; metot dönüşünün tipi bellidir.

> `uploadedFile()` adı `file()` değil, çünkü `FormRequest::file()` zaten dolu
> (framework'ün kendi metodu). İsim çakışmasını gizlemek yerine **farklı bir ad
> seçmek**, "üstüne yazıp umut etmek"ten güvenli.

---

## 6. 🔴 Faz 1'in **M1** kuralı burada karşılığını buluyor

`ForceJsonResponse` middleware'i Faz 1'de yazılmıştı:

```php
$request->headers->set('Accept', 'application/json');
// Content-Type'a DOKUNULMAZ
```

Ve **M1** kuralı şöyle konmuştu:

> Yalnızca `Accept` ezilir; **`Content-Type` asla**. `Content-Type` bir
> olgudur; ezilirse **Faz 6 dosya yüklemesi kırılır**.

Bugün Faz 6'dayız. Neden kırılırdı?

Bir dosya yüklemesi `multipart/form-data` gövdesiyle gelir ve başlığı şuna
benzer:

```
Content-Type: multipart/form-data; boundary=----WebKitFormBoundaryAbC123
```

O `boundary` değeri, gövdedeki parçaları birbirinden ayıran işarettir ve
**tarayıcı üretir**. `Content-Type`'ı `application/json` diye ezseydik PHP
gövdeyi ayrıştıramaz, `$_FILES` **boş kalır**, `$request->file('file')` `null`
döner — ve hata mesajı *"file alanı gereklidir"* olurdu. Yani belirti,
sebebin bulunduğu yerden **üç katman uzakta** görünürdü.

| Başlık | Ne söyler | Kim söyler | Ezilebilir mi |
|---|---|---|---|
| `Accept` | *"Bana şu biçimde cevap ver"* | İstemcinin **tercihi** | ✅ Evet — tercihe müdahale edilebilir |
| `Content-Type` | *"Gövdeyi şu biçimde gönderdim"* | Bir **olgu** | ❌ Asla |

🔴 Ders: **bir tercihi ezebilirsin, bir olguyu ezemezsin.** Beş faz önce
yazılan bir yorum satırı, bugün çalışan bir uç noktanın sebebi.

---

## 7. Neden `201`, `200` değil?

```php
return (new MediaResource($media))
    ->response()
    ->setStatusCode(JsonResponse::HTTP_CREATED);
```

`MediaResource`'ı doğrudan döndürseydik Laravel **200** yazardı. RFC 9110'a
göre `201 Created` şu anlama gelir: *"İstek başarılı ve sonucunda **yeni bir
kaynak oluştu**."*

Bu uçta gerçekten yeni bir kaynak oluşuyor: diskte bir dosya, veritabanında bir
satır, ve yanıtta o satırın **kimliği**. `InvitationController::store()` ve
`PublicRsvpController::store()` aynı deseni kullanıyor (**C3**: aynı sözleşmeyi
üreten uçlar aynı şekilde davranır).

### `->response()` ne yapıyor?

`JsonResource` bir yanıt **değildir**, yanıta dönüşebilen bir nesnedir.
`->response()` onu bir `JsonResponse`'a çevirir ve böylece durum kodu, başlık
gibi HTTP detaylarına erişilebilir hâle gelir.

⚠️ Zarf yine Laravel tarafından ekleniyor — `{data: {id, url}}`. Elle
`['data' => ...]` yazmak `{data: {data: ...}}` üretirdi.

---

## 8. Neden hiç `if` yok? Hata yolu nereden geçiyor?

Bu uç dört farklı hata üretebilir ve **hiçbiri** controller'da görünmüyor:

```
StoreMediaRequest doğrulaması kırıldı
   └→ ValidationException → 422 VALIDATION_FAILED  (fields: file / kind)

Gate::authorize başarısız
   └→ AuthorizationException → 404 RESOURCE_NOT_FOUND  (H7)

Route-model binding çözemedi (yok veya soft-deleted)
   └→ ModelNotFoundException → 404 RESOURCE_NOT_FOUND

Kota dolu
   └→ MediaQuotaExceededException (HasErrorCode) → 403 MEDIA_QUOTA_EXCEEDED
```

Son satır Faz 5'in kazancı: `MediaQuotaExceededException` `HasErrorCode`
arayüzünü uyguluyor, yani `ApiExceptionRenderer`'a **elle kol eklemek
gerekmedi**. Eskiden **H11** bunu bir hatırlama yüküne bağlıyordu; unutmanın
bedeli sessiz bir **500**'dü.

**H10**: *Action ve Controller hata yanıtı üretmez, exception fırlatır.* Biçim
kararı tek yerde durur.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `Gate::authorize('view', ...)` yazmak | Faz 7'nin kilit kuralı sessizce atlanır; paywall'da delik |
| 2 | `Gate::authorize()` satırını unutmak | 🔴 **Herkes herkesin davetiyesine dosya yükler** — IDOR |
| 3 | `withTrashed()` eklemek | Silinmiş davetiye ile var olmayan ayırt edilebilir hâle gelir (H7 delinir) |
| 4 | `$request->input('kind')` kullanmak | Ham string; `MediaKind::from()` Action'a sızar, tip garantisi kaybolur |
| 5 | `$request->file('file')` doğrudan kullanmak | Dönüş `UploadedFile\|array\|null`; PHPStan kırılır, `null` sessizce ilerler |
| 6 | Durum kodunu `200` bırakmak | Sözleşme yalan söyler; istemci "oluştu mu?" sorusunu yanıttan okuyamaz |
| 7 | Controller'a `try/catch` koymak | H10 ihlali; hata biçimi iki yere düşer |
| 8 | Kota kontrolünü buraya taşımak | Action'ın kilidi devre dışı kalır; `if` yasağı da ihlal |
| 9 | Rota parametresini `string` yapıp elle sorgulamak | Policy'nin göreceği model controller'da doğar; binding'in soft-delete koruması kaybolur |
| 10 | `MediaResource::collection()` beklemek | Tek kayıt dönüyor; koleksiyon zarfı `{data: [...]}` üretir ve frontend `url` bulamaz |

---

## 10. Kendin dene

Rota 6.13'te bağlanacağı için uç henüz canlı değil. Şimdilik tinker ile:

```php
// php artisan tinker
use App\Http\Controllers\Api\V1\MediaController;

// Konteyner controller'ı ve bağımlılıklarını çözebiliyor mu?
app(MediaController::class);       // exception atmamalı

// Policy hangi soruyu cevaplıyor?
$user = App\Models\User::first();
$inv  = $user->invitations()->first();

Gate::forUser($user)->allows('update', $inv);    // true
Gate::forUser(App\Models\User::factory()->create())->allows('update', $inv);  // false
```

### Mutasyon tablosu (kural 14) — 6.15'te yazılacak testler için

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 1 | `Gate::authorize()` satırını sil | "başkasının davetiyesine yükleme 404 döner" · 🔴 **T14**: yanıtı değil **etkiyi** doğrula — `media` tablosunda satır oluşmamalı |
| 2 | `'update'` → `'view'` | ⚠️ **Bugün hiçbiri** — ikisi aynı sonucu veriyor. Faz 7'de anlam kazanacak, ve o gün test yazılacak (B6) |
| 3 | `setStatusCode(HTTP_CREATED)` satırını sil | "başarılı yükleme 201 döner" |
| 4 | `$request->kind()` → `MediaKind::RsvpPhoto` sabiti | "sahibin ucu yalnızca gallery kabul eder" |
| 5 | Rota parametresini `withTrashed()` ile çöz | "silinmiş davetiyeye yükleme 404 döner" |

2 numaralı satır kasıtlı: bugün **öldürülemeyen** bir mutasyon var ve bunu
yazmak, olmadığını sanmaktan iyidir.

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Route-model binding** | Rotadaki kimliği otomatik olarak model nesnesine çeviren Laravel özelliği |
| **Servis konteyneri** | Sınıf bağımlılıklarını tipe bakarak üreten Laravel bileşeni |
| **Metot enjeksiyonu** | Bağımlılığın kurucuda değil, kullanıldığı metodun imzasında istenmesi |
| **`multipart/form-data`** | Dosya yüklemede kullanılan, gövdeyi `boundary` ile parçalara ayıran içerik tipi |
| **`boundary`** | Multipart gövdedeki parçaları ayıran, tarayıcının ürettiği işaret dizisi |
| **IDOR** | Insecure Direct Object Reference — başkasının kaynağına kimliğini değiştirerek erişme |
| **`201 Created`** | "İstek başarılı ve yeni bir kaynak oluştu" anlamına gelen HTTP durum kodu |

---

## 12. Sırada ne var?

**6.12 — `PublicMediaController`.** Misafirin LCV foto/videosu:
`POST /api/public/invitations/{invitation}/media`.

Orada bu dosyadaki **her** karar tersine dönecek:

| | `MediaController` | `PublicMediaController` |
|---|---|---|
| Auth | `auth:sanctum` | ❌ yok |
| Rota parametresi | `Invitation` (binding) | `string` — görünürlük sorgu kapsamı |
| Yetki | `Gate::authorize('update')` | ❌ yok — **P5**: yetki üst kaynağa devredilir |
| Kabul edilen tür | `gallery` | `rsvp_photo` \| `rsvp_video` |
| Hız sınırı | genel `throttle:api` | 🔴 ayrı, **sıkı** bir kova |
| Kota aşımı yanıtı | `params: {limit}` | 🔴 `params` **boş** (H9) |

🔴 Ve orada Faz 5'in en zor sorusu tekrar sorulacak: **kimliği bilinmeyen biri
diske dosya yazdırabiliyorsa, onu ne durdurur?**

| İlgili | Nerede |
|---|---|
| Action | [`../../../../Actions/Media/StoreUploadedMediaAction.md`](../../../../Actions/Media/StoreUploadedMediaAction.md) |
| Resource | [`../../../Resources/MediaResource.md`](../../../Resources/MediaResource.md) |
| İstek | [`../../../Requests/Media/StoreMediaRequest.md`](../../../Requests/Media/StoreMediaRequest.md) |
| Policy | [`../../../../Policies/InvitationPolicy.md`](../../../../Policies/InvitationPolicy.md) |
| M1 kuralı | [`../../../Middleware/ForceJsonResponse.md`](../../../Middleware/ForceJsonResponse.md) |
| Faz özeti | [`../../../../../fazlar/FAZ-6.md`](../../../../../fazlar/FAZ-6.md) |
