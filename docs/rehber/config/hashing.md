# `config/hashing.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `config/hashing.php` · `.env` · `.env.example` · `phpunit.xml`
> **Yol haritasındaki yeri:** Faz 2 — **K32'nin uygulanması**
> **Bağlantılı:** [`app/Models/User.md`](../app/Models/User.md) §3.4 ·
> [`UserFactory.md`](../database/factories/UserFactory.md) §2.2 ·
> [`env.md`](../env.md) · [`phpunit.md`](../phpunit.md)

---

## 0. Bir dakikalık özet

Bu dosya **parolaların nasıl saklanacağını** belirler. K32 kararıyla sürücü
`bcrypt`'ten **Argon2id**'ye geçirildi.

```env
HASH_DRIVER=argon2id
ARGON_MEMORY=65536      # 64 MB
ARGON_TIME=4            # 4 tur
ARGON_THREADS=1
```

🔴 **Karar Faz 2 girişinde alınmıştı ama uygulanmamıştı.** `config/hashing.php`
yayınlanmış, `'driver' => env('HASH_DRIVER', 'bcrypt')` satırı durmuş, ama
`.env`'e `HASH_DRIVER` hiç yazılmamıştı — yani varsayılan devredeydi ve **hâlâ
bcrypt kullanılıyordu**. Bu dosya o boşluğu kapatır.

> **Ders:** Bir karar "alındı" işaretlendiği anda uygulanmış olmaz. Karar
> kaydında ✅ görünen bir madde, kodda doğrulanmadıysa yalnızca bir niyettir.

---

## 1. Parola neden "hash'lenir", şifrelenmez?

İkisi farklı şeydir ve karıştırılması ciddi bir güvenlik hatasıdır.

| | Şifreleme (encryption) | Hash'leme |
|---|---|---|
| Yön | **Çift yönlü** — anahtarla geri açılır | **Tek yönlü** — geri döndürülemez |
| Amaç | Veriyi saklayıp sonra okumak | Doğrulamak, ama asla okumamak |
| Örnek | Kredi kartı numarası | **Parola** |

Parolayı şifrelesek, anahtar sızdığında **tüm parolalar** açığa çıkardı.
Hash'lersek sızan veriden parola geri üretilemez.

**Peki doğrulamayı nasıl yapıyoruz?** Kullanıcı parolayı girer, aynı işlemi
tekrar uygularız, sonuçlar eşleşiyorsa parola doğrudur:

```
Kayıtta:  "gizli123"  →  hash  →  $argon2id$...  (veritabanına bu gider)
Girişte:  "gizli123"  →  hash  →  $argon2id$...  eşleşti mi?
```

Ham parola **hiçbir zaman** saklanmaz.

---

## 2. Neden "yavaş" bir algoritma seçiyoruz?

MD5 veya SHA-256 de hash üretir — ve **çok hızlıdır**. İşte sorun tam olarak bu.

Bir saldırgan veritabanını ele geçirdiğinde yaptığı şey **offline brute-force**:
milyonlarca parola adayını hash'leyip veritabanındaki hash'lerle karşılaştırır.

| Algoritma | Saniyede deneme (kaba sıra) |
|---|---|
| SHA-256 (GPU) | **Milyarlarca** |
| bcrypt (cost 12) | ~yüzlerce |
| Argon2id (64 MB, t=4) | ~onlarca |

Parola hash'leme algoritmaları **kasıtlı olarak yavaştır**. Buna **iş faktörü
(work factor)** denir ve ayarlanabilir olması özelliğin kalbidir: donanım
hızlandıkça parametre artırılır, algoritma değiştirilmez.

> Bizim için 200 ms'lik gecikme kabul edilebilir — kullanıcı günde birkaç kez
> giriş yapar. Saldırgan için aynı gecikme, 10 milyar denemeyi **imkânsız** hâle
> getirir. Asimetri savunmanın tamamıdır.

---

## 3. bcrypt yerine Argon2id — asıl fark **bellek**

bcrypt (1999) hâlâ güvenlidir ve yanlış bir seçim değildir. Ama tek bir eksene
yaslanır: **CPU zamanı**.

Saldırganın elindeki donanım ise CPU değil: GPU ve ASIC. Bunlar **binlerce basit
işlemi paralel** çalıştırmak için tasarlanmıştır. bcrypt'in çalışma alanı ~4 KB
olduğu için, 8 GB belleği olan bir GPU **binlerce denemeyi aynı anda** yürütebilir.

