# `app/Actions/Auth/LoginUserAction.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Actions/Auth/LoginUserAction.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.8c-2 — **fazın ikinci kritik güvenlik işi**
> **Bağlantılı:** [`RegisterUserAction.md`](RegisterUserAction.md) (Action deseni orada) ·
> [`InvalidCredentialsException.md`](../../Exceptions/InvalidCredentialsException.md) ·
> [`config/hashing.md`](../../../config/hashing.md) · `docs/08-HATA-SOZLESMESI.md` §3.1

---

## 0. Bir dakikalık özet

```php
$user = User::where('email', $credentials['email'])->first();

$hash = $user?->password ?? self::dummyHash();          // ← savunma burada

$passwordMatches = Hash::check($credentials['password'], $hash);

if ($user === null || ! $passwordMatches) {
    throw new InvalidCredentialsException;
}
```

Bu dosya bir soruya cevap veriyor: **"Bu kişi, o kişi mi?"**

Ve bunu yaparken, cevabın **ne kadar sürede** verildiğinin de bir bilgi
sızdırdığını hesaba katıyor. Faz 2'nin en ince güvenlik konusu budur.

---

## 1. 🔴 Zamanlama saldırısı (timing attack) nedir?

### 1.1 Naif kod ve açığı

Doğal olarak şöyle yazardık:

```php
$user = User::where('email', $email)->first();

if ($user === null) {
    throw new InvalidCredentialsException;      // ❌ HEMEN döner
}

if (! Hash::check($password, $user->password)) {
    throw new InvalidCredentialsException;      // ~200 ms sonra döner
}
```

Yanıt gövdesi **iki durumda da aynı**: `{"error":{"code":"INVALID_CREDENTIALS"}}`.
H6'ya uygun görünüyor. Ama bir fark var:

| Durum | Yapılan iş | Süre |
|---|---|---|
| Kullanıcı **yok** | 1 SQL sorgusu | **~2 ms** |
| Kullanıcı **var**, parola yanlış | 1 SQL + **Argon2id** | **~200 ms** |

**100 katlık fark.** Saldırgan gövdeyi okumadan, sadece **kronometreyle**
e-postanın kayıtlı olup olmadığını anlar.

### 1.2 Saldırı pratikte nasıl işler?

```
POST /auth/login  {"email":"ahmet@ornek.test","password":"x"}   → 3 ms   → KAYITLI DEĞİL
POST /auth/login  {"email":"ayse@ornek.test","password":"x"}    → 210 ms → KAYITLI ✓
POST /auth/login  {"email":"mehmet@ornek.test","password":"x"}  → 2 ms   → KAYITLI DEĞİL
```

Ağ gecikmesi gürültü yaratır ama saldırgan her adresi **20-30 kez** deneyip
ortalama alır; gürültü rastgeledir, 200 ms'lik sistematik fark değildir.
İstatistik gürültüyü eler.

Sonuç: `unique` ve `exists` kurallarını kaldırarak kapattığımız enumeration
açığı, **yan kapıdan** geri gelir.

> 🔴 Bu, güvenlikte **yan kanal (side-channel)** denen sınıfın örneğidir: bilgi
> içerikten değil, işlemin **gözlemlenebilir özelliklerinden** sızar — süre, güç
> tüketimi, bellek erişim deseni, hatta yanıt boyutu.

### 1.3 Ve K32 bu açığı **büyüttü**

Faz 2'de bcrypt'ten Argon2id'ye geçtik. Argon2id daha yavaş — bu iyi bir şeydi.
Ama yavaşlık aynı zamanda **sinyali güçlendirir**:

| Algoritma | Doğrulama süresi | Sinyal/gürültü oranı |
|---|---|---|
| Düz karşılaştırma | ~0.001 ms | Ölçülemez |
| bcrypt (cost 12) | ~50 ms | Ölçülebilir |
| **Argon2id (64 MB, t=4)** | **~200 ms** | **Çok net** |

Bir güvenlik kararının başka bir açığı büyütmesi — K36'da (rate limit) da aynı
şeyi görmüştük. **Sistemler bir yerden sertleşince başka bir yerden esner.**

---

## 2. Savunma: sahte hash

