# `tests/Feature/PublicInvitationTest.php`

> **Kod dosyası:** `tests/Feature/PublicInvitationTest.php`
> **Faz:** 4 — Public davetiye, dosya 4.7
> **Önce oku:** [`InvitationTest.md`](InvitationTest.md) — Faz 3'ün testleri

---

## 1. Bu testler neyi kanıtlıyor?

Faz 4 boyunca üç kez "bu sessizce yanlış olabilir" dedik. Sessiz hatanın tek
panzehiri testtir — çünkü tanımı gereği hiçbir hata mesajı üretmez.

| Sessiz olabilecek şey | Nerede söylendi | Hangi test yakalıyor |
|---|---|---|
| Yayınlanmamış davetiye sızabilir | 4.1 §2 | `saved_invitation_is_not_readable` |
| Kapalı modülün verisi gövdeye girebilir | 4.2b §3 | `gift_details_are_absent_when_the_module_is_off` |
| Otomatik keşif kopabilir | 4.6b §2 | `the_change_event_is_wired_to_the_cache_listener` |

25 test dört bölüme ayrılmış: **görünürlük**, **sözleşme**, **cache**, **ETag**.

---

## 2. 🔴 T14: yanıta değil, etkiye bak

Faz 3'te kurduğumuz kural:

> **T14** — Bir işlemin **yapılmadığını** test ediyorsan yanıtı değil **etkiyi**
> doğrula.

Bu fazda üç yerde uygulandı:

```php
$response = $this->getJson($this->url($inv))->assertNotFound();

// 404 gormek YETMEZ — baslik govdede hic gecmemeli.
$this->assertStringNotContainsString('HENUZ YAYINDA DEGIL', $this->body($response));
```

Neden yetmez? Çünkü `assertNotFound()` yalnızca durum kodunu okur. Şöyle bir
hata düşün: uç 404 döndürüyor ama gövdeye hata ayıklama amaçlı davetiyenin
başlığını da koyuyor. Durum kodu doğru, sızıntı var.

Aynı mantık IBAN testinde de var:

```php
$this->assertStringNotContainsString('TR3300061005', $this->body($response));
```

`assertJsonMissingPath('data.invitation.iban')` yalnızca **o yolu** kontrol
eder. IBAN başka bir anahtarın içinde (`debug`, `meta`, iç içe bir dizi)
sızıyorsa fark etmez. Ham gövdede metin araması kaçış yolu bırakmaz — bu
**T11**'in (ayırt edilemezlik ham gövdeyle doğrulanır) kardeşi.

> **Kalıp:** Bir şeyin *olmadığını* iddia ediyorsan, aradığın yerin dışında da
> olmadığını göster. `assertJsonMissingPath` bir **yol** kontrolüdür, bir
> **sızıntı** kontrolü değil.

---

## 3. Ayırt edilemezlik: iki yanıt, tek karşılaştırma

```php
$yayinlanmamis = $this->getJson($this->url($inv));
$yok = $this->getJson(route('public.invitations.show', self::YOK_OLAN_ULID));

$this->assertSame($yayinlanmamis->getStatusCode(), $yok->getStatusCode());
$this->assertSame($this->body($yayinlanmamis), $this->body($yok));
```

İki **farklı sebep** (kayıt yok / kayıt var ama yayında değil) tek bir yanıtta
buluşmalı. `assertJsonPath` ile ayrı ayrı bakmak yetmez: bir alan farklı olsa
görmezdik.

Bu testin `APP_DEBUG=false` (T4) ile birlikte anlamı var. Debug açık olsaydı
`error.debug.message` iki durumda farklı olur ve test doğru biçimde kırılırdı —
gerçek bir sızıntıyı gösterirdi.

### `YOK_OLAN_ULID` neden küçük harfli?

```php
private const YOK_OLAN_ULID = '01arz3ndektsv4rrffq69g5fav';
```

