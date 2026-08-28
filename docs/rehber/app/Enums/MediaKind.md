# `app/Enums/MediaKind.php`

> **Kod dosyası:** `app/Enums/MediaKind.php`
> **Faz:** 6 — Medya dilimi, dosya 6.1
> **Birlikte değişen:** `config/davetkart.php` → `media.rsvp_photo/rsvp_video`
> **Kardeş dosyalar:** [`RsvpStatus.md`](RsvpStatus.md) · [`InvitationStatus.md`](InvitationStatus.md)

---

## 1. Bu dosya ne işe yarar?

Sisteme üç farklı sebeple dosya yükleniyor:

| Tür | Kim yükler | Ne | Boyut | Sayı sınırı |
|---|---|---|---|---|
| `gallery` | 🔒 Davetiye sahibi | Galeri fotoğrafı | 5 MB | 30 |
| `rsvp_photo` | 🌍 **Misafir** (auth yok) | LCV fotoğrafı | 2 MB | 200 |
| `rsvp_video` | 🌍 **Misafir** (auth yok) | LCV videosu | 20 MB | 100 |

Bu enum o üç türü **tipe dönüştürür** ve her türün sınırlarını sorulabilir hâle
getirir. Yani `'gallery'` bir metin parçası olmaktan çıkıp, *"kaç MB?"*,
*"misafir yükleyebilir mi?"*, *"optimize edilir mi?"* sorularını cevaplayan bir
nesneye dönüşür.

🔴 **Neden bu bir güvenlik sınırı?** Çünkü türü belirleyen şey, **kimin ne kadar
büyük dosya yükleyebileceğini** belirliyor. Misafirin `gallery` türünü seçebilmesi,
2 MB yerine 5 MB yükleyebilmesi demek olurdu.

---

## 2. 🔴 En önemli tasarım kararı: değer = config anahtarı

```php
case Gallery = 'gallery';
...
public function maxSizeKb(): int
{
    return Config::integer("davetkart.media.{$this->value}.max_size_kb");
}
```

Enum'un **değeri** doğrudan `config/davetkart.php` içindeki anahtar olarak
kullanılıyor:

```
MediaKind::Gallery->value  ===  'gallery'
                                   ↓
config('davetkart.media.gallery.max_size_kb')
```

Alternatifi şu olurdu:

```php
private function configKey(): string          // ❌ YAZMIYORUZ
{
    return match ($this) {
        self::Gallery => 'gallery',
        self::RsvpPhoto => 'rsvp_photo',
        ...
    };
}
```

Bu ikinci eşleme **hiçbir şey kazandırmaz ama bir risk ekler**: enum değeri ile
config anahtarı iki ayrı yerde tanımlı olur, biri değişince diğeri sessizce
eskir. **C3**'ün tanımı budur — *aynı sözleşmeyi üreten iki uç tek yerden
üretir.*

Kazancı somut: yarın `rsvp_audio` eklemek istersen **iki şey** yeterli — bir
`case` ve bir config bloğu. Üçüncü bir yeri güncellemeyi unutma ihtimali yok.

> ⚠️ Bedeli de var ve yazılmalı (**B6**): config'te o anahtar yoksa
> `Config::integer()` çalışma anında patlar. Bu **iyi** bir davranıştır —
> sessizce `0` dönüp sınırı kaldırmaktan iyidir — ama bir enum değeri
> eklerken config'i güncellemeyi unutursan hatayı testte görürsün, yazarken
> değil.

---

## 3. Sınırlar neden config'te, enum'da değil?

```php
// ❌ Böyle YAZMIYORUZ
public function maxSizeKb(): int
{
    return match ($this) {
        self::Gallery => 5120,
        ...
    };
}
```

**E6**: *veritabanı kısıtı (ve genel olarak kod) yalnızca backend'in sahibi
olduğu kurallara konur.* Dosya boyutu bir **iş tercihidir**:

- Yarın "Elit plan 20 MB galeri" denebilir
- Depolama maliyeti düşünce sınır artırılabilir
- Bir kampanya için geçici olarak gevşetilebilir

Bunların hiçbiri **kod değişikliği** gerektirmemeli. Aynı ilkeyi Faz 5'te
`max_guests_per_entry` ve `rsvp.rate_limit` için de uygulamıştık.

Enum'da duran şey ise gerçekten **koda ait**: hangi türü misafir yükleyebilir
(güvenlik sınırı), hangi tür optimize edilir (teknik yetenek).

---

## 4. `isGuestUploadable()` — misafir neyi yükleyebilir?

```php
return match ($this) {
    self::Gallery => false,
    self::RsvpPhoto, self::RsvpVideo => true,
};
```

Bu, Faz 5'te öğrendiğimiz dersin devamı: **auth'suz yazma yolu sistemin en
tehlikeli noktasıdır.** Misafirin `gallery` yükleyebilmesi iki şeyi birden
açardı:

1. Daha büyük dosya limiti (5 MB vs 2 MB)
2. Davetiye sahibinin galerisine **içerik enjekte etme** imkânı

`default` kolu **yok** — dördüncü bir tür eklendiği gün PHP burada
`UnhandledMatchError` fırlatır ve *"bunu misafir yükleyebilir mi?"* sorusunu
cevaplamaya zorlar. `default => false` yazsaydık yeni tür sessizce
misafire kapalı olurdu; `default => true` yazsaydık sessizce **açık**.
İkisi de kararı gizlerdi.

`guestUploadableValues()` ise bu metottan **türetilir**, elle yazılmaz:

```php
foreach (self::cases() as $case) {
    if ($case->isGuestUploadable()) {
        $values[] = $case->value;
    }
}
```

