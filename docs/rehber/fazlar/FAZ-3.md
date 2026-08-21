# FAZ 3 — Invitation Özellik Dilimi

> **Tarih:** 19 Ağustos 2026
> **Durum:** Kod tarafı tamamlandı (backend 12 dosya + frontend 8 dosya)
> **Önceki:** [`FAZ-2.md`](FAZ-2.md) · **Sonraki:** Faz 4 — Public davetiye
> **Bu dosya:** fazın kronolojik kaydı, alınan kararlar, kurulan kurallar ve devir

---

## 1. Faz 3 neydi?

**Amaç:** Sahiplik, yetkilendirme ve iç içe koleksiyon yönetimi.

Faz 2'de *"sen kimsin?"* sorusunu çözmüştük (kimlik doğrulama). Faz 3 farklı bir
soruyu çözdü: *"buna dokunabilir misin?"* (yetkilendirme).

**Bitiş ölçütü:** Dashboard'da davetiye listesi gerçek veritabanından geliyor;
editörde autosave çalışıyor.

**Öğrenme hedefleri:**

| Hedef | Nerede karşılandı |
|---|---|
| Migration ve indeks stratejisi | 3.2, 3.3 |
| Eloquent ilişkileri | 3.4, 3.5 |
| Policy ile IDOR kapatma | 3.7, 3.12 |
| İç içe koleksiyon senkronizasyonu | 3.10 |
| N+1 önleme | 3.9, 3.11 |
| Sözleşme değişikliğinin uçtan uca yayılması | F1-F8 |

---

## 2. 🔴 Adım adım ne yaptık?

### 2.1 Giriş: sözleşmenin yeniden okunması

Faz 3'e başlamadan önce frontend'in gerçekten ne beklediğini okuduk — ve planla
**üç çelişki** bulduk:

| Bulgu | Nerede görüldü |
|---|---|
| Frontend "hesap başına tek davetiye" varsayıyor | `services/invitations.ts`, `useDashboardData.ts` |
| `phoneBackground` hiçbir yerde okunmuyor, hep `imageTheme` ile aynı | Tüm `src/` araması |
| Frontend `/invite/{record.id}` kullanıyor — id = paylaşılan link | `DashboardPage.tsx:130` |

Bunlar dört kararı doğurdu ve **kod yazmadan önce** kilitlendi.

### 2.2 Kararların kilitlenmesi

Sırasıyla soruldu, tartışıldı, karara bağlandı: **K37** (REST koleksiyonu),
**K38** (`saved | published`), **K39** (`VARCHAR + CHECK`), **K40** (ULID PK).

`K38` özel bir tartışma gerektirdi: `draft` durumunun neden atıldığı, "durum
makinesine ancak onu doğuran bir olay varsa durum eklenir" ilkesiyle açıklandı.
Frontend'de Kaydet düğmesi yok (autosave var), dolayısıyla `draft`'ı doğuracak
bir an mevcut değildi.

### 2.3 3.1 — `InvitationStatus` enum

İlk kod dosyası. `values()` metodu bir sonraki adımda CHECK kısıtını beslemek
için yazıldı; `default()` üç ayrı yerde kullanılacağı için metot oldu (C3).

### 2.4 İş modelinin netleşmesi → K42, K43

Kullanıcı ticari modeli açtı: abonelik türüne göre belli sayıda veya sınırsız
davetiye; hem **tekil** (sepete atılan davetiye başına) hem **paket** (süreli
abonelik) satın alma.

İkisini veri modelinde ayrı taşımak dağılmış `if` blokları üretirdi. Bunun yerine
tek bir soruya indirgendi — *"bu kullanıcı, bu davetiyeyi Gold seviyesinde
yayınlama hakkına sahip mi?"* — ve `TierResolver` (Faz 7) sorumlusu oldu
(**K42**).

Ayrıca kotanın **yayınlananı** saydığı netleşti (**K43**), böylece Faz 3 kotadan
tamamen bağımsız kaldı.

### 2.5 3.2 — `invitations` migration

Üç karar burada alındı:

- Yalnızca `status` CHECK kısıtı aldı; `palette`, `category_id`, `preset_id`
  frontend kataloğunun anahtarları olduğu için kısıtlanmadı (**E6**)
- İçerik alanlarının **tamamı** `nullable` — autosave yarım veri gönderir
- `phone_background` kolonu **açılmadı**, `preset_id`'den türetilecek (**K41**)

### 2.6 🔴 Ara: `composer check` üç kez kırıldı

Faz 3'ün en öğretici bölümü kod yazmak değil, kapıyı yeşile döndürmekti.

**Kırılma 1 — Pint.** `DB::statement(` çağrısında sondaki virgül eksikti
(`trailing_comma_in_multiline`). `pint --test` düzeltmez, sadece bakar (Faz 1,
ders 12). Aynı hata 3.6'da **tekrarlandı** — kural bilinse bile alışkanlık
oturmadan tekrarlanıyor.

**Kırılma 2 — PHPStan.** `LoginUserAction`'da `$user?->password ?? ...` gereksiz
nullsafe kullanımı. Sebep incelendiğinde `??` operatörünün zaten `isset`
mantığıyla çalıştığı, yani `?->`'nin hiçbir şey eklemediği görüldü. Ve
`LoginUserAction.md` §3.1'de **yanlış bir açıklama** olduğu ortaya çıktı
("bu satır olmadan 500 alırdık") — düzeltildi (**B4**).

**Kırılma 3 — İki AuthTest kırmızı.** Git kaydına bakıldı: `Password::min(8)`
koda 4 Ağustos'ta girmiş, `'min'` bekleyen test 7 Ağustos'ta yazılmış,
`composer.lock` 31 Temmuz'dan beri değişmemiş. Yani **testler hiç geçmemişti** —
Faz 2 yeşil kapanmamıştı.

İkisi de gerçek kusurdu:

| Kusur | Kural |
|---|---|
| Kural nesnesi sınıf adıyla raporlanıyor, framework adı API'ye sızıyor | **D6** |
| `RequestGuard` kullanıcıyı önbelleğe alıyor, test token'a bakmıyor | **T13** |

`T13` için `tests/TestCase.php`'e `forgetAuthState()` yardımcısı eklendi —
Faz 3'ün IDOR testleri onsuz **sessizce boş yeşil** yanardı.

Bu noktada `composer check` **ilk kez gerçekten yeşil** oldu ve Faz 2 kapandı.

### 2.7 3.3 — `timeline_events` migration → K44

`TimelineEditor.tsx` incelenirken tarayıcının kendi kimliklerini ürettiği
görüldü (`tl-${Date.now()}`, varsayılanlarda `tl-1`…`tl-4` — yani **her
davetiyede aynı**).

Kullanıcının kararı: *"frontend id üretmesin, id'ler backend tarafında
üretilsin"* → **K44**. Sözleşme iki açık duruma indi: `id: null` = yeni satır,
`id: "7"` = güncelle.

🔴 Ayrıca netleştirildi: sözleşmenin açıklığı **güvenlik kontrolünün yerine
geçmez** — eşleştirme her zaman ilişki üzerinden yapılacak.

### 2.8 3.4-3.5 — Modeller

`Invitation` ve `TimelineEvent`, artı `User::invitations()` ilişkisi.

`#[Fillable]` listelerinden `user_id`, `status`, `published_at` ve
`invitation_id` **bilerek** çıkarıldı: aidiyet ve durum istemci kararı değil.

Sıralamanın nereye ait olduğu ayırt edildi: program adımlarının sırası
**anlamın parçası** (ilişkiye gömüldü), davetiyelerin sırası **sunum tercihi**
(çağırana bırakıldı).

### 2.9 3.6 — Fabrikalar ve seeder

`DatabaseSeeder`'ın Faz 2'den beri **bozuk** olduğu bulundu: `'name' => 'Test
User'` yazıyordu ama K35 ile kolon `first_name`/`last_name` olmuştu. Hiç
çalıştırılmadığı için kimse fark etmemişti.

Yeniden yazıldı ve idempotans eklendi.

### 2.10 3.7 — `InvitationPolicy`

