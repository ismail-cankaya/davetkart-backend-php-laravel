# `tests/Feature/MediaTest.php`

> **Faz:** 6 — Medya dilimi, dosya 6.22
> **28 test** · Kardeş: [`RsvpTest.md`](RsvpTest.md) · [`PublicInvitationTest.md`](PublicInvitationTest.md)

---

## 1. Bu testler neyi kanıtlıyor?

| Bölüm | Soru |
|---|---|
| Sahibin galerisi | Yükleyebiliyor mu, **başkasınınkine** yükleyemiyor mu (IDOR) |
| Dosya güvenliği | `.jpg` adlı PHP dosyası geçiyor mu, ad rastgele mi, boyut sınırı çalışıyor mu |
| Kota | `COUNT(*)` metriği, tür başına ayrım, **H9** (sınır kime söylenir) |
| Misafirin yolu | Token'sız yükleme, galeriye yazamama, görünürlük/modül/son tarih |
| LCV'ye bağlama | 🔴 **Başkasının medyası sessizce düşüyor mu** |
| Kuyruk | 15 saniye kuralı ve **T6** (yokluğun testi) |

---

## 2. 🔴 `Storage::fake()` — neden ve nasıl

```php
protected function setUp(): void
{
    parent::setUp();
    Storage::fake(Config::string('davetkart.media.disk'));
}
```

`Storage::fake()` gerçek diski, bellekte yaşayan bir taklitle değiştirir. Üç
şey kazandırıyor:

1. Testler **depoyu kirletmez** — gerçek `storage/app/public` altında dosya
   birikmez
2. Her test **temiz** başlar — `RefreshDatabase`'in dosya sistemi karşılığı
3. Testler **hızlı** — gerçek disk I/O yok

🔴 Disk adı `Config::string(...)` ile okunuyor, `'public'` yazılmıyor. Yarın
`DAVETKART_MEDIA_DISK` değiştiğinde testler **sessizce** gerçek diske yazmaya
başlamasın diye. Bir testin sabiti, ürünün yapılandırmasından türetilmelidir.

---

## 3. 🔴 T14 — bu dosyanın omurgası

**Üç** savunma bu fazda **ayırt edilemez yanıt** üretiyor:

| Savunma | Yanıt | Yanıt neyi kanıtlar |
|---|---|---|
| Başkasının medyası düşürülür | `201` | **Hiçbir şey** |
| Galeri fotoğrafı düşürülür | `201` | **Hiçbir şey** |
| Bilinmeyen kimlik düşürülür | `201` | **Hiçbir şey** |

`assertCreated()` yazan bir test, `resolveGuestMedia()` tamamen silinse de
**yeşil kalır**. Kanıtı testin taşıması gerekiyor:

```php
$this->assertDatabaseHas('rsvps', [
    'invitation_id' => $inv->id,
    'photo_media_id' => null,      // ← ASIL İDDİA
]);
```

**T14**: *bir işlemin yapılmadığını test ediyorsan yanıtı değil **etkiyi**
doğrula.* Faz 5'in honeypot testiyle birebir aynı durum (ders 44).

---

## 4. 🔴 En kritik üç test

### 4.1 `a_gallery_photo_cannot_be_attached_to_an_rsvp`

```php
$gallery = Media::factory()->create(['invitation_id' => $inv->id]);   // kind: gallery
// ... photoMediaId olarak gönder
$this->assertDatabaseHas('rsvps', ['photo_media_id' => null]);
```

Bu test `resolveGuestMedia()` içindeki **tür kontrolünü** koruyor:

```php
->where('kind', $kind)      // ← bu satır silinirse bu test kırılır
```

Neden kolay atlanır: galeri fotoğrafı **aynı davetiyeye ait**, yani ilk koşulu
(`$invitation->media()`) geçiyor. Yalnızca aidiyete bakan bir kontrol bunu
yakalayamaz — misafir, sahibin özel galeri görselini kendi yanıtına iliştirirdi.

### 4.2 `the_guest_action_refuses_owner_only_kinds`

```php
$this->expectException(LogicException::class);

app(StoreGuestMediaAction::class)->handle($inv->id, MediaKind::Gallery, $file);
```

🔴 Bu test **HTTP üzerinden yazılamaz.** `StorePublicMediaRequest` `kind=gallery`
isteğini 422 ile eler, yani Action'a **hiç ulaşmaz**. HTTP testi bu mutasyonu
**öldüremez**.

