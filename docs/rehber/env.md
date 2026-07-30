# `.env` — Kılavuz

> **Kod dosyası:** `.env` (proje kökü)
> **Faz:** 0 — Zemin ve kalite kapıları (adım 0.4)
> **Kılavuz yolu kuralı:** kod kökte olduğu için kılavuz da `docs/rehber/` kökünde.

---

## 1. `.env` nedir?

`.env` bir **PHP dosyası değildir**. İçinde `<?php` yoktur, fonksiyon yoktur,
noktalı virgül yoktur. Sadece `ANAHTAR=değer` satırlarından oluşan düz metindir.

```
DB_PORT=5432
```

Uygulama açılırken `vlucas/phpdotenv` paketi bu dosyayı okur ve her satırı
PHP'nin `$_ENV` süper global dizisine koyar. `env('DB_PORT')` çağrısı o diziden
okur.

### Neden ayrı bir dosya? — 12-Factor III

Bir uygulama üç yerde çalışır: senin laptop'ın, test sunucusu, üretim sunucusu.
Üçünde de **kod aynıdır**, ama veritabanı parolası, API anahtarı, hata ayıklama
modu **farklıdır**.

Bu farkları kodun içine yazarsan iki sorun doğar:

1. Üretime çıkarken kodu düzenlemen gerekir → insan hatası.
2. Parola Git'e girer → herkes görür. (GitHub'da sızmış AWS anahtarları
   dakikalar içinde botlar tarafından bulunur.)

Çözüm: **ortama göre değişen her şey ortam değişkeninde yaşar, kodda değil.**
Bu ilkenin adı 12-Factor App metodolojisinde *"Config"* (III. faktör).

### 🔴 `.env` Git'e girmez

`.gitignore` içinde `.env` satırı vardır. Repoya giren `.env.example` dosyasıdır:
aynı anahtarlar, ama değerler **boş veya sahte**. Projeye yeni katılan biri
`.env.example`'ı kopyalayıp kendi değerlerini yazar.

```
.env         → senin makinende, gerçek parolalar, Git'te YOK
.env.example → repoda, boş şablon, Git'te VAR
```

---

## 2. 🔴 En kritik kural: kodda `env()` çağırma

Bu, Laravel'de yeni başlayanların en sık düştüğü tuzaktır ve hatası **üretimde**
ortaya çıkar.

```php
// ❌ YANLIŞ — Controller, Action, Model, hiçbir yerde
$key = env('GEMINI_API_KEY');

// ✅ DOĞRU
$key = config('ai.gemini.key');
```

### Neden?

Üretimde performans için şu komut çalıştırılır:

```powershell
php artisan config:cache
```

Bu komut `config/` altındaki tüm dosyaları **tek bir PHP dizisine derler** ve
`bootstrap/cache/config.php` olarak kaydeder. Amaç: her istekte 15 dosya okumak
yerine 1 dosya okumak.

Derleme yapıldıktan sonra Laravel **`.env` dosyasını bir daha okumaz**. O yüzden
`env()` çağrısı `null` döner — hata fırlatmaz, uyarı vermez, sessizce `null`.

Sonuç: yerelde çalışan ödeme entegrasyonu, üretimde "API anahtarı geçersiz"
hatası verir ve nedenini bulmak saatler alır.

### Doğru zincir

```
.env  →  config/*.php  →  uygulama kodu
      ↑                ↑
   env() sadece      config() her yerde
   burada çağrılır   güvenle çağrılır
```

`config/` dosyaları derleme **anında** çalışır, o an `.env` hâlâ okunabilir
durumdadır. Derlenmiş dizi değerleri içinde barındırır.

Örnek — `config/ai.php` içinde:

```php
return [
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),   // ✅ config/ içinde serbest
    ],
];
```

---

## 3. Değiştirdiğimiz satırlar ve gerekçeleri

### `APP_NAME=DavetKart`

Önceki değer `Laravel` idi. Bu isim üç yerde görünür:

- Gönderilen e-postaların "Kimden" adı (`MAIL_FROM_NAME="${APP_NAME}"` satırı bunu
  referans alıyor — `.env` içinde değişken referansı kullanılabilir)
- Log kayıtlarının bağlamı
- `config('app.name')` çağrısının döndürdüğü değer

