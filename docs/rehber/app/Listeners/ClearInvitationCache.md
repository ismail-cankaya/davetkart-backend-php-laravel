# `app/Listeners/ClearInvitationCache.php`

> **Kod dosyası:** `app/Listeners/ClearInvitationCache.php`
> **Faz:** 4 — Public davetiye, dosya 4.6b
> **Önce oku:** [`../Events/InvitationChanged.md`](../Events/InvitationChanged.md) (4.6a)

---

## 1. Cache invalidation — "iki zor problemden biri"

Bilgisayar bilimlerinde çok alıntılanan bir söz var (Phil Karlton):

> *"There are only two hard things in Computer Science: cache invalidation and
> naming things."*
> — Bilgisayar bilimlerinde yalnızca iki zor şey vardır: **önbelleği
> geçersizleştirmek** ve **isimlendirmek**.

Espri gibi duruyor ama sebebi ciddi: cache'lemek kolaydır — bir değeri saklar,
bir daha hesaplamazsın. Zor olan, o değerin **ne zaman yanlış hâle geldiğini**
bilmektir. Çünkü:

- Yanlışlık **sessizdir**: sistem hata vermez, sadece eski cevabı verir
- Geç fark edilir: kullanıcı "değişikliğim niye görünmüyor?" diye sorana kadar
- Testle yakalamak zordur: her test taze bir cache ile başlar

Bu dosya tam olarak o zor problemi çözüyor ve 20 satır. Zorluk kodun
uzunluğunda değil, **doğru anı yakalamakta**.

---

## 2. Laravel dinleyiciyi nasıl buluyor?

Bu sınıfı hiçbir yere kaydetmedik. Ne `bootstrap/app.php`'de, ne bir
`EventServiceProvider`'da (Laravel 11+ ile o dosya zaten kaldırıldı — Faz 0,
ders 3'ün ailesi).

Yine de çalışacak, çünkü Laravel **otomatik keşif** (auto-discovery) yapıyor.
Kaynağa bakalım:

```php
// vendor/.../Foundation/Application.php:248
return (new static::$applicationBuilder(new static($basePath)))
    ->withKernels()
    ->withEvents()          // ← keşif varsayılan olarak açık
    ->withCommands()
    ->withProviders();
```

```php
// vendor/.../Support/Providers/EventServiceProvider.php:166
protected function discoverEventsWithin()
{
    return static::$eventDiscoveryPaths ?: [
        $this->app->path('Listeners'),      // ← app/Listeners taranır
    ];
}
```

```php
// vendor/.../Foundation/Events/DiscoverEvents.php:86
foreach ($listener->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if ((! Str::is('handle*', $method->name) && ! Str::is('__invoke', $method->name)) ||
    // ... ve parametrenin TIP BELIRTIMI olay sinifidir
```

Yani kural üç maddede:

1. Sınıf `app/Listeners/` altında olacak
2. `handle` ile başlayan (ya da `__invoke`) **public** bir metodu olacak
3. O metodun parametresi bir **sınıfla tip belirtilmiş** olacak

`handle(InvitationChanged $event)` bu üçünü de sağlıyor. Laravel yansımayla
(reflection) parametrenin tipini okuyor ve `InvitationChanged` olayına bu
dinleyiciyi bağlıyor.

> 🔴 Bunun bir bedeli var: **bağlantı koda bakarak görünmez.** `handle()`'ın
> tip belirtimini `Invitation $event` diye yanlış yazarsan hiçbir hata almazsın
> — dinleyici sessizce hiç çağrılmaz. Bu yüzden 4.7'de bunun **testi** var:
> "güncelleme cache'i düşürür" testi kırmızıya döner.
>
> Otomatik keşif kolaylık verir, görünürlük alır. Faz 4'ün ders 34'ü burada da
> geçerli: *beklediğim sonucu almak, beklediğim sebeple aldığım anlamına
> gelmez.*

Üretimde keşfi hızlandırmak için `php artisan event:cache` var (Faz 9).

---

## 3. Kod okuması

```php
public function handle(InvitationChanged $event): void
{
    Cache::forget(Invitation::publicCacheKey($event->invitation->id));
}
```

### Anahtarı neden model üretiyor?

Bu satır, `PublicInvitationController`'ın yazdığı anahtarla **birebir aynı
metni** üretmek zorunda. Bir harf farkı olsa:

```php
Cache::forget('davetkart:public_invitation:'.$id);   // ❌ alt çizgi
```

`forget()` var olmayan bir anahtarı silmeye çalışır ve **hiçbir şey olmaz** —
exception yok, uyarı yok, hatta bazı sürücülerde dönüş değeri `true`. Davetiye
güncellenir, sahibi "kaydedildi" görür, misafirler 6 saat eski hâlini okur.

Bu yüzden anahtar `Invitation::publicCacheKey()` içinde tek bir yerde üretiliyor
(**C3**) — ayrıntısı [`../Models/Invitation.md`](../Models/Invitation.md) §10'da.

### `void` dönüş tipi

Dinleyici bir değer döndürmez. Laravel'de bir dinleyicinin `false` döndürmesi
**olay yayılımını durdurur** (sonraki dinleyiciler çalışmaz). `void` yazarak bu
kapıyı kapatıyoruz: yanlışlıkla bir `false` sızarsa PHP hata verir.

---

## 4. 🔴 Neden kuyruğa alınmıyor?

Laravel'de bir dinleyiciyi arka plana atmak tek satırdır:

```php
final class ClearInvitationCache implements ShouldQueue   // ❌ BURADA YANLIŞ
```

Ve genellikle **iyi** bir fikirdir: yavaş işleri (mail, görsel işleme) HTTP
isteğinden çıkarmak `CLAUDE.md` §4'ün "15 saniye kuralı"dır.

Burada yanlış olmasının üç sebebi var:

| # | Sebep |
|---|---|
| 1 | **İş zaten mikrosaniyelik.** `Cache::forget()` bir anahtar siler. Kuyruğa yazmanın maliyeti işin kendisinden büyük. |
| 2 | **Gecikme = yanlış veri.** Kuyruk işçisi 2 saniye sonra çalışırsa, o 2 saniyede giren misafirler eski davetiyeyi görür. |
| 3 | 🔴 **Kuyruk çalışmıyorsa hiç temizlenmez.** Geliştirmede `QUEUE_CONNECTION=database` ve `queue:work` açık değilse iş **hiç** koşmaz. Cache TTL dolana kadar (6 saat) bayat kalır — ve bunu fark etmezsin, çünkü sistem hata vermiyordur. |

Üçüncüsü en sinsi olanı: kuyruğa almak, çalışmayan bir mekanizmaya **sessizce**
bağımlı olmak demek. Faz 4'ün 4.3 kılavuzunda TTL için yazdığımız cümle
buranın da özeti: *bir mekanizmanın çalıştığını varsayma.*

> **Kalıp:** Kuyruğa alma kararı "yavaş mı?" sorusuyla değil, **"gecikirse ne
> olur?"** sorusuyla verilir. Hoş geldin e-postası 10 saniye gecikebilir; bir
> tutarlılık garantisi gecikemez.

### Peki Faz 6'nın medya temizliği?

Faz 6'da davetiye silinince S3'teki dosyaların da silinmesi gerekecek. **O iş
kuyruğa girer**: ağ çağrısı, yavaş, ve 30 saniye gecikmesi kimseyi rahatsız
etmez. Aynı olayı iki dinleyici dinleyecek — biri senkron, biri kuyruklu.

Gevşek bağın kazancı tam da bu: `UpdateInvitationAction` ikisinden de habersiz.

---

## 5. 🔴 `ShouldHandleEventsAfterCommit` — transaction yarışı

`UpdateInvitationAction` işini bir transaction içinde yapıyor (E4):

```php
DB::transaction(function () use (...) {
    $invitation->fill($attributes)->save();      // ← 'updated' olayı BURADA fırlar
    $this->syncTimelineEvents->handle(...);
    ...
});                                              // ← commit BURADA
```

Model olayları **transaction'ın içinde** fırlar. Dinleyici hemen çalışsaydı,
cache commit'ten **önce** temizlenirdi. Zararsız gibi duruyor — değil:

```
t0  T1: transaction başlar, save() → cache anahtarı SİLİNİR
t1  T2: bir misafir GET atar → cache MISS
t2  T2: veritabanını okur → T1 henüz commit etmedi → ESKİ veriyi görür
t3  T2: cache'i ESKİ veriyle doldurur
t4  T1: commit eder
    ⇒ Cache artık eski veriyi tutuyor ve kimse temizlemeyecek — 6 saat.
```

