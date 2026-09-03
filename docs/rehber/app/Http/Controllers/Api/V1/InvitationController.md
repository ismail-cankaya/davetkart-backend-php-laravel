# `app/Http/Controllers/Api/V1/InvitationController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/InvitationController.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.11
> **Aynı adımda:** `routes/api.php` — bkz. [`routes/api.md`](../../../../routes/api.md)

---

## 1. Katmanların birleştiği yer

Sekiz dosya boyunca parçaları yazdık. Controller onları **bağlıyor** — ve
kendisi neredeyse hiçbir şey yapmıyor:

```php
public function store(StoreInvitationRequest $request, CreateInvitationAction $action): JsonResponse
{
    Gate::authorize('create', Invitation::class);

    $invitation = $action->handle($user, $request->invitationAttributes(), $request->timelineEvents());

    return (new InvitationResource($invitation))->response()->setStatusCode(201);
}
```

Dört satır: yetki sor, iş kuralını çağır, sonucu biçimlendir, durum kodunu ayarla.
`CLAUDE.md` §1: *Controller'lar sadece gelen isteği ilgili Action'a yönlendirmekten
ve Resource dönmekten sorumludur (maksimum 3-8 satır).*

İçinde `if` yok, SQL yok, `try/catch` yok. Hata olursa exception fırlar ve
`ApiExceptionRenderer` onu zarfa çevirir (H10) — Faz 1'de kurulmuştu.

### İstek tek bir metodun içinde nasıl akıyor?

```
1. auth:sanctum          → token gecerli mi?           yoksa 401
2. StoreInvitationRequest → dogrula + camelCase esle    gecersizse 422
3. Gate::authorize        → Policy'ye sor               degilse 404 (H7)
4. CreateInvitationAction → transaction, iliski, sync
5. InvitationResource     → beyaz liste + camelCase
6. 201 + {data: {...}}
```

Her adım ayrı bir dosyada, çünkü **her birinin değişme sebebi farklı.**

---

## 2. `Gate::authorize()` — yetki nasıl soruluyor?

```php
Gate::authorize('view', $invitation);
```

Bu satır `InvitationPolicy::view()` metodunu çağırır. `false` dönerse
`AuthorizationException` fırlatır; fırlatmazsa akış devam eder.

Sonrası Faz 1'de kurulmuştu:

```
AuthorizationException → ApiExceptionRenderer → ErrorCode::ResourceNotFound → 404
```

Yani controller "404 dön" demiyor. Yalnızca **soruyor**; cevabın HTTP'de nasıl
göründüğü sözleşme katmanının kararı (3.7 §5).

### `index` ve `store`'da model yok

```php
Gate::authorize('viewAny', Invitation::class);   // nesne degil, SINIF
Gate::authorize('view', $invitation);            // nesne
```

"Liste açılabilir mi?" sorusunda ortada somut bir kayıt yoktur; Policy'ye hangi
model türünden bahsettiğimizi sınıf adıyla söyleriz.

---

## 3. 🔴 `authorizeResource` neden kullanılmadı?

Yol haritası bu kısayolu ima ediyordu:

```php
public function __construct()
{
    $this->authorizeResource(Invitation::class, 'invitation');   // ❌ calismaz
}
```

Tek satırda beş metodu policy'ye bağlardı. Denemeden önce kaynağa baktım
(`Foundation/Auth/Access/AuthorizesRequests.php`):

```php
foreach ($middleware as $middlewareName => $methods) {
    $this->middleware($middlewareName, $options)->only($methods);
}
```

`$this->middleware(...)` çağırıyor. Ama bu projenin taban controller'ı:

```php
abstract class Controller
{
    //
}
```

**Boş.** Laravel 11+ ile controller'lardan `middleware()` metodu kaldırıldı;
taban sınıf artık `Illuminate\Routing\Controller`'dan türemiyor. Yani
`authorizeResource` çağrısı `Call to undefined method` ile ölürdü.

Bu, Faz 0'ın **3. dersinin** ailesinden: *Laravel 11+ ile `Kernel.php` kaldırıldı;
internetteki eğitimlerin çoğu eski yapıyı anlatıyor.* Aynı şey burada da geçerli
— `authorizeResource` anlatan her eğitim Laravel 10 ve öncesini varsayıyor.