Kullanıcıya "Laravel'den bir e-posta geldi" demek istemiyoruz.

### `APP_URL=http://localhost:8000`

Önceki değer portsuz `http://localhost` idi. Port önemli çünkü Laravel bu değeri
**mutlak URL üretirken** kullanır: e-postadaki doğrulama bağlantısı, `asset()`
ile üretilen dosya adresi, ödeme sağlayıcısına verilen callback adresi.

Port yanlışsa üretilen bağlantı `http://localhost/...` olur ve tarayıcıda 80
portuna gider — orada bir şey yoktur.

> **8000 neden zorunlu?** Frontend'in `vite.config.ts` dosyasında
> `/api → localhost:8000` proxy'si tanımlı. Bu bir tercih değil, **sözleşme**.

### `APP_LOCALE=tr` · `APP_FALLBACK_LOCALE=en` · `APP_FAKER_LOCALE=tr_TR`

Üçü farklı iş yapar, karıştırılır:

| Anahtar | İşi |
|---|---|
| `APP_LOCALE` | Varsayılan dil. Doğrulama hataları bu dilde döner |
| `APP_FALLBACK_LOCALE` | Çevirisi **bulunamayan** anahtar için yedek dil |
| `APP_FAKER_LOCALE` | Sadece **sahte test verisi** üretirken kullanılır |

**Neden fallback `en` kaldı?** Laravel'in kendi çevirileri `en` ile eksiksiz
gelir. Türkçeye çevrilmemiş bir hata mesajı olursa boş string yerine İngilizcesi
görünür — kullanıcı için kötü ama geliştirici için teşhis edilebilir.

**`tr_TR` ne işe yarar?** Faz 3'te `InvitationFactory` yazacağız. Faker
kütüphanesi test verisi üretir:

```php
$this->faker->name();     // en_US: "John Doe"
                          // tr_TR: "Ayşe Yılmaz"
```

Türkçe veriyle test etmek, ileride karakter kodlaması (ş, ğ, İ) veya alan
uzunluğu sorunlarını erken gösterir.

> **Not:** Frontend 10 dil destekliyor. Backend'in çok dilli hata mesajı
> döndürmesi Faz 8'de `SetLocaleFromHeader` middleware'i ile gelecek —
> `Accept-Language` başlığına göre dil seçilecek. `APP_LOCALE` sadece
> **varsayılanı** belirler.

### 🔴 `DB_CONNECTION=sqlite` → `pgsql`

Faz 0'ın asıl işi bu. Gerekçe **K19 kararı: dev/prod parity**.

Önceki plan "geliştirmede SQLite, üretimde MySQL" diyordu. Bunun gerekçesi
teknik üstünlük değil, *"Herd'ün ücretsiz sürümünde veritabanı sunucusu yok,
kurulum zahmetli"* idi. PostgreSQL kurulunca bu gerekçe düştü.

| Konu | SQLite | PostgreSQL | Bizi etkiler mi |
|---|---|---|---|
| `ENUM` kolon tipi | Yok, `varchar`'a düşer | Var | 🔴 6 enum kullanacağız |
| `jsonb` | Yok, düz metin | İndekslenebilir | 🔴 `gift_options` |
| `CHECK` kısıtı | Kısıtlı | Tam | `guest_count > 0` |
| Eşzamanlı yazma | Dosya kilidi, **tek yazıcı** | Satır kilidi | 🔴 LCV seli |
| Kısmi indeks | Yok | Var | `WHERE status='published'` |
| Yabancı anahtar | Varsayılan **kapalı** | Açık | Yetim kayıt riski |

**Asıl mesele son sütun değil, ilkesel olan:** farklı veritabanı kullanmak,
hataların laptop'ta değil **üretimde** ortaya çıkması demektir. `RSVP` kota
testin SQLite'ta geçip PostgreSQL'de farklı davranabilir — ve bunu müşteri
keşfeder.

Feragat ettiğimiz tek şey **test hızı**. SQLite `:memory:` ile testler 3-5 kat
hızlı koşardı. Ama yanlış veritabanında koşan hızlı test **yanlış güven** verir.

### `DB_HOST=127.0.0.1` — neden `localhost` değil?

Windows'ta `localhost` adı önce **IPv6** (`::1`) olarak çözülür. PostgreSQL
varsayılan kurulumda IPv6 dinlemeyebilir; bu durumda bağlantı zaman aşımına
uğrar, sonra IPv4'e düşer. Her sorguda birkaç yüz milisaniye kayıp.

