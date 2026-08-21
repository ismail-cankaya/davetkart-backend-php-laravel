# `app/Actions/Invitation/ResolvePublicInvitationAction.php`

> **Kod dosyası:** `app/Actions/Invitation/ResolvePublicInvitationAction.php`
> **Faz:** 4 — Public davetiye, dosya 4.1
> **Önce oku:** [`CreateInvitationAction.md`](CreateInvitationAction.md) ·
> [`../../Policies/InvitationPolicy.md`](../../Policies/InvitationPolicy.md)

---

## 1. Faz 4 neyi çözüyor, bu dosya neden ilk?

Faz 3'te davetiyenin **sahibi** için okuma yolunu yazdık: giriş yapmış kullanıcı
kendi kaydını görüyor. Faz 4 aynı verinin **ikinci okuyucusunu** açıyor:
davetiye linki WhatsApp grubuna düşüyor ve 500 kişi iki dakikada tıklıyor. Bu
kişilerin hesabı yok, token'ı yok, kim oldukları bilinmiyor.

İki okuyucu arasındaki fark üç ayrı soruya bölünür:

| Soru | Nerede cevaplanır |
|---|---|
| **Hangi davetiye misafire açıktır?** | **bu dosya (4.1)** |
| Hangi alanları görebilir? | `PublicInvitationResource` (4.2) |
| Veritabanına kaç kere gidilir? | Controller'daki cache (4.3) + ETag (4.5) |

Bu dosyanın **ilk** yazılmasının sebebi bir zevk meselesi değil, **bağımlılık
sırası** (çalışma kuralı 10):

```
PublicInvitationController  →  ResolvePublicInvitationAction   (bu dosya)
                            →  PublicInvitationResource
                            →  routes/api.php
```

Controller üç şeye birden bağımlı. Bu Action ise yalnızca **zaten var olan**
iki şeye bağımlı: `Invitation` modeli (3.4) ve `InvitationStatus::Published`
(3.1). Yani zincirin en içteki yaprağı. Onu önce yazınca hiçbir adım "henüz
olmayan bir sınıfa" referans vermiyor ve her adım yeşil bitiyor.

> **Genel ilke:** Yaprakları önce yaz. Kök (controller) en son yazılır, çünkü
> yalnızca o birden çok şeyi birbirine bağlar.

---

## 2. 🔴 Görünürlük bir `if` değil, sorgunun kapsamıdır

Kodun tamamı beş satır:

```php
return Invitation::query()
    ->whereKey($id)
    ->where('status', InvitationStatus::Published)
    ->with('timelineEvents')
    ->firstOrFail();
```

Aynı iş şöyle de yazılabilirdi:

```php
$invitation = Invitation::findOrFail($id);          // ❌

if ($invitation->status !== InvitationStatus::Published) {
    throw new ModelNotFoundException();
}

return $invitation;
```

İkincisi **çalışır**. Yine de yanlıştır, ve sebebi Faz 3'te kurduğumuz **P3**
kuralının tam olarak aynısıdır:

> **P3** — Koleksiyon uçlarında sahiplik Policy ile değil **sorgu ile** korunur.

Faz 3'te bunu `index()` için öğrenmiştik: `$user->invitations()` yazmak,
`Invitation::all()` yazıp sonra filtrelemekten farklıdır. Farkı **unutulabilirlik**
yaratır:

| Yaklaşım | Kuralı unutursan ne olur |
|---|---|
| `if` ile eleme | Kod derlenir, testler (o senaryoyu yazmadıysan) geçer, **taslak sızar** |
| Sorgunun kapsamı | Filtreyi silmek için satırı **bilerek** silmen gerekir |

İkisi arasındaki asıl fark şu: `if` ile yazarsan **yayınlanmamış davetiye bir an
için belleğe gelir**. O nesne artık ortada dolaşıyordur; bir `dd()`, bir log
satırı, bir `catch` bloğu onu dışarı sızdırabilir. Sorgunun kapsamıyla
yazarsan o satır veritabanından **hiç çıkmaz**.

