# `config/davetkart.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `davetkart-backend-php-laravel/config/davetkart.php`
> **Yol haritasındaki yeri:** Adım 2 (ilk dosya)
> **Ön koşul bilgi:** Yok. Bu doküman PHP'ye sıfırdan başlayan biri için yazıldı.

---

## 0. Bir dakikalık özet

Bu dosya, uygulamanın **iş sabitlerini** tutan bir *ayar defteri*dir. Plan fiyatları,
LCV kotası, dosya boyutu limitleri, cache süreleri burada yaşar. Hiç kod
çalıştırmaz — sadece bir **PHP dizisi döndürür**. Laravel bu diziyi açılışta okur,
hafızada tutar ve uygulamanın her yerinden `config('davetkart.tiers.gold.price')`
gibi bir çağrıyla erişilebilir hâle getirir.

Amaç tek cümleyle: **"Değişebilecek sayıları koddan ayır."**

---

## 1. PHP temelleri — dosyayı satır satır okumak

Frontend'den (TypeScript) geliyorsun, o yüzden karşılıklarıyla anlatıyorum.

### 1.1 `<?php`

```php
<?php
```

PHP dosyaları, "buradan itibarası PHP kodudur" anlamına gelen bu etiketle başlar.
PHP tarihsel olarak HTML'in içine gömülen bir dildi (`<p><?php echo $ad; ?></p>`),
bu yüzden böyle bir işarete ihtiyaç duyar.

**Önemli kural:** Sadece PHP içeren dosyalarda **kapanış etiketi `?>` yazılmaz.**
Yazarsan, etiketten sonraki görünmez bir boşluk/satır sonu tarayıcıya "çıktı" olarak
gider ve `Headers already sent` hatası alırsın. PSR-12 standardı da bunu yasaklar.

### 1.2 `declare(strict_types=1);`

```php
declare(strict_types=1);
```

PHP varsayılan olarak **gevşek tiplidir**: bir fonksiyon `int` beklerken `"5"`
(string) gönderirsen PHP bunu sessizce `5`'e çevirir. Kulağa pratik gelir ama
hataları saklar: `"5 adet"` gönderirsen o da `5` olur.

`strict_types=1`, bu otomatik dönüşümü **kapatır**. Yanlış tip gönderirsen
`TypeError` fırlar. TypeScript'teki `strict: true`'nun karşılığıdır.

Bu satır **dosya bazlıdır** — her yeni PHP dosyasının başına yazacağız. Projede
kural: istisnasız her dosyada bulunur.

### 1.3 `return [ ... ];`

```php
return [
    'currency' => 'TRY',
];
```

Bu dosyanın **tek işi** budur: bir dizi döndürmek.

- `[ ... ]` → PHP'nin dizi (array) sözdizimi. JS'teki `[]` ve `{}` bunun *ikisi
  birden*dir; PHP'de tek bir `array` tipi hem liste hem sözlük görevi görür.
- `'anahtar' => 'değer'` → JS'te `{ anahtar: 'değer' }` demek. PHP'de iki nokta
  yerine **ok işareti** (`=>`) kullanılır.
- Sonda `;` var çünkü `return` bir **deyimdir** (statement), her deyim noktalı
  virgülle biter.
- Son elemandan sonraki virgül (*trailing comma*) serbesttir ve **tercih edilir**:
  yeni satır eklerken git diff'inde tek satır değişir.

**"Bir dosya nasıl değer döndürür?"** PHP'de `include`/`require` ile çağrılan bir
dosya, içindeki `return` ifadesinin sonucunu çağırana verir. Laravel tam olarak
bunu yapar:

```php
$ayarlar = require 'config/davetkart.php';   // $ayarlar artık o dizi
```

### 1.4 Tek tırnak vs çift tırnak

```php
'TRY'        // tek tırnak: içeriği aynen alınır, hızlıdır
"Merhaba $ad" // çift tırnak: içindeki değişkeni yerine koyar
```

Değişken enterpolasyonuna ihtiyaç yoksa **tek tırnak** kullanılır. Bu dosyada
hepsi tek tırnak.

### 1.5 İç içe diziler

```php
'tiers' => [
    'standart' => ['rank' => 0, 'price' => 249, 'rsvp_limit' => 100],
],
```

