# `phpunit.xml` — Kılavuz

> **Kod dosyası:** `phpunit.xml` (proje kökü)
> **Faz:** 0 — Zemin ve kalite kapıları (adım 0.10)
> **Kurulu sürüm:** PHPUnit 12.5

---

## 1. Bu dosya ne işe yarar?

`php artisan test` çalıştırdığında Laravel arka planda PHPUnit'i çağırır.
PHPUnit da bu dosyayı okur ve şunları öğrenir:

- Testler nerede? (`tests/Unit`, `tests/Feature`)
- Hangi ortam değişkenleriyle koşacak? (`<php>` bölümü)
- Ne zaman "başarısız" sayılacak? (`failOn*` bayrakları)

🔴 **En kritik işi: testlerin geliştirme veritabanına dokunmasını
engellemek.**

---

## 2. En önemli değişiklik: SQLite → PostgreSQL

Laravel'in varsayılanı şuydu:

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Yani testler **bellekte bir SQLite** veritabanı kullanıyordu. Hızlıydı ama
K19 kararına aykırı.

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="davetkart_test"/>
```

### Neden hızdan feragat ediyoruz?

SQLite `:memory:` testleri 3–5 kat hızlı koşar. Ama **yanlış veritabanında
koşan hızlı test, yanlış güven verir.**

Somut örnek — Faz 5'teki LCV kota testi:

| Konu | SQLite | PostgreSQL |
|---|---|---|
| Eşzamanlı yazma | Dosya kilidi, **tek yazıcı** | Satır kilidi |
| `ENUM` kolonu | Yok, `varchar`'a düşer | Var |
| `CHECK` kısıtı | Kısıtlı | Tam |
| Yabancı anahtar | Varsayılan **kapalı** | Açık |

`guest_count > 0` kısıtını SQLite yok sayabilir; testin **geçer**. Üretimde
PostgreSQL kısıtı uygular ve kod patlar. Testin görevi bu farkı **önceden**
göstermektir.

> **12-Factor X — dev/prod parity.** Ortamlar ne kadar benzerse, hatalar o kadar
> erken çıkar. Test ortamı da bir ortamdır.

### 🔴 Neden ayrı veritabanı?

Testler `RefreshDatabase` özelliğiyle çalışır: **her koşuda tüm tabloları siler
ve migration'ları yeniden koşar.**

Tek veritabanı kullansaydın, `php artisan test` yazdığın an geliştirme verilerin
uçardı. Bu bir konfor tercihi değil, **veri kaybı önlemidir**.

```
davetkart       ← senin elle girdiğin veriler, dokunulmaz
davetkart_test  ← her test koşusunda sıfırlanır
```

---

## 3. 🔴 Parola neden burada yok?

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="davetkart_test"/>
<!-- DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD .env'den gelir -->
```

