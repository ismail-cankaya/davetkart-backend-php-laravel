# `PHP-LARAVEL-SETUP.md` — Faz 6 eki

> **Bu bir yama dosyasıdır.** Aşağıdaki bloklar master
> `claude/PHP-LARAVEL-SETUP.md` dosyasının ilgili bölümlerine **elle** eklenecek.
>
> 🔴 `PHP-LARAVEL-SETUP-EK-FAZ-5.md` de hâlâ işlenmemiş olabilir — önce onu
> kontrol et. İki ek dosya birlikte işlenmeli.

---

## A) §7 Karar tablosuna eklenecek satırlar (K54–K63)

| # | Karar | Gerekçe |
|---|---|---|
| **K54** | `media.disk` **kolonda saklanır**, `config()`'ten okunmaz | Config *"şu an nereye yazıyoruz"*, kolon *"o dosya nereye yazılmıştı"* sorusunu cevaplar. Yerel diskten S3'e geçiş eski satırları **kırmaz** |
| **K55** | Depolama **yerel `public` diski** kalır; S3/R2 ertelendi | Kapsam kararı (İsmail onayladı). `media.disk` göçü zaten ucuzlatıyor. ⚠️ Dosyalar bugün `storage:link` ile **web kök dizini altında** — Faz 9 borcu |
| **K56** | `media.id` = **ULID** | K40/K52'nin doğrudan uygulaması: kimlik hem URL'de hem LCV gövdesinde geçiyor. Artan bigint platformdaki toplam dosya sayısını ele verirdi |
| **K57** | Medya **polimorfik değil**; `invitation_id` + `kind` ile modellendi | Laravel'in `morphTo`'su cazip ama **yabancı anahtar kısıtı kurdurmaz** — E2 buna karşı bir argüman. `kind` zaten türü taşıyor |
| **K58** | LCV medyası **kimlikle** iliştirilir, URL ile değil | Bir URL doğrulanamaz. Kimlik *"bu medya bu davetiyeye mi ait?"* sorusunu sordurur (**N1**) |
| **K59** | Geçersiz medya kimliği **sessizce düşürülür**, reddedilmez | `403` dönmek kimliğin **gerçek** olduğunu doğrular ve `media` tablosunu ULID uzayından taranabilir yapar (docs/08 §3.2'nin bir adım ilerisi) |
| **K60** | `rsvps` medya FK'leri **`nullOnDelete`** | Misafirin yazdığı metin, eklediği fotoğraftan **bağımsız** bir veridir. `cascade` yazsaydık bir temizlik işi sessizce LCV kayıtlarını götürebilirdi |
| **K61** | Misafirin medya ucu **ayrı bir throttle kovası** alır (`throttle:media`) | Honeypot katmanı yok + istek başına maliyet on kat. Aynı kovayı paylaşmak sınırları birbirine karıştırırdı (ders 25) |
| **K62** | `Jobs/SendRsvpNotification` **Faz 8'e** kaldı | K53 doğrulandı: bildirim kanalı hâlâ tasarlanmadı (İsmail onayladı) |
| **K63** | `invitations.timezone` **Faz 7'ye** ertelendi | Davetiye şemasına ait, medya modülüyle bağı yok; frontend'de tarih arayüzü de değişmeli (İsmail onayladı). ⚠️ Faz 4'ten **üçüncü** erteleme |

---

## B) Kural listesine eklenecekler (Faz 6 · 11 kural)

### Yeni seri **F** — dosya kabul etme

| # | Kural | Gerekçe |
|---|---|---|
| **F1** | Dosya tipi **içerikten** doğrulanır (`mimetypes:`), uzantıdan değil | Uzantı kullanıcı girdisidir; `.jpg` adlı PHP dosyası yüklenebilir. `fileinfo` eklentisi şart |
| **F2** | Depolanan ad **sunucu üretir**; orijinal ad hiçbir yere yazılmaz | Path traversal ve üzerine yazma **yapısal olarak** imkânsızlaşır |
| **F3** | **Dosya sistemi transaction'a dâhil değildir** — geri alınamayan iş elle telafi edilir | `DB::transaction()` diski geri almaz. `try/catch` + `Storage::delete()` (compensating transaction) |
| **F4** | Depolama konumu **satırda** saklanır, config'ten okunmaz | Config bugünü, kolon geçmişi anlatır. Göç eski satırları kırmaz |
| **F5** | Sözleşme **URL** taşır, şema **kimlik** tutar | URL türetilebilir (E1); ham URL `APP_URL`'i veritabanına gömerdi |

### Mevcut serilere eklenenler

| # | Kural | Gerekçe |
|---|---|---|
| **L5** | İstemciden gelen bir **kimliğin aidiyeti**, doğrulama katmanında değil **Action'da** sorulur | FormRequest üst kaynağı henüz çözmemiştir; *"bu senin mi"* orada cevaplanamaz |
| **L6** | Geçersiz bir kimliğin reddi **sessizdir** | Reddin kendisi kimliğin gerçek olduğunu doğrular — L2'nin kimlik uzayındaki hâli |
| **A8** | Bir sınıfın **değişmezi (invariant)**, doğrulama katmanına bırakılmaz | Doğrulama HTTP'ye aittir ve **atlanabilir** (konsol, kuyruk, yeni uç); değişmez sınıfın tanımıdır |
| **T17** | Bir savunma **kendinden önceki katmanla** test edilemiyorsa, o katman **atlanarak** test edilir | FormRequest önce elediği için Action guard'ının HTTP testi mutasyonu **öldüremez** (T15'in uygulaması) |
| **E10** | Metriği **sınırın tanımı** belirler, alışkanlık değil | "Kaç dosya" `COUNT(*)`, "kaç misafir" `SUM(guest_count)` ister |
| **B8** | Bir kural **çıkarıldığında**, kılavuzundaki anlatımı da **taşınır** — iki yerde kalmaz | Bir kılavuz da bir doğruluk kaynağıdır |

