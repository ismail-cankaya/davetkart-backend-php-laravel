# `app/Actions/Media/StoreUploadedMediaAction.php`

> **Kod dosyası:** `app/Actions/Media/StoreUploadedMediaAction.php`
> **Faz:** 6 — Medya dilimi, dosya 6.8
> **Bu fazın kalbi.** Sisteme giren her dosya buradan geçer.
> **Kardeş dosyalar:** [`../Rsvp/SubmitRsvpAction.md`](../Rsvp/SubmitRsvpAction.md) ·
> [`../Invitation/CreateInvitationAction.md`](../Invitation/CreateInvitationAction.md)
> **Birlikte okunur:** [`../../Enums/MediaKind.md`](../../Enums/MediaKind.md) ·
> [`../../Models/Media.md`](../../Models/Media.md) ·
> [`../../Jobs/OptimizeUploadedImage.md`](../../Jobs/OptimizeUploadedImage.md)

---

## 1. Neden bu dosya özel?

Faz 5'in `SubmitRsvpAction`'ı özeldi çünkü **kimliği bilinmeyen biri** oraya
yazabiliyordu. Bu dosya başka bir sebeple özel: burada kullanıcı bir **string**
göndermiyor, bir **dosya** gönderiyor.

Aradaki fark şu tabloda görünür. Bir metin alanında doğrulaman gereken tek şey
vardır: değerin kendisi. Bir dosyada **beş ayrı şey** vardır ve **beşi de
kullanıcının kontrolündedir**:

| Ne | Kim belirliyor | Saldırı |
|---|---|---|
| Dosya **adı** | Kullanıcı | `../../.env` (path traversal), var olan dosyanın üzerine yazma |
| **Uzantı** | Kullanıcı | `kotu.php` — sunucuda çalışabilir |
| **Beyan edilen MIME** (`Content-Type`) | Kullanıcı (tarayıcı başlığı) | `image/jpeg` yazıp PHP kodu göndermek |
| **İçerik** | Kullanıcı | Zip bomb, hazırlanmış görsel (image parser açığı) |
| **Boyut** | Kullanıcı | Disk doldurma (DoS) |

🔴 Buradaki asıl ders şu: **"doğrulama" bir alan kontrolü değil, bir güven
sınırıdır.** Metin girdisinde sınır tek boyutludur; dosyada beş boyutlu.

Bu Action bu beş boyutun **üçünü** kapatıyor (ad, MIME, boyut/adet); kalan
ikisi başka katmanların işi ve §13'te açıkça yazılı — çünkü **bir savunmanın
neyi kapatmadığı da yazılır (B6)**.

---

## 2. Action'ın imzası

```php
public function handle(
    Invitation $invitation,
    MediaKind $kind,
    UploadedFile $file,
): Media
```

### PHP temeli: tip belirtimi (type declaration)

TypeScript'te `function handle(invitation: Invitation)` yazarsın ve tip
**derleme anında** kontrol edilir, çalışma anında yok olur. PHP'de tip
belirtimi **çalışma anında** da yaşar: yanlış tipte bir değer geçirilirse PHP
`TypeError` fırlatır.

Yani bu üç tip bir belge değil, bir **kapı**:

| Parametre | Ne garanti ediyor |
|---|---|
| `Invitation $invitation` | Bir **model nesnesi** geliyor — kimlik string'i değil. Yani birileri onu zaten **veritabanından çözmüş** ve (sahibin ucunda) yetkisini sormuş |
| `MediaKind $kind` | Bir **enum** geliyor — `'gallery'` string'i değil. Geçersiz bir tür buraya **ulaşamaz**, çünkü `MediaKind::from()` daha önce patlardı |
| `UploadedFile $file` | Gerçekten yüklenmiş bir dosya — `null` veya rastgele bir dizi değil |

`: Media` dönüş tipi de aynı işi ters yönde yapar: Action bir dizi veya bir
`bool` döndüremez, çünkü PHP kabul etmez.

> 💡 **Neden `string $kind` değil?** `CLAUDE.md` §1: *"sihirli string
> kullanılmamalıdır."* `string` olsaydı `'galery'` yazım hatası buraya kadar
> gelir ve `Config::integer("davetkart.media.galery.max_size_kb")` çalışma
> anında patlardı. Enum'la o hata **daha erken**, `MediaKind::from()`
> satırında patlar.

---

## 3. Katmanlı savunma — Faz 5'ten devralınan desen

