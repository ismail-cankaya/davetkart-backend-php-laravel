# FAZ 4 — Public Davetiye (Okuma Yolu)

> **Tarih:** 27 Ağustos 2026
> **Durum:** Tamamlandı (backend 8 dosya + frontend 2 dosya)
> **Önceki:** [`FAZ-3.md`](FAZ-3.md) · **Sonraki:** Faz 5 — RSVP
> **Bu dosya:** fazın kronolojik kaydı, alınan kararlar, kurulan kurallar ve devir

---

## 1. Faz 4 neydi?

**Amaç:** Aynı verinin **ikinci okuyucusu** ve okuma-ağırlıklı yük.

Faz 2 *"sen kimsin?"*, Faz 3 *"buna dokunabilir misin?"* sorusunu çözmüştü.
Faz 4 üçüncü bir soruyu çözdü: *"kimliği bilinmeyen biri bunun **neyini**
görebilir, ve bunu **kaç kere hesaplarız**?"*

**Bitiş ölçütü:** `/invite/{id}` sayfası gerçek backend'den yükleniyor; ikinci
istek `304 Not Modified` dönüyor; yayınlanmamış davetiye sızmıyor.

**Öğrenme hedefleri:**

| Hedef | Nerede karşılandı |
|---|---|
| Okuma-ağırlıklı yük ve iki katmanlı optimizasyon | 4.3, 4.5 |
| Cache invalidation stratejileri | 4.6 |
| ETag ve koşullu istek (RFC 7232) | 4.5 |
| `/api/public/` fail-safe grubu (K12) | 4.4 |
| Aynı verinin iki farklı Resource ile sunulması (C4) | 4.2 |
| Event/Listener ile gevşek bağ | 4.6 |

---

## 2. 🔴 Adım adım ne yaptık?

### 2.1 Giriş: üç karar kilitlendi

Kod yazmadan önce üç soru soruldu ve karara bağlandı: cache nerede yaşayacak
(**K45**), ETag nerede üretilecek (**K46**), yayınlanmış davetiye testler için
nasıl üretilecek (**K47**).

### 2.2 4.1 — `ResolvePublicInvitationAction`