**T15**: *uçtan uca doğrulanamayan zincir halkalara ayrılır, her halka ayrı
test edilir.* Üstteki `guest_cannot_upload_to_the_gallery` testi FormRequest
halkasını, bu test Action halkasını kanıtlıyor. İkisi **aynı kuralı** farklı
katmanlarda koruyor — ve bu bir tekrar değil, katmanlı savunmanın testi.

### 4.3 `guest_cannot_upload_after_the_deadline`

Bu test 6.12 refactor'ünün **varlık sebebi**. Kural `SubmitRsvpAction`'dan
çıkarılıp ortak bir Action'a taşınmasaydı, medya ucunda kopyalanması gerekirdi
— ve en kolay unutulacak olan buydu. Sonuç: davetiye başına ~2.4 GB **süresiz**
yükleme.

---

## 5. Dosya güvenliği testleri

### 🔴 5.1 Sahte dosyanın MIME'i finfo'dan gelmez — ilk koşuda öğrenildi

İlk yazılan test şuydu ve **kırıldı**:

```php
UploadedFile::fake()->createWithContent('kotu.jpg', '<?php echo shell_exec($_GET["c"]); ?>')
// beklenen 422, gelen 201
```

Sebep `vendor/`'da:

```php
// Illuminate\Http\Testing\File::getMimeType()
return $this->mimeTypeToReport ?: MimeType::from($this->name);
```

Sahte dosya **`finfo`'ya hiç gitmiyor**: tipi ya açıkça rapor edilen değerden
ya da **dosya adından** üretiyor. `kotu.jpg` → `image/jpeg` → doğrulama geçti.

Üretimde ise `Illuminate\Http\UploadedFile` (Symfony türevi) devrede ve
`MimeTypes::guessMimeType()` gerçek baytlara bakıyor. Yani **kod doğru, test
altyapısı farklı bir sınıf kullanıyordu.**

🔴 Sonuç: **içerikten-MIME doğrulaması bu test altyapısıyla kanıtlanamaz**
(**T15**). Test, kanıtlayabildiği şeye göre yeniden yazıldı:

```php
UploadedFile::fake()->create('kotu.jpg', 10, 'application/x-php')
```

Ad `.jpg`, bildirilen tip `application/x-php`. Artık test **`mimetypes:` →
`mimes:` mutasyonunu öldürüyor** (uzantıya bakan kural bunu geçirirdi) — ama
finfo'nun çalıştığını iddia etmiyor.

Gerçek kanıt `FAZ-6-ELLE-DOGRULAMA.md` adım 9'da: diske gerçek bir PHP dosyası
`.jpg` adıyla yüklenmeye çalışılıyor.

**Ders 50**: *bir testin sahtesi, ürünün gerçeğinden farklı bir sınıf
kullanıyor olabilir. O zaman test, iddia ettiği şeyi değil sahtenin izin
verdiğini kanıtlar.*

### 5.2 `the_stored_filename_is_random`

```php
UploadedFile::fake()->image('../../gizli.jpg', 100, 100)
// →  media/gallery/aB3xK9...q7.jpg
```

Üç iddia: ad orijinali **taşımıyor**, `..` **yok**, ve yol beklenen klasörde.
`store()` içeride `Str::random(40)` + içerikten türetilen uzantı kullanıyor
(6.8 §9).

---

### 🔴 5.3 `fake()->create()` BOŞ bir dosya üretir

İkinci kırılan test:

```
SQLSTATE[23514]: new row for relation "media" violates check constraint "media_size_bytes_check"
Failing row contains (..., video/mp4, 0, ...)
```

Yine `vendor/`:

```php
// Illuminate\Http\Testing\FileFactory::create()
return tap(new File($name, tmpfile()), function ($file) use ($kilobytes, $mimeType) {
    $file->sizeToReport = $kilobytes * 1024;    // ← yalnızca RAPOR
});
```

`tmpfile()` **boş** bir geçici dosya. `$file->getSize()` 512 KB der ama
**diskteki dosya 0 bayt**.

🔴 Ve `StoreUploadedMediaAction` boyutu **diskten** okuyor:

```php
$media->size_bytes = Storage::disk($disk)->size($path);
```

