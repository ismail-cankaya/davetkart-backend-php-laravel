# `app/Http/Controllers/Api/V1/AuthController.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Http/Controllers/Api/V1/AuthController.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.6
> **Bağlantılı:** [`RegisterUserAction.md`](../../../../Actions/Auth/RegisterUserAction.md) ·
> [`RegisterRequest.md`](../../../Requests/Auth/RegisterRequest.md) ·
> [`UserResource.md`](../../../Resources/UserResource.md) ·
> [`CLAUDE.md`](../../../../../../../CLAUDE.md) §1, §2

---

## 0. Bir dakikalık özet

Bütün controller **üç satır**:

```php
$result = $action->handle($request->userAttributes());   // devret

return response()->json([                                 // paketle
    'user' => (new UserResource($result['user']))->resolve(),
    'token' => $result['token'],
], JsonResponse::HTTP_CREATED);
```

`if` yok, `try/catch` yok, doğrulama yok, sorgu yok. Controller'ın tek işi
**trafik yönetmek**: isteği doğru Action'a devretmek ve sonucu doğru biçimde
paketlemek.

---

## 1. Controller neden bu kadar ince?

`CLAUDE.md` §1: *"Controller'lar sadece gelen isteği ilgili Action'a
yönlendirmekten ve Resource dönmekten sorumludur (**maksimum 3-8 satır**).
İçerisinde `if` blokları veya iş mantığı bulunamaz."*

Bu keyfi bir kural değil. Şişman bir controller'ın somut bedeli var:

| Kural olmasaydı | Sonucu |
|---|---|
| İş kuralı controller'da olurdu | HTTP olmadan test edilemezdi — her test bir istek kurmak zorunda |
| Aynı kural konsoldan çağrılamazdı | `php artisan user:create` yazmak için kod kopyalanırdı |
| Dosya zamanla büyürdü | 4 uç nokta × 40 satır = okunmaz bir sınıf |
| Değişiklik riski artardı | `login`'i düzeltirken `register`'ı bozma ihtimali |

Bu dosyanın **hiç iş kuralı içermemesi**, `RegisterUserAction`'ı `tinker`'dan
doğrudan çağırabilmemizin sebebidir (2.5b kılavuzu §5).

### 1.1 Peki controller ne İŞE yarıyor?

Yalnızca **HTTP'ye özgü kararları** verir:

| Karar | Bu dosyada |
|---|---|
| Hangi durum kodu? | `201 Created` |
| Yanıt hangi biçimde paketlenecek? | Zarfsız `{user, token}` |
| Hangi Resource kullanılacak? | `UserResource` |

Action bunların hiçbirini bilmez, bilmemeli.

---

## 2. PHP ve Laravel temelleri

### 2.1 Bağımlılık enjeksiyonu (dependency injection)

```php
public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
```

Bu satırda **iki nesne isteniyor** ama hiçbir yerde `new` yazmıyoruz. Laravel'in
**servis konteyneri** metodun imzasını okur, tip bildirimlerini görür ve nesneleri
kendisi üretip verir.

```
Laravel: "register() metodu RegisterUserAction istiyor."
         → sınıfı bul → kurucusuna bak → bağımlılığı yoksa new'le → ver
```

Buna **otomatik çözümleme (autowiring)** denir.

**Neden `new RegisterUserAction()` yazmıyoruz?** Yazsaydık sınıf o somut
uygulamaya çivilenirdi. Enjeksiyonla:

- Testte sahte (mock) bir Action geçirilebilir
- Action ileride bir bağımlılık kazanırsa (örneğin `Mailer`) controller'a
  dokunmadan alır
- Faz 7'de `PaymentGateway` **arayüzü** enjekte edilecek; hangi somut sınıfın
  geleceğine `AppServiceProvider` karar verecek (K8, Strategy Pattern)

Son madde bu tekniğin asıl ödemesidir: **Dependency Inversion**. Şimdilik basit
görünen bu kalıp, Faz 7'de sağlayıcı değiştirmeyi tek satırlık bir işe indirger.

### 2.2 FormRequest enjeksiyonu — doğrulama nerede çalıştı?

```php
public function register(RegisterRequest $request, ...)
```

Laravel imzada bir `FormRequest` görürse, **metodun gövdesine girmeden önce**
doğrulamayı çalıştırır. Başarısızsa `ValidationException` fırlar ve controller
**hiç çağrılmaz**.

