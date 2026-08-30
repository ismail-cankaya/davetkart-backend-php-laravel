# FAZ 6 — Medya Modülü (Dosya Kabul Eden Yol)

> **Tarih:** 29 Ağustos 2026
> **Durum:** ⚠️ **24/24 GELİŞTİRME ADIMI TAMAMLANDI — DOĞRULAMA BEKLİYOR**
> **Önceki:** [`FAZ-5.md`](FAZ-5.md) · **Sonraki:** Faz 7 — Ödeme ve paywall
> **Bu dosya:** fazın kronolojik kaydı, alınan kararlar, kurulan kurallar ve devir

---

## 0. 🔴 ÖNCE BUNU OKU — durum alanı ne diyor, ne demiyor

**Bu fazın ilk yarısı doğrulandı, ikinci yarısı doğrulanmadı.**

| Adım aralığı | `composer check` koştu mu |
|---|---|
| 6.1 – 6.14 | ✅ **Evet** — üç kez, üçünde de yeşil bitti |
| 6.15 – 6.24 | ⚠️ **Hayır** — bu adımlar PHP'siz bir ortamda yazıldı |

Sebep: 6.15'ten itibaren adımlar tek oturumda, onay beklemeden yazıldı ve o
oturumun çalıştığı yardımcı ortamda **PHP ve Composer yok**. Koşan tek kontrol
sözdizimi bile değil.

🔴 **B7** gereği durum alanına "tamamlandı" değil "doğrulama bekliyor" yazıldı.
Bu projede Faz 1, 3 ve 4'te üç kez "yeşil" yazılmış ve değildi; Faz 5'te
bilerek "doğrulanmadı" yazıldı ve **haklı çıktı** — Faz 6'nın ilk koşusunda
PHPStan 8 hatası ve bir flaky test ortaya çıktı (§6).

**Faz 6, [`FAZ-6-ELLE-DOGRULAMA.md`](FAZ-6-ELLE-DOGRULAMA.md) yeşil bitene
kadar KAPANMAMIŞTIR.** §11'deki kapanış listesi işaretlenmeden Faz 7'ye
geçilmemeli.

---

## 1. Faz 6 neydi?

**Amaç:** Sistemin **dosya kabul eden** yolunu açmak ve dosya yüklemenin
güvenlik yükünü öğrenmek.

Fazların soruları birikerek ilerliyor:

| Faz | Soru |
|---|---|
| 2 | *"Sen kimsin?"* |
| 3 | *"Buna dokunabilir misin?"* |
| 4 | *"Kimliği bilinmeyen biri bunun neyini görebilir?"* |
| 5 | *"Kimliği bilinmeyen biri buraya ne yazabilir?"* |
| **6** | 🔴 *"Kimliği bilinmeyen biri buraya ne YÜKLEYEBİLİR — ve o dosya sunucuda ne yapabilir?"* |

Fark: Faz 5'te bir **satır** yazılıyordu, burada bir **dosya**. Bir metin
girdisinde doğrulanacak tek şey vardır; bir dosyada **beş** vardır (ad, uzantı,
beyan edilen MIME, içerik, boyut) ve beşi de kullanıcının kontrolündedir.

### Öğrenme hedefleri

| Hedef | Nerede |
|---|---|
| İçerikten MIME doğrulaması | 6.6 |
| Rastgele dosya adı ve path traversal | 6.8 |
| Dosya sistemi transaction'a dâhil değildir | 6.8 |
| Telafi işlemi (compensating transaction) | 6.8 |
| 15 saniye kuralı ve kuyruk | 6.7 |
| Metriği sınırın tanımı belirler (`COUNT` ↔ `SUM`) | 6.8 |
| Bir kuralı iki uç paylaşınca ne olur (refactor) | 6.12, 6.13 |
| Değişmez (invariant) ile doğrulama farkı | 6.14 |
| Sessiz reddin savunma değeri | 6.20 |
| Şema kimlik tutar, sözleşme URL taşır | 6.17, 6.21 |

---

## 2. Adım adım ne yapıldı?

