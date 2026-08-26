# `app/Http/Controllers/Api/V1/PublicInvitationController.php`

> **Kod dosyası:** `app/Http/Controllers/Api/V1/PublicInvitationController.php`
> **Faz:** 4 — Public davetiye, dosya 4.3
> **Önce oku:** [`ResolvePublicInvitationAction.md`](../../../../Actions/Invitation/ResolvePublicInvitationAction.md) ·
> [`PublicInvitationResource.md`](../../../Resources/PublicInvitationResource.md)

---

## 1. Fazın üçüncü sorusu

Faz 4 aynı veriyi ikinci bir okuyucuya açıyor ve bunu üç soruya bölmüştük:

| Soru | Cevap nerede |
|---|---|
| Hangi davetiye misafire açıktır? | `ResolvePublicInvitationAction` (4.1) |
| Hangi alanları görebilir? | `PublicInvitationResource` (4.2) |
| **Veritabanına kaç kere gidilecek?** | **bu dosya (4.3)** |

Üçüncü soru neden ayrı bir mesele? Çünkü yük profili benzersiz:

```
Davetiye linki WhatsApp grubuna düşer
  → 500 kişi 2 dakika içinde açar
  → ama veri son 3 haftadır DEĞİŞMEDİ
```

Bu bir **okuma-ağırlıklı** (read-heavy) yüktür. Sistemin geri kalanı böyle
değil: editör autosave'i saniyede yazar, dashboard tek kullanıcı okur. Aynı
uygulamanın içinde çok farklı bir trafik deseni var ve ona özel bir çözüm
gerekiyor.

Çözüm iki katmanlı:

```
1. katman — Cache      → veritabanına HİÇ gitme   (bu dosya)
2. katman — ETag / 304 → gövdeyi HİÇ gönderme     (4.5)
```

---

## 2. 🔴 `string $id` — route-model binding neden yok?

Faz 3'ün controller'ı davetiyeyi hazır alıyordu:

```php
public function show(Invitation $invitation)   // Faz 3 — implicit binding
```

Burada **kullanamayız**. Sebebi 4.1'de konuşmuştuk, şimdi somutlaştı.

Route-model binding `SubstituteBindings` middleware'inde çalışır — yani
controller'a girmeden **önce**. Yaptığı iş `Invitation::findOrFail($id)`, yani
bir `SELECT`. Eğer controller'a `Invitation $invitation` yazsaydık akış şu
olurdu:

```
İstek → SubstituteBindings → SELECT ✗ → Controller → Cache::remember → cache HIT
```

Cache 500 sorguyu kurtarmak yerine 500 sorguyu aynen çektirirdi. Optimizasyon
kâğıt üzerinde kalır, ölçümde görünmezdi — **en tehlikeli hata türü budur:
çalışıyor gibi görünen ama işe yaramayan kod.**

`string $id` almak *"veritabanına gitme kararını ben vereceğim"* demektir:

```
İstek → Controller → Cache::remember → cache HIT → yanıt   (sıfır sorgu)
                                     → cache MISS → Action → SELECT
```

### Skaler parametre nasıl bağlanıyor?

Sınıf tipli parametreler (`Request`, Action) servis konteynerinden çözülür;
skaler olanlar rota parametrelerinden gelir. Kaynak
(`vendor/.../Routing/ResolvesRouteDependencies.php`) sınıf bağımlılıklarını
`spliceIntoParameters()` ile **reflection sırasındaki konuma** ekliyor, sonra
`array_values()` ile pozisyonel olarak çağırıyor. Yani:

```php
public function show(Request $request, string $id, ResolvePublicInvitationAction $resolve)
//                   ↑ konteyner        ↑ rota      ↑ konteyner
```

Rotada parametrenin adı `{id}` olacak (4.4). Adı tutturmak zorunlu değil ama
tutturmamak, ileride ikinci bir skaler eklendiğinde sessiz bir sıra hatası
üretir.

---

## 3. Cache'te ne duruyor: dizi, model değil

```php
fn (): array => PublicInvitationResource::make($resolve->handle($id))->resolve($request)
```

Bu, fazın başında birlikte aldığımız kararın uygulaması: **Action saf kalır,
cache Resource'tan çıkan diziyi saklar.**

