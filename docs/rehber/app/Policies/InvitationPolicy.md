# `app/Policies/InvitationPolicy.php`

> **Kod dosyası:** `app/Policies/InvitationPolicy.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.7
> **Aynı adımda:** `Invitation` modeline `'user_id' => 'integer'` cast'i eklendi
> **Bağlantılı:** `docs/08-HATA-SOZLESMESI.md` §3.2 · [`ApiExceptionRenderer.md`](../Exceptions/ApiExceptionRenderer.md)

---

## 1. Kimlik doğrulama ≠ yetkilendirme

Faz 2'de bir soruyu çözdük. Faz 3'te farklı bir soruyu çözüyoruz:

| Soru | Adı | Nerede |
|---|---|---|
| **Sen kimsin?** | Kimlik doğrulama (*authentication*) | Sanctum, `auth:sanctum` middleware |
| **Buna dokunabilir misin?** | Yetkilendirme (*authorization*) | **Policy** |

İkisini karıştırmak klasik bir güvenlik hatasıdır. Geçerli bir token taşıyan
herkes **kimliği doğrulanmış**tır — ama bu, herkesin her davetiyeye
dokunabileceği anlamına gelmez.

```
Token gecerli mi?     → Sanctum      → hayirsa 401
Bu kayit senin mi?    → Policy       → hayirsa 404   ← bu dosya
```

---

## 2. 🔴 IDOR — bu dosyanın önlediği saldırı

**IDOR** = *Insecure Direct Object Reference*, "güvensiz doğrudan nesne
başvurusu". OWASP'ın en çok raporlanan açık türlerinden biridir ve teknik olarak
son derece basittir.

Senaryo:

```
1. Ayse giris yapar, kendi davetiyesini acar:
   GET /api/invitations/01K3QX8FVBN3K7YHTM5RWDPC4E     → 200, kendi davetiyesi

2. Mehmet'in davetiye linki bir WhatsApp grubuna dusmustur:
   davetkart.com/invite/01K3ZZ7HTMQ2N8VYRW4KDPB6C

3. Ayse o ULID'i alip KENDI token'iyla dener:
   GET /api/invitations/01K3ZZ7HTMQ2N8VYRW4KDPB6C