### 2.1 6.1–6.8 — Temel (önceki oturum)

`MediaKind` enum'u (tür → config anahtarı bağlaması), `media` tablosu (ULID PK,
`disk` kolonu, iki CHECK, `UNIQUE(disk, path)`), `Media` modeli (**boş
`#[Fillable]`**), `MediaFactory`, `MEDIA_QUOTA_EXCEEDED` + `HasErrorCode`'u
uygulayan exception, `MediaRequest` ailesi, `OptimizeUploadedImage` kuyruk işi
ve `StoreUploadedMediaAction`.

### 2.2 6.9 — Eksik kılavuz kapatıldı

`StoreUploadedMediaAction` kodu `docs/rehber/.../StoreUploadedMediaAction.md`'ye
referans veriyordu ama dosya yoktu. Yazılırken **koddaki bir yorumun yanlış
olduğu** bulundu (§6.1).

### 2.3 🔴 Kalite kapısı — dört oturumdur ilk kez tam koştu

`composer check` zinciri ilk kez sonuna kadar koştu ve üç şey buldu (§6).

### 2.4 6.10–6.11 — Sözleşme ve sahibin ucu

`MediaResource` (**iki alan**: `id`, `url` — `disk`/`path` dışarı çıkmaz) ve
`MediaController` (`Gate::authorize('update')`, route-model binding).

### 2.5 6.12–6.13 — Ortak kural çıkarıldı

Misafirin medya ucu, `SubmitRsvpAction`'ın üç kontrolünü (yayında + modül açık
+ son tarih) **aynen** istiyordu. Kopyalamak yerine
`ResolveOpenRsvpInvitationAction` doğdu ve `SubmitRsvpAction` ona devretti.

🔴 Bu, **Faz 5 koduna dokunan ilk değişiklikti** ve güvenliğini `RsvpTest`'in
29 testi sağladı — testler yazıldıkları amaca ilk kez hizmet etti.

### 2.6 6.14–6.16 — Misafirin yolu

`StoreGuestMediaAction` (tür izni **değişmezi**), `PublicMediaController`
(auth yok, string parametre, Gate yok) ve iki rota + `throttle:media`.

### 2.7 6.17–6.21 — LCV'ye bağlama

`rsvps` medya kolonları + FK (`nullOnDelete`), `Rsvp` ilişkileri,
`StoreRsvpRequest`'te `photoMediaId`/`videoMediaId`, `SubmitRsvpAction`'da
**sahiplik doğrulaması**, `RsvpResource`'ta türetilmiş `photoUrl`/`videoUrl`.

### 2.8 6.22–6.24 — Kanıt ve kapanış

`MediaTest` (28 test, **20 satırlık mutasyon tablosu**), faz özeti, elle
doğrulama betiği ve doküman güncellemeleri.

---

## 3. Yazılan dosyalar

### 3.1 Yeni (16 kod dosyası + kılavuzları)

| # | Dosya | Ne yapar |
|---|---|---|
| 6.1 | `app/Enums/MediaKind.php` | Tür → config anahtarı; `isGuestUploadable()`, `isOptimizable()` |
| 6.2 | `..._create_media_table.php` | ULID PK, `disk` kolonu, iki CHECK, `UNIQUE(disk, path)` |
| 6.3 | `app/Models/Media.php` | **Boş** `#[Fillable]`, `url()` metodu (accessor değil) |
| 6.4 | `database/factories/MediaFactory.php` | Tür-tutarlı state'ler |
| 6.5 | `Exceptions/MediaQuotaExceededException.php` | `forOwner()` / `forGuest()` — H9 sınıfın şekliyle |
| 6.6 | `Requests/Media/{MediaRequest,Store…,StorePublic…}.php` | `mimetypes:` (içerikten), tür başına limit |
| 6.7 | `app/Jobs/OptimizeUploadedImage.php` | 15 saniye kuralı; veriyle idempotans |
| 6.8 | `Actions/Media/StoreUploadedMediaAction.php` | 🔴 Kilitli kota, rastgele ad, telafi işlemi |
| 6.10 | `Resources/MediaResource.php` | İki alanlık sözleşme |
| 6.11 | `Controllers/Api/V1/MediaController.php` | Sahibin ucu |
| 6.12 | `Actions/Rsvp/ResolveOpenRsvpInvitationAction.php` | 🔴 "Misafir yazabilir mi?" — tek yer |
| 6.14 | `Actions/Media/StoreGuestMediaAction.php` | Tür izni **değişmezi** |
| 6.15 | `Controllers/Api/V1/PublicMediaController.php` | Misafirin ucu |
| 6.17 | `..._add_media_columns_to_rsvps_table.php` | İki FK, `nullOnDelete` |
| 6.22 | `tests/Feature/MediaTest.php` | **28 test** + mutasyon tablosu |