### Alternatifler ve seçim

| Yol | Değerlendirme |
|---|---|
| `HasMiddleware` arayüzü | Çalışır ama `'can:view,invitation'` gibi **metin** kurallar — yazım hatası sessizce geçer |
| Rotada `can:` middleware | Her eyleme ayrı satır, rota dosyası şişer |
| **`Gate::authorize()` her metotta** | 5 satır, açık, aranabilir, IDE takip eder |

Sonuncuyu seçtim. Beş satır tekrar var ama tekrarlanan şey **kural değil,
çağrı**. Kural hâlâ tek yerde — `InvitationPolicy`. 3.7'de reddettiğimiz şey
sahiplik mantığının kopyalanmasıydı, kontrolü çağırmanın değil.

Ve gizli bir davranış yok: metodu okuyan biri yetkinin sorulduğunu **görüyor**.

---

## 4. 🔴 `with('timelineEvents')` — 3.9'un karşılığı

```php
$invitations = $user->invitations()
    ->with('timelineEvents')
    ->latest('updated_at')
    ->get();
```

3.9'da `whenLoaded` kullanmama kararı vermiştik: `InvitationPayloadResource`
doğrudan `$this->timelineEvents` diyor. O kararın bedeli burada ödeniyor —
**eager loading zorunlu.**

`with()` olmadan ne olur?

| Ortam | Sonuç |
|---|---|
| Yerelde | `LazyLoadingViolationException` — anında görürsün |
| Üretimde | 20 davetiye = 21 sorgu (N+1) — yavaş ama **doğru** |

Bu tam olarak istediğimiz davranıştı: hata **gürültülü**, sessiz yanlış veri yok.

`with()` iki sorgu çalıştırır:

```sql
SELECT * FROM invitations WHERE user_id = 1 ORDER BY updated_at DESC;
SELECT * FROM timeline_events WHERE invitation_id IN (...) ORDER BY sort_order;
```

20 davetiye için 21 değil **2** sorgu.

### `show`'da neden `load()`?

```php
return new InvitationResource($invitation->load('timelineEvents'));
```

`show`'da davetiye **route model binding** ile geldi, yani elimizde zaten model
var. `with()` sorgu kurulurken kullanılır; elde model varken `load()` kullanılır
(`CreateInvitationAction.md` §5).

### `latest('updated_at')` neden controller'da?

3.5'te `User::invitations()` ilişkisine sıralama **gömmemiştik**: davetiye
sırası bir *sunum tercihi*, program adımlarının sırası gibi *anlamın parçası*
değil.

Dashboard "en son düzenlenen üstte" istiyor; başka bir ekran tarihe göre
isteyebilir. Tercihi **çağıran** belirliyor.

---

## 5. Durum kodları

| Metot | Kod | Neden |
|---|---|---|
| `index` | 200 | Varsayılan |
| `store` | **201 Created** | Yeni kaynak oluştu; RFC 9110'un gereği |
| `show` | 200 | Varsayılan |
| `update` | 200 | Kaynak değişti, gövde döner |
| `destroy` | **204 No Content** | Silindi, dönecek gövde yok |

### 201 nasıl üretiliyor?

```php
return (new InvitationResource($invitation))
    ->response()
    ->setStatusCode(JsonResponse::HTTP_CREATED);
```

Resource'u doğrudan döndürürsek Laravel 200 verir. `->response()` onu
`JsonResponse`'a çevirir ve kodu ayarlayabiliriz.

`JsonResponse::HTTP_CREATED` sabitini kullanıyoruz, `201` yazmıyoruz — Faz 2'de
`AuthController::register` de öyleydi. Sabit, sayının **ne anlama geldiğini**
söyler.

### 204 ve boş gövde

```php
return response()->noContent();
```

204 yanıtına gövde **konulamaz** (HTTP kuralı). `noContent()` bunu garanti eder.
Frontend `axios` için `response.data` boş olur; silme işleminde okunacak bir şey
zaten yok.

---

## 6. `destroy` neden Action'sız?

Diğer iki yazma işlemi Action'a gidiyor, silme gitmiyor:

```php
$invitation->delete();
```

`CLAUDE.md` Action'ları **iş kuralı** için istiyor. Burada iş kuralı yok: tek
satır, tek tablo, transaction gerekmiyor (soft delete tek `UPDATE`).