> Kural sayıları: FAZ-0 (31) · FAZ-1 (19) · FAZ-2 (20) · FAZ-3 (15) ·
> FAZ-4 (11) · FAZ-5 (10) · **FAZ-6 (11)** = **117**

---

## C) Ders listesine eklenecekler (48–49)

**48. 🔴 Kodda verilen bir gerekçe, kaynakta karşılığı yoksa yalandır — ve
yanlış bir gerekçe, eksik bir gerekçeden tehlikelidir.**
`StoreUploadedMediaAction`'ın yorumu *"`store()` geçici dosyayı taşır"* diyordu.
`vendor/` okundu: `FilesystemAdapter::putFileAs()` dosyayı **taşımıyor**,
stream olarak **kopyalıyor**. Sıra hâlâ doğruydu ama sebebi başkaydı. **B4**'ün
ayna görüntüsü: sonraki geliştirici yanlış gerekçeye dayanarak karar verir.

**49. Örtük bir zaman bağımlılığı, flaky bir testi "geçen test" gibi gösterir.**
`touching_an_invitation_dispatches_the_change_event` Faz 4'ten beri kırılgandı.
Zincir: `Grammar::getDateFormat()` `'Y-m-d H:i:s'` döndürüyor (mikrosaniye yok)
→ aynı saniyede `touch()` `isDirty()` `false` görüyor → `performUpdate()`
çağrılmıyor → olay **hiç fırlamıyor** → `save()` yine `true` dönüyor. **T12**
bunu zaten yasaklıyordu ama zaman bir **girdi** olarak görünmüyordu.

---

## D) Doküman haritasına eklenecek satırlar