Argon2id (2015, Password Hashing Competition kazananı) ikinci bir eksen ekler:
**bellek**.

```
bcrypt      →  her deneme ~4 KB     →  8 GB GPU'da ~2.000.000 paralel deneme
Argon2id    →  her deneme  64 MB    →  8 GB GPU'da       ~128 paralel deneme
                                        (~15.000 kat daha az)
```

🔴 **Anlatılacak tek cümle:** bcrypt saldırganın *zamanını* pahalı yapar,
Argon2id *donanımını* pahalı yapar. GPU'ya bellek eklemek, çekirdek eklemekten
çok daha zordur.

### 3.1 Neden `argon2id`, `argon2i` veya `argon2d` değil?

| Varyant | Güçlü olduğu yer | Zayıf olduğu yer |
|---|---|---|
| `argon2d` | GPU saldırısı | Yan kanal (side-channel) saldırısı |
| `argon2i` | Yan kanal | GPU saldırısına daha açık |
| **`argon2id`** | **İkisi de** — hibrit | — |

`argon2id` ilk turda `argon2i` gibi, sonrasında `argon2d` gibi davranır. OWASP'ın
ve RFC 9106'nın önerdiği varyant budur. Laravel'de `argon` yazmak `argon2i`
demektir — biz açıkça **`argon2id`** yazıyoruz.

### 3.2 Bir ek fayda: 72 bayt sınırı yok

bcrypt parolanın yalnızca **ilk 72 baytını** kullanır; sonrası sessizce atılır.
Türkçe karakterler UTF-8'de 2 bayt tuttuğu için bu sınıra beklenenden erken
ulaşılır. Argon2id'de böyle bir kesme yoktur.

---

## 4. Üç parametre ne yapar?

```env
ARGON_MEMORY=65536      # KiB cinsinden → 64 MB
ARGON_TIME=4            # kaç tur (iterations)
ARGON_THREADS=1         # paralellik
```

| Parametre | Artırınca ne olur | Kime zarar verir |
|---|---|---|
| `memory` | Her hash daha çok RAM ister | 🔴 **Asıl silah** — saldırganın paralellik tavanını düşürür |
| `time` | Her hash daha uzun sürer | Hem bize hem saldırgana, **doğrusal** |
| `threads` | Hash birden çok çekirdeğe yayılır | Sunucu çekirdeği tüketir |

**`threads=1` neden?** PHP-FPM zaten her isteği ayrı sürecte çalıştırır; paralellik
oradan geliyor. Hash içinde ikinci bir paralellik katmanı, eşzamanlı istek
sayısını düşürür.

### 4.1 OWASP referansı

OWASP'ın kabul ettiği asgari yapılandırmalar (hepsi denk güvenlikte):

| memory | time | threads |
|---|---|---|
| 47104 (46 MB) | 1 | 1 |
| 19456 (19 MB) | 2 | 1 |
| 12288 (12 MB) | 3 | 1 |
| 9216 (9 MB) | 4 | 1 |
| 7168 (7 MB) | 5 | 1 |

Bizim değerimiz **65536 / 4 / 1** — bellek ekseninde en yükseğin **üstünde**, tur
sayısında da yüksek. Yani asgarinin epey üzerindeyiz.

---

## 5. 🔴 Neden Laravel varsayılanlarını değiştirmedik?

`PHP-LARAVEL-SETUP.md` §15 bunu açık soru olarak listeliyordu:
*"`memory` saldırgana karşı asıl silah ama her giriş isteğinde sunucudan istenen
RAM — denge ayarlanacak."*

Denge **ayarlanmadı**, bilinçli olarak. Gerekçe:

> **Ölçemediğin bir performans parametresini ayarlama.**

64 MB'ın "çok" olup olmadığı, üzerinde koşacağımız sunucunun RAM'ine ve eşzamanlı
giriş sayısına bağlıdır. İkisi de **henüz bilinmiyor** — barındırma kararı Faz
9'da. Şimdi tahminle düşürmek, ölçülmemiş bir kazanç için ölçülebilir bir
güvenlik kaybı demektir.

Değerleri düşürmek gerekirse bu **geriye dönük uyumlu** bir değişikliktir:
`rehash_on_login` sayesinde eski hash'ler girişte sessizce güncellenir (§7).
Yani karar ertelenebilir — ertelemenin maliyeti yok.

### 5.1 Ama neden `.env`'e yazdık, madem varsayılan aynı?

Çünkü **görünmez bir güvenlik parametresi, bakımı yapılmayan bir parametredir.**