Fazın güvenlik dosyası. Reddin **404** olması Faz 1'de kurulan H7 kuralının
karşılığını buldu: `ApiExceptionRenderer` zaten `AuthorizationException`'ı
`ResourceNotFound`'a eşliyordu, dolayısıyla Policy `bool` döndürmekle yetindi.

`Response::denyAsNotFound()` **kullanılmadı**: sözleşme kararını her policy'ye
kopyalamak, Policy yazmamızın gerekçesinin tersiydi.

Bir tuzak kaynaktan okunarak kapatıldı: `Model::getCasts()` yalnızca **artan**
anahtarlı modellerde birincil anahtarı `int`'e cast ediyor. `Invitation`
`HasUlids` kullandığı için `getIncrementing()` `false` — bu yüzden modele
`'user_id' => 'integer'` eklendi. Olmasaydı `===` karşılaştırması tutmaz ve
**hiç kimse kendi davetiyesine erişemezdi**.

### 2.11 3.8 — Doğrulama katmanı

Ortak `InvitationRequest` tabanı + iki ince alt sınıf (C3). İstek gövdesinin
`{ invitation: {...} }` sarmalı **yapısal bir güvenlik sınırı** olarak korundu:
`status` diye bir alan tanımlı olmadığı için `validated()` onu hiç görmüyor.

`array_key_exists` / `isset` ayrımı kritikti: `isset` yazsaydık kullanıcı bir
alanı **temizleyemezdi**.

`showRSVP` alanı, "sihirli dönüşüm yasak" kuralının somut kanıtı oldu:
`Str::snake('showRSVP')` → `show_r_s_v_p`.

### 2.12 3.9 — Resource ailesi (plandan sapma #1)

Yol haritası `whenLoaded()` diyordu; **uygulanmadı** ve gerekçesi tartışıldı:
`whenLoaded` ilişki yüklü değilse anahtarı düşürür, frontend eksik alanı
varsayılanla doldurur ve kullanıcı **hiç yazmadığı bir programı** görür.

Doğrudan erişimde ise `preventLazyLoading` yerelde exception fırlatır. *N+1 bir
performans sorunu, yanlış veri bir doğruluk sorunudur.*

Ayrıca 3.2'de düşülen bir not düzeltildi: hediye verisi **burada
maskelenmiyor** — sahibin kendi IBAN'ını görmesi gerekiyor, maskeleme Faz 4'ün
public Resource'unun işi (**C4**).

### 2.13 3.10 — Action katmanı