Dizinin değeri yine bir dizi olabilir. Burada `tiers` → `standart` → `price`
şeklinde üç seviyeli bir ağaç kurduk. Laravel bu ağaçta **nokta notasyonuyla**
gezinmemize izin verecek: `config('davetkart.tiers.standart.price')`.

### 1.6 `null` ne demek?

```php
'rsvp_limit' => null,
```

`null` = "değer yok". `0` ile karıştırma: `0` bir sayıdır ve "sıfır misafir"
anlamına gelirdi. Biz "sınırsız" demek istiyoruz, yani *limit diye bir şey yok* →
`null`. Kodda kontrolü şöyle olacak:

```php
if ($limit !== null && $toplam > $limit) { /* kota aşıldı */ }
```

### 1.7 `60 * 60 * 6`

```php
'public_invitation_ttl' => 60 * 60 * 6, // saniye
```

PHP bu çarpımı dosya okunurken **bir kez** hesaplar; sonuç `21600`. Neden doğrudan
`21600` yazmadık? Çünkü `60 * 60 * 6` okurken "6 saat" diye okunur, `21600` okunmaz.
Bu, **kendini belgeleyen kod** (self-documenting code) örneğidir.

---

## 2. Laravel tarafı — bu dosya neden `config/` klasöründe?

### 2.1 `config/` klasörü nedir?

Laravel açılırken `config/` içindeki **her `.php` dosyasını** okur ve dosya adını
anahtar yaparak tek bir büyük konfigürasyon dizisi oluşturur:

```
config/app.php        → config('app.name')
config/database.php   → config('database.default')
config/davetkart.php  → config('davetkart.currency')     ← bizimki
```

Yani dosyayı bu klasöre koymak, onu kaydetmeye yeter. Ekstra bir `register`
işlemi yok — **convention over configuration** (kural, ayardan üstündür) ilkesi.

### 2.2 `config()` yardımcı fonksiyonu

```php
config('davetkart.tiers.gold.price');        // 399
config('davetkart.module_tiers');            // dizinin tamamı
config('davetkart.yok.olan', 'varsayılan');  // anahtar yoksa 2. argüman döner
```

Noktalar dizinin katmanlarıdır. Bu okumalar **hafızadan** yapılır, diskten değil —
maliyeti yok denecek kadar azdır.

### 2.3 🔴 En kritik kural: kod içinde `env()` çağrılmaz

`.env` dosyası, makineye özgü sırları tutar (veritabanı şifresi, API anahtarı).
`env('X')` fonksiyonu bu dosyayı okur.

Üretimde performans için `php artisan config:cache` çalıştırılır. Bu komut tüm
config dizisini tek bir dosyaya derler ve **`.env` bir daha hiç okunmaz.**

Sonuç:

| Nerede | `env()` çağrısı | Sonuç |
|---|---|---|
| `config/*.php` içinde | ✅ Güvenli | Değer cache'e gömülür |
| Controller/Action/Model içinde | ❌ Tehlikeli | `config:cache` sonrası **`null`** döner |

Bu, Laravel'de en sık yaşanan sessiz üretim hatasıdır: yerelde çalışır, sunucuda
`null` döner, hata mesajı vermez. Bizim dosyada tek `env()` çağrısı var
(`DAVETKART_MEDIA_DISK`) ve doğru yerde.

### 2.4 12-Factor App ilkesi

Bu ayrım rastgele değil; **12-Factor App** metodolojisinin III. maddesidir:
*"Konfigürasyonu ortamda sakla."* Pratik karşılığı:

- **Ortama göre değişen** (DB şifresi, API anahtarı, disk adı) → `.env`
- **Ortamdan bağımsız ama değişebilen** (fiyat, kota, limit) → `config/`
- **Asla değişmeyen** (matematiksel sabitler) → koda gömülebilir

Fiyatı `.env`'e koysaydık her sunucuda ayrı fiyat riski doğardı. Koda gömseydik
fiyat değişiminde deploy gerekirdi. `config/` doğru orta nokta.

---

## 3. Bölüm bölüm: hangi ayar neyi çözüyor?

### 3.1 `tiers` — plan tanımları

```php
'tiers' => [
    'standart' => ['rank' => 0, 'price' => 249, 'rsvp_limit' => 100],
    'gold'     => ['rank' => 1, 'price' => 399, 'rsvp_limit' => null],
    'elit'     => ['rank' => 2, 'price' => 549, 'rsvp_limit' => null],
],
```

**`rank` neden var?**