`127.0.0.1` yazmak isim çözümlemesini tamamen atlar.

### `CACHE_STORE=database` → `file`

Cache "hesaplaması pahalı sonuçları geçici saklama" mekanizmasıdır. Faz 4'te
public davetiye sayfasında yoğun kullanacağız.

`database` sürücüsü cache'i `cache` tablosunda tutar. Yerelde bu ters teper:
her cache okuması bir SQL sorgusu, her yazma bir `INSERT` — yani **cache'in
amacı olan "veritabanına gitme" hedefi ihlal edilir**.

`file` sürücüsü `storage/framework/cache/` altında dosya yazar. Yerelde yeterli.

Üretimde **Redis**'e geçeceğiz (Faz 9). Geçiş tek satır:
```
CACHE_STORE=redis
```
Kodda hiçbir değişiklik gerekmez — Laravel'in `Cache` cephesi (facade) sürücüyü
soyutlar. **Bu, bağımlılık tersine çevirme ilkesinin (SOLID'in D'si) framework
seviyesindeki karşılığıdır.**

### `SESSION_DRIVER=database` → `file`

**Session'ı neredeyse hiç kullanmayacağız.** Kimlik doğrulama Sanctum **token**
ile yapılıyor (K5): frontend her istekte `Authorization: Bearer <token>` başlığı
gönderiyor. Bu **durumsuz** (stateless) bir yaklaşımdır — sunucu istekler arası
hiçbir şey hatırlamaz.

Session ise **durumlu** (stateful) modelin aracıdır: sunucu bir çerez verir,
kullanıcıyı o çerezle hatırlar.

Session'ı `database` bırakmak, hiç kullanılmayan bir tabloya boşuna yazma
yaratır. `file` yapıp geçiyoruz.

> **Neden tamamen kapatmıyoruz?** Laravel'in bazı iç mekanizmaları (özellikle
> hata sayfaları ve `php artisan` komutları) session servisinin **var olmasını**
> bekler. Sürücüyü hafifletmek, servisi kaldırmaktan güvenlidir.

### `QUEUE_CONNECTION=database` — değişmedi

Kuyruk, 15 saniye kuralının aracı: resim optimizasyonu ve mail gönderimi gibi
uzun işler HTTP isteğini bekletmez, `jobs` tablosuna yazılır ve arka planda
işlenir.

`database` sürücüsü ek servis gerektirmez, `jobs` tablosu zaten var. Üretimde
Redis'e geçeceğiz (Faz 9).

### `MAIL_FROM_ADDRESS` ve `AWS_DEFAULT_REGION`

Kozmetik düzeltmeler. `hello@example.com` yerine proje alan adı,
`us-east-1` yerine `eu-central-1` (Frankfurt) — Türkiye'den en düşük gecikmeli
AWS bölgesi. KVKK açısından da verinin AB içinde kalması tercih edilir.

`MAIL_MAILER=log` **bilinçli olarak kaldı**: yerelde gönderilen e-postalar
gerçekten gönderilmez, `storage/logs/laravel.log` dosyasına yazılır. Test
sırasında yanlışlıkla gerçek adrese mail atma riski sıfırlanır.

---

## 4. Dokunmadığımız ama bilmen gereken satırlar

### `APP_KEY=base64:...`

`php artisan key:generate` tarafından üretilmiş 32 baytlık rastgele anahtar.
Laravel bununla **şifreleme** yapar: çerezler, `encrypt()` ile şifrelenen alanlar
ve — bizim için önemlisi — **IP hash'leme** (K14).

🔴 **Bu anahtar değişirse şifrelenmiş tüm veri okunamaz hale gelir.** Üretimde
bir kez üretilir ve asla değiştirilmez.

### `APP_DEBUG=true`

Hata olduğunda ekrana **tam yığın izini** (stack trace) basar: dosya yolları,
kod satırları, hatta `.env` değerleri.

🔴 **Üretimde mutlaka `false`.** `true` bırakılan bir Laravel uygulaması,
veritabanı parolasını hata sayfasında gösterebilir. Faz 9'un ilk maddesi budur.

### `BCRYPT_ROUNDS=12`