### 3.2 Düzenlenenler

| Dosya | Değişiklik |
|---|---|
| `app/Models/Invitation.php` | `media()` ilişkisi |
| `app/Models/Rsvp.php` | `photoMedia()`, `videoMedia()` |
| `app/Enums/ErrorCode.php` · `contracts/error-codes.json` | `MEDIA_QUOTA_EXCEEDED` (20 kod) |
| `app/Actions/Rsvp/SubmitRsvpAction.php` | 🔴 Üç kontrol devredildi + medya aidiyeti |
| `app/Http/Requests/Rsvp/StoreRsvpRequest.php` | `mediaIds()` erişimcisi |
| `app/Http/Resources/RsvpResource.php` | `photoUrl` / `videoUrl` |
| `app/Http/Controllers/Api/V1/{Public,}RsvpController.php` | Eager loading + `mediaIds()` |
| `app/Providers/AppServiceProvider.php` | `media` limiter'ı |
| `routes/api.php` · `config/davetkart.php` | İki rota + `media.rate_limit` |
| `app/Policies/RsvpPolicy.php` | 🔴 Soft-delete `null` hatası düzeltildi (§6.2) |
| `config/filesystems.php` · `config/sanctum.php` | `(string) env(...)` — PHPStan 8 |
| `tests/Feature/{PublicInvitation,Rsvp}Test.php` | Flaky test + generic docblock'lar |

---

## 4. Alınan kararlar

| # | Karar | Gerekçe |
|---|---|---|
| **K54** | `media.disk` **kolonda saklanır**, config'ten okunmaz | Config *"şu an nereye yazıyoruz"*, kolon *"o dosya nereye yazılmıştı"* sorusunu cevaplar. S3 göçü eski satırları kırmaz |
| **K55** | Depolama **yerel `public` diski** kalır; S3/R2 ertelendi | Kapsam kararı (İsmail onayladı). `media.disk` göçü zaten ucuzlatıyor. ⚠️ Dosyalar bugün web kök dizini altında — Faz 9 borcu |
| **K56** | `media.id` = **ULID** | K40/K52'nin doğrudan uygulaması; kimlik URL'de ve LCV gövdesinde geçiyor |
| **K57** | Medya **polimorfik değil**, `invitation_id` + `kind` ile modellendi | `morphTo` yabancı anahtar kısıtı kurdurmaz — **E2** buna karşı bir argüman. `kind` zaten türü taşıyor |
| **K58** | LCV medyası **kimlikle** iliştirilir, URL ile değil | URL doğrulanamaz; kimlik "bu medya bu davetiyeye mi ait" sorusunu sordurur (**N1**) |
| **K59** | Geçersiz medya kimliği **sessizce düşürülür**, reddedilmez | 403 dönmek kimliğin *gerçek* olduğunu doğrular ve `media` tablosunu ULID uzayından taranabilir yapar (**L2**) |
| **K60** | `rsvps` medya FK'leri **`nullOnDelete`** | Misafirin yazdığı metin, eklediği fotoğraftan bağımsız bir veridir; bir temizlik işi LCV kayıtlarını götürmemeli |
| **K61** | Misafirin medya ucu **ayrı bir throttle kovası** alır | Honeypot katmanı yok + istek başına maliyet on kat. Aynı kovayı paylaşmak sınırları birbirine karıştırırdı |
| **K62** | `Jobs/SendRsvpNotification` **Faz 8'e** kaldı | K53 doğrulandı: bildirim kanalı hâlâ tasarlanmadı (İsmail onayladı) |
| **K63** | `invitations.timezone` **Faz 7'ye** ertelendi | Davetiye şemasına ait, medya modülüyle bağı yok; frontend'de tarih arayüzü de değişmeli (İsmail onayladı) |