Faz 5'te **L1** kuralını kurmuştuk: *savunma katmanları en ucuzdan pahalıya
sıralanır.* Aynı sıra burada da geçerli:

```
0. Hız sınırı       → rota katmanı (throttle)    ← Action'a HİÇ GELMEZ
1. Biçim / boyut    → FormRequest (mimetypes:)   ← Action'a HİÇ GELMEZ
─────────────────── Action burada başlıyor ───────────────────
2. Kota (ön kontrol) → diske yazmadan, ucuz sorgu
3. Kota (kesin)      → kilitli transaction içinde (E9)
4. Rastgele ad       → orijinal ad hiçbir yere yazılmaz
5. İçerikten MIME    → istemcinin beyanı SAKLANMAZ
```

🔴 Dikkat: **0 ve 1 numaralı katmanlar bu dosyada yok.** Bu bir eksiklik değil,
katman ayrımının kendisi. Action'a gelen veri `CLAUDE.md` §1 gereği "saf ve
güvenilir" kabul edilir — çünkü `MediaRequest` onu zaten süzdü.

---

## 4. 🔴 MIME neden en başta okunuyor? (ve koddaki yorum neden yanlış)

```php
$mimeType = $file->getMimeType();
```

Bu satır `store()` çağrısından **önce** duruyor. Koddaki yorum sebebi şöyle
açıklıyor:

> *"store() geçici dosyayı TAŞIR; sonrasında `$file->getMimeType()` artık var
> olmayan bir yolu okumaya çalışır."*

🔴 **Bu gerekçe yanlış.** Kural 11 diyordu ki: *tahmin yürütme, kaynağa bak —
`vendor/` okunabilir.* Baktık:

```php
// vendor/laravel/framework/src/Illuminate/Http/UploadedFile.php
public function store($path = '', $options = [])
{
    return $this->storeAs($path, $this->hashName(), $this->parseOptions($options));
}

// → FilesystemAdapter::putFileAs()
$stream = fopen(is_string($file) ? $file : $file->getRealPath(), 'r');
$result = $this->put($path = trim($path.'/'.$name, '/'), $stream, $options);
```

`putFileAs()` dosyayı **taşımıyor** — `fopen()` ile açıp **stream olarak
kopyalıyor**. Geçici dosya yerinde kalır (PHP onu isteğin sonunda kendisi
siler). Symfony'nin `UploadedFile::move()` metodu vardır ama Laravel'in
`store()`'u onu **kullanmaz**.

### O hâlde sıra neden hâlâ doğru?

Gerekçe değişti, sonuç değişmedi. Üç geçerli sebep var:

1. **Hata durumunda diske hiç dokunmamış oluruz.** `getMimeType()` `null`
   dönerse (veya `symfony/mime` eksikse `LogicException` fırlatırsa) exception
   burada patlar ve ortada temizlenecek bir dosya kalmaz. Sıra, telafi kodunu
   (§8) çalıştırmak zorunda kalmamamızı sağlar.
2. **`getMimeType()` bir disk okumasıdır** (`finfo` dosyanın ilk baytlarını
   okur). Bir kez okuyup değişkende tutmak, iki kez çağırmaktan ucuzdur.
3. **Geleceğe karşı sağlam.** Laravel bir gün `move()`'a dönerse — ki eski
   sürümlerde öyleydi — bu kod kırılmaz. Bu, "bugün doğru olduğu için değil,
   yarın da doğru kalacağı için" seçilen bir sıradır.

> 🔴 **Yapılacak iş:** koddaki yanlış yorum düzeltilecek. **B4** kuralı
> *"dokümanda verilen söz, kodda karşılığı yoksa yalandır"* diyordu; bu onun
> ayna görüntüsü — kodda verilen gerekçe kaynakta karşılığı yoksa yine yalandır.
> Ve yanlış bir gerekçe yanlış bir bilgiden **daha tehlikelidir**: sonraki
> geliştirici ona dayanarak karar verir.

### `getMimeType()` mi `getClientMimeType()` mi?

Symfony'de **iki ayrı metot** var ve aradaki fark bu fazın tamamının konusu:

```php
// vendor/symfony/http-foundation/File/File.php
public function getMimeType(): ?string
{
    return MimeTypes::getDefault()->guessMimeType($this->getPathname());
}

// vendor/symfony/http-foundation/File/UploadedFile.php
public function getClientMimeType(): string
{
    return $this->mimeType;   // ← tarayıcının GÖNDERDİĞİ başlık
}
```