Public uç noktanın `in:` doğrulama kuralı bu listeden beslenecek (6.5). İki
ayrı liste olsaydı, biri değişip diğeri unutulduğunda misafir galeriye
yükleyebilirdi — ve bunu **hiçbir test söylemezdi**, çünkü testler de listeye
bakardı.

---

## 5. `isOptimizable()` — ve bir savunmanın kapsamadığı şey

```php
self::Gallery, self::RsvpPhoto => true,
self::RsvpVideo => false,
```

Kuyruktaki `OptimizeUploadedImage` işi (6.10) yalnızca **görselleri** işler.

Video **transcode** etmek bambaška bir iştir: `ffmpeg` bağımlılığı, dakikalarca
süren işlem, ayrı bir kuyruk ve ayrı bir zaman aşımı politikası. Faz 6'nın
kapsamında değil.

🔴 **B6 gereği bu açıkça yazılıyor:** video yüklendiği gibi saklanır. 20 MB'lık
bir `.mov` dosyası misafirin telefonunda olduğu gibi indirilir. Yazılmasaydı
altı ay sonra biri *"medya optimize ediliyor, video da küçülüyordur"* derdi.

---

## 6. `max_per_invitation` — neden LCV türlerine de eklendi?

`config/davetkart.php` yalnızca `gallery` için bu sınırı tanımlıyordu. Faz 6'da
diğer ikisine de eklendi (200 ve 100).

Sebep **L3**, Faz 5'in kuralı:

> Hız sınırı ile kota birbirinin yerine geçmez. Biri *sıklığa*, diğeri *hacme*
> bakar.

LCV medyasını **kimliği bilinmeyen misafir** yüklüyor. Hız sınırı dakikada kaç
istek atabileceğini sınırlar — ama günlerce, yavaşça yükleyen biri diski yine
doldurur. Üstelik bir tuzak daha var:

> Misafir dosyayı **LCV formunu göndermeden önce** yüklüyor
> (`useRsvpStore.attachDraftMedia`). Yani formu hiç göndermeden yüklenen
> **"yetim" dosyalar** mümkün. Kota olmasa bunlar sınırsız birikirdi.

Sayılar (200/100) cömert ama **sonlu**. Bir sınırın var olması, doğru
ayarlanmasından önce gelir.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Boyut/MIME sınırlarını enum'a gömmek | Fiyat veya politika değişikliği kod değişikliği ister (E6) |
| 2 | `configKey()` diye ikinci bir eşleme yazmak | İki isim, biri eskir (C3) |
| 3 | `match`'e `default` eklemek | Yeni tür sessizce misafire açık/kapalı olur |
| 4 | `guestUploadableValues()`'ı elle yazmak | İki liste ayrışır ve hiçbir test söylemez |
| 5 | `MediaKind::from($girdi)` kullanmak | Geçersiz değerde `ValueError` → 500. Kullanıcı girdisinde `tryFrom()` + `in:` kuralı |
| 6 | `max_per_invitation`'ı yalnızca galeriye koymak | Misafirin yetim yüklemeleri sınırsız birikir |
| 7 | Video için de optimizasyon varsaymak | B6: yazılmayan sınır, abartılı güven üretir |

---

## 8. Kendin dene

```php
// php artisan tinker
use App\Enums\MediaKind;

MediaKind::cases();
MediaKind::Gallery->value;                    // 'gallery'

// Sınırlar config'ten geliyor mu?
MediaKind::Gallery->maxSizeKb();              // 5120
MediaKind::RsvpVideo->maxSizeKb();            // 20480
MediaKind::RsvpPhoto->allowedMimeTypes();     // ['image/jpeg','image/png','image/webp']

// Güvenlik sınırı
MediaKind::Gallery->isGuestUploadable();      // false
MediaKind::guestUploadableValues();           // ['rsvp_photo','rsvp_video']

// Optimizasyon kapsamı
MediaKind::RsvpVideo->isOptimizable();        // false

// 🔴 Bağlamanın kanıtı: config'i değiştir, enum'un cevabı değişsin
config(['davetkart.media.gallery.max_size_kb' => 1]);
MediaKind::Gallery->maxSizeKb();              // 1
```

**Mutasyon denemesi (kural 14):** `isGuestUploadable()` içinde `self::Gallery`
kolunu `true` yap ve `php artisan test --filter=MediaTest` koştur.
`guest_cannot_upload_gallery_media` testi kırılmalı. Kırılmıyorsa test türü
gerçekten doğrulamıyordur.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Backed enum** | Her case'in skaler değeri olan enum |
| **MIME tipi** | Dosyanın içerik türü (`image/jpeg`); uzantıdan bağımsızdır |
| **Transcode** | Videoyu başka bir codec/çözünürlüğe dönüştürme |
| **Yetim (orphan) dosya** | Yüklenmiş ama hiçbir kayda bağlanmamış dosya |
| **Kota** | Adet/hacim üst sınırı — hız sınırından farklıdır |
| **`UnhandledMatchError`** | `match` hiçbir kola uymadığında fırlayan hata |

---

## 10. Sırada ne var?

**6.2 — `media` tablosunun migration'ı.** Bu enum'un `values()` metodu orada
`CHECK (kind IN (...))` kısıtını üretecek — `RsvpStatus` ile birebir aynı desen.

| İlgili | Nerede |
|---|---|
| Plan tanımları | [`../../config/davetkart.md`](../../config/davetkart.md) |
| Kardeş enum | [`RsvpStatus.md`](RsvpStatus.md) |
| Faz özeti | [`../../fazlar/FAZ-6.md`](../../fazlar/FAZ-6.md) |