`phpunit.xml` **repoda** (`.gitignore`'da değil). `.env` ise **repoda değil**.

Buraya parola yazsaydın, veritabanı parolan GitHub'a giderdi. Bu, sızıntıların
en sık nedenlerinden biridir — insanlar `.env`'i korurken yapılandırma
dosyalarını unutur.

**Kural: repoya giren hiçbir dosyaya sır yazılmaz.** Sadece sırrın *adı* geçer.

Belirtmediğimiz değişkenler (`DB_HOST`, `DB_PASSWORD`…) `.env`'den okunur —
aynı PostgreSQL sunucusu, farklı veritabanı.

---

## 4. Değişkenlerin öncelik sırası

Bu, sık kafa karıştıran bir konu:

```
1. Gerçek işletim sistemi ortam değişkeni    ← en güçlü
2. phpunit.xml <env> girdileri
3. .env dosyası                              ← en zayıf
```

**Neden bu sırada çalışıyor?** PHPUnit, Laravel açılmadan **önce** `<env>`
girdilerini ortama yazar. Laravel sonra `.env`'i okur — ama kullandığı Dotpenv
kütüphanesi **var olan değişkenlerin üzerine yazmaz**. Böylece `phpunit.xml`
kazanır.

> Bu yüzden test koşarken `.env`'deki `DB_DATABASE=davetkart` değeri **etkisiz**
> kalır; `davetkart_test` kullanılır.

---

## 5. `<php>` bölümündeki diğer ayarlar

### Her şey bellekte: `array` ve `sync`

```xml
<env name="CACHE_STORE" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="MAIL_MAILER" value="array"/>
```

| Ayar | Etkisi | Neden |
|---|---|---|
| `CACHE_STORE=array` | Cache bellekte, test bitince yok | Testler birbirinin cache'ini görmemeli — **yalıtım** |
| `SESSION_DRIVER=array` | Oturum dosyası yazılmaz | Disk temiz kalır |
| `QUEUE_CONNECTION=sync` | 🔴 Job'lar **kuyruğa girmez, anında çalışır** | Test `queue:work` beklemez |
| `MAIL_MAILER=array` | Mail gönderilmez, bellekte tutulur | `Mail::assertSent()` ile doğrulanır, kimseye mail gitmez |

**`sync` sürücüsü neden önemli?** Faz 5'te `SendRsvpNotification` job'ı yazacağız.
Testte `sync` olmasa job kuyruğa girer ve orada bekler — test onu göremez.
`sync` ile job aynı istek içinde çalışır, sonucu test edilebilir.

> İstersen `Queue::fake()` ile bunu tersine çevirip *"job kuyruğa atıldı mı?"*
> diye de test edebilirsin. İki farklı soru, iki farklı araç.

### `BCRYPT_ROUNDS=4`

Üretimde `12`. Her tur maliyeti **iki katına** çıkarır: 12 tur ≈ 250 ms.

Yüz kullanıcı oluşturan bir test paketinde bu **25 saniye** demek. Testte
güvenlik değil hız önemli — saldırgan test veritabanına erişemez.

> Bu, "testte güvenliği gevşetiyoruz" değil; **hash maliyeti bir yavaşlatma
> önlemidir** ve yavaşlatılacak bir saldırgan yoktur.

### `APP_DEBUG=false` — K20 için

Hata sözleşmesinde `error.debug` bloğu yalnızca `APP_DEBUG=true` iken üretilir.
Testler varsayılan olarak **üretim kipinde** koşar, böylece sızıntı testi
yazılabilir:

```php
$response->assertJsonMissingPath('error.debug');
```

Debug bloğunu test etmek isteyen senaryo, kendisi
`config(['app.debug' => true])` diyerek açar.

### `APP_LOCALE=en` — K21 için

Backend tek dil konuşur. Testte de öyle.

---

## 6. Katılık bayrakları

```xml
failOnWarning="true"
failOnRisky="true"
failOnNotice="true"
beStrictAboutOutputDuringTests="true"
```

Varsayılan olarak PHPUnit bunları **sarı uyarı** gösterip geçer. Biz **kırmızıya**
çeviriyoruz.

| Bayrak | Ne yakalar |
|---|---|
| `failOnWarning` | Kullanımdan kalkmış API, şüpheli çağrı |
| `failOnRisky` | 🔴 **Hiçbir assertion içermeyen test** |
| `failOnNotice` | PHP seviyesi notice'lar |
| `beStrictAboutOutputDuringTests` | Unutulmuş `dd()`, `echo`, `var_dump()` |

**`failOnRisky` en değerlisi.** Assertion'sız test:

```php
public function test_kullanici_kayit_olabilir(): void
{
    $this->postJson('/api/auth/register', [...]);
    // assertion yok — test HER ZAMAN geçer
}
```

Bu test yeşil yanar ama **hiçbir şey doğrulamaz**. Sahte güven üretir. Katılık
bayrağı olmadan fark edilmez.

**`beStrictAboutOutputDuringTests` ise `dd()` avcısıdır** — hata ayıklarken
koyup unuttuğun çağrı testi kırar, üretime gitmez.

### `cacheDirectory=".phpunit.cache"`

PHPUnit test sonuçlarını önbelleğe alır (`--order-by=defects` gibi özellikler
için). Klasör `.gitignore`'da zaten var.

---

## 7. Komutlar

```powershell
# Tüm testler (Laravel sarmalayıcısı — daha okunur çıktı)
php artisan test

# Sadece bir dosya
php artisan test --filter=AuthTest

# Sadece bir metot
php artisan test --filter=test_kullanici_kayit_olabilir

# Paralel koşum (hızlı, ama dikkat — asagiya bak)
php artisan test --parallel

# Ham PHPUnit
./vendor/bin/phpunit
```

> ⚠️ **`--parallel` uyarısı:** Laravel her işlemci çekirdeği için ayrı veritabanı
> oluşturur: `davetkart_test_1`, `davetkart_test_2`… PostgreSQL kullanıcısının
> **veritabanı oluşturma yetkisi** olmalı. `postgres` süper kullanıcısında var.
> İlk denemede sorun çıkarsa `--parallel`'siz koş — Faz 0 için gerekli değil.

---

## 8. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| Test veritabanını ayırmamak | 🔴 `artisan test` geliştirme verilerini siler | Ayrı `davetkart_test` |
| `phpunit.xml`'e parola yazmak | Sır repoya gider | Sadece `.env`'de |
| `davetkart_test` veritabanını oluşturmamak | `database does not exist` | pgAdmin'den oluştur |
| Testte `RefreshDatabase` kullanmamak | Testler birbirinin verisini görür | Her Feature testinde kullan |
| `QUEUE_CONNECTION=database` bırakmak | Job'lar çalışmaz, test sebepsiz kırılır | `sync` |
| `.env`'i değiştirip test beklemek | Test `phpunit.xml`'i dinler | Test ayarı buraya yazılır |
| Assertion'sız test yazmak | Sahte yeşil | `failOnRisky` yakalar |
| `dd()` unutmak | Çıktı test raporunu bozar | `beStrictAboutOutputDuringTests` yakalar |

---

## 9. Deneme adımları

**1. Testleri koş:**

```powershell
php artisan test
```

İki örnek test var (`tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`).
Beklenen: **2 passed**.

**2. Doğru veritabanına bağlandığını kanıtla.** `tests/Feature/ExampleTest.php`
içine geçici olarak ekle:

```php
public function test_hangi_veritabani(): void
{
    dump(config('database.default'), config('database.connections.pgsql.database'));
    $this->assertTrue(true);
}
```

Beklenen çıktı: `"pgsql"` ve `"davetkart_test"`. **Sonra sil.**

> ⚠️ `dump()` çıktısı `beStrictAboutOutputDuringTests` yüzünden testi **riskli**
> işaretleyebilir. Bu beklenen davranış — ayarın çalıştığının kanıtı. Denemeyi
> yaptıktan sonra sil.

**3. Katılık bayrağının çalıştığını gör:**

```php
public function test_bos(): void
{
    $this->assertTrue(true);
    $this->markTestIncomplete();   // riskli isaretlenir
}
```

`failOnRisky` sayesinde **kırmızı** yanmalı. Sonra sil.

**4. pgAdmin'den doğrula:** `davetkart_test` → `Schemas` → `public` → `Tables`.
Test koştuktan sonra tablolar oluşmuş olmalı.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Test paketi** (test suite) | Birlikte koşan test grubu |
| **Unit testi** | Tek sınıfı yalıtılmış test eder. Hızlı, veritabanına dokunmaz |
| **Feature testi** | HTTP isteğiyle uçtan uca test eder. Veritabanı kullanır |
| **Assertion** | Doğrulama ifadesi — `assertStatus(201)` |
| **`RefreshDatabase`** | Her testte veritabanını sıfırlayan Laravel trait'i |
| **Riskli test** (risky) | Çalışan ama hiçbir şey doğrulamayan test |
| **Fake** | Gerçek servisin yerine geçen sahte — `Mail::fake()`, `Queue::fake()` |
| **Yalıtım** (isolation) | Bir testin diğerini etkilememesi |
| **Trait** | PHP'de sınıflara metot ekleme mekanizması |
| **dev/prod parity** | Ortamların benzer olması ilkesi (12-Factor X) |

---

## 11. Bağlantılar

| İlgili | Nerede |
|---|---|
| `.env` ve ortam değişkenleri | [`env.md`](env.md) |
| Statik analiz | [`phpstan.md`](phpstan.md) |
| Biçimlendirici | [`pint.md`](pint.md) |
| Hata sözleşmesi test stratejisi | `docs/08-HATA-SOZLESMESI.md` §9 |
| İlk gerçek test | `tests/Feature/HealthTest.php` (Faz 1) |