Action açsaydık yalnızca bu satırı saran boş bir kabuk olurdu — YAGNI.

⚠️ Faz 6'da bu değişebilir: davetiye silinince yüklenen medya dosyalarının da
temizlenmesi gerekirse, o zaman iş kuralı doğar ve `DeleteInvitationAction`
yazılır. **Soyutlama, ihtiyaç doğduğunda eklenir.**

Ve soft delete olduğu için `timeline_events` CASCADE'i tetiklenmiyor (3.3 §3) —
kullanıcı geri isterse programıyla birlikte döner.

---

## 7. `/** @var User $user */` neden gerekiyor?

```php
/** @var User $user auth:sanctum burada null OLAMAYACAGINI garanti eder. */
$user = $request->user();
```

`$request->user()` imzası `User|null` döndürür — kimliksiz istekler de olabilir.
Ama bu rota `auth:sanctum` middleware'i arkasında; kimliksiz istek buraya
**ulaşamaz**.

PHPStan bunu bilemez (middleware'i statik olarak takip edemez) ve level 6'da
"possibly null" uyarısı verir. Docblock ona garantiyi bildiriyor.

🔴 Bu bir **söz**dür: rotadan `auth:sanctum` kaldırılırsa docblock yalan olur ve
`$user->invitations()` üretimde `null` üzerinde çağrılır. Faz 2'nin **B4**
kuralının kod içindeki karşılığı — bu yüzden 3.12'de "kimliksiz istek 401 alır"
testi yazacağız.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `authorizeResource` kullanmak | `Call to undefined method` (Laravel 11+) | `Gate::authorize()` |
| 2 | `with('timelineEvents')` unutmak | Yerelde exception, üretimde N+1 | Eager load |
| 3 | `store`'da 200 dönmek | Sözleşme ihlali | 201 |
| 4 | `Invitation::all()` | **Herkesin** davetiyesi döner | `$user->invitations()` |
| 5 | Controller'da `if` / iş kuralı | Katman ihlali | Action'a taşı |
| 6 | Controller'da `try/catch` | Hata zarfı iki yerde üretilir (H10) | Exception fırlat |
| 7 | `destroy`'da `forceDelete()` | Kullanıcı geri alamaz | `delete()` |
| 8 | `show`'da `with()` kullanmak | Elde model varken yanlış araç | `load()` |

### 4. maddenin ayrıntısı

```php
$invitations = Invitation::query()->get();          // ❌ HERKESIN davetiyesi
$invitations = $user->invitations()->get();         // ✅ yalnizca kendisininki
```

`viewAny` policy'si `true` döndüğü için ilk satır **hiçbir yerde
engellenmezdi**. Liste ucunda sahiplik korumasını Policy değil, **sorgunun
kendisi** sağlıyor (3.7 §7) — ve bu daha güçlü bir korumadır çünkü filtrelemeyi
unutmak, kodu okuyanın gözüne çarpar.

---

## 9. Kendin dene

Artık tam tur çalışıyor. Sunucuyu başlat:

```powershell
php artisan serve
php artisan migrate:fresh --seed
```

Token al:

```powershell
$body = '{"email":"test@ornek.test","password":"password"}'
$login = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/auth/login" -Method Post -Body $body -ContentType "application/json"
$token = $login.token
$headers = @{ Authorization = "Bearer $token" }
```

Liste:

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/invitations" -Headers $headers | ConvertTo-Json -Depth 6
```

Beklenen: `data` altında **iki** kayıt (seeder'ın taslak + yayında olanı), her
birinde `invitation.timelineEvents` dolu.

Oluşturma:

```powershell
$yeni = '{"invitation":{"categoryId":"kina","imageTheme":"kina-bordo","palette":"midnight","title":"Kina Gecemiz","timelineEvents":[{"id":null,"time":"20:00","title":"Kina Yakma"}]}}'
$olusan = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/invitations" -Method Post -Body $yeni -ContentType "application/json" -Headers $headers
$olusan.data.id
$olusan.data.invitation.timelineEvents
```

🔴 IDOR denemesi — başka bir kullanıcının davetiyesini iste:

```powershell
# Once ikinci bir hesap ac ve onun davetiyesinin id'sini al, sonra ILK token'la iste:
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/invitations/$digerId" -Headers $headers
```

Beklenen: **404** ve `{"error":{"code":"RESOURCE_NOT_FOUND"}}` — 403 değil.

```powershell
composer check
```

Tam elle doğrulama betiği faz sonunda `docs/rehber/fazlar/FAZ-3-ELLE-DOGRULAMA.md`
olarak yazılacak.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Route model binding** | URL'deki kimliği otomatik olarak modele çevirme |
| **Eager loading** | İlişkiyi önceden, tek sorguda yükleme (`with()`) |
| **N+1 problemi** | Liste elemanı sayısı kadar ek sorgu açılması |
| **`Gate`** | Yetki sorularının sorulduğu merkezi arayüz |
| **204 No Content** | Başarılı ama gövdesi olmayan yanıt |
| **201 Created** | Yeni kaynak oluşturuldu |
| **YAGNI** | *You Aren't Gonna Need It* — ihtiyaç doğmadan soyutlama ekleme |

---

## 11. Sırada ne var?

**3.12 — `tests/Feature/InvitationTest.php`**

Fazın son dosyası ve kanıtı:

- 🔴 "Başkasının davetiyesini okuyamaz" — **404**, 403 değil
- 🔴 T13: her kimlikli istek arasında `forgetAuthState()` — yoksa IDOR testi
  **sessizce boş yeşil** yanar
- Senkronizasyonun üç yolu: ekle / güncelle / sil
- `null` ile `[]` ayrımı
- Sözleşme testleri: `date` biçimi, `id` metin, `sortOrder` sızmıyor

---

## 🆕 Faz 7 eklemesi — `publish()`

```php
public function publish(Invitation $invitation, PublishInvitationAction $action): InvitationResource
{
    Gate::authorize('publish', $invitation);

    return new InvitationResource(
        $action->handle($invitation)->load('timelineEvents'),
    );
}
```

Üç satır — `CLAUDE.md` §1'in "3-8 satır" kuralı içinde. Paywall'ın tamamı
Action'da (K3).

### 1. 🔴 `->load('timelineEvents')` neden şart?

`PublishInvitationAction` satırı **kilitleyip yeniden okuyor**
(`lockForUpdate()->firstOrFail()`), yani dönen örnek rota bağlamasının
yüklediği ilişkileri **taşımıyor**.

`InvitationPayloadResource` `timelineEvents`'e `whenLoaded` olmadan erişir
(Faz 3, 3.9: *"sözleşme bu anahtarı zorunlu kılar"*). Yüklenmezse:

- **Yerelde:** `LazyLoadingViolationException` (katı kip)
- **Üretimde:** sessiz bir N+1

Faz 3'ün `index()` metodundaki `with('timelineEvents')` ile aynı kural, farklı
sebep: orada N+1'i önlemek içindi, burada **kilidin yan etkisini** onarmak için.

### 2. Yanıt neden 200 ve tam kayıt?

Frontend'in editörü aynı `InvitationResource`'u okuyup durumu `published`
olarak gösterebilsin diye. Ayrı bir "yayınlandı" zarfı **ikinci bir sözleşme**
olurdu — **C2**: zarf istisnaları ad ad tanımlıdır ve bu onlardan biri değil.

201 değil: yeni bir kaynak yaratılmıyor, var olanın **durumu** değişiyor.

### 3. `publish` ability'si — `update` değil

Ayrıntı: [`../../../Policies/InvitationPolicy.md`](../../../Policies/InvitationPolicy.md)
§ Faz 7 eklemesi. Özet: bugün aynı cevabı veriyorlar ama `INVITATION_LOCKED`
kuralı geldiğinde `update` kilitlenecek, `publish` kilitlenmemeli.

### 4. Bu metodun fırlattığı üç exception

| Exception | Kod | Nereden |
|---|---|---|
| `AuthorizationException` | `RESOURCE_NOT_FOUND` (404) | `Gate::authorize` (H7) |
| `InvitationAlreadyPublishedException` | 409 | Action |
| `PaywallViolationException` | 402 | Action |

Üçü de **H10**'a uyuyor: controller hata **yanıtı** üretmiyor, exception
fırlıyor ve biçim kararı `ApiExceptionRenderer`'da tek yerde veriliyor.