> **Kalıp:** Güvenlik filtresini veriyi getirdikten sonra değil, **getirirken**
> uygula. En güvenli veri, hiç okunmamış veridir.

---

## 3. Neden `string $id`? Route-model binding neden yok?

Faz 3'ün controller'ı davetiyeyi böyle alıyordu:

```php
public function show(Invitation $invitation): InvitationResource   // Faz 3
```

Buna **implicit route model binding** denir: Laravel URL'deki `{invitation}`
parçasını görür, `Invitation::findOrFail($id)` çağırır ve hazır modeli metoda
verir. Çok pratiktir. Faz 4'te **kullanmıyoruz**, iki sebeple.

### 3.1 Binding cache'i baypas eder

Faz 4'ün bütün amacı şu: *"davetiye neredeyse hiç değişmiyor, o hâlde
veritabanına neredeyse hiç gitmeyelim."* Planladığımız akış:

```
İstek gelir
  ↓
Cache'te var mı?  ──── evet ──→  cevabı ver, veritabanına HİÇ GİTME
  ↓ hayır
Action → veritabanı → Resource → cache'e yaz → cevabı ver
```

Route-model binding **middleware ve controller'dan önce** çalışır. Yani
controller'a `Invitation $invitation` yazarsak, cache'e bakmadan **her istekte**
bir `SELECT` açılmış olur. 500 kişi açtığında cache 500 sorguyu kurtarmak yerine
500 sorguyu aynen çektirir — optimizasyon kâğıt üzerinde kalır.

`string $id` almak, "veritabanına gitme kararını **ben** vereceğim" demektir.

### 3.2 Binding yanlış soruyu sorar

Binding `findOrFail` çağırır — yani *"bu id'de bir davetiye var mı?"* diye
sorar. Bizim sorumuz bu değil: *"bu id'de **misafire açık** bir davetiye var
mı?"* Binding'i kullanırsak eksik kalan farkı yine bir `if` ile kapatmak
zorunda kalırız ve §2'deki tuzağa düşeriz.

> Laravel'in binding'i ayrıca `->where()` ile özelleştirilebilir
> (`Route::bind`, `resolveRouteBinding`). Kullanmıyoruz: kural o zaman rota
> dosyasına veya modele dağılır. Biz kuralın **tek bir Action'da** durmasını
> istiyoruz — Faz 5'te LCV gönderimi de aynı soruyu soracak ve aynı sınıfı
> çağıracak.

---

## 4. `firstOrFail()` — Action hata yanıtı üretmez

Metot `?Invitation` değil `Invitation` dönüyor: yani "bulamadım" durumunu
`null` ile değil **exception** ile bildiriyor. Bu Faz 1'de kurulan **H10**
kuralı:

> **H10** — Action ve Controller **hata yanıtı üretmez**, exception fırlatır.

`null` dönseydi controller şuna dönerdi:

```php
$invitation = $action->handle($id);

if ($invitation === null) {                    // ❌ 404 kararı controller'a sızdı
    abort(404);
}
```

