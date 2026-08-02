# `app/Http/Resources/UserResource.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Http/Resources/UserResource.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.3
> **Bağlantılı:** [`app/Models/User.md`](../../Models/User.md) ·
> [`CLAUDE.md`](../../../../../CLAUDE.md) §1, §2 ·
> `docs/03-MIMARI-PLAN.md` §4.2 (yanıt zarfı politikası)

---

## 0. Bir dakikalık özet

Bu dosya **veritabanı ile frontend arasındaki çeviri katmanıdır**.

```
VERİTABANI                 UserResource              FRONTEND (types.ts)
──────────                 ────────────              ───────────────────
id          bigint    →    (string) $this->id   →    id: string
first_name  varchar   ┐
last_name   varchar   ┴→   trim(f . ' ' . l)    →    fullName: string
email       varchar   →    $this->email         →    email: string
password    varchar   →    ✖ hiç çıkmaz
email_verified_at     →    ✖ hiç çıkmaz
created_at / updated_at →  ✖ hiç çıkmaz
```

Üç satır kod, üç ayrı sorunu çözüyor. Sırayla bakalım.

---

## 1. Resource neden var? — modeli doğrudan döndürsek ne olurdu?

Faz 1'de silinen varsayılan `/user` rotası tam olarak bunu yapıyordu:

```php
Route::get('/user', fn (Request $r) => $r->user());     // ham model
```

Ürettiği JSON:

```json
{
  "id": 1,
  "first_name": "Ayşe",          ← snake_case: frontend camelCase bekliyor
  "last_name": "Yıldırım",       ← fullName yok
  "email": "ayse@example.org",
  "email_verified_at": "2026-08-02T10:00:00.000000Z",
  "created_at": "...",           ← kimsenin istemediği alanlar
  "updated_at": "..."
}
```

Dört ayrı sorun var:

| Sorun | Sonucu |
|---|---|
| `snake_case` sızıyor | Frontend `user.fullName` okuyor → `undefined` |
| `id` sayı olarak gidiyor | Frontend `id: string` bekliyor |
| İstenmeyen alanlar sızıyor | Gereksiz bilgi ifşası |
| **Şema = sözleşme** oluyor | Kolon adı değişince API sessizce kırılır |

🔴 **Dördüncüsü en önemlisi.** Ham model döndürürsen, veritabanı şeman doğrudan
API sözleşmen hâline gelir. Bir kolonu yeniden adlandırmak — tamamen içsel bir
karar — frontend'i kırar. Resource bu bağı koparır: içeride istediğini yaparsın,
dışarısı sabit kalır.

> Bu, **Adapter (Uyarlayıcı)** desenidir: iki tarafın birbirine uymayan
> arayüzlerini ortada duran bir katman uzlaştırır. K35'te ad/soyadı bölerken
> "frontend kırılmıyor" diyebilmemizin sebebi bu katmanın varlığıdır.

---

## 2. PHP ve Laravel temelleri

### 2.1 `extends JsonResource` ve `toArray()`

`JsonResource` bir modeli sarmalar ve `toArray()` metodunun döndürdüğü diziyi
JSON'a çevirir. Yazman gereken tek metot budur.

```php
new UserResource($user);        // sarmalar
```

### 2.2 `$this->id` nasıl çalışıyor? — sihirli `__get`

`UserResource`'un `id` diye bir property'si **yok**. Yine de `$this->id`
çalışıyor.

Sebebi `JsonResource`'un `__get()` sihirli metodu: PHP, var olmayan bir
property'ye erişildiğinde bu metodu çağırır, o da isteği **sarmalanan modele**
iletir.

```
$this->id  →  __get('id')  →  $this->resource->id  →  User modelinin id'si
```

> Sihirli metotlar `__` ile başlar — `__invoke` (HealthController) ve `__get` gibi
> (bkz. [`php-dili.md`](../../../kavramlar/php-dili.md) §15.3).

### 2.3 🔴 `@mixin User` — bu satır olmadan PHPStan kırılır

```php
/**
 * @mixin User
 */
final class UserResource extends JsonResource
```

`__get()` çalışma anında çalışır; **statik analiz onu göremez**. PHPStan
`$this->first_name` satırına bakar ve "böyle bir property yok" der.

`@mixin User`, PHPStan'a *"bu sınıfa `User`'ın üyeleri de eklenmiş say"* der.
Bunun iki faydası var:

1. Hata kaybolur.
2. **Yazım hatası yakalanır** — `$this->frist_name` yazarsan PHPStan durdurur.