---

## 5. Kurulan kurallar

### 5.1 Dosya kabul etme — yeni seri **F**

| # | Kural | Gerekçe |
|---|---|---|
| **F1** | Dosya tipi **içerikten** doğrulanır (`mimetypes:`), uzantıdan değil | Uzantı kullanıcı girdisidir; `.jpg` adlı PHP dosyası yüklenebilir |
| **F2** | Depolanan ad **sunucu üretir**; orijinal ad hiçbir yere yazılmaz | Path traversal ve üzerine yazma yapısal olarak imkânsızlaşır |
| **F3** | **Dosya sistemi transaction'a dâhil değildir** — geri alınamayan iş elle telafi edilir | `DB::transaction()` diski geri almaz; `try/catch` + `delete()` |
| **F4** | Depolama konumu **satırda saklanır**, config'ten okunmaz | Config bugünü, kolon geçmişi anlatır |
| **F5** | Sözleşme **URL** taşır, şema **kimlik** tutar | URL türetilebilir (**E1**); ham URL `APP_URL`'i veritabanına gömerdi |

### 5.2 Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **L5** | İstemciden gelen bir **kimliğin aidiyeti**, doğrulama katmanında değil Action'da sorulur | FormRequest üst kaynağı henüz çözmemiştir; "bu senin mi" sorusu orada cevaplanamaz |
| **L6** | Geçersiz bir kimliğin reddi **sessizdir** | Reddin kendisi kimliğin gerçek olduğunu doğrular (**L2**'nin kimlik uzayındaki hâli) |
| **A8** | Bir sınıfın **değişmezi**, doğrulama katmanına bırakılmaz | Doğrulama HTTP'ye aittir ve atlanabilir; değişmez sınıfın tanımıdır |
| **T17** | Bir savunma **kendinden önceki katmanla** test edilemiyorsa, o katman **atlanarak** test edilir | FormRequest önce elediği için Action guard'ının HTTP testi mutasyonu öldüremez (**T15**'in uygulaması) |
| **E10** | Metriği **sınırın tanımı** belirler, alışkanlık değil | "Kaç dosya" `COUNT(*)`, "kaç misafir" `SUM(guest_count)` ister |
| **B8** | Bir kural **çıkarıldığında**, kılavuzundaki anlatımı da **taşınır** — iki yerde kalmaz | Bir kılavuz da bir doğruluk kaynağıdır |

> **Kural sayıları:** FAZ-0 (31) · FAZ-1 (19) · FAZ-2 (20) · FAZ-3 (15) ·
> FAZ-4 (11) · FAZ-5 (10) · **FAZ-6 (11)** = **117**

---

## 6. 🔴 Kalite kapısının bulduğu üç şey

`composer check` dört oturumdur ilk kez sonuna kadar koştu. Bulduğu her şey
öğretici:

### 6.1 Koddaki bir gerekçe yanlıştı (6.9)

`StoreUploadedMediaAction`'ın yorumu şöyle diyordu:

> *"`store()` geçici dosyayı TAŞIR; sonrasında `getMimeType()` var olmayan bir
> yolu okur."*

`vendor/`'a bakıldı: `FilesystemAdapter::putFileAs()` dosyayı **taşımıyor**,
`fopen()` ile stream olarak **kopyalıyor**. Sıra hâlâ doğru ama gerekçesi
başka (hata durumunda diske hiç dokunmamak).

**Ders 48**: *kodda verilen bir gerekçe, kaynakta karşılığı yoksa yalandır — ve
yanlış bir gerekçe, eksik bir gerekçeden tehlikelidir.* **B4**'ün ayna görüntüsü.