```
İstek → RegisterRequest doğrular ─┬─ ✅ → register() çalışır
                                  └─ ❌ → ApiExceptionRenderer → 422
```

Bu yüzden gövdede `if ($validator->fails())` yok. Doğrulama başarısızlığı bu
dosyaya **hiç ulaşmaz**.

### 2.3 `response()->json($veri, $durum)`

Laravel'in yardımcı fonksiyonu: diziyi JSON'a çevirir, `Content-Type:
application/json` başlığını koyar ve durum kodunu ayarlar.

`JsonResponse::HTTP_CREATED` = `201`. Sabit kullanmanın sebebi sihirli sayı
yasağı (`CLAUDE.md` §1) — `201` yazmak da çalışırdı ama okuyan kişi anlamını
ezberden bilmek zorunda kalırdı.

> `JsonResponse` sınıfı Symfony'nin `Response`'undan miras alır; bu yüzden tüm
> HTTP durum sabitleri onun üzerinden erişilebilir. Ayrı bir `use` satırı
> gerekmez — `JsonResponse` zaten ithal edilmiş durumda.

---

## 3. Alınan kararlar

### 3.1 🔴 `->resolve()` — zarfsız yanıtın anahtarı

`CLAUDE.md` §2'nin ikili kuralı:

| Endpoint | Zarf |
|---|---|
| `/auth/register`, `/auth/login` | ❌ **YOK** — `{user, token}` |
| Diğer tüm endpoint'ler | ✅ `{data: ...}` |

Bir `JsonResource` controller'dan **doğrudan** döndürülürse Laravel otomatik
olarak `data` zarfını ekler:

```php
return new UserResource($user);
// {"data":{"id":"1","firstName":"Ayşe",...}}          ← zarflı
```

Auth'ta bunu istemiyoruz, çünkü frontend şunu bekliyor:

```ts
// services/auth.ts
const { data } = await api.post<AuthSession>('/auth/register', payload);
return data;                    // { user, token } — data.user okunuyor
```

Zarflı dönseydik frontend `data.data.user` araması gerekirdi ve **anında
kırılırdı**.

`resolve()` Resource'u **diziye** çevirir; zarf hiç oluşmaz:

```php
(new UserResource($user))->resolve()
// ['id' => '1', 'firstName' => 'Ayşe', 'lastName' => 'Yıldırım', 'email' => '...']
```

> ⚠️ **Aklına gelecek ama yapılmaması gereken:** `JsonResource::withoutWrapping()`.
> O **global** bir ayardır ve zarfı *tüm* endpoint'lerden kaldırır — Faz 3'ün
> `InvitationResource`'unu da kırar. Sorun tek bir endpoint ailesine ait;
> çözümü de oraya ait olmalı.
>
> **Genel ilke:** Yerel bir istisnayı global bir ayarla çözmek, bugün bir sorunu
> kapatıp yarın üç tane açar.

### 3.2 Neden `201`, `200` değil?

`201 Created` "istek başarılı **ve yeni bir kaynak oluştu**" der. `200 OK` bu
ikinci bilgiyi taşımaz.

Fark pratikte ne kazandırır? Bir izleme (monitoring) panosunda `201` sayısı
doğrudan "kaç yeni kullanıcı kaydoldu" demektir; `200` ise her şeyle karışır.
Durum kodu, ücretsiz gelen bir metriktir.

**Frontend etkilenir mi?** Hayır. `api.ts` interceptor'ı yalnızca `401`'e tepki
veriyor; axios `2xx`'in tamamını başarı sayar.

> RFC 9110, `201` ile birlikte oluşan kaynağın adresini veren bir `Location`
> başlığı **önerir**. Koymadık: `GET /api/users/{id}` diye bir uç noktamız yok,
> olmayan bir adresi göstermek yanıltıcı olurdu.

### 3.3 `login`/`logout`/`me` neden şimdi yazılmadı?

Yol haritası bunları 2.8 ve 2.9'a koyuyor. Sebep dilim disiplini: **önce bir uç
noktayı uçtan uca çalıştır**, sonra ikinciyi ekle.