İkincisi asıl kazançtır: sihirli metotlar tip güvenliğini yok eder, `@mixin` onu
geri kazandırır.

### 2.4 `final` neden?

Bu sınıftan miras alınmasını tasarlamadık. `final`, tasarlanmamış kalıtımı baştan
reddeder (bkz. [`php-dili.md`](../../../kavramlar/php-dili.md) §3.2).

---

## 3. Alınan kararlar

### 3.1 Beyaz liste — üç alan, ne bir eksik ne bir fazla

Frontend sözleşmesi (`davetkart-frontent/src/types.ts`):

```ts
export interface AuthUser {
  id: string;
  fullName: string;
  email: string;
}
```

Resource **tam olarak bunu** üretir. `email_verified_at`, `created_at`,
`updated_at` dahil edilmedi — frontend istemiyor.

**Neden "zararsız, dursun" demiyoruz?** Çünkü bir alanı API'ye eklemek kolay,
**çıkarmak imkânsıza yakındır**. Bir kez yayınlandığında biri ona bağlanır.
Varsayılan olarak kapalı olmak (beyaz liste), sonradan gevşetilebilir; tersi
değil.

Bu, `$fillable` beyaz listesi (§3.2, `User.md`) ve `ErrorCode::filterParams()`
(H9) ile **aynı desenin** üçüncü uygulamasıdır: *varsayılan kapalı, istisna
açıkça yazılır.*

🔴 Ve elbette: `password` buraya asla yazılmaz. Modeldeki `#[Hidden]` ikinci
savunmadır, bu beyaz liste birincisi.

### 3.2 `(string) $this->id` — neden sayıyı string'e çeviriyoruz?

Frontend `id: string` bekliyor. `PHP-LARAVEL-SETUP.md` §11'deki ihlal edilemez
kurallardan biri: *"`id` alanları **string**"*.

Neden böyle bir kural var? Çünkü Faz 3'te `invitations.public_slug` **ULID**
olacak (K13) ve ULID zaten bir string'dir. Tüm kimlikleri baştan string tutmak,
frontend'in tip tanımlarının o geçişte değişmesini önler.

> **Yan fayda — JavaScript'in sayı sınırı.** JS'te tüm sayılar 64-bit
> kayan noktadır; güvenli tamsayı üst sınırı 2^53'tür. `bigint` bir kimlik bu
> sınırı aşarsa JavaScript onu **sessizce yuvarlar**. Kimlikleri string taşımak
> bu sınıf hataları tamamen ortadan kaldırır — büyük API'lerin (Twitter, Stripe)
> yıllar önce öğrendiği bir derstir.

### 3.3 `trim($this->first_name.' '.$this->last_name)`

K35'in karşılığı burada. Ad ve soyad veritabanında ayrı durur, `fullName` **her
istekte hesaplanır**.

**Neden veritabanında da bir `full_name` kolonu tutmuyoruz?** Tutsaydık iki
doğruluk kaynağı olurdu: biri güncellenip diğeri unutulduğunda veri sessizce
çelişirdi. Türetilebilen veri saklanmaz — **normalizasyon** ilkesi.

`trim()` neden var? İki kolon da şu an `NOT NULL`, yani teorik olarak gereksiz.
Ama migration kılavuzunda (§3.3) not düştüğümüz bir ihtimal var: uluslararası
kullanıcı için `last_name` ileride `nullable` yapılabilir. O gün `trim()`
olmasaydı `"Ayşe "` gibi sondaki boşluklu bir değer üretilirdi. Bir karakterlik
maliyetle geleceğe dayanıklılık.

### 3.4 🔴 `{data: ...}` zarfı — burada değil, controller'da çözülür

`CLAUDE.md` §2'nin ikili kuralı:

| Endpoint | Zarf |
|---|---|
| `/auth/login`, `/auth/register` | ❌ **YOK** — `{user, token}` |
| Diğer tüm endpoint'ler | ✅ `{data: ...}` |

`JsonResource` bir controller'dan **doğrudan döndürülürse** Laravel otomatik
olarak `{"data": {...}}` zarfını ekler. Auth'ta bunu istemiyoruz, çünkü
`services/auth.ts` doğrudan `data.user` okuyor:

```ts
const { data } = await api.post<AuthSession>('/auth/login', credentials);
return data;        // { user, token } bekliyor
```

**Çözüm bu dosyada değil, `AuthController`'da (2.6):**