### 6.2 🔴 PHPStan 8, gerçek bir 500'ü yakaladı

```
app/Policies/RsvpPolicy.php:40  Parameter #2 expects Invitation, Invitation|null given.
```

Sebep: `Invitation` `SoftDeletes` kullanıyor. Davetiye silinince `rsvps`
satırları kalır ama `$rsvp->invitation` **`null`** döner → `InvitationPolicy`
`TypeError` fırlatır → **500**. Yani silinmiş davetiyenin LCV'sini silmeye
çalışan **sahip**, 404 yerine sunucu hatası görürdü.

**Ders 35** doğrulandı: *bir aracın çalıştığı, bir şeyi yakaladığını gördüğünde
bilinir.* Faz 5'in level 6→8 yükseltmesi bir tören değilmiş.

### 6.3 Flaky bir test dört fazdır bekliyordu

`touching_an_invitation_dispatches_the_change_event` kırıldı. Zincir:

```php
Model::save():  $saved = $this->isDirty() ? $this->performUpdate($query) : true;
Grammar::getDateFormat():  'Y-m-d H:i:s'      // ← mikrosaniye YOK
```

`create()` ve `touch()` aynı saniyeye düşünce `updated_at` **değişmemiş**
sayılıyor, `performUpdate()` çağrılmıyor, olay **hiç fırlamıyor** — ve `save()`
yine `true` dönüyor.

Kusur testin kurgusundaydı (üretimde model DB'den saniyeler önceki damgayla
gelir). `$this->travel(1)->second()` ile deterministik hâle getirildi.

**Ders 49**: *örtük bir zaman bağımlılığı, flaky bir testi "geçen test" gibi
gösterir.* **T12** bunu zaten yasaklıyordu ama zaman bir **girdi** olarak
görünmüyordu.

> ⚠️ **B6:** düzeltme testi kurtardı, altındaki gerçeği değil. `touch()` tabanlı
> cache invalidation **saniye altı çözünürlükte kör**. §9'a açık madde.

---

## 7. Plandan sapmalar

| # | Plandaki | Yapılan | Gerekçe |
|---|---|---|---|
| 1 | 8 adım (`docs/09`) | **24 adım** | Plan misafir yolunu ve LCV bağlantısını hesaba katmamıştı |
| 2 | `POST /api/media/upload` | `POST /api/invitations/{id}/media` + `/api/public/invitations/{id}/media` | Düz uçta aidiyet gövdeden gelirdi (**N1**). Frontend uyarlanacak (§8) |
| 3 | Tek `MediaController` | İki controller | **K12** grubu ayrı; fail-safe tasarım |
| 4 | (yok) | `ResolveOpenRsvpInvitationAction` + `SubmitRsvpAction` refactor'ü | **C3** — iki uç aynı üç koşulu istiyor |
| 5 | (yok) | `StoreGuestMediaAction` tür **değişmezi** | **A8** — doğrulama atlanabilir, değişmez atlanamaz |
| 6 | Yanıt `{url}` | `{id, url}` | Kimlik olmadan misafir medyayı LCV'ye bağlayamaz. **Süperset**, frontend kırılmaz |
| 7 | (belirtilmemiş) | Geçersiz kimlik **sessizce düşer** | **K59 / L6** |
| 8 | Faz 5'e ait değildi | 🟡 `RsvpPolicy` soft-delete düzeltmesi | PHPStan 8 bulgusu; Faz 5 koduna dokundu (§6.2) |
| 9 | Faz 5'e ait değildi | 🟡 `PublicInvitationTest` flaky test düzeltmesi | Faz 4 koduna dokundu (§6.3) |
| 10 | `CLAUDE.md` §1 *"controller'da `if` yok"* | 🟡 **Gevşetildi** | İsmail'in kararı. ⚠️ `CLAUDE.md` **henüz güncellenmedi** — §9 |

🟡 ile işaretli üçü gözden geçirilmeli. 8 ve 9 gerekliydi (zincir aksi hâlde
kırmızı kalırdı); 10 bir standart değişikliği ve `CLAUDE.md`'ye işlenmeli,
yoksa doküman kodla çelişir (**B4**).