Fazın güvenlik dosyası. Görünürlük kuralı bir `if` değil **sorgunun kapsamı**
oldu (P3'ün aynı ailesi): yayınlanmamış davetiye veritabanından hiç çıkmıyor.
`firstOrFail()` sayesinde 404 sözleşmesi bedavaya geldi — `ApiExceptionRenderer`
`ModelNotFoundException`'ı zaten eşliyordu (H10, H11).

### 2.3 🔴 Ara: `composer check` üç kez kırıldı — üçü de Faz 3'ten kalma

Fazın en öğretici bölümü yine kod yazmak değil, kapıyı yeşile döndürmekti. Ve
bu kez ortaya çıkan şey daha ciddiydi: **Faz 3 yeşil kapanmamıştı.**

**Kırılma 1 — PHPStan, üç hata.** `InvitationResource` ve
`InvitationPayloadResource`'ta `->format()` ve `->value` çağrıları "string
üzerinde metot çağrılamaz" diyordu. Kaynağa bakıldı
(`vendor/larastan/.../ModelCastHelper.php:270`): Larastan'ın varsayılanı
`parseModelCastsMethod: false` ve o hâlde `casts()` **metodunun** yalnızca
*bildirilen dönüş tipine* bakıyor. Bizimki `array<string, string>`, yani sabit
dizi değil — dolayısıyla **tüm cast'ler sessizce yok sayılıyordu.** Düzeltme
tek satır: `parseModelCastsMethod: true`.

Bu hatalar Faz 3'ten beri vardı; PHPStan'ın sonuç önbelleği yüzünden
görünmüyorlardı. Yeni bir dosya eklenince tam analiz koştu ve üçü birden çıktı.

**Kırılma 2 — 10 test kırmızı, sebebi tek satır.** `routes/api.php`'de Faz 3'te
elle yazılmış rota kısıtı:

```php
->where(['invitation' => '[0-9A-HJKMNP-TV-Z]{26}'])   // yalnızca BÜYÜK harf
```

Ama `HasUlids::newUniqueId()` `strtolower()` uyguluyor. Yani `{invitation}`
parametreli **hiçbir rota hiçbir istekle eşleşmemişti**. Düzeltme:
`->whereUlid('invitation')` (**R6**).

🔴 Asıl bedel test sayısı değildi: `show`/`update`/`destroy` **404 dönüyordu**
ve Faz 3'ün IDOR testleri tam olarak 404 bekliyordu. Yani
`app/Policies/InvitationPolicy.php` dosyası silinseydi de o üç test geçerdi.
**Fazın güvenlik dosyası bir kez bile çalışmamıştı** (ders **34**).

**Kırılma 3 — `store` uçları 500.** `CreateInvitationAction` `create()`
çağırıyordu; `status` `#[Fillable]` listesinde yok (doğru karar) ve varsayılanı
yalnızca veritabanı biliyordu. Eloquent insert sonrası DB varsayılanlarını geri
okumaz → bellekteki modelde `status` `null` → `$this->status->value` patlıyordu.
Katı kip bunu yakalayamadı çünkü `preventAccessingMissingAttributes` yeni
oluşturulmuş modellerde **bilerek** devre dışı (`HasAttributes.php:518`).
Düzeltme: `make()` + açık atama (**E7**).

Bu noktada `composer check` gerçekten yeşil oldu ve Faz 3 kapandı.

### 2.4 4.2 — Resource ailesi (plandan sapma #3)

Plan tek bir `PublicInvitationResource` diyordu; ikiye bölündü. Sebep: misafirin
program adımları **artan bigint kimlik taşımamalı** — K40'ın ULID ile kapattığı
sayım sızıntısı aksi hâlde arka kapıdan geri gelirdi (C5).

Ana Resource'ta fazın sözleşme kararı verildi: **kapalı modülün verisi gövdeye
hiç girmez** (**C6**). Boş string değil, anahtar olarak da yok. Frontend'in
kırılmayacağı okunarak doğrulandı: `InvitationComposition.tsx` kapalı modülün
bileşenini mount etmiyor.

### 2.5 4.3 — Controller ve cache

`string $id` alındı, model değil: route-model binding `SubstituteBindings`'te,
yani cache'ten **önce** çalışıp her istekte bir `SELECT` açardı ve
optimizasyonu ölçümde görünmeden etkisizleştirirdi.

Burada 4.2'ye geriye dönük bir düzeltme gerekti: `JsonResource::resolve()`
iç içe koleksiyonu **nesne olarak** bırakıyor; cache'e Eloquent modeli yazılmasın
diye koleksiyon kendi içinde `->resolve($request)` ile çözüldü. *Bir bileşenin
sözleşmesi, onu kullanan ilk gerçek çağrı yeri yazılana kadar tam bilinmez.*

### 2.6 4.4 — `/api/public/` grubu

K12'nin uygulaması. Önek bir *fail-safe*: unutmanın sonucu "herkese açık" değil
"çalışmıyor" olur. `whereUlid('id')` burada da kullanıldı — ikinci kazancı,
biçimsiz bir kimliğin **cache anahtarı üretememesi** (cache flooding savunması).

### 2.7 4.5 — `SetEtag` middleware

RFC 7232'nin beş kuralı (çoklu ETag, zayıf karşılaştırma, `*`, öncelik sırası,
304'te yasak başlıklar) elle yazılmadı; `Response::isNotModified()` kullanıldı
(R6). Hash olarak `xxh128` seçildi: burada sorulan soru "değişti mi?", "biri
kurcaladı mı?" değil — K32'de Argon2id'yi *bilerek yavaş* seçmiştik, burada tam
tersi gerekiyordu.

Katmanın **kazandırmadığı** da yazıldı: 304 dönerken bile gövde bir kez
üretiliyor (**B6**).

### 2.8 4.6 — Olay/dinleyici (plandan sapma #1)

Plandaki `InvitationPublished`'ı bugün fırlatacak kod yoktu (yayın akışı Faz
7'de) — yazılsaydı üç faz boyunca ölü kod olurdu (ders 26). Tartışıldı ve
**`InvitationChanged`** olarak karara bağlandı (**K48**); modelden yapısal
olarak fırlıyor (`$dispatchesEvents`).

Yazarken bir yarış koşulu görüldü: model olayları transaction'ın **içinde**
fırlıyor, cache commit'ten önce temizlenirse arada gelen bir okuma cache'i eski
veriyle doldurabiliyor. `ShouldHandleEventsAfterCommit` eklendi (**O5**).

### 2.9 4.7 — Testler (plandan sapma #4)

25 test. Ve burada bir engelle karşılaşıldı: `RefreshDatabase` her testi bir
transaction'a sarıp **rollback** ediyor, yani after-commit dinleyicisi hiçbir
testte koşmuyor (`DatabaseTransactionsManager.php:251`).

Testi yeşile döndürmek için `ShouldHandleEventsAfterCommit`'i kaldırmak
reddedildi — *testi uyarla, üretimi değil* (ders **40**). Zincir üç halkaya
bölündü ve her halka ayrı doğrulandı (**T15**); kapatılamayan boşluk elle
doğrulama betiğine yazıldı.

### 2.10 4.8 — Frontend

`InvitePage.tsx`'teki `TODO(backend)` kapandı. Yeni servis, sözleşmeyi `Omit` +
`Pick` ile **tip sistemine** yazıyor: hangi alanın her zaman geleceği, hangisinin
modüle bağlı olduğu derleme zamanında görünür.

Eksik alanlar sınırda tamamlanıyor. Bu, Faz 3'te `whenLoaded` için reddettiğimiz
davranış **değil**: orada eksiklik "bilinmiyor" demekti ve doldurulan değer
çiziliyordu; burada "sana ait değil" demek ve doldurulan değer hiçbir zaman
çizilmiyor.

---

## 3. Yazılan dosyalar

### 3.1 Backend (8 dosya + kılavuzları)

| # | Dosya | Ne yapar |
|---|---|---|
| 4.1 | `app/Actions/Invitation/ResolvePublicInvitationAction.php` | slug → yalnızca yayınlanmış davetiye |
| 4.2a | `app/Http/Resources/PublicTimelineEventResource.php` | Misafir sürümü — `id` yok |
| 4.2b | `app/Http/Resources/PublicInvitationResource.php` | 🔴 Kapalı modülün verisi gönderilmez |
| 4.3 | `app/Http/Controllers/Api/V1/PublicInvitationController.php` | Auth'suz, cache'li |
| 4.4 | `routes/api.php` → `/api/public/` grubu | K12 fail-safe |
| 4.5 | `app/Http/Middleware/SetEtag.php` | ETag + 304 |
| 4.6a | `app/Events/InvitationChanged.php` | Alan olayı |
| 4.6b | `app/Listeners/ClearInvitationCache.php` | Cache invalidation |
| 4.7 | `tests/Feature/PublicInvitationTest.php` | **25 test** |

**Faz 4'te düzenlenenler:**

| Dosya | Değişiklik |
|---|---|
| `phpstan.neon` | `parseModelCastsMethod: true` — cast'ler ilk kez okunuyor |
| `routes/api.php` | Elle yazılmış ULID regex'i → `whereUlid()` (**R6**) |
| `app/Actions/Invitation/CreateInvitationAction.php` | `make()` + açık `status` ataması (**E7**) |
| `app/Models/Invitation.php` | `publicCacheKey()` + `$dispatchesEvents` |
| `docs/rehber/phpstan.md` · `routes/api.md` · `CreateInvitationAction.md` · `Invitation.md` | Gerekçeler işlendi |

### 3.2 Frontend (2 dosya + kılavuzları)

| # | Dosya | Değişiklik |
|---|---|---|
| 4.8a | `src/services/publicInvitation.ts` | **yeni** — public sözleşme tipte |
| 4.8b | `src/pages/InvitePage.tsx` | Gerçek veriye bağlandı, 4 durumlu yükleme |

---

## 4. Alınan kararlar

| # | Karar | Gerekçe |
|---|---|---|
| **K45** | Cache **Action'ın dışında**, Resource çıktısı olan **diziyi** saklar | Action saf ve cache'siz test edilebilir kalır; cache'te bayat Eloquent nesnesi canlanmaz; ETag aynı diziden hesaplanır |
| **K46** | ETag **ayrı middleware**, controller içi değil | Faz 5'in LCV polling ucu aynı katmanı yeniden kullanacak (C3); controller HTTP başlık mantığı taşımaz |
| **K47** | Faz 4 **salt okuma** kalır; yayın ucu Faz 7'de | `PublishInvitationAction` şimdi yazılırsa paywall'sız bir "bedava yayın" yolu açılır ve K42/K43 bozulur |
| **K48** | Olay **`InvitationChanged`**, modelden **yapısal** fırlar | `InvitationPublished`'ı bugün fırlatan kod yok (ölü kod); yazma yolu sayısı artıyor ve unutmanın bedeli sessiz |

---

## 5. Kurulan kurallar

### 5.1 Önbellek — yeni seri **O**

| # | Kural | Gerekçe |
|---|---|---|
| **O1** | Cache'e konan şey, cevabı üretmek için gereken **son adımın çıktısıdır** | Ara adımı cache'lemek, cache hit'te de iş yapmak demektir; model cache'lemek şema değişince bayat nesne canlandırır |
| **O2** | Cache anahtarı **tek bir yerde** üretilir | `forget()` yanlış anahtarla **sessizce** hiçbir şey yapmaz (C3'ün cache'teki hâli) |
| **O3** | TTL bir tazelik garantisi değil, **üst sınırdır** | Tazeliği olay sağlar; TTL olayın kaçırıldığı durumlar için emniyet kemeridir |
| **O4** | Cache geçersizleştirme **kuyruğa alınmaz** | Kuyruk çalışmıyorsa hiç temizlenmez ve sistem hata vermez; kuyruk kararı "yavaş mı" ile değil "gecikirse ne olur" ile verilir |
| **O5** | Temizleme **transaction commit'inden sonra** yapılır | Commit'ten önce temizlenirse arada gelen okuma cache'i eski veriyle doldurur |
| **O6** | Cache anahtarına giden değer **rota katmanında** biçim denetiminden geçer | Aksi hâlde çöp girdilerle cache şişirilebilir |

### 5.2 Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **R6** | Framework'ün hazır rota kısıtı varsa desen **elle yazılmaz** | Elle yazılan desen sessizce yanlış olabilir; framework'ünki, değeri üreten kodla aynı depoda durur |
| **E7** | Sunucunun sahip olduğu alanın değerini **sunucu kodu** söyler | ORM veritabanı varsayılanını geri okumaz; bellekteki nesne yanlış kalır |
| **C6** | Kapalı modülün verisi gövdeye **hiç girmez** — boş değerle maskelenmez | Ekranda görünmemek ile gönderilmemek farklıdır; boş bir alan hâlâ bir alandır ve bir gün dolar |
| **T15** | Uçtan uca doğrulanamayan zincir **halkalara ayrılır**, her halka ayrı test edilir | Test edilebilirlik uğruna üretim davranışı değiştirilmez |
| **B6** | Bir savunmanın **neyi kapatmadığı** da yazılır | Aksi hâlde altı ay sonra biri "ETag var, CPU sorunu olamaz" der |

> **Kuralların tam listesi:** FAZ-0 §4 (31) · FAZ-1 §4 (19) · FAZ-2 §4 (20) ·
> FAZ-3 §5 (15) · **FAZ-4 §5 (11)**

---

## 6. Öğrenilen dersler

**34. 🔴 "Beklediğim yanıtı aldım" ile "beklediğim sebeple aldım" farklı
şeylerdir.** Üç IDOR testi 404 bekliyordu ve 404 alıyordu — ama Policy'den
değil, eşleşmeyen rotadan. `InvitationPolicy.php` silinseydi de geçerlerdi.
Faz 2'nin 24. dersinin (`actingAs` guard'ı atlar) rota katmanındaki ikizi.