Parola hash'leme maliyeti. Her artış işlemi **iki katına** çıkarır: 12 tur ≈
250 ms. Yavaşlık burada **özelliktir** — saldırganın kaba kuvvet denemesini
yavaşlatır.

Faz 2'de Argon2id'ye geçip geçmeyeceğimizi tartışacağız.

---

## 5. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| Değerin etrafına gereksiz tırnak | `"5432"` metin olarak okunur | Boşluk yoksa tırnak koyma |
| `=` etrafında boşluk (`DB_PORT = 5432`) | Bazı parser'lar anahtarı `DB_PORT ` okur | Boşluksuz yaz |
| İçinde boşluk olan değeri tırnaksız yazmak | Değer ilk boşlukta kesilir | `MAIL_FROM_NAME="Davet Kart"` |
| Parolada `#` karakteri | `#` yorum başlatır, parola kesilir | Tırnak içine al: `DB_PASSWORD="a#b"` |
| `.env` değişti ama etkisi yok | Config cache bayat | `php artisan config:clear` |
| Kodda `env()` çağırmak | Üretimde sessizce `null` | `config()` kullan |
| `.env`'i Git'e eklemek | Parolalar herkese açık | `.gitignore`'da olduğunu doğrula |
| `.env.example`'ı güncellememek | Yeni geliştirici eksik anahtarla başlar | Her yeni anahtarı ikisine de ekle |

---

## 6. Deneme adımları

**1. Parolanı yaz.** `.env` içinde `DB_PASSWORD=BURAYA_POSTGRES_PAROLANI_YAZ`
satırını kendi PostgreSQL parolanla değiştir.

**2. Config cache'ini temizle** (bayat değer kalmasın):

```powershell
php artisan config:clear
```

**3. Değerlerin okunduğunu doğrula:**

```powershell
php artisan tinker
```

Açılan kabukta:

```php
config('app.name');            // "DavetKart"
config('app.locale');          // "tr"
config('database.default');    // "pgsql"
config('cache.default');       // "file"
```

Çıkmak için `exit`.

> `config('database.connections.pgsql.password')` yazarsan parolan ekrana basılır.
> Ekran paylaşımı yapıyorsan bunu **yazma**.

**4. Bağlantıyı test et** (adım 0.5):

```powershell
php artisan migrate
```

Beklenen: `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`,
`jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens` tabloları oluşur.

pgAdmin'de doğrula: `davetkart` → `Schemas` → `public` → `Tables` (yenilemek
için sağ tık → `Refresh`).

---

## 7. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Ortam değişkeni** (environment variable) | İşletim sisteminin sürece verdiği `ANAHTAR=değer` çifti |
| **12-Factor App** | Bulut uygulamaları için 12 maddelik metodoloji. III = Config, X = dev/prod parity |
| **dev/prod parity** | Geliştirme ve üretim ortamlarının olabildiğince benzer olması ilkesi |
| **Config cache** | `config/` dosyalarının tek PHP dizisine derlenmesi |
| **Sürücü** (driver) | Aynı arayüzün farklı uygulaması. `file`, `redis`, `database` — hepsi `Cache` arayüzünü karşılar |
| **Cephe** (facade) | `Cache::get()` gibi statik görünümlü çağrılar. Arkada gerçek nesneye yönlendirir |
| **Durumsuz** (stateless) | Sunucunun istekler arası bilgi tutmaması. Token tabanlı auth böyledir |
| **Kuyruk** (queue) | Uzun işlerin sonraya bırakılıp arka planda işlendiği sıra |
| **Hash** | Tek yönlü dönüşüm. Parolada ve IP maskelemede kullanılır; geri çevrilemez |
| **Migration** | Veritabanı şemasını kodla tanımlayan, sürümlenebilir dosya |

---

## 8. Bu dosyanın bağlantıları

| İlgili | Nerede |
|---|---|
| `.env` değerlerini okuyan config dosyaları | [`config/README.md`](config/README.md) |
| Veritabanı bağlantı tanımları | [`config/database.md`](config/database.md) |
| Cache sürücü ayrıntısı | [`config/cache.md`](config/cache.md) |
| Session neden kullanılmıyor | [`config/session.md`](config/session.md) |
| K19 kararının tam gerekçesi | `docs/07-GELISTIRME-YOL-HARITASI.md` §2.2 |