Sık sorulacak soru şu: *"Kullanıcının satın aldığı plan, davetiyenin gerektirdiği
planı karşılıyor mu?"*

String ile karşılaştıramayız — alfabetik sırada `"elit" < "gold"`, yani Elit alan
kullanıcı Gold içerik yayınlayamazdı. Sayısal `rank` sorunu tek satıra indirir:

```php
$sahipOlunan->rank() >= $gereken->rank()
```

Bu, "sıralanabilir kategori" problemine standart çözümdür.

**`price` neden burada, istemcide değil?**

Ödeme başlatılırken tutar **sunucuda** belirlenir. İstemciden gelen bir `amount`
alanına güvenirsek, kullanıcı DevTools'tan `549` yerine `1` gönderir ve Elit planı
1 liraya alır. Fiyatın tek doğru kaynağı bu dosyadır.

**`rsvp_limit` ve kota metriği**

`null` = sınırsız. Standart planda 100.

🔴 Bu 100 sayısı **misafir sayısıdır, kayıt sayısı değildir.** Frontend'deki
`LiveRsvpPanel` toplamı `reduce((s, r) => s + r.guestCount, 0)` ile hesaplıyor.
Backend `COUNT(*)` kullanırsa 100 kayıt × 4 kişi = **400 misafir** sızar. Doğru
sorgu:

```php
Rsvp::where('invitation_id', $id)->sum('guest_count');
```

Aynı iş kuralının iki tarafta **aynı metrikle** ölçülmesi zorunludur.

---

### 3.2 `module_tiers` — paywall haritası

```php
'module_tiers' => [
    'show_gallery'  => 'elit',
    'show_gift'     => 'elit',
    'show_envelope' => 'gold',
    'show_timeline' => 'gold',
    'show_timer'    => 'standart',
    'show_rsvp'     => 'standart',
],
```

Bu, frontend'deki `getRequiredTier()` fonksiyonunun **sunucu ikizidir**:

```ts
// frontend — sadece arayüz kararı için
export function getRequiredTier(invitation: Invitation): SubscriptionTier {
  if (invitation.showGallery || invitation.showGift) return 'elit';
  if (invitation.showEnvelope || invitation.showTimeline) return 'gold';
  return 'standart';
}
```

**Neden aynısını sunucuda tekrar yazıyoruz?** Çünkü tarayıcıdaki hiçbir kontrol
güvenlik değildir. Kullanıcı DevTools'tan `showGallery = true` yapıp Standart
planla yayınlamayı deneyebilir. Frontend kontrolü **kullanıcı deneyimi**dir;
güvenlik kararı sunucuda yeniden hesaplanır.

**Neden `if` zinciri değil de harita?**

Plan dokümanındaki taslak `if/else` zinciriydi. Haritaya çevirmenin kazancı:
"Galeri artık Gold'a dahil" kararı geldiğinde **kod değil, konfigürasyon** değişir.
Bu, SOLID'in **O**'sudur — *Open/Closed*: sınıf davranış eklemeye açık, değişime
kapalı olmalı.