### Neden model cache'lemiyoruz?

`Cache::put()` verdiğin değeri **serileştirir** (file sürücüsünde PHP
`serialize()`, Redis'te de öyle). Bir Eloquent modelini serileştirirsen:

| Sorun | Nasıl patlar |
|---|---|
| Model şeması değişir (yeni kolon, kaldırılan cast) | Cache'ten **eski şemalı** bir nesne canlanır; `$model->yeniAlan` yok |
| İlişkiler de serileşir | `timelineEvents`, onun içindeki `invitation`… — beklenmedik büyüklükte veri |
| `$appends`, `$hidden` gibi ayarlar | Serileşme anındaki hâlleriyle donar |

Dizi serileştirmenin böyle bir riski yok: dizi neyse odur. Cache'in içinde
duran şey **yanıtın kendisi**, uygulamanın bir iç nesnesi değil.

### İkinci kazanç: ETag bedavaya gelir

4.5'te ETag'i hesaplayacağız. ETag, gövdenin parmak izidir. Cache'te zaten
gövdenin ta kendisi durduğu için hash'i doğrudan ondan alınabilir — modeli
cache'leseydik her istekte önce Resource'u çalıştırıp gövdeyi **yeniden
üretmek** gerekirdi.

> **Kalıp:** Cache'e neyi koyacağını, *cevabı üretmek için gereken son adımın
> çıktısı* belirler. Ara adımları cache'lemek, cache hit'te de iş yapmak demektir.

### `->resolve($request)` neden şart?

`PublicInvitationResource::resolve()` bize bir dizi verir — ama içindeki
`timelineEvents` anahtarı hâlâ bir **Resource nesnesi** olurdu.
`JsonResource::resolve()` kaynağına bakınca sebebi görülüyor: `filter()`
yalnızca `is_array($value)` olan değerlere iniyor, bir koleksiyon nesnesine
dokunmuyor. O nesne de içinde Eloquent modelleri taşıyor.

Bu yüzden 4.2b'de koleksiyonu **kendi içinde** çözdük:

```php
PublicTimelineEventResource::collection($this->timelineEvents)->resolve($request)
```

> 🔴 Bu, 4.2b'ye 4.3 yazılırken yapılmış bir **düzeltmedir**. Cache kararı,
> kendisinden önce yazılmış bir dosyanın davranışını değiştirdi. Normal bir
> durum: *bir bileşenin sözleşmesi, onu kullanan ilk gerçek çağrı yeri
> yazılana kadar tam olarak bilinmez.*

---

## 4. `Cache::remember()` ne yapıyor?

```php
Cache::remember($key, $ttl, $closure);
```

Üç adımlık bir kalıp — İngilizcede *get-or-compute* denir:

1. `$key` cache'te var mı? Varsa **değeri döndür, closure'ı hiç çalıştırma**
2. Yoksa `$closure`'ı çalıştır
3. Sonucu `$ttl` saniye boyunca saklayıp döndür

Elle yazsaydık:

```php
$payload = Cache::get($key);

if ($payload === null) {                       // ❌
    $payload = /* hesapla */;
    Cache::put($key, $payload, $ttl);
}
```

İki sorun: (a) `null` bir **geçerli değer** olabilir, `get()` ile "yok"tan
ayırt edilemez; (b) iki yer arasında `$key` ve `$ttl` tekrarlanır. `remember()`
ikisini de çözer.

### TTL neden 6 saat, ve neden TTL'e güvenmiyoruz?

```php
// config/davetkart.php
'public_invitation_ttl' => 60 * 60 * 6,
```

TTL bir **tazelik garantisi değil, üst sınırdır.** Tazeliği 4.6'daki olay
sağlayacak: davetiye yayınlandığında/güncellendiğinde anahtar **silinecek**.

O hâlde TTL neden var? Çünkü olay kaçırılabilir — kuyruk düşer, ham SQL ile
güncelleme yapılır, bir kod yolu olayı fırlatmayı unutur. TTL o durumda
"en kötü ihtimalle 6 saat" der. **Emniyet kemeri, direksiyon değil.**

> Ders 26'nın cache'teki hâli: *bir mekanizmanın çalıştığını varsayma, çalışmadığı
> durumda ne olacağını da tasarla.*

### `Config::integer()` neden `config()` değil?

`config()` `mixed` döner. `Cache::remember()`'ın ikinci parametresi sayı
bekliyor; `mixed` geçmek PHPStan seviyesi yükseldikçe (K22: Faz 5'te 8) hata
verir. Tipli erişimci yanlış tipte **anında** patlar — bkz.
[`Invitation.md`](../../../Models/Invitation.md) §10.

---

## 5. Zarf neden elle kuruluyor?

```php
return response()->json(['data' => $payload]);
```

Faz 3'te `return new InvitationResource($invitation);` yazıyorduk ve zarfı
Laravel kendisi ekliyordu. Burada elde bir Resource yok — cache'ten **dizi**
geliyor. Zarfı biz koyuyoruz.

Sözleşme değişmiyor: **K11** gereği auth dışındaki her yanıt `{data: ...}`
ile döner. Değişen tek şey, o zarfın kim tarafından eklendiği.

> ⚠️ Buradaki tuzak: `$payload`'ı cache'lerken zarfı **içine koymamak**. Zarf
> bir HTTP sunum kararıdır, veri değil. Cache'e zarflı yazsaydık, aynı veriyi
> bir gün başka bir biçimde sunmak istediğimizde (örneğin bir konsol komutunda)
> zarfı sökmek zorunda kalırdık.

---

## 6. Controller neden bu kadar ince?

`CLAUDE.md` §1: *"Controller'lar sadece gelen isteği ilgili Action'a
yönlendirmekten ve Resource dönmekten sorumludur (3-8 satır). İçerisinde `if`
blokları veya iş mantığı bulunamaz."*

Bu metotta **hiç `if` yok** ve iş mantığı da yok:

| Satır | Kimin işi |
|---|---|
| `Invitation::publicCacheKey($id)` | Model — adlandırma |
| `Config::integer(...)` | Config — politika |
| `$resolve->handle($id)` | Action — görünürlük kuralı |
| `PublicInvitationResource` | Resource — sözleşme |
| `Cache::remember` + `response()->json` | **Controller — HTTP ve önbellek kablolaması** |

Controller'ın kendi işi yalnızca sonuncusu. Geri kalan her satır bir başka
katmanın adını çağırıyor. Bir controller'ın "ince" olması satır sayısıyla
değil, **kaç karar verdiğiyle** ölçülür.

---

## 7. ⚠️ Bilinen borç: 404'ler cache'lenmiyor

`Cache::remember()` closure'ı exception fırlatırsa hiçbir şey yazılmaz. Yani:

```
GET /api/public/invitations/<olmayan-ulid>   → her seferinde bir SELECT
```

Bir saldırgan rastgele ULID'lerle istek yağdırıp her defasında veritabanına
sorgu açtırabilir. Bugün bu bir felaket değil (sorgu birincil anahtar üzerinde,
tek satır), ama sınırsız.

| Neden şimdi çözmüyoruz | Ne zaman |
|---|---|
| Genel API hız sınırı (`throttleApi`) zaten planda | **Faz 5** — `Notlar`/yol haritası |
| Negatif cache'in kendi riski var: davetiye sonradan yayınlanınca 404 cache'te kalır | Olay tabanlı temizleme (4.6) bunu çözer, ama önce olay yazılmalı |

🔴 Bu satırı burada yazmamın sebebi **B5**: hiçbir otomatik kontrolün yolunda
olmayan bir eksikliği, gördüğün anda yazılı hâle getirmezsen kimse hatırlamaz.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `Invitation $invitation` type-hint'i | Binding cache'ten önce SELECT açar; cache işe yaramaz | `string $id` (§2) |
| 2 | Modeli cache'lemek | Şema değişince bayat nesne canlanır | Diziyi cache'le (§3) |
| 3 | Zarfı cache'e koymak | Sunum kararı veriye karışır | Zarf `json()` çağrısında (§5) |
| 4 | Sadece TTL'e güvenmek | Yayınlanan davetiye 6 saat eski görünür | Olayla temizle (4.6) |
| 5 | Cache anahtarını elle yazmak | Listener başka bir metin üretir, `forget()` sessizce hiçbir şey yapmaz | `Invitation::publicCacheKey()` (C3) |
| 6 | Controller'a `if ($invitation->status !== ...)` eklemek | Görünürlük kuralı ikiye bölünür | Kural 4.1'de, sorgunun kapsamında |
| 7 | `Cache::rememberForever()` | Olay kaçarsa veri **sonsuza kadar** bayat kalır | TTL bir emniyet kemeridir (§4) |

---

## 9. Kendin dene

Rota henüz yok (4.4). Yine de cache davranışını `tinker`'da görebiliriz.

```powershell
php artisan tinker
```

```php
use App\Actions\Invitation\ResolvePublicInvitationAction;
use App\Http\Resources\PublicInvitationResource;
use App\Models\Invitation;
use App\Enums\InvitationStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$inv = Invitation::factory()->withTimeline(2)->create([
    'status' => InvitationStatus::Published,
    'published_at' => now(),
    'show_timeline' => true,
]);

$key = Invitation::publicCacheKey($inv->id);
$key;                                  // ⇒ 'davetkart:public-invitation:01k...'

$resolve = app(ResolvePublicInvitationAction::class);
$build = fn (): array => PublicInvitationResource::make($resolve->handle($inv->id))
    ->resolve(request());

// 1) İlk çağrı — SORGU AÇILIR
DB::enableQueryLog();
$a = Cache::remember($key, Config::integer('davetkart.cache.public_invitation_ttl'), $build);
count(DB::getQueryLog());              // ⇒ 2 (davetiye + program)

// 2) İkinci çağrı — SIFIR sorgu
DB::flushQueryLog();
$b = Cache::remember($key, Config::integer('davetkart.cache.public_invitation_ttl'), $build);
count(DB::getQueryLog());              // ⇒ 0   🔴 asıl kanıt

// 3) Cache'te gerçekten DÜZ DİZİ mi duruyor?
$ham = Cache::get($key);
is_array($ham['invitation']['timelineEvents']);        // ⇒ true
is_array($ham['invitation']['timelineEvents'][0]);     // ⇒ true (nesne DEĞİL)

// 4) Bayat veri kanıtı — 4.6 tam olarak bunu çözecek
$inv->update(['title' => 'YENI BASLIK']);
Cache::get($key)['invitation']['title'];               // ⇒ eski başlık ⚠️
Cache::forget($key);
Cache::remember($key, 3600, $build)['invitation']['title'];   // ⇒ 'YENI BASLIK'

// Temizlik
Cache::forget($key);
Invitation::withTrashed()->forceDelete();
```

Adım 2 fazın kalbi: **aynı cevap, sıfır sorgu.** Adım 4 ise 4.6'nın neden
zorunlu olduğunun kanıtı — cache doğru çalıştığı için bayat kalıyor.

```powershell
composer check
```

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Read-heavy** | Okuma sayısının yazma sayısını kat kat aştığı yük profili |
| **Cache hit / miss** | İstenen anahtarın cache'te bulunması / bulunmaması |
| **TTL** | Time To Live — bir cache girdisinin azami ömrü |
| **Get-or-compute** | "Varsa ver, yoksa üret ve sakla" kalıbı (`remember()`) |
| **Cache invalidation** | Bayat girdinin silinmesi |
| **Serileştirme** | Nesneyi saklanabilir bir biçime çevirme |
| **Route-model binding** | URL parçasından modeli otomatik çözen Laravel özelliği |
| **Negatif cache** | "Bu kayıt yok" bilgisinin de cache'lenmesi |

---

## 11. Sırada ne var?

**4.4 — `routes/api.php`'ye `/api/public/` grubu.** Controller hazır ama hiçbir
URL'e bağlı değil. Rota **K12**'nin uygulaması olacak:

> Auth gerektirmeyen rotalar `/api/public/` öneki altında gruplanır.

Bu bir *fail-safe* tasarımı: `auth:sanctum`'u yanlışlıkla unutmak bir davetiyeyi
herkese açmak demektir. Öneki ayırmak, "açık" olmayı bir **unutmanın sonucu**
olmaktan çıkarıp **açıkça işaretlenmiş bir istisna** hâline getirir.