Yani cache temizleme, **temizlemeye çalıştığı bayatlığı kendi üretebilir.**
Buna *race condition* (yarış koşulu) denir: sonucu, iki işlemin hangi sırayla
ilerlediği belirliyor.

Çözüm tek satır:

```php
final class ClearInvitationCache implements ShouldHandleEventsAfterCommit
```

Laravel dinleyiciyi transaction yöneticisine erteliyor
(`vendor/.../Events/Dispatcher.php:601`). Kaynağa bakınca üç davranış
görünüyor:

| Durum | Ne olur |
|---|---|
| Transaction içinde | Temizleme **commit'ten sonraya** ertelenir |
| Transaction yok (örn. `destroy()` ucu) | `addCallback()` geri çağrımı **hemen** çalıştırır (`DatabaseTransactionsManager.php:219`) |
| Transaction **rollback** olur | Geri çağrım **hiç** çalışmaz — boşuna temizlik yapılmaz |

Üçüncüsü hoş bir yan kazanç: başarısız bir güncelleme cache'i gereksiz yere
düşürmüyor.

> ⚠️ **Dürüstlük payı:** bu yarışı *daraltır*, matematiksel olarak kapatmaz.
> Commit'ten hemen önce başlayıp temizlikten hemen sonra biten bir okuma hâlâ
> teorik olarak eski veri yazabilir. Pencere mikrosaniyeler mertebesine iner
> (transaction süresi yerine), ve kalan risk TTL ile sınırlıdır. Tam çözüm
> (sürüm damgalı anahtar ya da yazma anında cache'i doldurma) bu ölçekte
> gereksiz karmaşıklık olurdu.
>
> Bir savunmanın **neyi kapatmadığını** yazmak, kapattığını yazmak kadar
> önemli — 4.5'te ETag için de aynısını yapmıştık.

---

## 6. TTL ile olay: hangisi taze tutuyor?

Faz 4'ün cache tasarımında iki mekanizma var ve **rolleri farklı**:

| | Olay (bu dosya) | TTL (6 saat) |
|---|---|---|
| Ne zaman devreye girer | Veri değiştiği **anda** | Zaman geçince |
| Rolü | **Tazelik garantisi** | **Üst sınır** |
| Kaçırılabilir mi | Evet (ham SQL, `event:cache` bayat, keşif kopmuş) | Hayır |
| Benzetme | Direksiyon | Emniyet kemeri |

TTL'e "yeter" demek, davetiyesinin yazım hatasını düzelten kullanıcıya "yarım
gün bekle" demektir. Olaya "yeter" demek ise onu **tek** savunma yapmaktır —
oysa olayın kaçabileceği yollar var:

- `DB::table('invitations')->update(...)` — ham sorgu Eloquent olaylarını
  **tetiklemez**
- `php artisan event:cache` sonrası yeni bir dinleyici eklenip cache
  yenilenmezse
- `Invitation::withoutEvents(fn () => ...)` kullanılırsa

İkisi birlikte **katmanlı savunma** (defense in depth) oluşturuyor: aynı hedefi
farklı biçimde koruyan iki bağımsız mekanizma. Faz 5'in LCV modülünde aynı
ilkenin daha büyük bir örneğini göreceğiz.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Anahtarı elle yazmak | `forget()` sessizce hiçbir şey yapmaz | `Invitation::publicCacheKey()` (C3) |
| 2 | `implements ShouldQueue` | Kuyruk kapalıysa cache hiç temizlenmez | Senkron kalsın (§4) |
| 3 | `handle()`'a yanlış tip yazmak | Keşif eşleşmez, dinleyici hiç çağrılmaz — **sessizce** | Testle doğrula (4.7) |
| 4 | Sınıfı `app/Listeners/` dışına koymak | Keşif taramaz | Standart klasör |
| 5 | `Cache::flush()` çağırmak | **Tüm** uygulamanın cache'i uçar (oturumlar, rate limiter sayaçları) | Yalnızca ilgili anahtar |
| 6 | Dinleyicide `false` döndürmek | Sonraki dinleyiciler hiç çalışmaz | `void` |
| 7 | "TTL zaten var, olay gereksiz" demek | Kullanıcı düzeltmesini 6 saat göremez | İkisi farklı rolde (§6) |

> 5. madde: `Cache::flush()` bu tür bir dosyada en sık görülen ve en pahalı
> hatadır. "Emin olmak için hepsini temizleyelim" refleksi, aynı cache'i
> kullanan oturum verisini ve `throttle:auth` sayaçlarını (K36) da siler —
> yani bir hız sınırı savunmasını sıfırlar.

---

## 8. Kendin dene

Dinleyici hazır ama olay henüz **fırlatılmıyor** — model kablolaması 4.6c'de.
O yüzden olayı elle fırlatıyoruz; keşfin çalıştığını da böyle görüyoruz.

```powershell
php artisan tinker
```

```php
use App\Events\InvitationChanged;
use App\Enums\InvitationStatus;
use App\Http\Resources\PublicInvitationResource;
use App\Actions\Invitation\ResolvePublicInvitationAction;
use App\Models\Invitation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

$inv = Invitation::factory()->create([
    'status' => InvitationStatus::Published,
    'published_at' => now(),
]);
$key = Invitation::publicCacheKey($inv->id);

$resolve = app(ResolvePublicInvitationAction::class);
$build = fn (): array => PublicInvitationResource::make($resolve->handle($inv->id))->resolve(request());

// 1) Cache'i doldur
Cache::remember($key, 3600, $build);
Cache::has($key);                       // ⇒ true

// 2) 🔴 Keşif çalışıyor mu? Olayı elle fırlat
event(new InvitationChanged($inv));
Cache::has($key);                       // ⇒ false   ← dinleyici bulundu ve koştu

// 3) Bağlantı gerçekten kayıtlı mı? (kaydı listele)
Event::getRawListeners()[InvitationChanged::class] ?? 'KAYIT YOK';
// ⇒ ['App\Listeners\ClearInvitationCache@handle']

Invitation::withTrashed()->forceDelete();
```

Adım 2 fazın bu bölümünün kanıtı: **hiçbir yere kaydetmediğimiz bir sınıf
çalıştı.** Adım 3 ise onun *neden* çalıştığını gösteriyor — otomatik keşfin
ürettiği kaydı gözle görüyoruz.

Bir de negatif deney yap: `handle()`'ın parametre tipini geçici olarak
`Invitation $event` diye değiştir, `composer lint` çalıştır ve adım 2'yi
tekrarla. `Cache::has($key)` **`true` kalacak** ve hiçbir hata görmeyeceksin.
Sonra geri al. Bu, 6. bölümdeki 3. maddenin neden sessiz bir hata olduğunu
yaşayarak gösterir.

```powershell
composer check
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Cache invalidation** | Bayat cache girdisinin geçersiz kılınması |
| **Auto-discovery** | Laravel'in dinleyicileri yansımayla kendiliğinden bulması |
| **Reflection (yansıma)** | Bir sınıfın yapısını çalışma anında inceleme |
| **`ShouldQueue`** | Dinleyiciyi arka plan kuyruğuna atan arayüz |
| **Senkron** | İsteğin içinde, hemen çalışan |
| **Defense in depth** | Aynı hedefi koruyan birden çok bağımsız katman |
| **Olay yayılımı** | Bir olayın dinleyicileri sırayla dolaşması |

---

## 10. Sırada ne var?

**4.7 — `tests/Feature/PublicInvitationTest.php`.** Faz 4'ün bütün zinciri
artık kurulu; geriye onun **gerçekten** çalıştığını kanıtlamak kalıyor.

Bu fazda üç ayrı yerde "sessizce yanlış olabilir" dedik ve üçünün de tek
panzehiri test:

| Sessiz olabilecek şey | Testi ne doğrulayacak |
|---|---|
| Otomatik keşif kopabilir (§2) | Güncelleme sonrası cache anahtarı düşmeli |
| Kapalı modülün verisi sızabilir (4.2b) | `show_gift=false` iken gövdede `iban` **anahtarı olmamalı** |
| Yayınlanmamış davetiye sızabilir (4.1) | `saved` durumunda 404, ve var olmayan ULID ile **ayırt edilemez** olmalı |

Ayrıca **T14** gereği bazı testler yanıta değil **etkiye** bakacak: 404
dönmesi, davetiyenin sızmadığını kanıtlamaz — cache'in ve gövdenin ne
içerdiğine bakmak gerekir.