6.9'daki gerekçe şuydu: *"`$file->getSize()` taşıma öncesi değeri verirdi;
ikisi normalde aynı ama **tek doğru kaynak disktir**."*

Test sahtesi o "normalde aynı"yı **ayrıştırdı** ve karar canlı bir kanıt
kazandı. `getSize()` kullansaydık test geçerdi — ve gerçek bir yarım kopyalama
sessizce `size_bytes = 512000` yazardı.

Düzeltme: `createWithContent()` gerçek bayt yazar.

> `fake()->image()` bu sorunu yaşamaz: GD ile **gerçek** bir görsel üretiyor.
> Yalnızca `create()` boş.

### 🔴 5.4 `create()` `#[Fillable]`'a tabidir, fabrikalar değil

Üçüncü kırılan test `$inv->rsvps()->create([... 'ip_hash' => ...])` yazıyordu.
`ip_hash` ve `photo_media_id` **bilerek** fillable değil (6.18) → sessizce
düştüler → `ip_hash` `NOT NULL` ihlali.

Fabrikalar bu sorunu yaşamaz:

```php
// Illuminate\Database\Eloquent\Factories\Factory::makeInstance()
return Model::unguarded(fn () => ...);
```

Yani `Media::factory()->create(['invitation_id' => ...])` çalışır ama **elle**
`create()` çağıran test çalışmaz. Düzeltme: `forceCreate()`.

**Ders 51**: *bir beyaz liste testte de geçerlidir. Fabrikanın çalışması,
`create()`'in çalışacağı anlamına gelmez.*

---

## 6. Kuyruk testleri — ve **T6**

```php
Queue::assertPushed(OptimizeUploadedImage::class);      // görsel
Queue::assertNotPushed(OptimizeUploadedImage::class);   // video
```

**T6**: *bir davranışın hem varlığı hem yokluğu test edilir.* Yalnızca
`assertPushed` yazsaydık, `isOptimizable()` metodu `true` döndürecek şekilde
bozulsa hiçbir test uyarmazdı — ve videolar GD ile açılmaya çalışılırdı.

`Queue::fake()` işi **gerçekten çalıştırmaz**, yalnızca kuyruğa girdiğini
kaydeder. Testin sorusu *"optimizasyon doğru mu?"* değil, *"ağır iş isteği
bekletiyor mu?"* — yani **15 saniye kuralı**.

---

## 7. 🔴 Mutasyon tablosu (T16 — faz kapanış ölçütü)

Her satır: *"bu korumayı boz, şu test kırılmalı."* Kırılmıyorsa test süs
demektir.

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 1 | `MediaController`'daki `Gate::authorize()` sil | `owner_cannot_upload_to_someone_elses_invitation` |
| 2 | `MediaRequest`'te `mimetypes:` → `mimes:` | `the_upload_is_validated_by_mime_not_extension` · ⚠️ finfo'nun çalıştığını **kanıtlamaz** (§5.1) |
| 3 | `$file->store(...)` → `storeAs(..., getClientOriginalName())` | `the_stored_filename_is_random` |
| 4 | `MediaRequest`'te `max:` kuralını sil | `an_oversized_file_is_rejected` |
| 5 | `assertQuotaAvailable()` (kilitli olan) sil | `the_gallery_quota_is_enforced` |
| 6 | `forGuest()` → `forOwner($limit)` | `the_guest_never_learns_the_quota` |
| 7 | Kota sorgusundan `where('kind', ...)` sil | `quotas_are_counted_per_kind` |
| 8 | `StorePublicMediaRequest::allowedKinds()` → tüm türler | `guest_cannot_upload_to_the_gallery` |
| 9 | `StoreGuestMediaAction`'daki tür `if`'ini sil | `the_guest_action_refuses_owner_only_kinds` — 🔴 **yalnızca bu** |
| 10 | `resolveOpenInvitation` → `resolvePublic` | `guest_cannot_upload_when_the_rsvp_module_is_closed` + `..._after_the_deadline` |
| 11 | `lessThan(now()->startOfDay())` → `isPast()` | `guest_can_upload_on_the_deadline_day` |
| 12 | `resolveGuestMedia()`'da `$invitation->media()` → `Media::query()` | `media_from_another_invitation_is_silently_dropped` |
| 13 | `resolveGuestMedia()`'da `where('kind', $kind)` sil | `a_gallery_photo_cannot_be_attached_to_an_rsvp` + `a_video_id_cannot_be_used_as_a_photo` |
| 14 | `resolveGuestMedia()` `null` yerine `throw` | `media_from_another_invitation_is_silently_dropped` (201 beklediği için) |
| 15 | `RsvpResource`'ta `whenNotNull` → düz değer | `an_rsvp_without_media_omits_the_url_keys` |
| 16 | `RsvpResource`'a `photoMediaId` ekle | `the_rsvp_response_never_exposes_media_ids` |
| 17 | `RsvpController`'daki `with([...])` sil | `the_owner_list_carries_media_urls` (yerelde `LazyLoadingViolationException`) |
| 18 | `MediaKind::isOptimizable()` video için `true` | `a_video_upload_does_not_queue_the_optimizer` |
| 19 | `OptimizeUploadedImage::dispatch()` sil | `an_image_upload_queues_the_optimizer` |
| 20 | `MediaResource`'a `path` ekle | ⚠️ **Hiçbiri** — bu tablonun kabul ettiği boşluk; `assertJsonStructure` fazladan alanı yakalamaz |