`TierResolver` bu haritayı şöyle tüketecek (Adım 12'de yazacağız):

```php
// davetiyede açık olan modüllerin en yüksek rank'lısı = gereken plan
foreach (config('davetkart.module_tiers') as $kolon => $tier) {
    if ($invitation->{$kolon}) { /* en yükseği tut */ }
}
```

Sınıfın kendisi hangi modülün hangi plana ait olduğunu **bilmez** — bilgiyi
dışarıdan alır. Buna *veri odaklı tasarım* (data-driven design) denir.

---

### 3.3 `rsvp` — LCV savunma parametreleri

```php
'rsvp' => [
    'max_guests_per_entry' => 10,
    'rate_limit' => [
        'per_ip_per_minute'       => 10,
        'per_invitation_per_hour' => 60,
    ],
    'poll_interval_seconds' => 15,
],
```

Public LCV endpoint'i sistemin **en savunmasız noktasıdır**: giriş gerektirmez ve
dosya kabul eder. Katmanlı savunmanın sayısal parametreleri burada.

| Ayar | Hangi saldırıyı kesiyor |
|---|---|
| `max_guests_per_entry: 10` | Tek formda "500 kişi geliyoruz" yazıp kotayı bir istekte tüketmek |
| `per_ip_per_minute: 10` | Tek makineden gelen bot seli |
| `per_invitation_per_hour: 60` | Farklı IP'lerden gelen dağıtık sel (botnet) |
| `poll_interval_seconds: 15` | Sahip panelinin sorgu sıklığı — throttle ve Cache-Control bununla hizalanır |

İki ayrı rate limit olması tesadüf değil: biri **kaynağı** (IP), diğeri **hedefi**
(davetiye) korur. Tek başına IP limiti botnet'i durduramaz.

---

### 3.4 `media` — yükleme limitleri

```php
'media' => [
    'disk' => env('DAVETKART_MEDIA_DISK', 'public'),
    'gallery' => [
        'max_size_kb'        => 5120,
        'mimes'              => ['image/jpeg', 'image/png', 'image/webp'],
        'max_per_invitation' => 30,
    ],
    // ...
],
```

**`max_size_kb` — birim neden isimde?**

Laravel'in `max:5120` doğrulama kuralı **KB** bekler. Değişken adı `max_size`
olsaydı, kullanan kişi "byte mı, MB mi?" diye tahmin ederdi. Birimi isme yazmak
tahmini ortadan kaldırır — Clean Code'un *"isim niyeti açıklamalı"* ilkesi.

**`mimes` — neden uzantı değil?**

Dosya adı kullanıcı girdisidir ve yalandır. `zararli.php` dosyasını `resim.jpg`
diye adlandırmak saniyelik iştir. Bu yüzden Laravel'de:

- `mimes:jpg,png` → **uzantıya** bakar ⚠️ zayıf
- `mimetypes:image/jpeg,image/png` → dosyanın **ilk baytlarına** bakar ✅ güçlü

Biz ikincisini kullanacağız; bu dizi ona beslenecek.

**Public limitlerin daha düşük olması**

`rsvp_photo` 2 MB, `gallery` 5 MB. Sebep: galeri yüklemesini **kimliği belli**
kullanıcı yapar; LCV fotoğrafını **kim olduğunu bilmediğimiz** misafir yükler.
Kimliksiz yüklemede depolama tüketimi bir saldırı vektörüdür.

**`disk` — depolama soyutlaması**

Laravel'in Filesystem katmanı, "yerel disk", "S3", "FTP" gibi hedefleri aynı
arayüzün arkasına koyar. Kod `Storage::disk(config('davetkart.media.disk'))` der;
yerelden S3'e geçiş tek `.env` satırıdır. Bu, **Ports & Adapters** (Hexagonal
Architecture) deseninin küçük bir uygulamasıdır: iş kodu, dış dünyanın somut
tipini bilmez.

---

### 3.5 `cache` — public davetiye önbelleği

```php
'cache' => [
    'public_invitation_ttl' => 60 * 60 * 6, // saniye
    'key_prefix'            => 'davetkart',
],
```

Davetiye linki WhatsApp grubuna düşer, 500 kişi 2 dakikada açar. Veri ise
neredeyse hiç değişmez. Bu, klasik **okuma-ağırlıklı** (read-heavy) yüktür ve
cache'in en verimli olduğu senaryodur.

**TTL neden 6 saat gibi uzun?**

Cache'te iki tazeleme stratejisi vardır:

1. **Zaman tabanlı (TTL):** "5 dakikada bir yenilensin." Basit ama ya bayat veri
   gösterirsin ya da boşuna sorgu atarsın.
2. **Olay tabanlı (event-based invalidation):** "Davetiye güncellenince cache'i
   sil." Her zaman taze, sıfır gereksiz sorgu.

Biz **ikincisini** kullanıyoruz (`InvitationPublished` event → `ClearInvitationCache`
listener). TTL burada yalnızca **emniyet ağıdır**: bir event kaçarsa veri en fazla
6 saat bayat kalır, sonsuza kadar değil.

**`key_prefix` neden var?**

Aynı Redis/cache sunucusunu başka bir uygulama da kullanabilir. Önek, anahtar
çakışmasını önler: `davetkart:invitation:01H...`.

---

### 3.6 `auth`

```php
'auth' => [
    'login_rate_limit_per_minute' => 5,
    'token_name'                  => 'davetkart-spa',
],
```

**5 deneme/dakika** — brute-force (şifre deneme) savunması. Sınır, IP + e-posta
ikilisi başına uygulanacak; sadece IP'ye koyarsak aynı ofisten giren masum
kullanıcılar birbirini kilitler.