```

Policy yoksa ne olur? Controller `Invitation::findOrFail($id)` der, kayıt vardır,
ve **Mehmet'in davetiyesinin tamamı Ayşe'ye döner** — IBAN'ı, misafir listesi,
telefon numaraları. Ayşe hiçbir şeyi "hacklemedi"; sadece kendi geçerli
token'ıyla başka bir id istedi.

🔴 Dikkat: **ULID bunu engellemez.** ULID tahmin edilemez olmayı sağlar, ama
linki bir kez öğrenen için hiçbir engel değildir. Ve linkin paylaşılması zaten
ürünün amacıdır.

> **Kimlik gizliliği bir yetkilendirme değildir.** "Kimse bu id'yi bilemez"
> varsayımına dayanan her savunma, id'nin bilindiği gün çöker.

---

## 3. Neden Policy? Controller'da `if` neden olmaz?

Kontrolü şöyle de yazabilirdik:

```php
public function show(string $id)
{
    $invitation = Invitation::findOrFail($id);

    if ($invitation->user_id !== auth()->id()) {      // ❌
        abort(404);
    }
    ...
}
```

Çalışır. Ama aynı `if` `update()`, `destroy()`, `publish()`, `rsvps()` ve ileride
gelecek her uçta tekrarlanır. Beş kopyanın **dördünü doğru yazıp birini
unutmak**, tek bir yeri yazmaktan çok daha olasıdır.

Ve unutulan kopya sessizdir: test yazmazsan hiçbir şey uyarmaz, uygulama sorunsuz
çalışır — yalnızca bir kapı açık kalır.

Policy bunu **tek karar yerine** indirger:

| | Dağılmış `if` | Policy |
|---|---|---|
| Kural kaç yerde? | Uç sayısı kadar | 1 |
| Yeni uç eklenince | Kopyalamayı hatırlamalısın | `authorize()` çağırırsın |
| Kuralı okumak | Beş dosya gezersin | Tek dosya |
| Test etmek | Uç uç | Policy'yi doğrudan |

Bu, `CLAUDE.md` §1'in kuralıdır: *sahiplik ve erişim kontrolleri mutlaka
policy'ler ile yapılmalıdır.*

---

## 4. 🔴 Neden 404, neden 403 değil?

HTTP'de "yasak" demenin doğal yolu **403 Forbidden**'dır. Biz **404 Not Found**
dönüyoruz. Bu bilinçli ve K20 §3.2'de kayıtlı.

Sebep: **403, kaynağın var olduğunu doğrular.**

```
GET /api/invitations/01AAAA...   → 404   (boyle bir davetiye yok)
GET /api/invitations/01K3ZZ...   → 403   (VAR, ama senin degil)   ← bilgi sizdi
```

İkinci yanıtı alan saldırgan, denediği id'nin **gerçek bir davetiyeye** ait
olduğunu öğrenir. Yeterince deneyen biri, sistemdeki geçerli kimliklerin haritasını
çıkarabilir. Bu, Faz 2'de auth uçlarında kapattığımız **enumeration** açığının
aynısıdır — orada "bu e-posta kayıtlı mı", burada "bu davetiye var mı".

404 ile iki durum **ayırt edilemez** hâle gelir:

```
Yok olan kayit      → 404, {"error":{"code":"RESOURCE_NOT_FOUND"}}
Baskasinin kaydi    → 404, AYNI YANIT
```

### Bunun bedeli var mı?

Var, dürüst olalım: 404 hata ayıklamayı zorlaştırır. "Kayıt mı yok, yetkim mi
yok?" sorusunu yanıttan okuyamazsın.

Kabul edilebilir olmasının sebebi: meşru kullanıcı zaten kendi listesinden
tıklayarak gelir, elle id yazmaz. Yani belirsizlikten etkilenen kişi neredeyse
her zaman saldırgandır. Ayrıca sunucu log'unda gerçek sebep durur — bilgi
kaybolmuyor, yalnızca **yanıta girmiyor** (H8).

---

## 5. 404'e çevirme işi neden bu dosyada değil?

Laravel'in bunun için hazır bir yolu var:

```php
return $this->owns($user, $invitation)
    ? Response::allow()
    : Response::denyAsNotFound();      // ❌ biz bunu KULLANMIYORUZ