| Metot | Kaynak | Güvenilir mi |
|---|---|---|
| `getMimeType()` | **Dosyanın içeriği** (`finfo` — magic bytes) | ✅ Kullandığımız |
| `getClientMimeType()` | İstemcinin **beyanı** | ❌ Kullanıcı girdisi |

Adının içinde `Client` geçen her şey kullanıcı girdisidir. Bu, Symfony'nin
API'sinde bilerek yapılmış bir isimlendirmedir — tehlikeli olanın adı, tehlikeli
olduğunu söyler.

### `null` gelirse neden `RuntimeException`?

```php
if ($mimeType === null) {
    throw new RuntimeException('Uploaded file has no detectable MIME type.');
}
```

Bu satıra **normalde hiç ulaşılmaz**: `MediaRequest`'in `mimetypes:` kuralı
tanınmayan bir dosyayı zaten 422 ile elerdi. Peki neden yazıldı?

Çünkü alternatifi `$mimeType ?? ''` yazıp devam etmekti — ve o zaman
veritabanına **boş bir `mime_type`** yazılırdı. Sessiz bir boş değer, altı ay
sonra "bazı dosyalar önizlemede açılmıyor" olarak geri gelir.

**Gürültülü patlamak, sessizce yanlış olmaktan iyidir.** `MediaRequest`'teki
`uploadedFile()` metodundaki `LogicException` da aynı refleksin ikizi.

---

## 5. Kota neden İKİ KEZ kontrol ediliyor?

```php
$this->assertQuotaAvailable($invitation, $kind);   // ① ucuz ön kontrol
$path = $file->store(...);                         // diske yaz
DB::transaction(function () { 
    $this->lockInvitation($invitation);            
    $this->assertQuotaAvailable($invitation, $kind); // ② kesin kontrol
    ...
});
```

İlk bakışta israf görünür. Değil — **ikisi farklı işler yapıyor**:

| | ① Ön kontrol | ② Kesin kontrol |
|---|---|---|
| Amacı | **Performans** | **Doğruluk** |
| Kilit | Yok | `lockForUpdate()` var |
| Ne kazandırıyor | Kota doluysa diske hiç yazmayız | Eşzamanlı iki yüklemenin ikisinin birden geçmesini engeller |
| Silinirse ne olur | Boşa yaz-sonra-sil döngüsü; **güvenlik kaybı YOK** | 🔴 Kota **aşılabilir** hâle gelir |

🔴 Bu ayrımı bilmek önemli, çünkü bir gün biri "aynı kontrol iki kez, birini
silelim" diyecek. **Silinecek olan ①'dir, ② değil.**

---

## 6. `COUNT(*)` mı `SUM()` mü? — ders 42'nin doğrudan uygulaması

Faz 5'te `PHP-LARAVEL-SETUP.md` §11'de kalın harflerle şu kural vardı:

> **LCV kotası `SUM(guest_count)` ile.** `COUNT(*)` kullanılırsa 100 kayıt ×
> 4 kişi = 400 misafir sızar.

Ve bu dosyada **`COUNT(*)` kullanıyoruz**:

```php
$used = $invitation->media()->where('kind', $kind)->count();
```

Çelişki değil. **Ders 42**: *bir kuralı uygulamak, gerekçesini kontrol etmeden
kopyalamak değildir.*

| | Faz 5 (LCV) | Faz 6 (medya) |
|---|---|---|
| Sınırın tanımı | "En fazla **100 misafir**" | "En fazla **30 dosya**" |
| Bir satır kaç birim taşır | `guest_count` kadar (1–10) | Her zaman **1** |
| Doğru metrik | `SUM(guest_count)` | `COUNT(*)` |

🔴 **Metriği alışkanlık değil, sınırın tanımı belirler.** Faz 5'in kuralını
buraya taşısaydık `SUM(size_bytes)` yazardık — ve o zaman kullanıcı 30 küçük
dosya yerine 3 büyük dosyayla kotayı doldurabilirdi. Sonucu taşımak, kuralı
taşımak değildir.

> Not: ileride "davetiye başına toplam **kaç megabayt**" diye bir sınır da
> istenirse, o **ikinci bir sınırdır** ve `SUM(size_bytes)` ile ölçülür.
> Birinin diğerinin yerine geçmemesi, **L3**'ün (hız sınırı ≠ kota) aynı
> ailesidir.

---

## 7. Satır kilidi — `lockForUpdate()`