2.7'de rotayı açtığımızda `register` **gerçekten çalışacak** — tarayıcıdan istek
atıp yanıtı göreceğiz. Dört uç noktayı birden yazsaydık, ilk hatada dördünden
hangisinin bozuk olduğunu ayıklamak zorunda kalırdık.

> Bu, K17'nin (özellik-özellik inşa) faz içindeki küçük ölçekli hâlidir:
> **çalışan en küçük dilim** önce.

### 3.4 Neden invokable controller değil?

`HealthController` tek eylemi olduğu için `__invoke()` kullanıyordu. `AuthController`
dört eylem taşıyacak (`register`, `login`, `logout`, `me`) — dördü de aynı
kavramsal aileye ait olduğundan tek sınıfta adlandırılmış metotlar olarak durmaları
doğru.

Ölçüt şu: **eylemler aynı kaynağı mı paylaşıyor?** Evet ise tek controller;
hayır ise ayrı invokable sınıflar.

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `->resolve()` unutmak | `{"data":{"user":...}}` — frontend `data.user` bulamaz | `resolve()` |
| `withoutWrapping()` çağırmak | Tüm endpoint'lerin zarfı kalkar | Yerelde çöz (§3.1) |
| Controller'da `try/catch` yazmak | Hata biçimi dağılır (H10) | Renderer halleder |
| Controller'da `if` yazmak | İş kuralı sızar | Action'a taşı |
| `new RegisterUserAction()` yazmak | Somut sınıfa çivilenir, test edilemez | Enjeksiyon |
| `Request` yerine `RegisterRequest` yazmamak | Doğrulama **hiç çalışmaz** | Tip bildirimi doğrulamayı tetikler |
| `$request->all()` kullanmak | Beklenmeyen alanlar geçer | `userAttributes()` → `validated()` |
| Ham `201` yazmak | Sihirli sayı | `JsonResponse::HTTP_CREATED` |

---

## 5. Kendin dene

⚠️ Bu dosya **henüz erişilemez** — rota 2.7'de açılacak. Şu an yapılabilecek tek
doğrulama sınıfın yüklenebildiği:

```powershell
php artisan tinker
```

```php
class_exists(App\Http\Controllers\Api\V1\AuthController::class);   // true
```

Konteynerin Action'ı kendi başına üretebildiğini de görebilirsin — enjeksiyonun
çalışacağının kanıtı:

```php
app(App\Actions\Auth\RegisterUserAction::class);
// App\Actions\Auth\RegisterUserAction {#...}      ← `new` yazmadan üretildi
```

**Asıl deneme 2.7'de:**

```powershell
php artisan serve

curl.exe -X POST http://localhost:8000/api/auth/register `
  -H "Content-Type: application/json" `
  -H "Accept: application/json" `
  -d '{\"firstName\":\"Ayse\",\"lastName\":\"Yildirim\",\"email\":\"ayse@ornek.test\",\"password\":\"gizli1234\"}'
```

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Controller** | HTTP isteğini karşılayıp yanıtı biçimlendiren katman |
| **Bağımlılık enjeksiyonu** | Nesnenin ihtiyaçlarını dışarıdan alması |
| **Servis konteyneri** | Laravel'in nesne üretip bağımlılıkları çözen bileşeni |
| **Autowiring** | Tip bildiriminden bakarak otomatik çözümleme |
| **Dependency Inversion** | Somut sınıfa değil arayüze bağımlı olma ilkesi |
| **Zarf (envelope)** | Yanıtı saran dış anahtar (`{data: ...}`) |
| **`201 Created`** | Başarılı **ve** yeni kaynak oluştu |
| **Invokable controller** | Tek `__invoke()` metodu olan controller |

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| Çağırdığı Action | [`RegisterUserAction.md`](../../../../Actions/Auth/RegisterUserAction.md) |
| Doğrulayan sınıf | [`RegisterRequest.md`](../../../Requests/Auth/RegisterRequest.md) |
| Yanıtı biçimleyen | [`UserResource.md`](../../../Resources/UserResource.md) |
| Zarf politikası | `docs/03-MIMARI-PLAN.md` §4.2 · [`CLAUDE.md`](../../../../../../../CLAUDE.md) §2 |
| Tek eylemli örnek | [`HealthController.md`](HealthController.md) |
| Sıradaki dosya | `routes/api.php` (2.7) — 🎯 **ilk gerçek istek** |