Faz 4'ün başında öğrendiğimiz şey: `HasUlids::newUniqueId()` `strtolower()`
uyguluyor. Büyük harfli yazsaydık `whereUlid` kısıtı yine eşleşirdi (framework
deseni iki durumu da kabul ediyor) ama test gerçek veriye benzemezdi.

---

## 4. Rota kısıtı veritabanına hiç gitmiyor mu?

```php
DB::enableQueryLog();
$this->getJson('/api/public/invitations/bu-bir-ulid-degil')->assertNotFound();
$this->assertSame([], DB::getQueryLog());
```

Burada 404'ün **nereden geldiğini** doğruluyoruz. İki farklı 404 mümkün:

| Nereden | Maliyet |
|---|---|
| Rota eşleşmedi (`whereUlid`) | Sıfır sorgu |
| Rota eşleşti, kayıt bulunamadı | Bir `SELECT` |

Test birincisini talep ediyor. Kısıtı silersen bu test kırılır — ve bu, 4.4'te
anlattığımız cache flooding savunmasının hâlâ yerinde olduğunun kanıtı.

> Bu aynı zamanda bir **mutasyon testi**: kodu bozunca hangi testin kırılacağını
> bilmek, testin gerçekten bir şey ölçtüğünü bilmektir. Faz 3'ün
> `InvitationTest` kılavuzundaki mutasyon tablosunun aynı fikri.

---

## 5. Sözleşme testleri: anahtar var mı, yok mu?

```php
$this->assertSame(['time', 'title', 'description'], array_keys($adim));
```

`assertArrayNotHasKey('id', ...)` de yazabilirdik. Anahtar **listesinin
tamamını** karşılaştırmak daha güçlü: yarın Resource'a yanlışlıkla bir alan
eklenirse (örneğin `sortOrder`) bu test kırılır, diğeri kırılmazdı.

**C1/C5** beyaz liste kuralının test karşılığı bu: *listede olmayan hiçbir şey
çıkmasın* demek, "şu tek şey çıkmasın" demekten farklıdır.

### Fazın en anlamlı testi

```php
public function the_owner_still_sees_what_the_guest_cannot(): void
```

Aynı davetiye, iki uç, iki sonuç:

