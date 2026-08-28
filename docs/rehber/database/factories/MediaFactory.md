# `database/factories/MediaFactory.php`

> **Kod dosyası:** `database/factories/MediaFactory.php`
> **Faz:** 6 — Medya dilimi, dosya 6.4
> **Kardeş dosyalar:** [`RsvpFactory.md`](RsvpFactory.md) · [`InvitationFactory.md`](InvitationFactory.md)

---

## 1. Neden fabrikayı **şimdi** yazıyoruz?

`Media` modelinin docblock'unda şu satır var:

```php
/** @use HasFactory<MediaFactory> */
```

Yani model, var olmayan bir sınıfa referans veriyordu. **Kural 10**:

> Her adım yeşil bitmeli: var olmayan sınıfa referans verme; bağımlılık sırası
> dosya sırasını belirler.

Faz 5'te bu kural ihlal edilmişti — `Rsvp` modeli 5.3'te `RsvpFactory`'ye
referans verdi ama fabrika 5.12'de yazıldı. Nihai durum doğruydu, ama aradaki
dokuz commit'te PHPStan çalıştırılsaydı "bilinmeyen sınıf" derdi.

Bu fazda sıra düzeltildi: model → fabrika → geri kalanı.

---

## 2. 🔴 Fabrika diske dosya YAZMAZ

Bu ayrımı baştan netleştirmek gerekiyor:

| İhtiyaç | Araç |
|---|---|
| `media` **satırı** (kota, ilişki, sızıntı testleri) | `Media::factory()` |
| Gerçek **dosya** (yükleme, MIME, boyut testleri) | `Storage::fake()` + `UploadedFile::fake()` |

Fabrikanın ürettiği satırın `path` alanı diskte **karşılığı olmayan** bir yolu
gösterir. Bu bir kusur değil, bilinçli bir tercih:

- Kota testi *"30 satır varsa 31.'yi reddet"* diyor — dosyaya ihtiyacı yok
- İlişki testi *"başkasının medyasını görme"* diyor — dosyaya ihtiyacı yok
- Her testte disk I/O ödemek suite'i yavaşlatır

> ⚠️ Bunun sonucu: fabrikayla üretilmiş bir kayıtta `$media->url()` çağırmak
> **var olmayan bir dosyaya işaret eden** bir URL üretir. Test onu indirmeye
> çalışmadığı sürece sorun yok — ama bilerek bilmek gerekiyor (**B6**).

---

## 3. Rastgelelik nerede serbest, nerede yasak?

| Alan | Değer | Neden |
|---|---|---|
| `path` | 🎲 **rastgele olmak ZORUNDA** | §4 |
| `invitation_id` | fabrika üretir | Verilmezse kendi davetiyesini yapar |
| **`kind`** | 🔒 sabit `Gallery` | Türe göre limit ve yetki değişir |
| **`size_bytes`** | 🔒 sabit `2048` | Boyut sınırı testlerinin konusu |
| **`mime_type`** | 🔒 sabit | `kind` ile tutarlı olmalı |
| `optimized_at` | 🔒 `null` | Kuyruk testinin başlangıç durumu |

Bu, `RsvpFactory`'de `guest_count` ve `status`'ün sabit tutulmasıyla aynı
gerekçe (**T12**: *ölçümü kararsız olan şey teste konmaz*).

---

## 4. `path` neden rastgele olmak **zorunda**?

```php
'path' => 'media/gallery/'.fake()->uuid().'.jpg',
```

Tabloda şu kısıt var (6.2):

```php
$table->unique(['disk', 'path']);
```

Sabit bir yol yazsaydık:

```php
'path' => 'media/gallery/test.jpg',        // ❌
```

...ikinci `Media::factory()->create()` çağrısı **`UNIQUE` ihlali** ile
patlardı. Ve hata mesajı *"duplicate key value violates unique constraint"*
derdi — yani belirtiyi söylerdi, sebebi değil (**kural 11**). Testi yazan kişi
bir saat kaybederdi.

🔴 Buradaki genel ders: **fabrikanın varsayılanları, tablonun kısıtlarıyla
uyumlu olmak zorundadır.** Bir kısıt eklediğinde fabrikayı da gözden geçir.

---

## 5. Durum metotları (state) — birlikte değişen alanlar

```php
public function rsvpVideo(): static
{
    return $this->state(fn (array $attributes): array => [
        'kind' => MediaKind::RsvpVideo,
        'mime_type' => 'video/mp4',
        'path' => 'media/rsvp_video/'.fake()->uuid().'.mp4',
        'size_bytes' => 1_048_576,
    ]);
}
```