**35. Bir aracı kurmak ile aracın işini yapması ayrı şeylerdir.**
`checkModelProperties: true` Faz 0'dan beri açıktı ve açık *görünüyordu*; ama
`parseModelCastsMethod` kapalı olduğu için model cast'lerinin tamamı hiç
değerlendirilmiyordu. Bir ayarın çalıştığı, ancak bir şeyi **yakaladığını
gördüğünde** bilinir.

**36. Elle yazılan desen sessizce yanlış olabilir.** ULID regex'i kâğıt üzerinde
kusursuzdu — Crockford alfabesi, 26 karakter. Yalnızca küçük/büyük harf
yanlıştı ve hiçbir araç bunu söylemedi. Framework'ün kısıtı, kimliği üreten
kodla aynı depoda durduğu için onunla birlikte değişir.

**37. ORM veritabanı varsayılanını geri okumaz.** `create()` sonrası bellekteki
model, DB'nin doldurduğu kolonu taşımaz. Ve katı kipin eksik-alan koruması yeni
oluşturulmuş modellerde bilerek devre dışıdır — güvenlik ağının deliği tam
olarak hatanın durduğu yerdeydi.

**38. Bir optimizasyon altındaki hatayı düzeltmez, hızlandırır.** Bayat cache
bayat bir ETag üretiyor, tarayıcı onu doğruluyor ve 304 alıyor: yanlış veri
artık daha verimli servis ediliyor.