| İstek | `iban` |
|---|---|
| `GET /api/public/invitations/{id}` (kimliksiz) | **yok** |
| `GET /api/invitations/{id}` (sahip token'ı) | **var** |

Bu test **C4**'ün varlık sebebini kanıtlıyor. Tek bir Resource'a maskeleme
koysaydık ikinci satır da boş olurdu — yani kullanıcı kendi girdiği IBAN'ı
editöründe göremezdi. Maskeleme, sessizce silmeye dönerdi.

`forgetAuthState()` çağrısına dikkat: **T13**. İlk istek kimliksiz yapılıyor ve
`RequestGuard` "kullanıcı yok" sonucunu önbelleğe alıyor. Sıfırlamasaydık
ikinci istek token'a hiç bakmaz, 401 dönerdi.

---

## 6. 🔴 Cache zinciri neden üç parçaya bölündü?

Testleri yazarken bir engelle karşılaştık ve bunu gizlemek yerine tasarıma
yansıttık.

### Sorun

`ClearInvitationCache`, 4.6'da `ShouldHandleEventsAfterCommit` arayüzünü aldı:
temizleme, veritabanı transaction'ı **commit edildikten sonra** çalışsın diye.

Ama `RefreshDatabase` (T1) her testi bir transaction'a sarar ve test sonunda
onu **rollback** eder. Kaynağa bakalım:

```php
// vendor/.../Database/DatabaseTransactionsManager.php:251
public function afterCommitCallbacksShouldBeExecuted($level)
{
    return $level === 0;
}
```

Testte seviye hiçbir zaman 0'a inmez (dış transaction hiç commit edilmez), yani
**geri çağrım hiçbir testte koşmaz.**

Naif bir test şöyle olurdu:

```php
$inv->update(['title' => 'Yeni']);
$this->assertFalse(Cache::has($key));      // ❌ testte HER ZAMAN kirilir
```

### Yanlış çözümler

| Çözüm | Neden hayır |
|---|---|
| `ShouldHandleEventsAfterCommit`'i kaldır | Test yeşil olur ama **gerçek yarış koşulu geri gelir**. Testi mutlu etmek için üretimi bozmak. |
| `RefreshDatabase` yerine `DatabaseMigrations` | Her test için tam migration; süre dakikalara çıkar |
| Testi hiç yazma | Faz 4'ün en sessiz hatasını korumasız bırakır |

### Seçilen çözüm: zinciri halkalara ayır

Zincir üç halkadan oluşuyor ve **her halkayı ayrı test ediyoruz**:

```
Model ──(1)──► InvitationChanged ──(2)──► ClearInvitationCache ──(3)──► Cache
```

| # | Halka | Test | Nasıl |
|---|---|---|---|
| 1 | Model olayı fırlatıyor mu | `updating/deleting/restoring/touching_..._dispatches_the_change_event` | `Event::fake()` + `assertDispatched` |
| 2 | Olay dinleyiciye bağlı mı | `the_change_event_is_wired_to_the_cache_listener` | `Event::getRawListeners()` |
| 3 | Dinleyici doğru anahtarı düşürüyor mu | `the_listener_drops_the_public_cache_entry` | Dinleyiciyi **doğrudan** çağır |
| + | Erteleme kasıtlı mı | `the_listener_waits_for_the_transaction_to_commit` | `assertInstanceOf` |

Üçü birlikte, uçtan uca testin kanıtlayacağı her şeyi kanıtlıyor — tek farkla:
halkaların **birleştiğini** değil, her birinin doğru olduğunu gösteriyorlar.

> **Dürüstlük payı:** Bu, uçtan uca bir testin yerini tam tutmaz. Örneğin
> Laravel bir gün olay gönderim sırasını değiştirse üç test de yeşil kalır ama
> zincir kopmuş olabilir. Bu boşluğu **elle doğrulama** kapatıyor:
> `FAZ-4-ELLE-DOGRULAMA.md` gerçek bir HTTP isteğiyle, gerçek bir commit'le
> güncelleme yapıp cache'in düştüğünü gözle doğruluyor.
>
> Bir testin **neyi kanıtlamadığını** bilmek, kanıtladığını bilmek kadar
> önemli. 4.5'te ETag için, 4.6'da yarış koşulu için aynısını yaptık.

### 🔴 6.1 `touch()` testi neden `travel()` gerektiriyor?

Bu test **Faz 4'te yazıldı, Faz 6'da ilk kez koştu ve kırmızı yandı.** Sebebi
bu kılavuzun en öğretici bölümü:

```php
$this->published()->touch();          // ← eski hâli
Event::assertDispatched(InvitationChanged::class);
// The expected [App\Events\InvitationChanged] event was not dispatched.
```

#### Zincirin kaynaktan takibi

`touch()`'tan olaya giden yol dört adım:

```php
// 1) Illuminate\Database\Eloquent\Concerns\HasTimestamps::touch()
$this->updateTimestamps();
return $this->save();

// 2) HasTimestamps::updateTimestamps()
$time = $this->freshTimestamp();
if (! is_null($updatedAtColumn) && ! $this->isDirty($updatedAtColumn)) {
    $this->setUpdatedAt($time);
}

// 3) Model::save()   ← 🔴 KRİTİK SATIR
$saved = $this->isDirty() ? $this->performUpdate($query) : true;

// 4) Model::performUpdate()
$this->fireModelEvent('updated', false);   // ← olay BURADA fırlıyor
```

Adım 3'te `isDirty()` `false` dönerse `performUpdate()` **hiç çağrılmaz** —
ve olay hiç fırlamaz. `save()` yine de `true` döner, yani **hiçbir hata
görünmez**.

#### Peki `updated_at` neden "değişmemiş" sayılıyor?

Çünkü zaman damgası veritabanına **saniye** hassasiyetinde yazılıyor:

```php
// Illuminate\Database\Grammar (Postgres)
public function getDateFormat()
{
    return 'Y-m-d H:i:s';      // ← mikrosaniye YOK
}

// HasAttributes::fromDateTime()
return $this->asDateTime($value)->format($this->getDateFormat());
```

`create()` ile `touch()` arasında 0.05 saniye geçiyor. İkisi de aynı saniyeye
düşünce, `original['updated_at']` ile yeni değer **birebir aynı string** olur:

```
'2026-08-29 14:07:11'  ===  '2026-08-29 14:07:11'    → isDirty() = false
```

🔴 Bu test **flaky** idi: saniye sınırına denk gelirse geçer, gelmezse geçmez.
**T12** (*ölçümü kararsız olan şey teste konmaz*) tam olarak bunu yasaklıyordu —
ama testi yazarken bu bağımlılık görünmüyordu, çünkü zaman **örtük** bir
girdiydi.

#### Kusur testte mi üretimde mi?

**Testte.** Gerçek akışta kurgu oluşmaz:

| | Testte | Üretimde (`UpdateInvitationAction`) |
|---|---|---|
| Model nereden geliyor | Aynı test metodunda `create()` edildi | Route model binding ile **veritabanından** okundu |
| `updated_at`'in yaşı | ~0.05 saniye | Kullanıcının son kaydından beri **saniyeler/dakikalar** |
| `isDirty()` | `false` | `true` |

**Ders 33**: *bir aracın kırılması, kırılan yerin hatalı olduğu anlamına
gelmez.* Faz 3'te `logout revokes only the current token` testinde aynı şey
olmuştu — kusur `RevokeTokenAction`'da değil, testin guard önbelleğindeydi.

**Ders 40 / T15**: *test edilebilirlik ile doğruluk çatışırsa testi uyarla,
üretimi değil.* Zaman damgasına mikrosaniye eklemek (`timestamp('updated_at', 6)`
+ `$dateFormat = 'Y-m-d H:i:s.u'`) testi yeşile döndürürdü — ama şemayı bir
testin rahatlığı için değiştirmek olurdu.

#### Düzeltme

```php
$inv = $this->published();

$this->travel(1)->second();          // ← zamanı DETERMİNİSTİK olarak ilerlet

Event::fake([InvitationChanged::class]);
$inv->touch();
```

`travel()` `Carbon::setTestNow()` çağırır ve Carbon 3'te bu
`FactoryImmutable::getDefaultInstance()`'a yazar — yani `CarbonImmutable`
(K23) de aynı test-now'ı okur. Temizlik gerekmiyor: Laravel `tearDown()`
içinde `Carbon::setTestNow()` **ve** `CarbonImmutable::setTestNow()` çağırıyor
(`InteractsWithTestCaseLifecycle.php:163,167`), yani sonraki testlere sızmaz.

#### ⚠️ Bu düzeltmenin KAPATMADIĞI şey (B6)

Testi düzeltmek, altındaki gerçekliği değiştirmiyor: **`touch()` tabanlı cache
geçersizleştirme saniye altı çözünürlükte kör.** Aynı saniye içinde iki kayıt
gelirse ikincisi `updated_at`'i değiştirmez → `updated` olayı fırlamaz →
**cache düşmez**.

Üretimde bunun olması için autosave'in aynı saniyede iki kez, üstelik davetiye
alanlarına hiç dokunmadan (yalnızca program değiştirerek) kaydetmesi gerekir.
Debounce ve ağ gecikmesi bunu pratikte çok zorlaştırıyor — ama **imkânsız
kılmıyor**, ve TTL (O3: *tazelik garantisi değil, üst sınır*) o kaçırılan turu
en fazla 6 saat sonra kapatır.

> 📋 **Açık madde.** Gerçek çözüm `touch()`'a güvenmek yerine program
> senkronizasyonundan sonra `InvitationChanged`'i **açıkça** fırlatmaktır — ama
> bu K48'in (*olay modelden yapısal fırlar*) yeniden tartışılması demek.
> `FAZ-6.md` §9'a devrediliyor.

---

### `Event::fake()` model olaylarını da yakalıyor mu?

Evet, ve bu tesadüf değil:

```php
// vendor/.../Support/Facades/Event.php:60
return tap(new EventFake($actualDispatcher, $eventsToFake), function ($fake) {
    static::swap($fake);
    Model::setEventDispatcher($fake);      // ← modelin dağıtıcısı da değişiyor
    Cache::refreshEventDispatcher();
});
```

`Event::fake([InvitationChanged::class])` yalnızca **o olayı** sahteler; diğer
olaylar gerçek dağıtıcıya gider. Yani `Invitation::factory()->create()` hâlâ
normal çalışıyor.

### `assertNotDispatched` — yokluğun testi

```php
public function creating_an_invitation_does_not_dispatch_the_change_event(): void
```

**T6**: bir davranışın hem varlığı hem yokluğu test edilir. `created`'ı haritaya
bilerek koymadık (yeni kaydın cache girdisi olamaz). Bu test o kararı kilitler:
biri "tutarlılık olsun" diye `created` eklerse test kırılır ve gerekçeyi okumak
zorunda kalır.

---

## 7. ETag testleri

```php
$etag = $this->etag($this->getJson($url));
$response = $this->getJson($url, ['If-None-Match' => $etag]);

$response->assertStatus(304);
$this->assertSame('', $this->body($response));
```

İki ayrı iddia: **durum kodu** 304 ve **gövde boş**. İkisi ayrı çünkü ikisi ayrı
hata olabilir — 304 dönüp gövdeyi de göndermek RFC 7232 ihlalidir ve bant
genişliği kazancını sıfırlar.

### Neden `*` testi var?

```php
$this->getJson($this->url($inv), ['If-None-Match' => '*'])->assertStatus(304);
```

Bu, 4.5'te **elle yazmadığımız** kuralın testi. `*` "elimde bu kaynağın
herhangi bir sürümü var" demektir ve her ETag ile eşleşir. Kendi
karşılaştırmamızı yazsaydık büyük ihtimalle `===` yazar ve bunu kaçırırdık.

Test aynı zamanda **R6**'nın kanıtı: framework'ün çözümünü kullandığımız için
bu kural bedavaya geldi.

### `the_etag_changes_after_the_invitation_changes`

Bu test iki katmanı birbirine bağlıyor: veri değişti → cache düştü → gövde
değişti → ETag değişti → eski ETag artık 304 üretmiyor.

Ortadaki `Cache::forget()` çağrısı **testin bir parçası değil, §6'daki
kısıtın telafisi**. Yorumda açıkça yazılı; gerçek hayatta o satırı dinleyici
yapıyor.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Sızıntıyı yalnızca `assertJsonMissingPath` ile aramak | Başka anahtarda sızarsa görmezsin | Ham gövdede de ara (T14) |
| 2 | Ayırt edilemezliği `assertJsonPath` ile ölçmek | Bakmadığın alan farklı olabilir | Ham gövde karşılaştır (T11) |
| 3 | İki kimlikli istek arasında `forgetAuthState()` unutmak | Guard önbelleği; test boş yeşil (T13) | Sıfırla |
| 4 | Cache temizlemeyi uçtan uca test etmeye çalışmak | Transaction yüzünden her zaman kırmızı | Zinciri halkala (§6) |
| 5 | Testi yeşile döndürmek için `ShouldHandleEventsAfterCommit`'i kaldırmak | Üretimde yarış koşulu geri gelir | Testi uyarla, üretimi değil |
| 6 | 304'te yalnızca durum kodunu doğrulamak | Gövde sızabilir | Gövdenin boşluğunu da doğrula |
| 7 | `assertArrayNotHasKey` ile tek alan bakmak | Yeni eklenen alan fark edilmez | Anahtar listesini karşılaştır (§5) |
| 8 | ETag'i `$response->headers` ile okumak | `TestResponse::__get` sihri; statik analiz göremez | `$response->baseResponse->headers` |

---

## 9. Mutasyon tablosu

*Hiçbiri kırılmıyorsa test değil, süs yazmışsındır.*

| Kodu şöyle boz | Kırılması gereken test |
|---|---|
| `ResolvePublicInvitationAction`'dan `where('status', ...)` sil | `saved_invitation_is_not_readable` |
| `PublicInvitationResource`'ta `if ($this->show_gift)` sarmalını kaldır | `gift_details_are_absent_when_the_module_is_off` |
| `PublicTimelineEventResource`'a `'id' => ...` ekle | `timeline_events_do_not_expose_their_ids` |
| Rotadan `whereUlid('id')` sil | `a_malformed_id_never_reaches_the_database` |
| Controller'dan `Cache::remember`'ı kaldır | `the_second_request_does_not_touch_the_database` |
| `$dispatchesEvents`'ten `'updated'` sil | `updating_an_invitation_dispatches_the_change_event` |
| `ClearInvitationCache::handle()` tipini `Invitation` yap | `the_change_event_is_wired_to_the_cache_listener` |
| `publicCacheKey()`'de tireyi alt çizgi yap | `the_listener_drops_the_public_cache_entry` |
| `SetEtag`'ten `isNotModified()` satırını sil | `a_matching_etag_returns_304_without_a_body`, `a_wildcard_...` |
| `SetEtag`'i rota grubundan çıkar | `the_response_carries_an_etag` |
| `ShouldHandleEventsAfterCommit`'i kaldır | `the_listener_waits_for_the_transaction_to_commit` |

---

## 10. Kendin dene

```powershell
php artisan test --filter=PublicInvitationTest
```

Sonra tabloyu gerçekten uygula: yukarıdaki mutasyonlardan **birini** yap, testi
koştur, kırılan testi gör, geri al. En öğreticisi altıncı satır — `$dispatchesEvents`
haritasından `'updated'`'ı silmek. Çünkü uygulama hâlâ **çalışıyor** görünür:
davetiye güncellenir, sahibi kaydedildiğini görür. Yalnızca misafirler eski
hâlini görmeye devam eder. Hiçbir hata mesajı yoktur.

```powershell
composer check
```

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Mutasyon testi** | Kodu bilerek bozup hangi testin kırıldığını görme |
| **`Event::fake()`** | Olay dağıtıcısını sahtesiyle değiştirip fırlatılanları kaydetme |
| **Ham gövde** | Yanıtın ayrıştırılmamış metin hâli |
| **Sessiz hata** | Hiçbir hata mesajı üretmeden yanlış sonuç veren kusur |
| **Uçtan uca test** | Zincirin tamamını tek seferde çalıştıran test |
| **`RefreshDatabase`** | Her testi transaction'a sarıp sonunda geri alan trait |

---

## 12. Sırada ne var?

**4.8 — frontend: `InvitePage.tsx` gerçek veriye bağlanır.** Backend tarafı
bitti; `/invite/:id` sayfası hâlâ yerel store'u çiziyor (`TODO(backend)` yorumu
dosyada duruyor). Orada iki iş var:

1. Yeni public ucu çağıran bir servis + hidrasyon
2. `types.ts`'te kapalı modül alanlarının **isteğe bağlı** hâle getirilmesi —
   çünkü artık gövdede olmayabilirler (4.2b §3)

Ardından faz kapanışı: `FAZ-4.md`, `FAZ-4-ELLE-DOGRULAMA.md` ve
`PHP-LARAVEL-SETUP.md` güncellemesi.