### 🔴 20 numaralı satır bilerek burada

`assertJsonStructure(['data' => ['id', 'url']])` **fazladan alan olmadığını**
doğrulamaz. Yani `MediaResource`'a `path` eklenirse hiçbir test kırılmaz ve iç
dizin düzeni sessizce sözleşmeye sızar.

Bunu yazmak, olmadığını sanmaktan iyidir. Kapatmanın yolu `assertExactJson`
ya da anahtar sayımı — ama o da her alan eklendiğinde testi kırar. **B6**:
bir savunmanın neyi kapatmadığı da yazılır.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `Storage::fake()` unutmak | Testler gerçek diske yazar; depo kirlenir, testler birbirini etkiler |
| 2 | Disk adını `'public'` diye sabitlemek | Config değişince testler sessizce gerçek diske yazar |
| 3 | Sessiz düşürmeyi `assertCreated()` ile test etmek | Savunma silinse de yeşil kalır (**T14**) |
| 4 | Action seviyesindeki tür guard'ını HTTP ile test etmek | FormRequest önce eler; mutasyon **öldürülemez** (**T15**) |
| 5 | `actingAs()` kullanmak | Guard atlanır, token yolu test edilmez (**T10**) |
| 6 | Kota testinde gerçek dosya yüklemek | Yavaş; `Media::factory()` satırı yeterli |
| 7 | `Queue::fake()` unutup optimizasyonu gerçekten çalıştırmak | GD'ye bağımlı, kırılgan test |
| 8 | 🔴 `fake()->create()` ile dosya yüklemek | Diskteki dosya **0 bayt**; `CHECK (size_bytes > 0)` patlar (§5.3) |
| 9 | 🔴 Sahte dosyayla içerikten-MIME test ettiğini sanmak | Sahte sınıf finfo'ya hiç gitmez (§5.1) |
| 10 | Testte `create()` ile fillable dışı alan yazmak | Alan sessizce düşer; `NOT NULL` ihlali (§5.4) |

---

## 9. Kendin dene

```powershell
php artisan test --filter=MediaTest
```

Bir mutasyonu elle dene:

```powershell
# app/Actions/Rsvp/SubmitRsvpAction.php -> resolveGuestMedia()
#   ->where('kind', $kind)      satırını sil
php artisan test --filter=MediaTest
# -> a_gallery_photo_cannot_be_attached_to_an_rsvp KIRILMALI
```

Kırılmıyorsa test o savunmayı korumuyor demektir — ve bunu bilmek, yeşil bir
listeye bakmaktan değerlidir.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Mutasyon testi** | Koda kasten bozukluk ekleyip hangi testin kırıldığına bakma yöntemi |
| **`Storage::fake()`** | Gerçek diski bellekte yaşayan bir taklitle değiştiren test yardımcısı |
| **`Queue::fake()`** | İşi çalıştırmadan, kuyruğa girdiğini kaydeden test yardımcısı |
| **IDOR** | Başkasının kaynağına kimliği değiştirerek erişme |
| **T14** | "Yapılmadığını test ediyorsan etkiyi doğrula" kuralı |
| **T15** | "Uçtan uca doğrulanamayan zincir halkalara ayrılır" kuralı |