```

Kullanmıyoruz, çünkü bu satır **sözleşme kararını policy'ye taşır**. Yarın ikinci
bir policy yazdığımızda (`RsvpPolicy`, `MediaPolicy`) aynı kararı orada da
hatırlamamız gerekir — §3'te reddettiğimiz "dağılmış kopya" probleminin aynısı,
bir kat yukarıda.

Karar zaten **Faz 1'de tek yere** konmuştu:

```php
// ApiExceptionRenderer::resolveCode()
// H7: sahiplik yoksa 404. 403 kaynagin varligini dogrular.
$e instanceof ModelNotFoundException,
$e instanceof AuthorizationException => ErrorCode::ResourceNotFound,
```

Yani zincir şöyle işliyor:

```
1. Policy    false doner
2. authorize()  AuthorizationException firlatir            (Laravel varsayilani: 403)
3. ApiExceptionRenderer  →  ErrorCode::ResourceNotFound
4. ErrorCode::status()   →  404
5. Yanit:  404  {"error":{"code":"RESOURCE_NOT_FOUND"}}
```

Policy `true`/`false` döndürmekle yetiniyor: **"senin mi?"** sorusunun cevabı onun
işi, o cevabın HTTP'de nasıl görüneceği değil.

> Bu, Faz 1'in **H10** kuralının aynısı: *Action/Controller hata yanıtı üretmez,
> exception fırlatır.* Burada Policy de yanıt üretmiyor, yalnızca karar veriyor.

Faz 1'de yazdığımız o iki satır, üç faz sonra ilk kez gerçekten kullanılıyor.

---

## 6. `owns()` — ve neden `===` güvenli

```php
private function owns(User $user, Invitation $invitation): bool
{
    return $user->id === $invitation->user_id;
}
```

`===` **katı karşılaştırma**dır: hem değer hem tip eşleşmeli. `==` kullansaydık
PHP tip dönüşümü yapardı ve güvenlik karşılaştırmasında bu istenmez.

Ama katı karşılaştırmanın bir ön koşulu var: **iki tarafın da tipi garanti
olmalı.** Biri `1`, diğeri `"1"` olursa sonuç `false` çıkar ve **hiç kimse kendi
davetiyesine erişemez.**

### Tipler nereden garanti?

Laravel kaynağına baktım (`Concerns/HasAttributes.php`):

```php
public function getCasts()
{
    if ($this->getIncrementing()) {
        return array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts);
    }

    return $this->casts;
}
```

Yani model **artan** birincil anahtar kullanıyorsa, Eloquent anahtarı otomatik
olarak `int`'e cast eder.

| Model | `getIncrementing()` | Sonuç |
|---|---|---|
| `User` | `true` (bigint) | `id` otomatik `int` ✅ |
| `Invitation` | **`false`** (`HasUlids`) | otomatik cast **yok** ⚠️ |

Ve `user_id` zaten birincil anahtar değil — hiçbir modelde otomatik cast almaz.
Bu yüzden 3.7'de `Invitation` modeline şu satır eklendi:

```php
'user_id' => 'integer',
```

Artık iki taraf da kesin `int`.

> **Ders:** Bir güvenlik karşılaştırması yazarken "bu değer hangi tipte?"
> sorusunu **tahmin etmeyeceksin**. Sürücü davranışına, yapılandırmaya veya
> "genelde böyle olur"a dayanan bir savunma, ortam değişince sessizce çalışmayı
> bırakır. Kaynağı okumak beş dakika sürdü.

Bu aynı zamanda 3.4 ve 3.5'te tekrarladığımız ilkenin devamı: **tip belirsizliğini
sınırda çöz.**

---

## 7. `viewAny` ve `create` neden hep `true`?

```php
public function viewAny(User $user): bool { return true; }
public function create(User $user): bool { return true; }
```

Hiçbir şeyi engellemiyorlarsa neden varlar?

**1. `authorizeResource` onları arıyor.** 3.11'de controller şunu yazacak:

```php
$this->authorizeResource(Invitation::class, 'invitation');
```

Bu tek satır, controller metotlarını policy metotlarına otomatik bağlar:

| Controller | Policy |
|---|---|
| `index` | `viewAny` |
| `store` | `create` |
| `show` | `view` |
| `update` | `update` |
| `destroy` | `delete` |

Policy'de karşılığı olmayan bir metot **reddedilir**. `viewAny` yazmasaydık liste
ucu herkese kapanırdı.

**2. `create` Faz 7'nin yeri.** K43'e göre plan kotası yayınlanan davetiyeyi
sayacak — ama "hesap başına en fazla N taslak" gibi bir kural gelirse **buraya**
gelir. Metodun şimdiden var olması, o kuralın nereye yazılacağını belirsiz
bırakmıyor.

### `viewAny` neden davetiye almıyor?

`view(User $user, Invitation $invitation)` iki parametre alır — ortada somut bir
kayıt vardır. `viewAny(User $user)` ise "liste ucunu açabilir mi?" sorusudur;
henüz kayıt yoktur.

Liste sorgusu zaten `$user->invitations()` üzerinden kurulacağı için başkasının
kaydı sonuca hiç girmez. Yani listede sahiplik **sorgunun kendisiyle** korunur,
policy ile değil — ve bu daha güçlü bir korumadır: filtrelemeyi unutmak mümkün
değildir, çünkü ilişkiden başka bir kaynak yoktur.

---

## 8. Kayıt gerekmiyor — otomatik keşif

Laravel 11+ policy'leri ada bakarak bulur:

```
App\Models\Invitation   →   App\Policies\InvitationPolicy
```

`AuthServiceProvider`'a elle kayıt yazmaya gerek yok (eski eğitimlerin çoğu bunu
anlatır — Laravel 10 ve öncesinin kuralıydı).

⚠️ Bunun bedeli: **dosya adını yanlış yazarsan policy sessizce devreye girmez.**
`InvitationsPolicy` (çoğul) yazsaydın hiçbir hata almazdın, yalnızca yetki
kontrolü hiç çalışmazdı. 3.12'deki test tam olarak bunu yakalamak için var.

---

## 9. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Policy yerine controller'da `if` | Bir uçta unutulur, kapı açık kalır | Tek karar yeri |
| 2 | 403 döndürmek | Kaynağın varlığı doğrulanır (enumeration) | 404 (H7) |
| 3 | `==` kullanmak | Tip dönüşümü güvenlik kararına karışır | `===` |
| 4 | `user_id` cast'ini eklememek | `int !== string` → **kimse kendi kaydına erişemez** | `'user_id' => 'integer'` |
| 5 | Policy dosya adını yanlış yazmak | Otomatik keşif bulamaz, kontrol **hiç** çalışmaz | `<Model>Policy` |
| 6 | `viewAny` yazmamak | `authorizeResource` liste ucunu kapatır | Metodu ekle |
| 7 | `$user->is($invitation->user)` | İlişkiyi tembel yükler → `LazyLoadingViolationException` | Kolonu karşılaştır |
| 8 | Policy'de sorgu çalıştırmak | Her istekte ekstra SQL | Yüklü kolonu kullan |

### 7. maddenin ayrıntısı

Laravel belgelerinde sık görülen `$user->is($post->user)` yazımı burada
**çalışmaz**: `$invitation->user` ilişkisi yüklü değilse Eloquent onu tembel
yüklemeye çalışır ve Faz 0'da açtığımız `Model::shouldBeStrict()` bunu exception'a
çevirir.

Zaten gereksiz: `user_id` kolonu davetiyeyle birlikte **zaten geldi**. Kullanıcı
kaydını yeniden çekmek, elimizde olan bilgiyi tekrar sormaktır.

---

## 10. Kendin dene

Policy'yi doğrudan çağırabilirsin — HTTP'ye gerek yok. Zaten policy'nin ayrı
sınıf olmasının kazancı bu:

```powershell
php artisan tinker
```

```php
use App\Models\User;
use App\Models\Invitation;
use Illuminate\Support\Facades\Gate;