Artık HTTP durum kodu kararı iki yerde: hem `ApiExceptionRenderer`'da hem
burada. Üçüncü bir public uç geldiğinde (Faz 5'in LCV gönderimi) aynı `if`
kopyalanır — ve bir gün biri onu kopyalamayı unutur.

### 4.1 Neden yeni bir exception sınıfı yazmadık?

`firstOrFail()` Laravel'in kendi `ModelNotFoundException`'ını fırlatır ve
`ApiExceptionRenderer` onu **zaten** tanıyor:

```php
// app/Exceptions/ApiExceptionRenderer.php — 3.7'de yazıldı
$e instanceof ModelNotFoundException,
$e instanceof AuthorizationException => ErrorCode::ResourceNotFound,
```

`ResourceNotFound` → `status()` → **404** → gövde `{"error":{"code":"RESOURCE_NOT_FOUND"}}`.

Yani sözleşmeye uygun yanıtı **bedavaya** aldık; `H11` gereği renderer'a yeni
bir `match` kolu eklememiz gerekmiyor. Yeni exception sınıfı yazmak yalnızca
**yeni bir hata kodu** gerektiğinde anlamlıdır (Faz 5'in
`RsvpQuotaExceededException`'ı gibi). Burada gerekmiyor.

---

## 5. 🔴 Enumeration savunması: iki farklı sebep, tek yanıt

Şu iki durum **ayırt edilemez**:

| Durum | Yanıt |
|---|---|
| Böyle bir ULID hiç yok | `404` · `RESOURCE_NOT_FOUND` |
| ULID var ama davetiye `saved` (yayında değil) | `404` · `RESOURCE_NOT_FOUND` |

Bu bir kolaylık değil, bilinçli bir güvenlik kararı — `08-HATA-SOZLESMESI.md`
§3.2'nin public tarafı.

Ayrım verseydik ne olurdu? Saldırgan ULID uzayını tarar ve `403 Forbidden`
aldığı her id için *"burada bir davetiye var, henüz yayında değil"* bilgisini
kazanır. Bu bilgiyle:

- Kaç kullanıcının hazırlık yaptığını sayabilir (rakip firma için değerli)
- Bir davetiyenin yayına girdiği **anı** yoklamayla tespit edebilir
- Elindeki id listesini "gerçek" ve "sahte" diye ikiye ayırabilir

ULID'in kendisi de aynı ailenin savunması (K40): ardışık integer olsaydı
`/invite/1`, `/invite/2` diye gezmek yeterdi. ULID tahmin edilemez, ama
**tahmin edilemezlik tek başına yeterli değildir** — sızdırılmış bir id ile
gelen kişiye de ayrım vermemek gerekir. İkisi birlikte çalışır.

> **Kalıp:** Yanıtın **farkı** bilgidir. İki farklı sebep aynı bilgi sınıfına
> giriyorsa, yanıtları da birebir aynı olmalıdır — durum kodu, gövde, hatta
> mümkünse süre.

---

## 6. `with('timelineEvents')` — Faz 3'ün sapması burada da geçerli

Faz 3'te plandan bilinçli olarak saptık: `whenLoaded()` kullanmadık.

`InvitationPayloadResource` (3.9) ilişkiye **doğrudan** erişiyor:

```php
'timelineEvents' => TimelineEventResource::collection($this->timelineEvents),
```

Gerekçe şuydu: `whenLoaded` ilişki yüklü değilse anahtarı **sessizce düşürür**,
frontend eksik alanı varsayılanla doldurur ve kullanıcı hiç yazmadığı bir
programı görür. Doğrudan erişimde ise `AppServiceProvider`'daki
`preventLazyLoading` yerelde `LazyLoadingViolationException` fırlatır.

> *N+1 bir performans sorunudur; yanlış veri bir doğruluk sorunudur.*
> Gürültülü hatayı sessiz yanlışa tercih ederiz.

Bu karar Faz 4'e bir **yükümlülük** olarak geçiyor: ilişkiyi kim yüklüyorsa,
yüklemeyi unutmamalı. Faz 3'te bu iş controller'ın `with()`'undaydı. Faz 4'te
Action'ın kendi içinde, çünkü:

- Action tek bir kayıt döndürüyor; ilişkinin yüklü olması bu Action'ın
  **sözleşmesinin parçası** (döndürdüğü nesne Resource'a hazır olmalı)
- Cache'lenen şey Resource çıktısı olacak; ilişki eksikse cache'e **eksik veri**
  yazılır ve TTL boyunca eksik kalır. Yani buradaki bir unutma Faz 3'tekinden
  daha uzun ömürlü olurdu

**N+1 nedir?** Bir ana sorgu + her satır için bir ek sorgu. Burada tek davetiye
var, yani N=1; `with()` olmadan toplam 2 sorgu olurdu — felaket değil. Ama
kural performanstan çok **tutarlılık** için var: Resource ilişkinin yüklü
olduğunu varsayıyor, dolayısıyla onu üreten her yol yüklemekle yükümlü.

---

## 7. Satır satır dil notları

```php
final class ResolvePublicInvitationAction
```

`final` = bu sınıftan miras alınamaz. Neden? Bu bir **davranış** sınıfı, bir
soyutlama değil. Miras alınabilir bırakmak, birinin `handle()`'ı ezip güvenlik
filtresini kaldırabileceği anlamına gelir. Projedeki tüm Action'lar `final`.

```php
public function handle(string $id): Invitation
```

- `string $id` → PHP 8'de tip zorunlu; `int` gelirse `TypeError` fırlar
  (`strict_types=1` sayesinde sessiz dönüşüm yok, K1)
- `: Invitation` → dönüş tipi; `null` dönmek **imkânsız**. PHPStan bunu bilir ve
  controller'da `if ($x === null)` yazarsan "bu koşul asla doğru olamaz" der

```php
Invitation::query()
```

Neden `Invitation::whereKey(...)` değil? İkisi de çalışır — statik çağrı
Eloquent'in `__callStatic` sihriyle aynı builder'ı üretir. `query()` yazmak
**açıklıktır**: okuyan kişi bir sorgu kurucusu (query builder) zinciri
başladığını görür. Larastan da tipi net çözer (`Builder<Invitation>`).

```php
->whereKey($id)
```

`where('id', $id)` ile aynı işi yapar ama kolon adını **modelden** okur
(`getKeyName()`). Yarın birincil anahtarın adı değişse bu satır kendiliğinden
doğru kalır. Sihirli string yok (`CLAUDE.md` §1).

```php
->where('status', InvitationStatus::Published)
```

Sorguya **enum nesnesi** veriyoruz, `'published'` düz metni değil. Laravel
`BackedEnum`'u bağlarken otomatik olarak `->value`'sunu alır. Kazanç: yazım
hatası (`'publised'`) çalışma anında sessizce sıfır satır döndürmek yerine
**derleme/analiz anında** yakalanır.

```php
->firstOrFail();
```

`first()` bulamazsa `null`, `firstOrFail()` bulamazsa `ModelNotFoundException`
döner. §4'te anlatıldı.

### Görünmeyen bir filtre: soft delete

Modelde `SoftDeletes` trait'i var (3.4). Bu trait bir **global scope** ekler ve
her sorguya sessizce `WHERE deleted_at IS NULL` koyar. Yani silinmiş davetiye
buradan zaten çıkmaz; `->withoutTrashed()` yazmak gereksiz tekrar olurdu.

Üretilen SQL kabaca şu:

```sql
SELECT * FROM invitations
WHERE id = ? AND status = 'published' AND deleted_at IS NULL
LIMIT 1
```

`id` birincil anahtar olduğu için sorgu tek satırlık bir indeks erişimidir —
`(user_id, status)` indeksi burada kullanılmaz, gerekmez de.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `findOrFail()` + sonra `if ($status !== ...)` | Yayınlanmamış kayıt belleğe gelir; filtreyi silmek kolay | Filtreyi sorguya koy (§2) |
| 2 | Controller'a `Invitation $invitation` type-hint'i | Binding cache'ten önce çalışır, her istekte SELECT açılır | `string $id` al (§3.1) |
| 3 | `?Invitation` dönmek | 404 kararı controller'a sızar, her yeni uçta kopyalanır | `firstOrFail()` (§4) |
| 4 | Yayınlanmamış için `403` dönmek | Kaynağın varlığını doğrular; enumeration açığı | Her iki durumda 404 (§5) |
| 5 | `with('timelineEvents')`'i unutmak | Yerelde `LazyLoadingViolation`; üretimde cache'e eksik veri yazılır | Action kendi ilişkisini yükler (§6) |
| 6 | `->where('status', 'published')` düz metin | Yazım hatası sessizce 0 satır döndürür | Enum kullan (§7) |
| 7 | Buraya cache eklemek | Action cache sürücüsüne bağlanır, birim testi zorlaşır | Cache 4.3'te, Resource çıktısı üzerinde |
| 8 | Buraya "sayaç artır" gibi yan etki eklemek | Cache hit'te hiç çalışmaz → sayaç yalan söyler | Yan etki cache'in dışında durmalı |

---

## 9. Kendin dene

Henüz rota yok, o yüzden `tinker` ile deneyeceğiz. Ayrıca `status` alanını
`published` yapan bir uç da yok (yayın akışı Faz 7'de) — durumu elle
değiştiriyoruz.

```powershell
php artisan tinker
```

```php
use App\Actions\Invitation\ResolvePublicInvitationAction;
use App\Models\Invitation;
use App\Enums\InvitationStatus;

// 1) Yayınlanmamış (saved) bir davetiye üret
$inv = Invitation::factory()->withTimeline(3)->create();
$inv->id;                       // ULID'i not al

$action = app(ResolvePublicInvitationAction::class);

// 2) Yayında olmadığı için BULAMAMALI
$action->handle($inv->id);
// ⇒ Illuminate\Database\Eloquent\ModelNotFoundException

// 3) Var olmayan bir id de AYNI hatayı vermeli (§5)
$action->handle('01ARZ3NDEKTSV4RRFFQ69G5FAV');
// ⇒ aynı exception — ayrım yok

// 4) Yayına al, tekrar dene
$inv->update(['status' => InvitationStatus::Published, 'published_at' => now()]);
$found = $action->handle($inv->id);
$found->title;

// 5) İlişki YÜKLÜ mü? (§6)
$found->relationLoaded('timelineEvents');       // ⇒ true
$found->timelineEvents->count();                // ⇒ 3

// 6) Soft delete de kapatıyor mu? (§7)
$found->delete();
$action->handle($inv->id);                      // ⇒ yine ModelNotFoundException

// Temizlik
Invitation::withTrashed()->forceDelete();
```

Adım 3 en önemlisi: **iki farklı sebep, tek yanıt.** Adım 5 olmadan Faz 4'ün
Resource'u yerelde patlardı.

Sonra kaliteyi doğrula:

```powershell
composer lint
composer check
```

> `composer lint` **düzeltir**, `composer check` içindeki `pint --test`
> yalnızca **bakar** (Faz 1, ders 12). Sıra bu yüzden böyle.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Query builder** | Zincirlenebilir metotlarla SQL kuran nesne (`->where()->first()`) |
| **Global scope** | Modelin her sorgusuna otomatik eklenen koşul (`SoftDeletes` gibi) |
| **Route model binding** | URL parçasından modeli otomatik çözen Laravel özelliği |
| **Implicit binding** | Type-hint'e bakarak yapılan otomatik binding |
| **Enumeration** | Hata farkından kaynakların varlığını haritalama saldırısı |
| **N+1 sorgu** | 1 ana sorgu + her satır için 1 ek sorgu |
| **Eager loading** | İlişkiyi `with()` ile önceden yükleme |
| **Soft delete** | Satırı silmeyip `deleted_at` damgalama |
| **`final`** | Sınıfın miras alınmasını engelleyen anahtar sözcük |
| **Backed enum** | Her case'i bir skaler değere bağlı enum (PHP 8.1) |
| **Cache baypası** | Cache'e bakmadan kaynağa gitme; optimizasyonu etkisizleştirir |

---

## 11. Sırada ne var?

**4.2 — `PublicInvitationResource`.** Bu Action *"hangi davetiye açık?"*
sorusunu cevapladı. Sıradaki dosya *"hangi alanları görebilir?"* sorusunu
cevaplayacak ve Faz 3'te kurduğumuz **C4** kuralının ilk gerçek uygulaması
olacak:

> **C4** — Aynı veri, farklı okuyucular için **farklı Resource**'a çıkar.

Somut olarak: `show_gift = false` iken `iban`, `bankName`, `accountHolder`
alanları misafire **hiç gitmeyecek** — boş string olarak değil, anahtar olarak
da yok. Sahibin kendi editöründe ise (3.9) aynı alanlar görünmeye devam edecek.