`.env`'de yoksa: kimse orada bir karar olduğunu bilmez, Faz 9'da nereye
bakacağını bilmez, Laravel bir gün varsayılanını değiştirse fark edilmez.

Açıkça yazınca üçü de çözülür. Y4 kuralı gereği `.env` ve `.env.example`'a
**birlikte** eklendi.

---

## 6. Testte parametreler neden düşürüldü?

`phpunit.xml`:

```xml
<env name="HASH_DRIVER" value="argon2id"/>
<env name="ARGON_MEMORY" value="1024"/>   <!-- 64 MB değil 1 MB -->
<env name="ARGON_TIME" value="1"/>
<env name="ARGON_THREADS" value="1"/>
```

Testin amacı **davranışı** doğrulamaktır, saldırgana direnmek değil. Üretim
maliyetini her test koşusunda ödemek, test süresini dakikalara çıkarır — ve yavaş
test suite'i **çalıştırılmayan** test suite'ine dönüşür.

Bu, `phpunit.xml`'de zaten var olan `BCRYPT_ROUNDS=4` satırının aynı mantığıdır.

> 🔴 **Bu satırlar neden eklenmek zorundaydı?** `BCRYPT_ROUNDS` **yalnızca bcrypt
> için geçerlidir**. Sürücüyü Argon2id yapıp `ARGON_*` değerlerini
> düşürmeseydik, testler sessizce üretim ayarıyla (64 MB × her kullanıcı)
> koşacaktı. Sürücü değiştirmenin gözden kaçan yan etkisi buydu.

`UserFactory`'deki memoization (§2.2) ikinci savunmadır: aynı test içinde hash
bir kez üretilir.

---

## 7. `verify` ve `rehash_on_login`

```php
'argon' => [ ..., 'verify' => env('HASH_VERIFY', true) ],
'rehash_on_login' => true,
```

### 7.1 `verify` — algoritma denetimi

`true` iken Laravel, doğrulama sırasında hash'in **gerçekten Argon2id olduğunu**
kontrol eder; değilse `RuntimeException` fırlatır.

Neden? **Algoritma düşürme (downgrade) saldırısına** karşı. Saldırgan
veritabanına yazma imkânı bulursa, bir parolayı zayıf bir MD5 hash'iyle
değiştirip onu kırabilir. `verify` bunu engeller.

> ⚠️ **Yan etkisi:** Var olan bcrypt hash'leri artık **doğrulanamaz** — exception
> fırlar, sessizce Argon2id'ye yükseltilmez. Bizde sorun değil: `migrate:fresh`
> sonrası veritabanında hiç kullanıcı yok. Ama gerçek kullanıcısı olan bir
> projede geçiş, `verify` geçici olarak kapatılarak veya çift-doğrulama koduyla
> yapılmalıdır.

### 7.2 `rehash_on_login` — sessiz yükseltme

`true` iken, başarılı bir girişte parametrelerin eskidiği fark edilirse hash
**yeniden üretilir**. Kullanıcı hiçbir şey fark etmez; ham parola zaten o anda
elimizdedir.

Faz 9'da `ARGON_MEMORY`'yi değiştirirsek, kullanıcılar giriş yaptıkça hash'ler
kendiliğinden yeni parametreye taşınır. §5'teki "erteleme maliyetsizdir"
iddiasının teknik dayanağı budur.

**Açık kalıyor** — `PHP-LARAVEL-SETUP.md` §15'teki soru bu kararla kapandı.

---

## 8. Salt nerede? — "hash'e bakıp anlamak"

Klasik bir soru: *"Salt'ı ayrı bir kolonda saklamamız gerekmiyor mu?"* **Hayır.**

```
$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG
└───┬───┘└──┬─┘└──────┬──────┘└────┬────┘└──────────────┬──────────────┘
algoritma  sürüm   parametreler    salt              asıl hash
```

Salt her hash için **rastgele üretilir** ve sonucun **içine gömülür**. Doğrulama
sırasında Laravel onu oradan okur.

Salt niye var? Aynı parolayı kullanan iki kullanıcının aynı hash'i almasını
engeller — böylece önceden hesaplanmış tablolar (*rainbow table*) işe yaramaz ve
saldırgan "bu ikisinin parolası aynı" bilgisini bile edinemez.

Bu yapı ayrıca `rehash_on_login`'i mümkün kılar: parametreler hash'in içinde
yazılı olduğu için Laravel eskimiş olanı tanıyabilir.

bcrypt karşılaştırması:

```
$2y$12$LQv3c1yqBWVHxkd0LHAkCO...
└┬┘└┬┘  └──────────┬──────────┘
tür maliyet    salt + hash birlikte
```

---

## 9. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `HASH_DRIVER` yazmayı unutmak | Sessizce bcrypt kullanılır — **bizim düştüğümüz tuzak** | `.env` **ve** `.env.example` (Y4) |
| Sürücü değişince test parametrelerini unutmak | Test suite dakikalara çıkar | `phpunit.xml`'de `ARGON_*` |
| `argon` ile `argon2id`'yi aynı sanmak | `argon` = `argon2i`, GPU'ya daha açık | Açıkça `argon2id` |
| `.env` değiştirip `config:clear` yapmamak | Eski değer önbellekten gelir | `php artisan config:clear` |
| Kodda `Hash::make()` çağırıp cast'e de bırakmak | Çift hash — hiçbir giriş çalışmaz | Yalnızca cast (`User.md` §3.4) |
| `memory`'yi ölçmeden düşürmek | Ölçülmemiş kazanç, ölçülebilir kayıp | Faz 9'da, gerçek donanımda |
| Parolayı şifrelenmiş sanmak | Geri açılabileceğini varsaymak | Hash tek yönlüdür |
| Salt için ayrı kolon açmak | Gereksiz; zaten hash'in içinde | §8 |

---

## 10. Kendin dene

Önce önbelleği temizle — `.env` değişti:

```powershell
php artisan config:clear
php artisan tinker
```

**1. Sürücü gerçekten değişti mi?**

```php
config('hashing.driver');       // "argon2id"
config('hashing.argon');        // ['memory' => 65536, 'threads' => 1, 'time' => 4, ...]
```

**2. Hash'in biçimine bak:**

```php
Hash::make('gizli123');
// $argon2id$v=19$m=65536,t=4,p=1$...   ← $2y$ ile başlamıyorsa geçiş başarılı
```

**3. Aynı parola iki farklı hash üretiyor mu?** (salt kanıtı)

```php
Hash::make('ayni') === Hash::make('ayni');    // false — salt rastgele
```

**4. Yine de ikisi de doğrulanıyor mu?**

```php
$h = Hash::make('ayni');
Hash::check('ayni', $h);      // true
Hash::check('yanlis', $h);    // false
```

**5. Ne kadar sürüyor?** — iş faktörünü hissetmek için:

```php
$t = microtime(true); Hash::make('deneme'); round((microtime(true) - $t) * 1000).' ms';
```

Makinene göre ~100-400 ms görmelisin. Kullanıcı için görünmez, saldırgan için
duvar.

**6. Model üzerinden de çalışıyor mu?**

```php
$u = App\Models\User::factory()->create();
str_starts_with($u->password, '$argon2id$');    // true
```

---

## 11. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Hash** | Tek yönlü özet — geri döndürülemez |
| **Şifreleme** | Çift yönlü — anahtarla geri açılır |
| **Salt** | Her hash'e eklenen rastgele değer |
| **İş faktörü** | Hash'lemenin kasıtlı maliyeti (`cost`, `memory`, `time`) |
| **Brute-force** | Tüm olasılıkları tek tek deneme |
| **Rainbow table** | Önceden hesaplanmış hash tablosu |
| **Bellek-zor (memory-hard)** | Çok RAM isteyen, bu yüzden paralelleştirilmesi pahalı algoritma |
| **ASIC** | Tek bir işe göre üretilmiş özel çip |
| **Yan kanal (side-channel)** | Zamanlama, güç tüketimi gibi dolaylı ölçümle bilgi sızdırma |
| **Downgrade saldırısı** | Sistemi daha zayıf bir algoritmaya düşürme |
| **Rehash** | Parametreler eskiyince hash'i yeniden üretme |

---

## 12. Bağlantılar

| İlgili | Nerede |
|---|---|
| `hashed` cast'i ve model tarafı | [`app/Models/User.md`](../app/Models/User.md) §3.4 |
| Test verisinde hash memoization | [`UserFactory.md`](../database/factories/UserFactory.md) §2.2 |
| `.env` kuralları (Y1-Y5) | [`env.md`](../env.md) · [`fazlar/FAZ-0.md`](../fazlar/FAZ-0.md) §4.1 |
| Test ortamı | [`phpunit.md`](../phpunit.md) |
| Zamanlama saldırısı savunması | `docs/08-HATA-SOZLESMESI.md` §3.1 (Faz 2, dosya 2.8) |
| Config klasörü dizini | [`README.md`](README.md) |