$ayse   = User::factory()->create();
$mehmet = User::factory()->create();

$ayseninDavetiyesi   = Invitation::factory()->for($ayse)->create();
$mehmetinDavetiyesi  = Invitation::factory()->for($mehmet)->create();

// Sahibi erisir
Gate::forUser($ayse)->allows('view', $ayseninDavetiyesi);      // => true

// 🔴 Baskasi erisemez
Gate::forUser($ayse)->allows('view', $mehmetinDavetiyesi);     // => false
Gate::forUser($ayse)->allows('update', $mehmetinDavetiyesi);   // => false
Gate::forUser($ayse)->allows('delete', $mehmetinDavetiyesi);   // => false

// Liste ve olusturma herkese acik
Gate::forUser($ayse)->allows('viewAny', Invitation::class);    // => true
Gate::forUser($ayse)->allows('create', Invitation::class);     // => true

// Tip kontrolu — cast gercekten calisiyor mu?
gettype($ayseninDavetiyesi->user_id);   // => "integer"   ✅
gettype($ayse->id);                     // => "integer"   ✅

// Temizlik
Invitation::query()->forceDelete();
User::query()->delete();
```

`allows()` yerine `denies()` de var; ikisini birlikte kullanmak T6'nın
("bir davranışın hem varlığı hem yokluğu test edilir") tinker'daki karşılığıdır.

```powershell
composer check
```

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Kimlik doğrulama** (*authentication*) | "Sen kimsin?" — token/parola kontrolü |
| **Yetkilendirme** (*authorization*) | "Buna dokunabilir misin?" — sahiplik/rol kontrolü |
| **IDOR** | Kimliğini bildiği başkasının kaynağına erişmek |
| **Policy** | Bir model için yetki kurallarını toplayan sınıf |
| **Gate** | Yetki sorularının sorulduğu merkezi arayüz |
| **`authorizeResource`** | Controller metotlarını policy metotlarına otomatik bağlayan kısayol |
| **Otomatik keşif** (*auto-discovery*) | Policy'nin ad kuralıyla bulunması |
| **Enumeration** | Yanıt farklarından geçerli kimliklerin listesini çıkarmak |
| **Katı karşılaştırma** | `===` — hem değer hem tip eşleşmesi |

---

## 12. Sırada ne var?

**3.8 — `StoreInvitationRequest` / `UpdateInvitationRequest`**

Doğrulama katmanı. Orada:

- 🔴 **28 camelCase alan** → snake_case eşlemesi (D1, D4)
- İç içe dizi doğrulaması: `timelineEvents.*.time` (`date_format:H:i`)
- K44'ün sözleşmesi: `id` alanı `nullable`, ve gelen id'nin **bu davetiyeye**
  ait olduğunun doğrulanması
- Autosave gerçeği: neredeyse hiçbir alan `required` olamaz (D3'ün biçimi)

---

## 🆕 Faz 7 eklemesi — `publish()`

```php
public function publish(User $user, Invitation $invitation): bool
{
    return $this->owns($user, $invitation);
}
```

### 1. Neden `update` yeniden kullanılmadı?

İkisi bugün **aynı** cevabı veriyor (`owns()`). Yine de ayrı, çünkü
**niyetleri** farklı.

`docs/08`'in kod kataloğunda kullanılmayan bir kod duruyor:
`INVITATION_LOCKED` (403). O kod bir gün *"yayınlanmış davetiye
düzenlenemez"* kuralı için kullanılacak. O gün `update` kilitlenecek ama
`publish` kilitlenmemeli — aynı ability'yi paylaşıyor olsalardı ikisi
**birlikte** kilitlenirdi.

> **Ders:** iki kuralın bugün aynı cevabı vermesi, aynı kural oldukları
> anlamına gelmez.

### 2. 🔴 Plan yeterliliği neden burada değil?

Policy'ye koymak cazipti. Reddedildi — **çünkü Policy'nin cevabı bir `bool`dur**
ve `bool` bilgi taşıyamaz:

| Katman | Sorusu | Reddin karşılığı | Taşıdığı bilgi |
|---|---|---|---|
| **Policy** | "Bu kayıt senin mi?" | **404** (H7) | Hiçbiri — kaynak gizlenir |
| **Action** | "Planın yetiyor mu?" | **402** | `requiredTier` |

Policy'ye konsaydı paywall reddi 404'e dönüşür ve kullanıcı *"davetiyem
kayboldu"* derdi. Kural iki katmana **doğru yerlerinden** bölündü.

### 3. Aynı yetenek iki uçta

```php
POST /api/invitations/{invitation}/publish    → Gate::authorize('publish', …)
POST /api/invitations/{invitation}/checkout   → Gate::authorize('publish', …)
```

Bir davetiye için plan satın almak, yalnızca **yayınlayabileceğin** davetiye
için anlamlıdır. İkinci bir ability (`purchase`) tanımlamak aynı kuralın
(`owns()`) ikinci kopyası olurdu — **P1**: sahiplik kuralı tek yerde.

### 4. `create()`'teki not hâlâ açık

```php
/** Faz 7: plan kotasi kontrolu (K43) buraya gelecek. */
public function create(User $user): bool
{
    return true;
}
```

🔴 **K43 bu fazda uygulanmadı** ve not bilerek duruyor. K43 *"plan kotası
yayınlananı sayar, taslağı değil"* diyor — yani davetiye **oluşturmak**
zaten serbest olmalı. Sınırlanması gereken şey **kaç yayın** yapılabildiği ve
o karar (paket alımın kaç yayın açtığı) bugün **verilmedi**.

Açık ticari karar olarak `FAZ-7.md` §9'da kayıtlı.