```php
$hash = $user?->password ?? self::dummyHash();

$passwordMatches = Hash::check($credentials['password'], $hash);

if ($user === null || ! $passwordMatches) {
    throw new InvalidCredentialsException;
}
```

Fikir basit: **kullanıcı bulunamasa bile Argon2id'yi çalıştır.** İş yükü her iki
yolda da aynı olur, süre farkı kaybolur.

| Durum | Yapılan iş | Süre |
|---|---|---|
| Kullanıcı yok | SQL + Argon2id (**sahte hash'e karşı**) | ~200 ms |
| Kullanıcı var, parola yanlış | SQL + Argon2id | ~200 ms |
| Kullanıcı var, parola doğru | SQL + Argon2id + token | ~205 ms |

### 2.1 🔴 Sıra değiştirilemez

```php
$passwordMatches = Hash::check(...);          // 1. ÖNCE hesapla

if ($user === null || ! $passwordMatches) {   // 2. SONRA karar ver
```

Şunu yazsaydık savunma **çökerdi**:

```php
if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
//    ↑ true olursa PHP sağ tarafı HİÇ değerlendirmez (kısa devre)
```

PHP'nin `||` operatörü **kısa devre** yapar: sol taraf `true` olduğunda sağ taraf
çalışmaz. Kullanıcı yoksa `Hash::check` hiç koşmaz ve 100 katlık fark geri gelir.

Bu yüzden hesaplama ayrı bir satırda, koşuldan **önce** yapılıyor.

> **Genel ders:** Güvenlik kodunda **kısa devre değerlendirmesi bir tuzaktır.**
> "Gereksiz iş yapma" sezgisi burada tersine çalışır: gereksiz işi yapmak
> savunmanın kendisidir.

### 2.2 Sahte hash neden çalışma anında üretiliyor?

```php
private static function dummyHash(): string
{
    return self::$dummyHash ??= Hash::make(self::DUMMY_PASSWORD);
}
```

Sabit bir string gömmek daha basit olurdu:

```php
private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$...';   // ❌
```

Ama hash'in **parametreleri kendi içinde yazılıdır** (`m=65536,t=4`). Gömülü
hash'in maliyeti o parametrelere göre sabitlenir. `ARGON_MEMORY` değişince:

| Ortam | Gerçek kullanıcı hash'i | Gömülü sahte hash | Sonuç |
|---|---|---|---|
| Test (`m=1024`) | ~2 ms | ~200 ms (m=65536) | 🔴 Fark **tersine** döner |
| Faz 9 (m düşürülürse) | daha hızlı | eski değerde kalır | 🔴 Fark oluşur |

Çalışma anında üretilince sahte hash **her zaman güncel ayarları** taşır ve
süreler eşitlenir.

`??=` (memoization) sayesinde süreç başına yalnızca bir kez hesaplanır — bkz.
[`UserFactory.md`](../../../database/factories/UserFactory.md) §2.2, aynı desen.

### 2.3 Savunma mükemmel mi? Hayır — ve bunu bilmek önemli

Kalan küçük farklar:

| Kaynak | Büyüklük |
|---|---|
| SQL sorgusunun satır döndürüp döndürmemesi | ~0.1 ms |
| İlk başarısız girişte `dummyHash()` üretimi (make + check) | bir kez ~200 ms fazla |
| Başarılı girişte token üretimi ve olası rehash | ~5 ms |

200 ms'lik ana sinyalin yanında bunlar gürültüye gömülür. **Ama asıl savunma
tek başına bu değil:** K36'daki rate limit, saldırganın istatistik için gereken
**örnek sayısını** toplamasını engeller. Dakikada 5 denemeyle ortalama almak
pratik olmaktan çıkar.

> İkisi birlikte çalışır: **zamanlama savunması sinyali zayıflatır, rate limit
> örneklemeyi engeller.** Katmanlı savunma (defense in depth) budur.

---

## 3. Diğer kararlar

### 3.1 `$user?->password` — nullsafe operatörü

```php
$hash = $user?->password ?? self::dummyHash();
```

`?->` PHP 8 ile geldi: sol taraf `null` ise ifade **tümüyle** `null` olur ve
hata fırlamaz. TypeScript'teki `?.` ile aynı.

Bu satır olmadan `$user->password` yazsaydık, kullanıcı bulunamadığında
`Error: Attempt to read property on null` alırdık → 500.

Zincir: `$user?->password` (null olabilir) → `??` (null ise sağdakini al) →
sonuç her zaman `string`.

### 3.2 `rehashIfNeeded()` — bir sözü tutmak

```php
if (! Hash::needsRehash($user->password)) {
    return;
}

$user->password = $plainPassword;   // 'hashed' cast'i güncel ayarla hash'ler
$user->save();
```

`config/hashing.php`'de `'rehash_on_login' => true` yazıyor ve
[`hashing.md`](../../../config/hashing.md) §5'te şu söz verilmişti:

> *"Değerleri düşürmek gerekirse bu geriye dönük uyumlu bir değişikliktir:
> `rehash_on_login` sayesinde eski hash'ler girişte sessizce güncellenir."*

🔴 **Bu söz kendiliğinden tutulmuyordu.** `rehash_on_login` ayarı Laravel'in
**kendi guard'ı** (`Auth::attempt()`) tarafından okunur. Biz `Hash::check()` ile
manuel doğrulama yapıyoruz, dolayısıyla o ayar bizim yolumuzda **etkisiz**.

Bu üç satır sözü tutuyor. `Hash::needsRehash()` hash'in içine gömülü
parametreleri güncel yapılandırmayla karşılaştırır; farklıysa `true` döner.

Ham parola **yalnızca bu anda** elimizde — kaçırılırsa bir sonraki girişe kadar
fırsat yok. Bu yüzden tam burada yapılıyor.

> **Genel ders:** Bir dokümanda verilen söz, kodda karşılığı yoksa yalandır.
> Bu satırlar, `hashing.md` yazılırken farkında olmadan verilmiş bir sözün
> ödenmesidir. Dokümanları kodla **çapraz kontrol etmek** faz kapanışlarının
> asıl işidir.

### 3.3 Neden `Auth::attempt()` kullanmadık?

Laravel'in hazır yolu var:

```php
if (! Auth::attempt($credentials)) { ... }     // ❌ kullanmadık
```

Üç sebep:

| Sorun | Açıklama |
|---|---|
| **Oturum kurar** | `Auth::attempt` web guard'ıyla çalışır, session başlatır. Biz **token tabanlıyız** (K5); session gereksiz yük ve gereksiz çerez |
| **Zamanlama savunması yok** | Laravel'in `attempt`'i kullanıcı bulunamazsa erken döner |
| **Şeffaflık** | Bu bir öğrenme projesi; `attempt()` içinde ne olduğunu görmemek Faz 2'nin amacına aykırı |

`rehash_on_login`'in etkisiz kalması (§3.2) bu tercihin **görünmeyen bedeliydi**
ve elle ödendi.

### 3.4 Token her girişte yeniden üretiliyor

`createToken()` her başarılı girişte **yeni** bir kayıt açar. Eskiler silinmez.

Bu bilinçli: kullanıcı telefondan ve bilgisayardan aynı anda girebilmeli.
Telefondan çıkış yapmak bilgisayardaki oturumu düşürmemeli — 2.9'daki
`logout` yalnızca **o anki** token'ı silecek.

> ⚠️ Bilinen sınır: token'ların süresi yok (`config/sanctum.php` →
> `expiration => null`) ve eskiyenler temizlenmiyor. Uzun vadede
> `personal_access_tokens` tablosu büyür. Faz 9'da `sanctum:prune-expired`
> zamanlanmış görevi eklenecek.

