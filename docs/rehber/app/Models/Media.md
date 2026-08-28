# `app/Models/Media.php`

> **Kod dosyası:** `app/Models/Media.php`
> **Faz:** 6 — Medya dilimi, dosya 6.3
> **Birlikte değişen:** `app/Models/Invitation.php` → `media()` ilişkisi
> **Kardeş dosyalar:** [`Rsvp.md`](Rsvp.md) · [`TimelineEvent.md`](TimelineEvent.md)

---

## 1. 🔴 `#[Fillable([])]` — boş beyaz liste

```php
#[Fillable([])]
class Media extends Model
```

Projedeki dört modelin beyaz listelerini yan yana koy:

| Model | Doldurulabilir alan | Neden o kadar |
|---|---|---|
| `Invitation` | 21 | Kullanıcı tasarımını kendisi yazıyor |
| `Rsvp` | 5 | Misafir formu dolduruyor |
| `TimelineEvent` | 4 | Kullanıcı program adımını yazıyor |
| **`Media`** | **0** | 🔴 **İstemcinin sahip olduğu tek bir alan yok** |

Bu boş liste bir eksiklik değil, **bu tablonun en dürüst ifadesi**. Kolonlara
tek tek bak:

| Kolon | Kim belirliyor |
|---|---|
| `disk` | Sunucu — `config('davetkart.media.disk')` |
| `path` | Sunucu — rastgele ad üretiyor |
| `mime_type` | Sunucu — **dosyanın içeriğini okuyarak** |
| `size_bytes` | Sunucu — diskteki gerçek boyut |
| `invitation_id` | İlişkiden geliyor (`$invitation->media()->make(...)`) |
| `kind` | İstemci gönderiyor **ama kararı Action veriyor** |

Son satır önemli: `kind` doğrulamadan geçiyor (`in:gallery` veya
`in:rsvp_photo,rsvp_video`), ama onu modele **Action açıkça atıyor**. Neden
fillable yapmadık? Çünkü o zaman `make($request->validated())` gibi bir çağrı
yazılabilirdi ve gelecekte listeye bir alan eklendiğinde **sessizce** istemci
kontrolüne açılırdı.

Boş liste, o kapıyı **yapısal olarak** kapatıyor: bu modele hiçbir şey toplu
atamayla giremez.

> **S3** hatırlatması: `$guarded = []` yasak. Ama `$fillable = []` yasak değil —
> tam tersi, en katı hâli.

---

## 2. `protected $table = 'media';` — neden gerekli?

Laravel model adından tablo adını **tahmin eder**: `Media` → `medias`.

Yanlış tahmin. `media` zaten Latince `medium` kelimesinin çoğulu; İngilizce'de
de çoğul olarak kullanılıyor. Laravel'in `Str::plural()` metodu bu istisnayı
bilmiyor.

Bu satır olmasaydı hata şöyle görünürdü:

```
SQLSTATE[42P01]: Undefined table: relation "medias" does not exist
```