Fazın en ilginç algoritması. "Sil ve yeniden yarat" reddedildi (id'ler her
autosave'de değişir, React çizimi bozulur); gerçek senkronizasyon yazıldı.

🔴 Aidiyet bir `if` ile değil, **sorgunun kapsamıyla** korundu:

```php
$existing = $invitation->timelineEvents()->get()->keyBy('id')->all();
```

Kısa devre tuzağı ikinci kez karşımıza çıktı — bu kez bir veri güncellemesini
çökertecekti.

### 2.14 3.11 — Controller ve rotalar (plandan sapma #2)

`authorizeResource` **çalışmadı**: kaynağa bakıldığında `$this->middleware()`
çağırdığı, Laravel 11+ taban controller'ının ise boş olduğu görüldü. Yerine her
metotta `Gate::authorize()` kullanıldı.

Rota kısıtı (`whereUlid deseni`) hem sıra tuzağını hem geçersiz kimlik
sorgularını kapattı.

### 2.15 3.12 — Testler

18 test. Dördü güvenlik regresyonu, ikisi T13'e bağımlı. Ayırt edilemezlik
**ham gövde** karşılaştırmasıyla doğrulandı (T11).

Kılavuza bir **mutasyon tablosu** eklendi: hangi kodu bozunca hangi testin
kırılması gerektiği. *Hiçbiri kırılmıyorsa test değil, süs yazmışsındır.*

### 2.16 F1-F8 — Frontend uyarlaması

Sözleşme değişikliğinin uçtan uca yayılması. `types.ts` ile başlandı ki
TypeScript kalan işi **listeleyebilsin**.

En kritik üç bulgu:

| # | Bulgu |
|---|---|
| 1 | `loadInvitation(card.invitation)` kimliği düşürüyordu → her düzenlemede **kopya davetiye** |
| 2 | Autosave yarışı iki POST atabilirdi → **iki kayıt** |
| 3 | Sunucu kimlikleri belleğe yazılmazsa her autosave programı **yeniden yaratır** |

Üçü de K37/K44'ün doğrudan sonucuydu; tek davetiye varsayımında hiçbiri mümkün
değildi.

---

## 3. Yazılan dosyalar

### 3.1 Backend (12 dosya + kılavuzları)

| # | Dosya | Ne yapar |
|---|---|---|
| 3.1 | `app/Enums/InvitationStatus.php` | `saved \| published`, `default()`, `values()` |
| 3.2 | `..._create_invitations_table.php` | ULID PK, CHECK, 6 `show_*`, `(user_id,status)` indeksi |
| 3.3 | `..._create_timeline_events_table.php` | `foreignUlid`, `sort_order`, CASCADE |
| 3.4 | `app/Models/Invitation.php` | `HasUlids`, `#[Fillable]`, `immutable_*` cast, iki ilişki |
| 3.5 | `app/Models/TimelineEvent.php` | `belongsTo`, `sort_order` cast |
| 3.6 | `InvitationFactory` · `TimelineEventFactory` · `DatabaseSeeder` | Deterministik test verisi |
| 3.7 | `app/Policies/InvitationPolicy.php` | 🔴 IDOR savunması |
| 3.8 | `Requests/Invitation/{InvitationRequest, Store…, Update…}.php` | 21 alanlık eşleme, iç içe doğrulama |
| 3.9 | `Resources/{InvitationResource, InvitationPayloadResource, TimelineEventResource}.php` | Beyaz liste, iki tarih biçimi |
| 3.10 | `Actions/Invitation/{Create…, Update…, SyncTimelineEvents…}.php` | Transaction + senkronizasyon |
| 3.11 | `Controllers/Api/V1/InvitationController.php` + `routes/api.php` | 5 uç nokta |
| 3.12 | `tests/Feature/InvitationTest.php` | 18 test |

**Faz 3'te düzenlenenler:** `app/Models/User.php` (`invitations()`),
`tests/TestCase.php` (**yeni** — `forgetAuthState()`),
`app/Actions/Auth/LoginUserAction.php`, `app/Http/Requests/Auth/RegisterRequest.php`,
`tests/Feature/AuthTest.php`, `database/seeders/DatabaseSeeder.php`.

### 3.2 Frontend (8 dosya + kılavuzları)

| # | Dosya | Değişiklik |
|---|---|---|
| F1 | `src/types.ts` | `TimelineEvent.id: string \| null` + `localKey` |
| F2 | `src/services/invitations.ts` | REST istemcisi, `Wire*` tipleri, hidrasyon |
| F3 | `src/services/persistence.ts` | Kimlik taşıyan arayüz; sınır düzeltildi |
| F4 | `src/stores/useInvitationStore.ts` | 🔴 `recordId`, kaydetme kuyruğu, `adoptServerIds` |
| F5 | `src/hooks/useDashboardData.ts` | Gerçek dizi + iyimser silme |
| F6 | `src/pages/DashboardPage.tsx` | Kart kaydın tamamını taşır; silme düğmesi |
| F7 | `src/components/create/TimelineEditor.tsx` | `id: null`, `localKey` ile eşleştirme |
| F8 | `src/data.ts` | Varsayılan programın kimlikleri |

### 3.3 Kavram dokümanları

Backend kılavuzları `docs/rehber/` altında kod yolunu birebir yansıtıyor (K18).
Frontend kılavuzları aynı kuralla `davetkart-frontent/docs/rehber/src/` altında.

---

## 4. Alınan kararlar

| # | Karar | Gerekçe |
|---|---|---|
| **K37** | `/api/invitations` **REST koleksiyonu** | Ticari model çoklu davetiye; `orders.invitation_id` zaten bunu varsayıyor |
| **K38** | `InvitationStatus` = `saved \| published` | `draft`'ı doğuran olay yok (autosave var, Kaydet düğmesi yok) |
| **K39** | `VARCHAR + CHECK`, native `ENUM` değil | Değer eklemek/çıkarmak sıradan migration olur |
| **K40** | `invitations.id` = **ULID PK** | Enumeration savunması + zaman sıralı indeks. `timeline_events.id` bigint kalır (URL'de geçmez) |
| **K41** | `phone_background` kolonu **yok** | Türetilebilen veri saklanmaz (E1); `preset_id`'den üretilir |
| **K42** | Yayın hakkı **iki kaynaktan**, tek arayüzden sorulur | Tekil ve paket satın alma; `TierResolver` (Faz 7). `orders.invitation_id NULL` = paket |
| **K43** | Kota **yayınlananı** sayar | Taslak denemek bedelsiz olmalı; Faz 3 kotadan bağımsız kaldı |
| **K44** | Kimliği **backend üretir** | `tl-1` her davetiyede aynıydı; `id: null` = yeni satır |

---

## 5. Kurulan kurallar

### 5.1 Yetkilendirme — yeni seri **P**

| # | Kural | Gerekçe |
|---|---|---|
| **P1** | Sahiplik kuralı **tek yerde** tanımlanır; her uçta `if` ile tekrarlanmaz | Beş kopyanın dördünü doğru yazıp birini unutmak, tek yeri yazmaktan olası |
| **P2** | Reddin HTTP karşılığı Policy'de değil, **hata katmanında** belirlenir | Sözleşme kararı her policy'ye kopyalanmamalı (H10'un aynı ailesi) |
| **P3** | Koleksiyon uçlarında sahiplik Policy ile değil **sorgu ile** korunur | `viewAny` her zaman `true`; filtreyi unutmak gözden kaçmaz olmalı |
| **P4** | Güvenlik karşılaştırmasında **iki tarafın tipi garanti** olmalı | `int !== string` sessizce herkesi kilitler; sürücü davranışına güvenilmez |

### 5.2 İç içe koleksiyon — yeni seri **N**

| # | Kural | Gerekçe |
|---|---|---|
| **N1** | Alt kayıt **her zaman üst kaydın ilişkisinden** oluşturulur | Aidiyet, doğrulanacak girdi olmaktan çıkıp yapısal garanti olur |
| **N2** | Tanınmayan kimlik **hata değil, yeni kayıttır** | Bayat id 422 üretirse autosave kilitlenir |
| **N3** | Sıra **dizinin konumundan** okunur; istemci sıra alanı göndermez | İki bilgi çelişirse hangisine inanılacağı belirsizdir |
| **N4** | **`null` ile `[]` farklı bilgilerdir** | Kısmi güncelleme kullanıcının koleksiyonunu sessizce silmemeli |

### 5.3 Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **D6** | Doğrulama kuralının **adı** sözleşmenin parçasıdır; kural nesnesi değil **string kural** kullanılır | `Password::min(8)` → `illuminate\_validation\_rules\_password` yanıta sızıyordu |
| **E6** | Veritabanı kısıtı yalnızca **backend'in sahibi olduğu** kurallara konur | `palette`/`category_id` kısıtlansaydı her yeni tema deploy isterdi |
| **C4** | Aynı veri, farklı okuyucular için **farklı Resource**'a çıkar | Sahibin IBAN'ını maskelemek onu sessizce silerdi |
| **C5** | Gövdeye giden alanlar da **beyaz listedir** | Sözleşmede yeri olmayan alan, ona bağlanılmasına davetiye çıkarır |
| **T13** | Aynı test metodunda ikinci kimlikli istekten önce **guard sıfırlanır** | `RequestGuard` kullanıcıyı önbellekler; IDOR testi boş yeşil yanar |
| **T14** | Bir işlemin **yapılmadığını** test ediyorsan yanıtı değil **etkiyi** doğrula | 404 dönmesi, yazmanın gerçekleşmediğini kanıtlamaz |
| **B5** | Hiçbir otomatik kontrolün yolunda olmayan dosyayı **elle çalıştırmak** senin sorumluluğun | Seeder Faz 2'den beri bozuktu; `composer check` onu koşturmuyor |

> **Kuralların tam listesi:** FAZ-0 §4 (31) · FAZ-1 §4 (19) · FAZ-2 §4 (20) ·
> **FAZ-3 §5 (15)**

---

## 6. Öğrenilen dersler

**26. 🔴 Çalıştırılmayan kod, doğru olduğu varsayılan koddur.** Faz 3'te bunun
**üç** örneği bulundu: iki AuthTest (7 Ağustos'tan beri kırmızı) ve
`DatabaseSeeder` (hiç çalıştırılmamış, var olmayan kolona yazıyordu). Üçü de
"yazıldı" ile "çalışıyor"un farklı durumlar olduğunu gösterdi.

**27. Aynı dil özelliği bir yerde tuzak, başka yerde araçtır.** Kısa devre
(`||`, `&&`) `LoginUserAction`'da bir savunmayı çökertiyordu (A4),
`SyncTimelineEventsAction`'da bir güncellemeyi çökertecekti, ama
`UpdateInvitationAction`'da tam istenen davranıştı. Ayırt edici soru: *"sağ taraf
her durumda çalışmalı mı?"*

Aynı şey `?->` için de geçerli: `??` ile birlikte gereksiz, `$this->command?->info()`
gibi doğrudan metot çağrısında zorunlu.

**28. Laravel 11+ taban controller'ı boştur.** `authorizeResource` `$this->middleware()`
çağırıyor; taban sınıf artık `Illuminate\Routing\Controller`'dan türemediği için
o metot yok. İnternetteki eğitimlerin çoğu Laravel 10 ve öncesini anlatıyor
(Faz 0, ders 3'ün ailesi).

**29. Tip belirsizliğini sınırda çöz.** `user_id` cast'i, `show_*` boolean
cast'i, `sort_order` integer cast'i ve Resource'taki `(string)` dönüşümleri hep
aynı ilkeden. Belirsiz tip sistemin içine girerse nerede patlayacağını
kestiremezsin — ve güvenlik karşılaştırmasında patlarsa herkesi kilitler.

**30. Savunma kodu her yere değil, güven sınırına yazılır.** Backend'de Action'a
gelen veri güvenilirdi (FormRequest doğrulamıştı), frontend'de servise gelen veri
**değildi** (ağdan geliyordu). İçeride tekrarlamak iki doğruluk kaynağı üretir;
sınırda yazmamak çökme üretir.

**31. Kısıt, sahibi olduğun kurala konur.** `status` backend'in malı ve güvenlik
sınırı — CHECK aldı. `palette`, `category_id`, `preset_id` frontend kataloğunun
anahtarları — almadı. Aksi hâlde sunum katmanındaki her değişiklik backend'i
kilitlerdi.

**32. Sözleşme değişince adı da değiştir.** `loadInvitation` → `loadRecord`.
Aynı adla kalsaydı, çağıran yerlerde kimliği geçirmeyi unutmak sessizce
geçerdi; ad değişince TypeScript her çağrıyı derleme hatasıyla gösterdi.

**33. Bir aracın kırılması, kırılan yerin hatalı olduğu anlamına gelmez.**
`logout revokes only the current token` testi kırmızıydı ama `RevokeTokenAction`
doğruydu — kusur **testin kendisindeydi**. Belirtiye değil sebebe bakmak (Faz 2,
ders 18) bu kez ters yönde işledi.

---

## 7. Plandan sapmalar

İkisi de tartışılarak yapıldı (çalışma kuralı 5).

| Plandaki | Yapılan | Gerekçe |
|---|---|---|
| `whenLoaded()` ile N+1 önleme (3.9) | Doğrudan erişim + controller'da `with()` | `whenLoaded` sessiz yanlış veri üretir; doğrudan erişim gürültülü hata verir |
| `authorizeResource` (3.11) | Her metotta `Gate::authorize()` | Laravel 11+ taban controller'da `middleware()` yok — çalışmıyor |

---

## 8. Faz 2'de bulunan ve düzeltilen kusurlar

Faz 3, Faz 2'nin **yeşil kapanmadığını** ortaya çıkardı.

| Kusur | Ne zamandan beri | Düzeltme |
|---|---|---|
| `Password::min(8)` sınıf adını sözleşmeye sızdırıyordu | 4 Ağu | `'min:8'` (D6) |
| Guard önbelleği token testini boş yeşil yakıyordu | 7 Ağu | `forgetAuthState()` (T13) |
| `LoginUserAction`'da gereksiz `?->` | 4 Ağu | `->` + kılavuz düzeltmesi |
| `LoginUserAction.md` §3.1'de **yanlış açıklama** | 4 Ağu | Bölüm yeniden yazıldı (B4) |
| `DatabaseSeeder` var olmayan `name` kolonuna yazıyordu | Faz 0'dan beri | Yeniden yazıldı + idempotans |

---

## 9. Faz 4'e devir

### 9.1 Hazır olanlar

- `invitations` ve `timeline_events` tabloları, modeller, ilişkiler
- `InvitationStatus::Published` — public sorgunun dayanağı
- ULID kimlik = paylaşılan link
- `InvitationPayloadResource` — public sürüm bunu **örnek alacak** ama
  kopyalamayacak (C4)

### 9.2 Faz 4'ün işleri

| # | İş | Not |
|---|---|---|
| 4.1 | `ResolvePublicInvitationAction` | slug → **yalnızca yayınlanmış** davetiye |
| 4.2 | `PublicInvitationController` | `/api/public/` grubu (K12), auth'suz |
| 4.3 | `PublicInvitationResource` | 🔴 **C4**: `show_gift = false` iken `iban` dönmez |
| 4.4 | Cache + `InvitationPublished` olayı | K7 |
| 4.5 | ETag → `304 Not Modified` | |
| 4.6 | `PublicInvitationTest` | 🔴 taslak sızmıyor |

### 9.3 Faz 4'e taşınan açık sorular

| Konu | Not |
|---|---|
| `event_at` saat dilimi | Duvar saati olarak saklanıyor; geri sayım sayacı başka saat diliminden bakan misafirde ne göstermeli? |
| `InvitePage.tsx` hâlâ yerel store'u çiziyor | Public uç hazır olunca gerçek veriye bağlanacak |
| 404 sayfası | Frontend `path: '*'` ana sayfaya yönlendiriyor |

---

## 10. Hâlâ açık kalanlar

### 10.1 Faz 3'ün kendi kalan işi

- `FAZ-3-ELLE-DOGRULAMA.md` — uçtan uca doğrulama betiği
- `claude/Notlar/03-FRONTEND-YAPILACAKLAR.md` güncellemesi (kapanan maddeler)
- `claude/PHP-LARAVEL-SETUP.md` §7 karar tablosuna K37-K44 eklenmesi

### 10.2 Sonraki fazlara

| Konu | Ne zaman |
|---|---|
| `routes/web.php` closure'ı (R1/R4 ihlali) | Faz 9 — `route:cache` kırılır |
| Genel API hız sınırı (`throttleApi`) | Faz 5 |
| `HasErrorCode` arayüzü | Faz 5 — üçüncü exception gelince |
| PHPStan level 6 → 8 | Faz 5 (K22) |
| `subscriptions` tablosu + `TierResolver` | Faz 7 (K42, K43) |
| `DeleteInvitationAction` | Faz 6 — medya temizliği iş kuralı doğurunca |
| `window.confirm` yerine tasarım sistemine uygun modal | Frontend borcu |
| K20'nin frontend tarafı: `toDisplayError()` çeviri katmanı | `Notlar/03` §3.2-3.3 |
| `restoreSession()` → açılışta `GET /auth/me` | `Notlar/03` §10.2 |

---

## 11. Bir cümlelik özet

Faz 3'te davetiye CRUD'unu yazdık; ama asıl öğrendiğimiz şey **sahipliğin bir
`if` bloğu değil, sorgunun kapsamı** olduğu ve **çalıştırılmayan kodun doğru
olduğu varsayılan kod** olduğuydu.