```php
return response()->json([
    'user'  => (new UserResource($user))->resolve(),   // ← diziye çevirir, zarfsız
    'token' => $token,
]);
```

`resolve()` Resource'u diziye çevirir ve zarf hiç oluşmaz.

> ⚠️ **Yapılmaması gereken:** `JsonResource::withoutWrapping()` çağırmak. O
> **global** bir ayardır ve zarfı *tüm* endpoint'lerden kaldırır — Faz 3'ün
> `InvitationResource`'unu da kırar. Sorun tek bir endpoint ailesine ait;
> çözümü de oraya ait olmalı.
>
> **Genel ilke:** Yerel bir istisnayı global bir ayarla çözmek, bugün bir
> sorunu kapatıp yarın üç tane açar.

### 3.5 Neden bir `UserResource::collection()` kullanımı yok?

Faz 2'de kullanıcı **listesi** dönen bir endpoint yok — auth yalnızca tek
kullanıcıyla ilgilenir. Koleksiyon kullanımını Faz 3'te `InvitationResource` ile
göreceğiz.

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `@mixin User` yazmamak | PHPStan "undefined property" verir | Docblock'a ekle |
| Ham modeli döndürmek | snake_case sızar, sözleşme kırılır | Her zaman Resource |
| Auth yanıtında `->resolve()` unutmak | `{data:{user...}}` — frontend `data.user` bulamaz | 2.6'da `resolve()` |
| `withoutWrapping()` çağırmak | Tüm endpoint'lerin zarfı kalkar | Sorunu yerelde çöz |
| Modele `fullName` accessor'ı eklemek | Dönüşüm iki yere dağılır | Yalnızca Resource |
| `'id' => $this->id` (cast'siz) | Frontend `string` bekliyor, `number` alır | `(string)` |
| "Zararsız" diye fazladan alan eklemek | Çıkarılamaz hâle gelir | Beyaz liste |
| Veritabanına `full_name` kolonu eklemek | İki doğruluk kaynağı, sessiz çelişki | Türetilebilen veri saklanmaz |

---

## 5. Kendin dene

```powershell
php artisan tinker
```

**1. Resource ne üretiyor?**

```php
$u = App\Models\User::factory()->create();
(new App\Http\Resources\UserResource($u))->resolve();
```

Beklenen çıktı — **tam olarak üç anahtar**:

```php
[
  "id" => "1",                       // string, sayı değil
  "fullName" => "Ayşe Yıldırım",
  "email" => "ayse@example.org",
]
```

**2. `password` sızıyor mu?** (sızmamalı)

```php
array_key_exists('password', (new App\Http\Resources\UserResource($u))->resolve());
// false
```

**3. `id` gerçekten string mi?**

```php
gettype((new App\Http\Resources\UserResource($u))->resolve()['id']);
// "string"
```

**4. Zarf farkını gör:**

```php
$r = new App\Http\Resources\UserResource($u);
$r->resolve();                   // ['id' => ..., ...]        ← zarfsız
$r->response()->getContent();    // {"data":{"id":...}}       ← zarflı
```

İkincisi Faz 3'te istediğimiz, birincisi auth'ta istediğimiz biçim.

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Resource** | Model → API JSON'u çeviren sunum katmanı |
| **Adapter deseni** | Uyumsuz iki arayüzü ortadaki katmanla uzlaştırma |
| **Zarf (envelope)** | Yanıtı saran dış anahtar (`{data: ...}`) |
| **`__get`** | Var olmayan property'ye erişimde çağrılan sihirli metot |
| **`@mixin`** | "Bu sınıfa şu sınıfın üyelerini de ekle" diyen PHPStan etiketi |
| **Beyaz liste** | "Yalnızca şunlar serbest" — varsayılan kapalı |
| **Normalizasyon** | Türetilebilen veriyi saklamama ilkesi |
| **Güvenli tamsayı sınırı** | JS'te 2^53 — üstü sessizce yuvarlanır |

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| Kaynak model | [`app/Models/User.md`](../../Models/User.md) |
| Test verisi | [`UserFactory.md`](../../../database/factories/UserFactory.md) |
| Yanıt zarfı politikası | `docs/03-MIMARI-PLAN.md` §4.2 |
| Katman sorumlulukları | [`CLAUDE.md`](../../../../../CLAUDE.md) §1 |
| Sihirli metotlar | [`kavramlar/php-dili.md`](../../../kavramlar/php-dili.md) §15.3 |
| Sıradaki dosya | `app/Http/Requests/Auth/RegisterRequest.php` (2.4) |