**39. Bir savunmanın neyi kapatmadığını yazmak, kapattığını yazmak kadar
önemlidir.** ETag ağı kurtarır, CPU'yu değil. `ShouldHandleEventsAfterCommit`
yarışı daraltır, kapatmaz. Yazılmazsa ikisi de abartılı bir güven üretir.

**40. Test edilebilirlik ile doğruluk çatışırsa testi uyarla, üretimi değil.**
After-commit dinleyicisi `RefreshDatabase` altında koşmuyor. Arayüzü kaldırmak
testi yeşile döndürürdü ve gerçek bir yarış koşulunu geri getirirdi.

**41. Doğru katmanda alınmış bir karar, umulmadık bir yerde ikinci kez işe
yarar.** `localKey` (K44 için yazılmıştı) misafirin `id`'siz adımlarında;
`$invitation->touch()` (frontend'in "son kaydetme" göstergesi için yazılmıştı)
program değişiminde cache'in düşmesinde. Tersi de doğrudur: yanlış katmandaki
karar umulmadık bir yerde ikinci kez bozar.

---

## 7. Plandan sapmalar

Dördü de tartışılarak yapıldı (çalışma kuralı 5).

| Plandaki | Yapılan | Gerekçe |
|---|---|---|
| Cache `ResolvePublicInvitationAction` içinde | Cache controller'da, dizi üzerinde | Action saf kalsın; cache'te model serileşmesin (K45) |
| `Events/InvitationPublished` | `Events/InvitationChanged` | Bugün fırlatacak kod yok → ölü kod (K48) |
| Tek `PublicInvitationResource` | + `PublicTimelineEventResource` | Misafire artan bigint kimlik gitmemeli (C5) |
| Cache testleri uçtan uca | Zincir üç halkaya bölündü | `RefreshDatabase` after-commit'i engelliyor (T15) |

---

## 8. Faz 3'te bulunan ve düzeltilen kusurlar

Faz 4, Faz 3'ün de **yeşil kapanmadığını** ortaya çıkardı. Bu, Faz 3'ün Faz 2
için yaptığının aynısı.

| Kusur | Ne zamandan beri | Etkisi | Düzeltme |
|---|---|---|---|
| Rota ULID kısıtı yalnızca büyük harf | Faz 3 | `show`/`update`/`destroy` hiç çalışmadı; **3 IDOR testi boş yeşildi** | `whereUlid()` (R6) |
| `CreateInvitationAction` `status` yazmıyordu | Faz 3 | `POST /api/invitations` → **500** | `make()` + atama (E7) |
| Larastan `casts()` metodunu hiç okumuyordu | Faz 0'dan beri | 3 PHPStan hatası gizliydi; tüm cast'ler yok sayılıyordu | `parseModelCastsMethod: true` |

> Üç fazda üçüncü kez aynı desen: **bir sonraki faz, bir öncekinin gerçekten
> yeşil olup olmadığını ortaya çıkarıyor.** Ortak sebep, kapının bir halkasının
> kırıldığında sonrakilerin hiç koşmaması — `composer check` fail-fast
> tasarlandı, bu doğru; ama "yeşil gördüm" demek için **zincirin tamamının**
> koşmuş olması gerekiyor.

---

## 9. Faz 5'e devir

### 9.1 Hazır olanlar

- `/api/public/` grubu — LCV gönderim ucu buraya eklenecek (K12)
- `SetEtag` middleware — sahibin LCV polling ucunda yeniden kullanılacak (K46)
- `InvitationChanged` + `ClearInvitationCache` — ikinci bir dinleyici eklemek
  artık `UpdateInvitationAction`'a dokunmadan mümkün
- `Invitation::publicCacheKey()` — LCV sayacı için ikinci bir anahtar deseni
  gerekirse örnek burada

### 9.2 Faz 5'in işleri

`docs/07-GELISTIRME-YOL-HARITASI.md` §Faz 5'te (10 dosya). Faz 4'ten doğan
ekler:

| İş | Not |
|---|---|
| Genel API hız sınırı (`throttleApi`) | 🔴 Faz 4'ün açık borcu: 404'ler cache'lenmiyor, rastgele ULID yağdıran biri her istekte bir sorgu açtırabiliyor |
| `HasErrorCode` arayüzü | Üçüncü exception (`RsvpQuotaExceededException`) geliyor |
| PHPStan level 6 → 8 | K22 |

### 9.3 Faz 5'e taşınan açık sorular

| Konu | Not |
|---|---|
| 🔴 `event_at` saat dilimi | Duvar saati saklanıyor; geri sayım başka saat diliminden bakan misafirde kayıyor. Doğru çözüm `invitations.timezone` kolonu + iki alan. **Faz 4'te bilinçli olarak ertelendi** |
| `RsvpModal` yerel çalışıyor | Public LCV ucu Faz 5'te bağlanacak |
| `galleryImages` her zaman `[]` | Faz 6 |
| Frontend deposunda CRLF/LF karmaşası | `git add -A` 473 dosyayı satır sonu farkıyla sürüklüyor; `.gitattributes` kararı İsmail'e ait |

---

## 10. Hâlâ açık kalanlar

| Konu | Ne zaman |
|---|---|
| `app/Actions/Invitation/PublishInvitationAction.php` boş iskelet duruyor | **Faz 7** — ya doldurulacak ya silinecek |
| `routes/web.php` closure'ı (R1/R4 ihlali) | Faz 9 — `route:cache` orada kırılır |
| K20'nin frontend tarafı: `toDisplayError()` | `Notlar/03` §3.2-3.3 |
| `restoreSession()` → açılışta `GET /auth/me` | `Notlar/03` §10.2 |
| `prepareForValidation`'daki ölü `trim` | Faz 5 |

---

## 11. Bir cümlelik özet

Faz 4'te misafirin okuma yolunu, iki katmanlı önbelleği ve koşullu isteği
yazdık; ama asıl öğrendiğimiz şey **bir aracın sessiz kalmasının, hatanın
olmadığı anlamına gelmediği** — üç fazdır kapanmış sayılan işlerin ancak bir
sonraki faz onlara gerçekten dokunduğunda sınandığıydı.