**`token_name`** — Sanctum her token'a bir etiket ister. Bunu koda gömmek yerine
buraya almak, ileride "mobil uygulama token'ı" gibi ikinci bir istemci
eklediğimizde tek yerden yönetmemizi sağlar.

---

### 3.7 `assistant`

```php
'assistant' => [
    'daily_message_limit_per_user' => 30,
    'max_prompt_chars'             => 2000,
],
```

AI çağrısı **paraya mal olur**. Kotasız bırakılan bir AI endpoint'i, teknik bir
açık değil doğrudan **finansal** açıktır: bir betik gece boyunca istek atar, fatura
sabah gelir.

Dikkat: burada **API anahtarı yok**. Anahtar `config/ai.php` içinde duracak. Bu
dosya yalnızca *iş kuralı* taşır; sır yönetimi ayrı dosyanın işidir — **Separation
of Concerns**.

---

## 4. Sık yapılan hatalar

| Hata | Sonucu | Doğrusu |
|---|---|---|
| Controller'da `env('DAVETKART_MEDIA_DISK')` | Üretimde `null` | `config('davetkart.media.disk')` |
| Fiyatı istemciden almak | Kullanıcı 1 ₺'ye Elit alır | Fiyatı config'ten oku |
| Kotayı `COUNT(*)` ile ölçmek | 4 katı misafir sızar | `SUM(guest_count)` |
| Dosya tipini uzantıdan doğrulamak | `.php` dosyası yüklenir | `mimetypes` kuralı |
| Dosya sonuna `?>` koymak | `Headers already sent` | Kapanış etiketi yazma |
| `strict_types` unutmak | Tip hataları sessizce geçer | Her dosyanın başında |

---

## 5. Kendin dene

Terminalde (proje kökünde):

```powershell
php artisan tinker
```

Açılan kabukta:

```php
config('davetkart.tiers.gold.price');        // 399
config('davetkart.module_tiers.show_gift');  // "elit"
config('davetkart.cache.public_invitation_ttl'); // 21600
config('davetkart.tiers.elit.rsvp_limit');   // null
```

`null` dönüyorsa dosya adını veya anahtar yolunu kontrol et. Config cache'i
açtıysan (`config:cache`), değişiklik sonrası `php artisan config:clear`
çalıştırmayı unutma — yoksa eski değerleri görürsün.

---

## 6. Bu dosyayı kimler tüketecek?

| Tüketen | Ne okuyacak | Hangi adımda |
|---|---|---|
| `SubscriptionTier` enum | `tiers.*.rank`, `price`, `rsvp_limit` | Adım 2 |
| `TierResolver` | `module_tiers` | Adım 12 |
| `StoreRsvpRequest` | `rsvp.max_guests_per_entry` | Adım 10 |
| `SubmitRsvpAction` | `tiers.*.rsvp_limit` | Adım 10 |
| `routes/api.php` (throttle) | `rsvp.rate_limit`, `auth.login_rate_limit_per_minute` | Adım 10 |
| `MediaController` / `StoreUploadedMediaAction` | `media.*` | Adım 11 |
| `PublicInvitationController` | `cache.*` | Adım 9 |
| `AuthController` | `auth.token_name` | Adım 6 |
| `AssistantController` | `assistant.*` | Adım 12 |

---

## 7. Sözlük

| Terim | Anlamı |
|---|---|
| **Config** | Uygulamanın davranışını belirleyen, koddan ayrılmış ayar değerleri |
| **Env** | Ortama (makineye) özgü değişkenler; sırlar burada tutulur |
| **TTL** | *Time To Live* — bir cache kaydının yaşam süresi |
| **Rate limit** | Belirli sürede izin verilen istek sayısı üst sınırı |
| **MIME type** | Dosyanın gerçek türünü belirten içerik etiketi (`image/jpeg`) |
| **Paywall** | Ücretli özelliğin ödeme yapılmadan kullanılmasını engelleyen kontrol |
| **Idempotans** | Aynı işlemin birden çok kez çalışmasının tek kez çalışmasıyla aynı sonucu vermesi |
| **12-Factor App** | Modern uygulamalar için 12 maddelik tasarım metodolojisi |
| **Open/Closed** | SOLID'in O'su: genişlemeye açık, değişikliğe kapalı tasarım |
| **Ports & Adapters** | İş kodunun dış sistemleri somut tipleriyle değil arayüzle tanıması |