🔴 **Kural 11'in tipik örneği:** hata mesajı sana *belirtiyi* söylüyor
("medias yok"), *sebebi* değil ("Laravel çoğullama kuralı bu kelimeyi
bilmiyor"). Tablo adını elle yazmak, tahmini ortadan kaldırıyor.

---

## 3. 🔴 `url()` neden accessor değil, metot?

```php
public function url(): string
{
    return Storage::disk($this->disk)->url($this->path);
}
```

Laravel'in idiomatik yolu bir **accessor** olurdu:

```php
protected function url(): Attribute        // ❌ KULLANMIYORUZ
{
    return Attribute::get(fn () => Storage::disk($this->disk)->url($this->path));
}
```

O zaman `$media->url` yazılabilirdi — daha şık görünür. İki sebeple
yazmıyoruz:

**1. Accessor bir kolon gibi görünür.** `$media->url` yazan biri, `url` diye
bir kolon olduğunu sanır. Oysa o değer **türetiliyor** (E1). Metot çağrısı
(`$media->url()`) türetmeyi çağrı yerinde görünür kılar.

**2. Sızıntı yüzeyi.** Accessor'lar `$appends` ile veya bazı serileştirme
yollarında JSON çıktısına karışabilir. Bu modelde `disk` ve `path` gibi **iç
detaylar** var; Resource katmanı dışında hiçbir şeyin kendiliğinden dışarı
çıkmaması gerekiyor (**C1**).

**Diskin satırdan okunması** de bilinçli — 6.2 §4'te anlatıldı: `config(...)`
"şimdi nereye yazıyorum", `$this->disk` "o dosya nereye yazılmıştı".

---

## 4. `casts()` — üç dönüşüm, üç ayrı sebep

```php
'kind' => MediaKind::class,
'size_bytes' => 'integer',
'optimized_at' => 'immutable_datetime',
```

**`kind`:** Veritabanında `'gallery'` metni, PHP'de `MediaKind::Gallery` enum
örneği. Cast olmasaydı her karşılaştırmada sihirli string yazmak gerekirdi.

**`size_bytes`:** PostgreSQL sürücüsü `integer`'ı duruma göre `"5120"` (string)
döndürebilir. Faz 3'ün **29. dersi**: *tip belirsizliğini sınırda çöz.* Aynı
eksiklik `InvitationPolicy`'de `user_id` için olsaydı **hiç kimse kendi
davetiyesine erişemezdi**.

**`optimized_at`:** **K23** — tarihler `CarbonImmutable`. `->addDay()` orijinali
bozmaz, kopya döndürür.

---

## 5. `isOptimized()` — neden bir metot daha?

```php
public function isOptimized(): bool
{
    return $this->optimized_at !== null;
}
```

`$media->optimized_at !== null` yazmak da mümkün. Metot iki şey kazandırıyor:

1. **Niyeti isimlendiriyor.** `if ($media->isOptimized())` okunur;
   `if ($media->optimized_at !== null)` çözülür.
2. **Karşılaştırmayı tek yere topluyor.** Yarın "optimize sayılması için ayrıca
   `size_bytes` küçülmüş olmalı" dersek, tek yer değişir (C3).

Bu, `RsvpStatus::consumesQuota()` ile aynı fikir: **bir kural, bir ad, bir yer.**

---

## 6. `Invitation::media()` — sıralama neden yok?

```php
public function media(): HasMany
{
    return $this->hasMany(Media::class);
}
```

`timelineEvents()` ilişkisinde `->orderBy('sort_order')` vardı, çünkü sıra
**anlamın parçasıydı**. Burada yok — ve sebebi ilginç:

> Galerinin sırası `media` tablosunda **tutulmuyor.** Kullanıcının sürükleyip
> bıraktığı sıra `invitations.gallery_images` dizisinde (6.12). `media`
> satırları sunucunun kaydı: kota sayımı ve temizlik için.

Yani bu ilişki üzerinde anlamlı bir sıra **yok**. Olmayan bir sırayı uydurmak
(`created_at`'e göre sıralamak) galeriyi yanlış gösterirdi.

Kota sorgusu da hiç sıralamaz:

```php
$invitation->media()->where('kind', $kind)->count();
```

Gereksiz bir `ORDER BY` her kota kontrolüne maliyet eklerdi — Faz 5'te
`rsvps()` için verilen kararın aynısı.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `#[Fillable]`'a alan eklemek | İstemci kontrolüne bir kapı açılır; bu tabloda hiçbir alan buna aday değil |
| 2 | `$guarded = []` yazmak | **S3** ihlali |
| 3 | `protected $table` satırını silmek | `medias` tablosu aranır, "relation does not exist" |
| 4 | `url()`'i accessor yapmak | Türetilmiş değer kolon gibi görünür, JSON'a sızabilir |
| 5 | `Storage::disk(config(...))` kullanmak | Disk değiştiği gün eski dosyalar çözülemez |
| 6 | `size_bytes` cast'ini unutmak | `SUM` ve `===` sessizce yanlış çalışır |
| 7 | İlişkiye `->latest()` gömmek | Kota sorgusu da sıralar; ayrıca galeri sırası buradan gelmiyor |
| 8 | `Media::create([...])` ile doğrudan yazmak | **N1**: alt kayıt her zaman üstün ilişkisinden oluşturulur |

---

## 8. Kendin dene

```php
// php artisan tinker
$inv = App\Models\Invitation::first();

// 1) İlişki üzerinden oluşturma (N1) — alanlar AÇIKÇA atanıyor
$m = $inv->media()->make();
$m->kind = App\Enums\MediaKind::Gallery;
$m->disk = 'public';
$m->path = 'media/gallery/deneme.jpg';
$m->mime_type = 'image/jpeg';
$m->size_bytes = 2048;
$m->save();

$m->invitation_id === $inv->id;    // true — gövdeden değil ilişkiden
$m->kind;                          // MediaKind enum örneği
$m->size_bytes === 2048;           // true (cast çalışıyor)
$m->isOptimized();                 // false
$m->url();                         // http://localhost:8000/storage/media/...

// 2) 🔴 Boş beyaz listeyi delmeye çalış
$m->fill(['path' => 'hack/evil.php']);
$m->path;                          // DEĞİŞMEDİ
```

> ⚠️ Katı kip (`Model::shouldBeStrict()`) geliştirmede açık olduğu için,
> `fill()` sessizce atmak yerine **exception** fırlatabilir. Bu iyi bir şey:
> hatayı laptopta patlatır, üretimde değil (**S1**).

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Beyaz liste** | Varsayılan kapalı; yalnızca sayılanlar serbest |
| **Toplu atama** | Diziden birden çok alanı tek çağrıda doldurma |
| **Accessor** | Okuma anında değer türeten Eloquent özelliği |
| **Cast** | Veritabanı değerinin PHP tipine çevrilme kuralı |
| **Türetilmiş değer** | Saklanmayan, başka alanlardan hesaplanan değer |
| **Çoğullama (pluralization)** | Model adından tablo adı tahmini |

---

## 10. Sırada ne var?

**6.4 — `MediaFactory`.** Testler ve seeder için sahte medya kaydı. Kural 10
gereği fabrikayı **şimdi** yazıyoruz: `Media` modelinin `@use HasFactory<MediaFactory>`
docblock'u ona referans veriyor ve var olmayan bir sınıfa referans bırakmıyoruz.

| İlgili | Nerede |
|---|---|
| Tablo | [`../../database/migrations/2026_08_28_130000_create_media_table.md`](../../database/migrations/2026_08_28_130000_create_media_table.md) |
| Tür enum'u | [`../Enums/MediaKind.md`](../Enums/MediaKind.md) |
| Kardeş model | [`Rsvp.md`](Rsvp.md) |