| Dosya | İçerik |
|---|---|
| `docs/rehber/fazlar/FAZ-6.md` | Faz 6 kaydı — ⚠️ durum: 6.15+ DOĞRULANMADI |
| `docs/rehber/fazlar/FAZ-6-ELLE-DOGRULAMA.md` | 18 adımlık kapanış betiği |
| `docs/rehber/app/Enums/MediaKind.md` | Tür → config anahtarı bağlaması |
| `docs/rehber/app/Models/Media.md` | Boş `#[Fillable]`, `url()` metodu |
| `docs/rehber/app/Exceptions/MediaQuotaExceededException.md` | H9 sınıfın şekliyle |
| `docs/rehber/app/Http/Requests/Media/*.md` | Üç dosya — `mimetypes:` ve en az ayrıcalık |
| `docs/rehber/app/Jobs/OptimizeUploadedImage.md` | 15 saniye kuralı, veriyle idempotans |
| `docs/rehber/app/Actions/Media/StoreUploadedMediaAction.md` | 🔴 F1-F4'ün tamamı |
| `docs/rehber/app/Actions/Media/StoreGuestMediaAction.md` | A8 — değişmez ile doğrulama farkı |
| `docs/rehber/app/Actions/Rsvp/ResolveOpenRsvpInvitationAction.md` | C3 — bir kuralın çıkarılması |
| `docs/rehber/app/Http/Resources/MediaResource.md` | İki alanlık sözleşme |
| `docs/rehber/app/Http/Controllers/Api/V1/MediaController.md` | M1'in karşılığını bulduğu yer |
| `docs/rehber/app/Http/Controllers/Api/V1/PublicMediaController.md` | P5 — sahipsiz kaynak |
| `docs/rehber/database/migrations/2026_08_28_130000_create_media_table.md` | ULID PK, `disk` kolonu |
| `docs/rehber/database/migrations/2026_08_29_100000_add_media_columns_to_rsvps_table.md` | `nullOnDelete` gerekçesi |
| `docs/rehber/tests/Feature/MediaTest.md` | 🔴 20 satırlık mutasyon tablosu |

---

## E) "Teknik durum" bölümüne yazılacak

```
Faz 0-4 : ✅ tamamlandı ve doğrulandı
Faz 5   : ⚠️ KOD TAMAMLANDI (17 adım) · composer check Faz 6'da koştu ve
          YEŞİL bitti · elle doğrulama (16 adım) HÂLÂ AÇIK
Faz 6   : ⚠️ KOD TAMAMLANDI (24 adım) · 6.1-6.14 doğrulandı ·
          6.15-6.24 composer check HİÇ KOŞMADI
          kapanış ölçütü: FAZ-6-ELLE-DOGRULAMA.md (18 adım)
Faz 7   : ⬜ sıradaki (Ödeme ve paywall)

Uç nokta sayısı : 15
Test sayısı     : 123 (95 doğrulanmış + 28 doğrulanmamış)
PHPStan level   : 8  (Faz 6'da ilk kez gerçekten koştu — gerçek bir 500 buldu)
Kural sayısı    : 117
Karar sayısı    : 63
Ders sayısı     : 49
```

---

## F) §12 "Bilinen Frontend Uyuşmazlıkları" tablosuna

| Konu | Backend | Frontend | Karar |
|---|---|---|---|
| ~~Medya yükleme rotası~~ | ~~`POST /api/media`~~ | ~~`POST /media/upload`~~ | 🔴 **İKİSİ DE GEÇERSİZ.** Uçlar iç içe kaynak oldu: `/api/invitations/{id}/media` ve `/api/public/invitations/{id}/media`. Düz uçta aidiyet gövdeden gelirdi (**N1**). Frontend uyarlanacak — `FAZ-6.md` §8 |
| LCV medyası | `photoMediaId` (ULID) bekler, `photoUrl` döner | `photoUrl` **gönderiyor** | 🔴 Frontend uyarlanacak: bir URL doğrulanamaz (**K58**) |
| Medya yanıtı | `{data: {id, url}}` | `{url}` bekliyor (`unwrapEnvelope` zarfı açıyor) | ✅ Süperset — `toHostedUrl()` kırılmaz, ama `id` kullanılmalı |