### 3.5 `TOKEN_NAME` neden ikisinde de `'api'`?

`RegisterUserAction` ile aynı etiket. Token'ın **nereden geldiği** ayırt
edilmemeli — aksi hâlde veritabanına erişen biri "bu kullanıcı yeni kaydolmuş"
gibi çıkarımlar yapabilir. Küçük bir detay ama aynı ilkenin devamı: **gereksiz
bilgi üretme.**

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `if ($user === null) throw` erken çıkışı | 🔴 Zamanlama saldırısı açığı | Sahte hash'e karşı kontrol |
| `Hash::check`'i koşulun **içine** yazmak | 🔴 Kısa devre → savunma çöker | Ayrı satırda, önce |
| Sabit bir hash string'i gömmek | Ayar değişince süreler ayrışır | Çalışma anında üret |
| `Auth::attempt()` kullanmak | Session kurar, zamanlama savunması yok | Manuel `Hash::check` |
| İki farklı exception fırlatmak | Enumeration açığı | Tek `InvalidCredentialsException` |
| `rehashIfNeeded` atlamak | `rehash_on_login` sözü tutulmaz | Elle uygula |
| `$user->password` (nullsafe'siz) | Kullanıcı yoksa 500 | `$user?->password` |
| Her girişte eski token'ları silmek | Diğer cihazlardaki oturum düşer | Yalnızca yeni token üret |

---

## 5. Kendin dene

```powershell
php artisan migrate:fresh
php artisan tinker
```

**1. Bir kullanıcı üret:**

```php
$u = App\Models\User::factory()->create(['email' => 'ayse@ornek.test']);
$action = new App\Actions\Auth\LoginUserAction;
$sifre = Database\Factories\UserFactory::PASSWORD;   // 'password'
```

**2. Doğru parola:**

```php
$r = $action->handle(['email' => 'ayse@ornek.test', 'password' => $sifre]);
$r['token'];      // "1|..." — yeni token
```

**3. Yanlış parola:**

```php
$action->handle(['email' => 'ayse@ornek.test', 'password' => 'yanlis']);
// App\Exceptions\InvalidCredentialsException
```

**4. Olmayan kullanıcı — aynı exception:**

```php
$action->handle(['email' => 'hicyok@ornek.test', 'password' => 'yanlis']);
// App\Exceptions\InvalidCredentialsException   ← AYNI
```

**5. 🔴 Asıl deneme: süreleri ölç**

```php
$olc = function (string $email) use ($action) {
    $t = microtime(true);
    try { $action->handle(['email' => $email, 'password' => 'yanlis']); } catch (Throwable) {}
    return round((microtime(true) - $t) * 1000).' ms';
};

$olc('ayse@ornek.test');      // kayıtlı
$olc('hicyok@ornek.test');    // kayıtsız
```

İkisi **birbirine yakın** olmalı. Birkaç kez çalıştır (ilk çağrı sahte hash'i
üretirken bir defalık fazladan maliyet taşır).

**6. Savunmayı geçici olarak kır ve farkı gör.** `handle()` metodunun başına
şunu ekle, ölç, sonra **geri al**:

```php
if ($user === null) { throw new InvalidCredentialsException; }   // GEÇİCİ
```

Şimdi `$olc('hicyok@ornek.test')` **~2 ms**, `$olc('ayse@ornek.test')` **~200 ms**
dönecek. Saldırganın gördüğü şey tam olarak budur.

Bu tek deneme, dosyanın varlık sebebini kalıcı olarak anlatır.

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Zamanlama saldırısı** | İşlem süresini ölçerek bilgi çıkarma |
| **Yan kanal (side-channel)** | İçerik dışı gözlemlerden sızan bilgi |
| **Kısa devre** | `\|\|` ve `&&`'de sol taraf sonucu belirlerse sağın çalışmaması |
| **Nullsafe operatör** | `?->` — sol taraf null ise ifade null olur |
| **Memoization** | Pahalı hesabın sonucunu saklayıp tekrar kullanma |
| **Rehash** | Parametreler eskiyince hash'i yeniden üretme |
| **Guard** | Laravel'de kimlik doğrulama stratejisi (session, token…) |
| **Defense in depth** | Tek savunmaya güvenmeyip katmanlama |

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| Action deseni | [`RegisterUserAction.md`](RegisterUserAction.md) §1 |
| Fırlattığı exception | [`InvalidCredentialsException.md`](../../Exceptions/InvalidCredentialsException.md) |
| Hash ayarları ve `rehash_on_login` | [`config/hashing.md`](../../../config/hashing.md) |
| Rate limit (ikinci katman) | [`AppServiceProvider.md`](../../Providers/AppServiceProvider.md) §5.5 |
| Enumeration kuralı (H6) | `docs/08-HATA-SOZLESMESI.md` §3.1 |
| Sıradaki dosya | `AuthController::login()` + rota (2.8d) |