```php
private function lockInvitation(Invitation $invitation): void
{
    Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();
}
```

### PHP/SQL temeli: kilit nedir?

`lockForUpdate()` SQL'e `SELECT ... FOR UPDATE` ekler. Bu, *"bu satırı
okuyorum ve değiştireceğim; transaction'ım bitene kadar başka kimse aynı satırı
`FOR UPDATE` ile okuyamasın"* demektir. İkinci istek o satırda **bekler**.

### Neden ÜST kayıt (davetiye) kilitleniyor?

Sezgi *"media satırlarını kilitleyelim"* der. İşe yaramaz:

PostgreSQL'in varsayılan yalıtım seviyesi **READ COMMITTED**'dır. Bu seviyede:

- Normal `SELECT`'ler birbirini **beklemez** — iki istek aynı anda "29 dosya
  var" görebilir, ikisi de "yer var" der, ikisi de yazar → **30 sınırı 31
  olur**. Bu, **E2**'nin adını koyduğu **check-then-act** yarış koşuludur.
- 🔴 **Var olmayan satır kilitlenemez.** Kotayı aşacak olan satır *henüz
  yoktur*; kilitlemek istediğin şey bir **yokluk**. (Buna *phantom read*
  denir.)

Kilitlenebilecek tek ortak nesne, iki isteğin de **paylaştığı** satırdır:
davetiyenin kendisi. Faz 5'in LCV kotasında birebir aynı desen kullanılmıştı
(**E9**).

### Dönen değer neden kullanılmıyor?

```php
Invitation::query()->whereKey(...)->lockForUpdate()->first();
//                                                  ↑ sonuç bir yere atanmıyor
```

Bu bir kod kokusu gibi görünür, değildir: **sorgunun kendisi yan etkidir.**
Kilidi alan şey `SELECT ... FOR UPDATE`'in veritabanında çalışmasıdır; dönen
model nesnesine ihtiyacımız yok, zaten elimizde taze olmayan bir kopyası var.

⚠️ `first()` yerine `exists()` yazılamaz: bazı sürücülerde `EXISTS` sorgusu
`FOR UPDATE` ile birleşmez. Değeri kullanmasak da satırı **gerçekten seçmek**
gerekiyor.

---

## 8. 🔴 Bu dosyanın en önemli bölümü: dosya sistemi transaction'a DAHİL DEĞİLDİR

```php
$path = $file->store('media/'.$kind->value, ['disk' => $disk]);  // ← DİSK

try {
    $media = DB::transaction(function () { ... });                // ← VERİTABANI
} catch (Throwable $e) {
    Storage::disk($disk)->delete($path);                          // ← TELAFİ
    throw $e;
}
```

### Problem

`DB::transaction()` bir **veritabanı** özelliğidir. Hata olursa PostgreSQL
yazdıklarını geri alır. Ama diskte oluşturduğun dosyayı **hiçbir veritabanı
geri almaz**. Dosya sistemi transactional değildir.

İki yönlü bir tutarsızlık riski var:

| Durum | Sonuç | Ne kadar kötü |
|---|---|---|
| Dosya var, satır **yok** | **Yetim dosya** — kimsenin bilmediği, kimsenin silmeyeceği bayt | Disk sessizce dolar |
| Satır var, dosya **yok** | **Kırık URL** — galeride kırık görsel | Kullanıcı hemen görür |

### Neden dosya önce yazılıyor?

Çünkü `size_bytes` **diskteki gerçek boyuttan** okunuyor
(`Storage::disk($disk)->size($path)`). Satırı önce yazsaydık, boyutu henüz
bilmiyor olurduk.

### Çözüm: telafi işlemi (compensating transaction)

`try/catch` bloğu, "geri alınamayan işi elle geri alma" desenidir. Adı
**compensating transaction**'dır ve dağıtık sistemlerde (Saga deseni) tam olarak
bunun için kullanılır.

```php
} catch (Throwable $e) {
    Storage::disk($disk)->delete($path);
    throw $e;     // ← 🔴 exception YUTULMUYOR, yeniden fırlatılıyor
}
```

🔴 `throw $e;` satırı kritik. Onsuz `handle()` bir `Media` döndürmek zorunda
kalırdı ama elinde yoktu — **ve daha kötüsü, hata sessizce kaybolurdu**. Burada
`catch` bloğunun işi hatayı *ele almak* değil, **temizlik yapıp yoluna devam
etmesine izin vermek**.

### PHP temeli: `Throwable` neden `Exception` değil?