### 🔴 Faz 5'ten devralınan üç 🟡 hâlâ açık

`FAZ-5.md` §7'deki `app/Contracts/` klasörü, `rsvps.id` ULID (K52) ve
`hash()` ↔ `hash_hmac()` kararları **hâlâ onay bekliyor**. Faz 6 bunlara
dokunmadı.

---

## 8. Frontend'e düşen iş

🔴 **Backend sözleşmesi değişti; frontend bugünkü hâliyle medya modülünde
çalışmaz.** Faz 3'ün F1-F8 ve Faz 5'in F1-F7 uyarlamalarıyla aynı sınıftan.

Sıra önemli — Faz 3'ün **32. dersi**: önce `types.ts` değişir ki TypeScript
kalan işi derleme hatası olarak **listelesin**.

| # | Dosya | Değişiklik |
|---|---|---|
| F1 | `src/types.ts` | `RSVPResponse`'a `photoUrl?`/`videoUrl?` **duruyor**; `RsvpCreatePayload` artık `photoMediaId?`/`videoMediaId?` göndermeli (`Omit` genişletilecek) |
| F2 | `src/types.ts` | `RsvpDraft` `photoUrl` **ve** `photoMediaId` tutmalı: biri önizleme, diğeri gönderim |
| F3 | `src/services/media.ts` | 🔴 `upload(file)` → `upload(invitationId, kind, file)`; uç `/invitations/{id}/media` veya `/public/invitations/{id}/media`; yanıt artık `{id, url}` |
| F4 | `src/stores/useRsvpStore.ts` | `attachDraftMedia()` hem `id` hem `url` saklamalı; `submitDraft()` `photoMediaId` göndermeli |
| F5 | `src/components/create/GalleryUploader.tsx` | 🔴 `recordId` gelene kadar yükleme **beklemeli** (yetim yükleme kararı) |
| F6 | `src/components/preview/RsvpModal.tsx` · `templates/shared/RSVPForm.tsx` | Yükleme çağrısına davetiye kimliği ve `kind` eklenmeli |

### 🔴 Faz 5'in honeypot borcu **hâlâ açık**

`FAZ-5.md` §8'deki honeypot alanı frontend'e eklenmediyse LCV savunmasının ilk
katmanı **hiç çalışmıyor** — ve hiçbir test bunu söylemez.

---

## 9. Hâlâ açık kalanlar

### 9.1 Faz 6'nın kendi kalan işi

- 🔴 **`composer check` 6.15'ten sonra hiç koşmadı** — [`FAZ-6-ELLE-DOGRULAMA.md`](FAZ-6-ELLE-DOGRULAMA.md)
- 🔴 `php artisan storage:link` **hiç çalıştırılmadı** — o olmadan hiçbir medya URL'i açılmaz
- 🔴 Frontend uyarlaması (§8)
- `CLAUDE.md` §1'in `if` kuralı gevşetildi ama **dosyaya işlenmedi** (B4)
- `claude/PHP-LARAVEL-SETUP.md`'ye K54-K63 ve F1-F5/L5-L6/A8/T17/E10/B8 işlenmeli

### 9.2 Sonraki fazlara

| Konu | Ne zaman | Not |
|---|---|---|
| 🔴 `invitations.timezone` (`event_at` + `rsvp_deadline`) | **Faz 7** | K63. Faz 4'ten **üçüncü** kez erteleniyor — yazılı madde hâline getirildi |
| 🔴 **Yetim medya temizliği** | Faz 7 veya 9 | Misafir yükleyip formu göndermezse dosya kalır. Kota sınırlar, temizlemez |
| 🔴 Yüklenenlerin web kökü altında durması | Faz 9 | K55. Bugünkü savunma MIME beyaz listesi — kurala bağlı, yapısal değil |
| `touch()` tabanlı cache invalidation saniye altı kör | Faz 7 | §6.3. Çözüm K48'i yeniden tartışmak demek |
| `DeleteInvitationAction` ve dosya temizliği | Faz 7 | Faz 3'ten ertelendi; artık gerçek bir iş kuralı var |
| `Jobs/SendRsvpNotification` | Faz 8 | K62 |
| `PublishInvitationAction` boş iskeleti | Faz 7 | Faz 5'ten devredildi |
| `routes/web.php` closure'ı (R1/R4) | Faz 9 | `route:cache` orada kırılır |
| Kuyruk işçisinin izolasyonu (GD zararlı görsel açıyor) | Faz 9 | 6.9 §14 |
| `hash_hmac` önerisi | `CLAUDE.md` §3 ile birlikte | Faz 5 §7 madde 8 |