🔴 Dikkat: **dört alan birlikte** değişiyor. Yalnızca `kind`'i değiştirseydik:

```php
kind = rsvp_video,  mime_type = image/jpeg,  path = .../x.jpg
```

Böyle bir satır gerçekte **asla oluşamaz** — `StoreUploadedMediaAction` MIME'i
dosyanın içeriğinden okuyor. Testler var olmayan bir durumu doğrulamış olurdu.

Bu, `InvitationFactory::published()`'ın `status` ve `published_at`'i **birlikte**
değiştirmesiyle aynı ilke: **bir state, tutarlı bir gerçekliği temsil eder.**

`sized(int $bytes)` ise okunurluk için: `->sized(5_000_000)` yazmak, o sayının
**testin konusu** olduğunu söyler.

---

## 6. `Config::string('davetkart.media.disk')` — neden sabit `'public'` değil?

```php
'disk' => Config::string('davetkart.media.disk'),
```

Testler `Storage::fake($disk)` ile o diski taklit ediyor. Fabrika farklı bir
disk adı yazsaydı, üretilen satır **taklit edilmemiş** bir diske işaret ederdi
ve `Storage::disk(...)->assertExists()` gibi doğrulamalar sessizce yanlış
çalışırdı.

Config'ten okumak, fabrikayı Action ile **aynı kaynağa** bağlıyor (C3).

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `path`'i sabit yapmak | İkinci kayıt `UNIQUE` ihlali; kafa karıştırıcı hata |
| 2 | `kind`'i rastgele yapmak | Kota ve yetki testleri flaky olur |
| 3 | State'te yalnızca `kind`'i değiştirmek | Gerçekte oluşamayacak satır; test yalan söyler |
| 4 | Fabrikanın dosya yazdığını sanmak | `assertExists()` beklenmedik şekilde kırılır |
| 5 | `definition()`'a `: array` dönüş tipi yazmak | Üst sınıfla çakışır (Faz 2, ders 19) |
| 6 | `disk`'i sabit `'public'` yazmak | `Storage::fake()` başka diski taklit ediyorsa doğrulamalar boşa çalışır |
| 7 | `->for($invitation)` yerine `['invitation_id' => …]` | Çalışır ama ilişkiyi kullanmayan alışkanlık üretir (N1) |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Models\{Invitation, Media};

$inv = Invitation::factory()->create();

Media::factory()->for($inv)->count(3)->create();
$inv->media()->count();                        // 3

// State'ler
Media::factory()->rsvpVideo()->make()->toArray();
// kind=rsvp_video, mime_type=video/mp4, path .mp4 ile bitiyor  ← TUTARLI

Media::factory()->sized(5_000_000)->make()->size_bytes;   // 5000000

// 🔴 UNIQUE kısıtı gerçekten çalışıyor mu?
$m = Media::factory()->for($inv)->create();
Media::factory()->for($inv)->create(['path' => $m->path, 'disk' => $m->disk]);
// QueryException: media_disk_path_unique   ← BEKLENEN
```

Sonuncusu **geçerse** kısıt oluşmamış demektir — 6.2'ye dön.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Factory** | Test/seed verisi üreten sınıf |
| **State** | Fabrikanın varsayılanlarını değiştiren adlandırılmış varyant |
| **`Storage::fake()`** | Diski bellekte taklit eden test yardımcısı |
| **`UploadedFile::fake()`** | Sahte yüklenmiş dosya üreten test yardımcısı |
| **Flaky test** | Kod değişmeden bazen geçen bazen kalan test |
| **Disk I/O** | Diske okuma/yazma maliyeti |

---

## 10. Sırada ne var?

**6.5 — Yeni hata kodu ve kota exception'ı.** `MEDIA_QUOTA_EXCEEDED` kodu
`ErrorCode` enum'una eklenecek, `contracts/error-codes.json` yeniden üretilecek
ve `MediaQuotaExceededException` Faz 5'te doğan `HasErrorCode` arayüzünü
uygulayacak.

| İlgili | Nerede |
|---|---|
| Model | [`../../app/Models/Media.md`](../../app/Models/Media.md) |
| Tablo | [`../migrations/2026_08_28_130000_create_media_table.md`](../migrations/2026_08_28_130000_create_media_table.md) |
| Kardeş fabrika | [`RsvpFactory.md`](RsvpFactory.md) |