PHP'de fırlatılabilen şeylerin hiyerarşisi şöyle:

```
Throwable (arayüz)
├── Exception   ← "beklenen" hatalar (RuntimeException, LogicException…)
└── Error       ← "programcı hatası" (TypeError, DivisionByZeroError…)
```

`catch (Exception $e)` yazsaydık bir `TypeError` **yakalanmazdı** ve dosya
diskte yetim kalırdı. Telafi kodu **her** hata için çalışmalı, yalnızca
öngördüklerimiz için değil.

### ⚠️ Bu savunmanın neyi KAPATMADIĞI (B6)

`catch` bloğu, PHP kodu çalışmaya devam ettiği sürece çalışır. Şu durumlarda
**çalışmaz** ve yetim dosya kalır:

- Süreç öldürülürse (`kill -9`, bellek limiti, sunucu çökmesi)
- Web sunucusu isteği zaman aşımına uğratırsa

Gerçek çözüm periyodik bir temizlik görevidir: *"`media` tablosunda karşılığı
olmayan dosyaları sil."* **Faz 6'da bu yok** — bilinçli bir eksik ve
`FAZ-6.md` §9'a yazılacak.

---

## 9. Rastgele dosya adı — `store()` gerçekte ne yapıyor?

```php
$path = $file->store('media/'.$kind->value, ['disk' => $disk]);
```

Kaynağa bakalım (kural 11):

```php
// Illuminate\Http\UploadedFile
public function store($path = '', $options = [])
{
    return $this->storeAs($path, $this->hashName(), $this->parseOptions($options));
}

// Illuminate\Http\FileHelpers
public function hashName($path = null)
{
    $hash = $this->hashName ?: $this->hashName = Str::random(40);

    if ($extension = $this->guessExtension()) {
        $extension = '.'.$extension;
    }

    return $path.$hash.$extension;
}
```

Üç şey oluyor:

| Adım | Sonuç |
|---|---|
| `Str::random(40)` | 40 karakterlik rastgele ad. Orijinal ad **hiçbir yere yazılmıyor** |
| `guessExtension()` | Uzantı **içerikten türetiliyor** — `getMimeType()`'ın ürettiği tipe karşılık gelen uzantı |
| Dönüş | `media/gallery/aB3x...9Kq.jpg` |

Yani:

- `../../.env` adlı bir dosya → adı kullanılmaz, **path traversal imkânsız**
- `kotu.php` adlı bir dosya → uzantı içerikten geldiği için `.php` olamaz
- İki kullanıcı aynı adlı dosya yüklerse → **üzerine yazma imkânsız**

Üstelik `media` tablosunda `UNIQUE(disk, path)` kısıtı var: rastgeleliğin
çarpışma ihtimali zaten yok denecek kadar küçük, kısıt onu **alışkanlığa değil
yapıya** bağlıyor (**E2**).

### 🔴 Ama bu neyi kapatmıyor? (B6)

Rastgele ad, **adın kullanıcıdan gelmediğini** garanti eder — dosyanın
**çalıştırılamayacağını değil**. Bir dosyanın sunucuda çalışıp çalışmaması
web sunucusu yapılandırmasına ve dosyanın **nerede durduğuna** bağlıdır.

Bugün `config('davetkart.media.disk')` = `public`, yani `storage:link` ile
dosyalar web kök dizininin altından servis ediliyor. Bugün bizi koruyan şey
MIME beyaz listesi ve içerikten türetilen uzantı — yani **kurala bağlı bir
savunma, yapısal bir savunma değil**. Nesne depolamaya (S3/R2) veya private
diske geçiş bilinçli olarak ertelendi; `media.disk` kolonu tam da o göçü
ucuzlatmak için saklanıyor.

---

## 10. `size_bytes` neden diskten okunuyor?

```php
$media->size_bytes = Storage::disk($disk)->size($path);
```

`$file->getSize()` de bir sayı verirdi ve **normalde ikisi aynı**. Ama:

- `getSize()` **geçici dosyanın** boyutudur — henüz kopyalanmadan önceki değer
- `Storage::size()` **kalıcı dosyanın** boyutudur — kaydettiğimiz şeyin gerçeği

Kopyalama yarım kalırsa ikisi ayrışır. Bu, **E1**'in ("türetilebilen veri
saklanmaz") kardeşi olan bir ilke: *ölçtüğün şey, ölçmek istediğin şeyin
kendisi olmalı.*