---

## 10. Ortaya çıkan zincir

```
 1. public/index.php
 2. bootstrap/app.php
 3. [global middleware]        ← TrimStrings, ConvertEmptyStringsToNull
 4. Router::findRoute()        ← whereUlid: biçimsiz kimlik burada durur (O6)
 5. [ForceJsonResponse]        ← 🔴 M1: Content-Type'a DOKUNMAZ (multipart!)
 6. [throttle:api]             ← genel tavan
 7. [throttle:rsvp | media]    ← yazma uçlarında, ayrı kovalar (K61)
 8. [auth:sanctum]             ← yalnızca sahibin uçlarında
 9. [SetEtag]                  ← POST'ta erken döner
10. FormRequest                ← biçim + mimetypes (İÇERİKTEN) + honeypot olgusu
11. Controller                 ← 3-5 satır
12. Gate/Policy                ← sahibin uçlarında (P1/P5)
13. Action                     ← 🔴 katmanlı savunma
    ├─ ResolveOpenRsvpInvitationAction   ← görünürlük + modül + son tarih (C3)
    ├─ StoreGuestMediaAction             ← tür değişmezi (A8)
    └─ StoreUploadedMediaAction          ← kota (kilit) + rastgele ad + telafi
14. Storage                    ← diske yazma — TRANSACTION DIŞI (F3)
15. Model                      ← #[Fillable] beyaz listesi
16. Queue                      ← OptimizeUploadedImage (commit'ten SONRA)
17. Resource                   ← C1 beyaz listesi; URL türetilir (F5)
    │
    ├─ başarılı ────────────→ JSON
    └─ exception fırladı
         ↓
18. ApiExceptionRenderer       ← HasErrorCode kolu
19. ErrorCode                  ← status() + filterParams() (H9)
```

---

## 11. Faz 6 kapanış listesi

- [ ] `php artisan migrate` başarılı (iki FK oluştu)
- [ ] 🔴 `php artisan storage:link` çalıştırıldı
- [ ] `composer check` **son satırı** yeşil (fail-fast: ilk satıra bakma)
- [ ] `php artisan test --filter=MediaTest` → 28 test
- [ ] `php artisan test` → **123 test** (95 + 28)
- [ ] [`FAZ-6-ELLE-DOGRULAMA.md`](FAZ-6-ELLE-DOGRULAMA.md) tamamlandı
- [ ] Mutasyon tablosundan en az 5 satır denendi (**T16**)
- [ ] Frontend uyarlaması (§8) yapıldı ve editörden gerçek fotoğraf yüklendi
- [ ] `CLAUDE.md` §1'in `if` kuralı güncellendi (§9.1)
- [ ] Bu dosyanın **durum alanı** güncellendi (**B7**)

---

## 12. Bir cümlelik özet

Faz 6'da sisteme dosya kabul etmeyi öğrettik ve bunun bir doğrulama sorunu
değil bir **güven sınırı** sorunu olduğunu gördük; ama asıl öğrendiğimiz şey,
bir kuralı ikinci bir uç istediğinde **kopyalamanın değil çıkarmanın** doğru
hamle olduğu — ve dört fazdır koşmayan bir kalite kapısının ilk koşuşunda
gerçek bir 500'ü, yanlış bir yorumu ve dört fazdır uyuyan bir flaky testi
birlikte bulduğuydu.