Ayrıca migration'da `CHECK (size_bytes > 0)` kısıtı var — sıfır baytlık bir
dosya "yükleme yarım kaldı" demektir ve veritabanı onu **kabul etmez**.

---

## 11. `#[Fillable([])]` ve açık atama

```php
$media = $invitation->media()->make();

$media->kind = $kind;
$media->disk = $disk;
$media->path = $path;
$media->mime_type = $mimeType;
$media->size_bytes = Storage::disk($disk)->size($path);

$media->save();
```

### PHP temeli: attribute (`#[...]`)

`#[Fillable([])]` bir **attribute**'tur — PHP 8 ile gelen, sınıfa/metoda
iliştirilen yapısal meta veri. TypeScript'teki decorator'a benzer, ama PHP'de
attribute **kendi başına hiçbir şey yapmaz**; onu okuyan bir kod (burada
Laravel'in model katmanı) olmak zorundadır.

### Neden liste boş?

Çünkü `media` tablosunda **istemcinin sahip olduğu tek bir alan yok**:

| Kolon | Kim belirliyor |
|---|---|
| `invitation_id` | İlişkiden gelir (`$invitation->media()->make()`) — **N1** |
| `kind` | Doğrulamadan geçse de **kararı Action verir** |
| `disk`, `path` | Sunucu |
| `mime_type` | Sunucu, dosyayı inceleyerek |
| `size_bytes` | Sunucu, diski ölçerek |

Boş `#[Fillable]` bir eksiklik değil, **bu tablonun en doğru ifadesi**. Biri
gelip `Media::create($request->all())` yazmaya çalışırsa **hiçbir alan
dolmaz** — yani yanlış kullanım sessizce çalışmaz, görünür şekilde başarısız
olur.

### `make()` neden `create()` değil?

`create()` nesneyi kurar **ve hemen kaydeder**. Biz araya beş atama koyacağız,
bu yüzden `make()` (kaydetmeyen sürüm) + sonunda `save()`.

Ayrıca Faz 4'ün **E7** dersi burada da geçerli: `create()` sonrası bellekteki
model, veritabanının doldurduğu varsayılan değerleri **geri okumaz**. Her alanı
açıkça yazmak o tuzağı tamamen ortadan kaldırıyor.

---

## 12. Kuyruk gönderimi neden commit'ten SONRA?

```php
});   // ← transaction burada kapandı (COMMIT)

if ($kind->isOptimizable()) {
    OptimizeUploadedImage::dispatch($media);
}
```

Bu, Faz 4'ün **O5** kuralının ("temizleme commit'ten sonra yapılır") medya
tarafındaki ikizi.

Transaction'ın **içinde** `dispatch` etseydik: kuyruk işçisi **ayrı bir
veritabanı bağlantısıdır**. İş, transaction daha commit olmadan alınabilir ve
`Media::find($id)` **hiçbir şey bulamaz** — çünkü satır henüz başka bağlantılar
için görünmüyor.

⚠️ Test ortamında (`QUEUE_CONNECTION=sync`) iş **anında ve aynı süreçte**
koşar. Bu yüzden sıra iki kat önemli: yanlış sıra, testte *farklı* bir şekilde
patlar ve yanıltıcı bir hata mesajı verir.

### "15 saniye kuralı"

`CLAUDE.md` §4: *"İsteğe hemen cevap verilmeli, uzun sürecek işlemler ana HTTP
sürecini bekletmemeli."* Frontend'in `api.ts` timeout'u **15 saniye**. Büyük
bir görseli yeniden kodlamak saniyeler sürer.

Bu yüzden akış şöyle: dosya diske yazılır → satır oluşur → **URL hemen döner** →
küçültme arkada olur. Kullanıcı önce büyük dosyayı görür, birkaç saniye sonra
küçüğünü — ama **hiçbir zaman beklemez**.

---

## 13. Bu Action'ın BİLMEDİĞİ şeyler

| Soru | Kim cevaplıyor |
|---|---|
| İstek yapan kim? | Sanctum + Controller |
| Bu davetiye onun mu? | `Gate::authorize()` + `InvitationPolicy` (**P1**) |
| Davetiye yayında mı? | `ResolvePublicInvitationAction` (misafir ucunda) |
| Dosya kabul edilebilir bir biçimde mi? | `MediaRequest` (`mimetypes:`, `max:`) |
| Bu kişi çok sık mı yüklüyor? | `throttle` middleware (**L3**) |
| Yanıt hangi HTTP koduyla dönecek? | Controller + `ApiExceptionRenderer` (**H10**) |
| Dosya içerikten gelen bir açık taşıyor mu? | 🔴 **Hiç kimse** — §14'e bak |

Bu tablo bir eksiklik listesi değil, **sorumluluk sınırı**. Action'ın bunları
bilmesi, `CLAUDE.md` §1'in yasakladığı "fat service"e giden ilk adım olurdu.

---

## 14. ⚠️ Faz 6'nın kapatmadığı iki şey (B6)

Dürüst olmak gerekirse §1'deki beş boyuttan ikisi hâlâ açık:

| Açık | Bugünkü durum | Gerçek çözüm |
|---|---|---|
| **Hazırlanmış görsel** (image parser açığı) | `OptimizeUploadedImage` işi GD ile dosyayı **çözüyor** — yani zararlı bir görsel PHP sürecinde açılıyor | Kuyruğu izole çalıştırmak; ImageMagick policy; Faz 9 |
| **Yetim dosya birikmesi** | §8'deki telafi süreç ölürse çalışmaz | Periyodik temizlik komutu |

Bunları yazmak, çözmek değil — ama **bilinmeyen bir açık, bilinen bir açıktan
tehlikelidir**.

---

## 15. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `getMimeType()` yerine `getClientMimeType()` kullanmak | Kullanıcının **beyanı** saklanır; `image/jpeg` etiketli bir PHP dosyası veritabanında meşru görünür |
| 2 | `mimetypes:` yerine `mimes:` yazmak | Kural **uzantıya** bakar — uzantıyı kullanıcı belirler, doğrulamanın tamamı boşa çıkar |
| 3 | Kota kontrolünü kilitsiz bırakmak | Eşzamanlı iki yükleme sınırı aşar (**E9**); tek kullanıcıda hiç görünmez, üretimde görünür |
| 4 | Diske yazmayı `DB::transaction()`'ın **içine** koymak | Rollback dosyayı geri almaz — sanılan güvenlik, gerçek yetim dosya |
| 5 | `catch (Exception $e)` yazmak | `TypeError` yakalanmaz, telafi çalışmaz (**Throwable** gerekli) |
| 6 | `catch` içinde `throw $e;` unutmak | Hata sessizce yutulur; Controller `null` görür → 500 |
| 7 | `dispatch()`'i transaction'ın **içine** koymak | İş, satırı henüz göremeden koşar |
| 8 | Faz 5'in `SUM()` kuralını körü körüne kopyalamak | Sınır "kaç dosya" iken "kaç bayt" ölçülür (**ders 42**) |
| 9 | `Media::create($request->validated())` yazmak | `#[Fillable]` boş — **hiçbir alan dolmaz**, satır CHECK kısıtında patlar |
| 10 | Ön kota kontrolünü "gereksiz" diye silmek yerine **kilitliyi** silmek | Sınır aşılabilir hâle gelir; hiçbir test bunu tek istekle söylemez |

---

## 16. Kendin dene

### Tinker ile

```php
// php artisan tinker
use App\Actions\Media\StoreUploadedMediaAction;
use App\Enums\MediaKind;
use App\Models\Invitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

Storage::fake('public');

$invitation = Invitation::query()->first();
$file = UploadedFile::fake()->image('tatil.jpg', 1200, 800);

$media = app(StoreUploadedMediaAction::class)
    ->handle($invitation, MediaKind::Gallery, $file);

$media->path;        // 'media/gallery/<40 rastgele karakter>.jpg'  ← orijinal ad YOK
$media->mime_type;   // 'image/jpeg'  ← içerikten
$media->disk;        // 'public'
$media->size_bytes;  // > 0
$media->url();       // türetilmiş URL — kolonda YOK

// Kota sınırını gör
MediaKind::Gallery->maxPerInvitation();   // 30
```

### 🔴 Mutasyon tablosu (kural 14)

Testler 6.15'te yazılacak; ama **testin ne kanıtlaması gerektiğini şimdi**
belirlemek zorundayız. Her satır: *"şu korumayı boz, şu test kırılmalı."*

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 1 | `getMimeType()` → `getClientMimeType()` | "saklanan MIME istemcinin beyanı değildir" |
| 2 | `$file->store(...)` → `storeAs(..., $file->getClientOriginalName())` | "diskteki ad orijinal adı içermez" |
| 3 | Kilitli `assertQuotaAvailable()` çağrısını sil | ⚠️ **Hiçbiri** — eşzamanlılık otomatik testle doğrulanamaz (**T15**). Elle doğrulamada adım olacak |
| 4 | Ön `assertQuotaAvailable()` çağrısını sil | ⚠️ **Hiçbiri** — ve olmamalı; bu bir performans optimizasyonu (§5) |
| 5 | `catch` bloğundaki `Storage::delete()` satırını sil | "veritabanı hatasında diskte dosya kalmaz" |
| 6 | `catch` bloğundaki `throw $e;` satırını sil | "kota dolduğunda 403 döner" (aksi hâlde 500) |
| 7 | `if ($kind->isOptimizable())` koşulunu kaldır | "video yüklendiğinde optimizasyon işi kuyruğa GİRMEZ" |
| 8 | `dispatch()`'i transaction'ın içine al | ⚠️ `sync` sürücüsünde kırılır, `database` sürücüsünde kırılmayabilir — **T15** ailesi |
| 9 | `size_bytes`'ı `$file->getSize()`'dan oku | ⚠️ Muhtemelen **hiçbiri** — bu bir doğruluk tercihi, gözlemlenebilir bir fark üretmiyor |
| 10 | `MediaQuotaExceededException::forOwner()` → `forGuest()` | "sahibe dönen yanıt `params.limit` içerir" |

🔴 3, 4, 8 ve 9 numaralı satırlar **bilerek** "hiçbir test kırılmaz" diyor. Bir
mutasyon tablosunun değeri, testin neyi yakaladığını göstermesi kadar **neyi
yakalayamadığını** göstermesindedir.

---

## 17. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **MIME type** | Bir dosyanın türünü bildiren standart etiket (`image/jpeg`) |
| **Magic bytes** | Dosyanın ilk baytları; biçimi ele veren imza. `finfo` bunu okur |
| **`finfo`** | PHP'nin dosya türü tahmin eklentisi (`fileinfo`) |
| **Path traversal** | `../` ile hedeflenen dizinin dışına çıkma saldırısı |
| **Check-then-act** | "Önce sor, sonra yap" — arada başkası araya girebilir (yarış koşulu) |
| **Yarış koşulu (race condition)** | Sonucun, iki işlemin zamanlamasına bağlı olması |
| **`FOR UPDATE`** | Satırı transaction boyunca kilitleyen SQL eki |
| **READ COMMITTED** | PostgreSQL'in varsayılan yalıtım seviyesi; yalnızca commit'lenmiş veriyi okur |
| **Phantom read** | Henüz var olmayan satırların araya girmesi; kilitle engellenemez |
| **Compensating transaction** | Geri alınamayan bir işi elle geri alan telafi kodu |
| **`Throwable`** | PHP'de fırlatılabilen her şeyin ortak arayüzü (`Exception` + `Error`) |
| **Attribute (`#[...]`)** | PHP 8 ile gelen yapısal meta veri |
| **Idempotans** | Bir işlemin birden çok kez çalışmasının tek kez çalışmasıyla aynı sonucu vermesi |
| **Yetim dosya** | Diskte duran ama veritabanında karşılığı olmayan dosya |

---

## 18. Sırada ne var?

**6.10 — `MediaResource`.** Bu Action'ın döndürdüğü `Media` modelinin
sözleşmeye nasıl çıkacağı. Orada iki soru var:

1. `disk` ve `path` **dışarı çıkmayacak** — depolama detayı sözleşme değildir
   (**C1**: Resource bir beyaz listedir).
2. Frontend `{ url }` bekliyor (`services/media.ts` → `toHostedUrl()`), ve URL
   kolonda **yok** — `Media::url()` metoduyla türetiliyor (**E1**).

| İlgili | Nerede |
|---|---|
| Tür enum'u | [`../../Enums/MediaKind.md`](../../Enums/MediaKind.md) |
| Model | [`../../Models/Media.md`](../../Models/Media.md) |
| Kota exception'ı | [`../../Exceptions/MediaQuotaExceededException.md`](../../Exceptions/MediaQuotaExceededException.md) |
| Kuyruk işi | [`../../Jobs/OptimizeUploadedImage.md`](../../Jobs/OptimizeUploadedImage.md) |
| Kardeş Action | [`../Rsvp/SubmitRsvpAction.md`](../Rsvp/SubmitRsvpAction.md) |
| Faz özeti | [`../../../fazlar/FAZ-6.md`](../../../fazlar/FAZ-6.md) |
