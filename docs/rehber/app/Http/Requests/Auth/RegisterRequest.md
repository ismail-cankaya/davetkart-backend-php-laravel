# `app/Http/Requests/Auth/RegisterRequest.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Http/Requests/Auth/RegisterRequest.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.4
> **Bağlantılı:** `docs/08-HATA-SOZLESMESI.md` §3.1 (enumeration) ·
> [`app/Models/User.md`](../../../Models/User.md) §3.6 ·
> [`CLAUDE.md`](../../../../../../CLAUDE.md) §1

---

## 0. Bir dakikalık özet

Bu dosya **kapıdaki güvenlik görevlisidir**. Controller'a ulaşan veri buradan
geçmiş, kırpılmış, küçültülmüş ve doğrulanmış olur.

Dört iş yapıyor:

| Metot | İşi |
|---|---|
| `authorize()` | Bu kişi bu işlemi yapabilir mi? (kayıtta: herkes) |
| `prepareForValidation()` | Doğrulamadan **önce** temizle (trim, küçült) |
| `rules()` | Doğrula |
| `userAttributes()` | camelCase girdiyi snake_case kolonlara eşle |

🔴 **Ve bir şeyi bilerek yapmıyor: `unique` kontrolü.** Sebebi bu dosyanın en
önemli kararıdır — [§3.1](#31--unique-kuralı-neden-yok--enumeration-savunması).

---

## 1. FormRequest neden var?

Doğrulamayı controller içinde de yazabilirdik:

```php
public function register(Request $request)
{
    $data = $request->validate([...]);      // çalışır
}
```

Ama üç şey kaybederdik:

| Kayıp | Açıklama |
|---|---|
| **Tek sorumluluk** | Controller hem doğrular hem yönlendirir; `CLAUDE.md` §1 controller'ı 3-8 satırla sınırlıyor |
| **Yeniden kullanım** | Aynı kurallar başka bir uçta lazım olursa kopyalanır |
| **Test edilebilirlik** | Kuralları HTTP'siz test edemezsin |

Ayrıca FormRequest **otomatik** çalışır: Laravel controller metodunun imzasında
`RegisterRequest $request` görürse, controller'a girmeden önce doğrulamayı
yapar. Doğrulama başarısızsa controller **hiç çağrılmaz**.

```
İstek → prepareForValidation() → authorize() → rules() → ✅ Controller
                                                       └─ ❌ ValidationException
```

Exception fırladığında Faz 1'de kurduğumuz `ApiExceptionRenderer` devreye girer
ve `422 VALIDATION_FAILED` üretir. **Controller bu yolu hiç görmez** — H10
kuralı ("Action ve Controller hata yanıtı üretmez") burada bedava gelir.

---

## 2. PHP ve Laravel temelleri

### 2.1 `authorize()` — neden `true`?

```php
public function authorize(): bool
{
    return true;
}
```

`false` dönerse Laravel `AuthorizationException` fırlatır. Kayıt olmak için
kimlik gerekmediğinden herkese açık.

> ⚠️ `make:request` iskeleti bazı Laravel sürümlerinde `false` üretir. Farkında
> olmadan bırakılırsa **her istek 404 döner** (H7 gereği `AuthorizationException`
> → `RESOURCE_NOT_FOUND`) ve sebebi bulunması zordur.

Faz 3'te bu metot gerçekten iş yapacak: `UpdateInvitationRequest`'te
*"bu davetiye bu kullanıcının mı?"* sorusu buraya değil **Policy**'ye gidecek
(M4 kuralı) — ama basit sahiplik kontrolleri burada da yaşayabilir.

### 2.2 `rules()` — dizi mi string mi?

İki yazım eşdeğerdir:

```php
'firstName' => 'required|string|max:60',      // boru ile
'firstName' => ['required', 'string', 'max:60'],   // dizi ile  ← bizim
```

Dizi biçimini seçtik çünkü boru karakteri, `regex:/^a|b$/` gibi kuralların
**içinde** geçtiğinde ayrıştırmayı bozar. Dizi biçimi her durumda güvenlidir.

Dizi biçimi ayrıca **kural nesnelerini** (`Password::min(8)` gibi) kabul eden tek
biçimdir — ama biz onları bilerek kullanmıyoruz, sebebi §3.5'te.

### 2.3 `prepareForValidation()` — sıra kritik

```
prepareForValidation()  →  rules()  →  validated()
      ↑ HAM VERİ            ↑ temizlenmiş veriyi doğrular
```

Bu metot doğrulamadan **önce** çalışır ve `merge()` ile girdiyi değiştirir.
Değiştirilmiş girdi hem kurallara hem `validated()`'a yansır.

### 2.4 `validated()` ile `all()` farkı — ihlal edilemez kural

| Metot | Ne döndürür |
|---|---|
| `all()` | 🔴 İsteğin **gönderdiği her şey** |
| `validated()` | ✅ Yalnızca **kuralları olan** alanlar |

Saldırgan şunu gönderirse:

```json
{ "firstName": "Ali", "lastName": "Veli", "email": "a@b.com",
  "password": "12345678", "is_admin": true, "id": 1 }
```

`all()` bu diziyi olduğu gibi verir. `User::create()`'e giderse `$fillable`
beyaz listesi ikinci savunma olarak devreye girer — ama **ilk savunma budur**.

`validated()` `is_admin` ve `id` anahtarlarını hiç döndürmez, çünkü onların
kuralı yok. `CLAUDE.md` §1 ve Faz 0'ın kural listesi bunu zorunlu tutar.

> Bu dosyada `userAttributes()` metodunun `validated()` çağırmasının sebebi
> tam olarak budur. `$this->input()` veya `$this->string()` kullanmak
> **doğrulanmamış** veriye dönmek olurdu.

---

## 3. Alınan kararlar

### 3.1 🔴 `unique` kuralı neden YOK? — enumeration savunması

Refleks olarak şunu yazmak isterdik:

```php
'email' => ['required', 'email', 'unique:users,email'],   // ❌ YAZMADIK
```

Yazsaydık, kayıtlı bir e-posta gönderildiğinde yanıt şu olurdu:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "fields": { "email": [{ "rule": "unique" }] }
  }
}
```

Bu yanıt saldırgana **kesin bir bilgi** verir: *"bu e-posta bizde kayıtlı."*

**Saldırı senaryosu:** Elindeki 10.000 e-postalık sızıntı listesini kayıt
formuna tek tek gönderir. `unique` hatası alanları işaretler. Kayıt formu bir
**hesap tarayıcısına** dönüşür. Sonuç: hedefli oltalama ("DavetKart hesabınız
askıya alındı"), parola püskürtme saldırısı, veya listeyi satmak.

`docs/08-HATA-SOZLESMESI.md` §3.1 bu yüzden şart koşar:

| Endpoint | ❌ Yasak | ✅ Zorunlu |
|---|---|---|
| `POST /auth/register` | `fields: {email: [{rule: "unique"}]}` | `REGISTRATION_FAILED`, `fields` **yok** |

**Peki benzersizlik nasıl korunuyor?** İki katmanda:

```
1. Veritabanı    UNIQUE(email) kısıtı           ← asıl garanti, atlanamaz
2. Action        kısıt ihlalini yakalar → REGISTRATION_FAILED (fields'sız)
```

Yani kontrol kaybolmadı, **yeri değişti** (2.5). Ve orada yapılması aslında
daha doğru: `unique` kuralı ile `INSERT` arasında geçen mikrosaniyelerde başka
bir istek aynı e-postayı kaydedebilir (*race condition*). Veritabanı kısıtına
dayanmak bu yarışı yapısal olarak kazanır.

> **Genel ilke:** *Bir hata mesajı, bilmediği bir şeyi karşı tarafa öğretmemeli.*
> Diğer doğrulama hataları (`password` çok kısa, `email` biçimsiz) serbestçe
> `fields` döndürebilir — çünkü onlar kullanıcının **kendi gönderdiği** veri
> hakkındadır, sistemin durumu hakkında değil. Fark budur.

### 3.2 Alan adları neden camelCase?

```php
'firstName' => [...],     // firstName, first_name değil
```

Çünkü **istek bunu gönderiyor**:

```ts
// davetkart-frontent/src/types.ts
export interface RegisterPayload {
  firstName: string; lastName: string; email: string; password: string;
}
```

Doğrulama, gelen veriye bakar. Kuralı `first_name` yazsaydık *"first_name alanı
zorunludur"* hatası alırdık — frontend öyle bir alan göndermiyor.

Ve hata zarfındaki `fields` anahtarları da bu adları taşır (`08` §2.4), böylece
frontend hatayı doğru input'un altında gösterebilir. snake_case dönüşümü yalnızca
**çıkışta**, Resource katmanında olur (`CLAUDE.md` §1).

### 3.3 `prepareForValidation()` — güvenlik detayı

```php
foreach (['firstName', 'lastName', 'email'] as $key) {
    $value = $this->input($key);

    if (is_string($value)) {              // ← 🔴 bu kontrol şart
        $normalized[$key] = trim($value);
    }
}
```

**Neden `is_string` kontrolü?** Çünkü bu metot doğrulamadan **önce** çalışır;
buradaki veri tamamen güvenilmezdir. Saldırgan şunu gönderebilir:

```
POST /api/auth/register
email[]=a@b.com&email[]=c@d.com
```

`$this->input('email')` bir **dizi** döner. `mb_strtolower(dizi)` → `TypeError`
→ yakalanmamış exception → **500 Server Error**. Doğrulama kuralları hiç
çalışmadan uygulama patlar.

Kontrol sayesinde dizi olduğu gibi geçer ve `'string'` kuralı onu düzgünce
reddeder: `422 VALIDATION_FAILED`.

> **Genel ders:** `prepareForValidation()` doğrulamanın **öncesindedir**, yani
> onun içindeki kod doğrulanmış veri varsayamaz. Burası hâlâ düşman
> topraklarıdır.

### 3.4 E-posta normalizasyonu — 2.1'deki borcun kapanışı

`User` modelinde bir mutator var: kolona yalnızca küçük harf yazılabiliyor.
Peki neden burada da yapıyoruz?

**Çünkü mutator çok geç çalışır.** Sıralamaya bak:

```
prepareForValidation()  →  (burada olmazsa)  →  rules()  →  Action  →  Model mutator
                                                  ↑ ham girdiyle sorgular
```

`unique` kuralı kullansaydık (ki kullanmıyoruz) veya Action `where('email', ...)`
ile arama yapsaydı, sorgu **normalize edilmemiş** değerle çalışırdı:

```
Gönderilen:  Ismail@Gmail.com
Aranan:      Ismail@Gmail.com   →  bulunamaz
Kaydedilen:  ismail@gmail.com   →  UNIQUE ihlali → 500
```

İki katman aynı işi yapmıyor, **iki farklı anı** koruyorlar:

| Katman | Neyi garanti eder |
|---|---|
| `prepareForValidation()` | Doğrulama ve **sorgular** normalize değerle çalışır |
| Model mutator | Kolona **hangi yoldan** gelirse gelsin küçük harf yazılır (seeder, tinker) |

### 3.5 Parola kuralı — neden karmaşıklık zorunluluğu yok?

```php
'password' => ['required', 'string', 'min:8', 'max:255'],
```

Büyük harf, rakam, sembol **zorunlu tutulmadı**. Bu bilinçli.

**NIST SP 800-63B** (dünyanın en çok referans verilen parola standardı)
kompozisyon kurallarını açıkça **önermez**. Sebebi ölçülmüş insan davranışıdır:
"en az bir büyük harf, bir rakam, bir sembol" denildiğinde kullanıcıların ezici
çoğunluğu `Parola1!` üretir — tahmin edilebilirlik **artar**, entropi artmaz.

NIST'in önerdiği: **uzunluk** + bilinen sızmış parolaların engellenmesi.

| Kural | Durumumuz |
|---|---|
| Minimum uzunluk | ✅ `min:8` |
| Sızmış parola kontrolü | ⬜ `->uncompromised()` mevcut, **eklenmedi** |
| Kompozisyon zorunluluğu | ❌ Bilerek yok |

`uncompromised()` neden yok? HaveIBeenPwned API'sine **ağ çağrısı** yapar. Üç
sorun: `api.ts` timeout'u 15 saniye, servis çökerse kayıt akışı kilitlenir,
çevrimdışı geliştirmede testler kırılır. Faz 9'da kuyruk veya yerel liste ile
yeniden değerlendirilecek.

#### 🔴 Neden `Password::min(8)` değil de `'min:8'`? (D6)

Bu satır ilk yazımda `Password::min(8)` idi. **Yanlıştı** ve hatayı Faz 3'te
`composer check` yakaladı.

Sebep, doğrulama hatasının nasıl raporlandığıyla ilgili. K20 gereği API hata
**metni** değil hata **kodu** döndürür; başarısız olan kuralın **adı** yanıta
girer:

```json
{ "error": { "code": "VALIDATION_FAILED",
             "fields": { "password": [ { "rule": "min", "params": [8] } ] } } }
```

Frontend bu `rule` değerini bir çeviri anahtarı gibi kullanır: `min` + `8` →
"En az 8 karakter". Yani **kural adı API sözleşmesinin parçasıdır.**

Laravel bir kural **nesnesi** başarısız olduğunda onu sınıf adıyla raporlar.
`Password::min(8)` kullanınca yanıt şuna dönüşüyordu:

```
"rule": "illuminate\_validation\_rules\_password"
```

Üç ayrı sorun:

| Sorun | Neden önemli |
|---|---|
| **Kararsız** | Laravel o sınıfı taşırsa bizim public API'miz değişir |
| **Sızıntı** | Yanıt hangi framework'ü kullandığımızı söyler |
| **Kullanılamaz** | Frontend bunu neye çevirecek? Uzunluk bilgisi kayıp |

Buradan çıkan kural — **D6:** *doğrulama kuralının adı API sözleşmesinin
parçasıdır; bu yüzden kural nesnesi değil, adı sabit string kural tercih edilir.*

İleride parola politikasını sertleştirmek gerekirse (`uncompromised()` gibi),
kural nesnesini geri getirmek yerine ya açık string kurallar yazılır ya da
`ApiExceptionRenderer`'a sınıf → sabit kod eşlemesi eklenir. Sözleşmeye
framework iç adı **hiçbir durumda** girmez.

**`max:255` neden var?** Girdiyi sınırsız bırakmak, saldırganın 10 MB'lık bir
parola gönderip Argon2id'ye onu hash'lettirmesine izin verir — ucuz bir DoS
yüzeyi. Sınır bunu kapatır.

### 3.6 `email:rfc` — neden `dns` değil?

```php
'email' => ['required', 'string', 'email:rfc', 'max:255'],
```

Laravel'in `email` kuralının birkaç kipi var:

| Kip | Ne yapar | Bizde |
|---|---|---|
| `rfc` | Biçimi RFC'ye göre doğrular | ✅ |
| `dns` | Alan adının **MX kaydını sorgular** | ❌ |
| `spoof` | Homograf (görsel taklit) karakter kontrolü | ❌ |

`dns` cazip görünür ama her kayıt isteğine bir **DNS sorgusu** ekler: gecikme
ekler, DNS sunucusu yavaşsa istek asılı kalır, ve testler ağ bağlantısına
bağımlı hâle gelir. Sahte ama biçimsel olarak geçerli adresleri de zaten
engellemez.

Gerçek çözüm ileride **e-posta doğrulama linki** göndermektir — o zaman adresin
çalıştığı kanıtlanır, tahmin edilmez.

### 3.7 `userAttributes()` — eşleme neden burada?

```php
'first_name' => $data['firstName'],
```

camelCase → snake_case eşlemesi bir yerde yapılmalı. Üç aday vardı:

| Nerede | Değerlendirme |
|---|---|
| Controller | Controller 3-8 satır olmalı, eşleme onu şişirir |
| Action | 🔴 Action'ın **HTTP alan adlarını bilmesi** katman ihlalidir |
| **FormRequest** ✅ | HTTP sınırının kendisi; alan adlarını bilmek zaten işi |

Belirleyici sınav Faz 3: `UpdateInvitationRequest` **28 camelCase alan**
taşıyacak. O eşlemeyi Action'ın içine koymak, iş kuralını 28 satırlık bir
çeviri tablosunun altına gömer.

`CLAUDE.md` §1 *"snake_case → camelCase dönüşümü yalnızca Resource'ta"* der —
dikkat, o **çıkış** yönüdür (DB → API). Bu metot **giriş** yönüdür (API → DB) ve
onun karşılığıdır.

> **Simetriyi aklında tut:**
> `RegisterRequest::userAttributes()` → API'den DB'ye
> `UserResource::toArray()` → DB'den API'ye

### 3.8 `@var` etiketi — kaçış kapısı, gerekçesiyle

```php
/** @var array{firstName: string, lastName: string, email: string, password: string} $data */
$data = $this->validated();
```

`validated()` PHPStan için `array<string, mixed>` döndürür — Laravel şekli
bilemez. `mixed` bir değeri `string` beyan eden bir diziye koymak PHPStan level
6'da hata verir.

`@var` ile *"burada ne geleceğini biliyorum"* diyoruz. Bu bir **kaçış kapısıdır**
ve yanlış söylersen PHPStan sana inanır. Burada meşru: `rules()` bu dört alanın
varlığını ve `string` olduğunu garanti ediyor; garanti etmese doğrulama zaten
geçmezdi.

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `unique:users,email` eklemek | 🔴 Enumeration açığı | Kontrol Action'da (§3.1) |
| `authorize()`'ı `false` bırakmak | Her istek 404 | `return true` |
| `all()` kullanmak | Beklenmeyen alanlar geçer | `validated()` |
| `prepareForValidation`'da `is_string` atlamak | `email[]=x` → 500 | Tip kontrolü şart |
| Kuralları snake_case yazmak | "first_name zorunludur" — frontend öyle göndermiyor | camelCase |
| `email:dns` kullanmak | Her isteğe DNS sorgusu, testler ağa bağlanır | `email:rfc` |
| Kompozisyon kuralları eklemek | `Parola1!` üretir, entropiyi düşürür | Uzunluk (NIST) |
| `max` sınırı koymamak | 10 MB parola → Argon2id DoS | `max:255` |
| Action'da tekrar doğrulamak | İki doğruluk kaynağı | Action veriyi temiz kabul eder |

---

## 5. Kendin dene

Bu dosya tek başına çalışmaz — rota 2.7'de açılacak. Şimdilik kuralları
`tinker`'da doğrudan sınayabilirsin:

```powershell
php artisan tinker
```

**1. Kısa parola reddediliyor mu?**

```php
Validator::make(
    ['firstName' => 'Ali', 'lastName' => 'Veli', 'email' => 'a@b.com', 'password' => '123'],
    (new App\Http\Requests\Auth\RegisterRequest)->rules()
)->fails();     // true
```

**2. Hangi kural ihlal edildi?** — hata zarfının kaynağı (Faz 1, ders 16):

```php
$v = Validator::make(
    ['firstName' => 'Ali', 'lastName' => 'Veli', 'email' => 'gecersiz', 'password' => '12345678'],
    (new App\Http\Requests\Auth\RegisterRequest)->rules()
);
$v->fails();
$v->failed();      // ['email' => ['Email' => []]]  ← METİN değil KURAL ADI
```

Bu çıktı K20'yi teknik olarak mümkün kılan şeydir: `$v->errors()` çevrilmiş
**cümle** döndürürdü, `failed()` ise **kural adını** verir.

**3. 60 karakter sınırı:**

```php
Validator::make(
    ['firstName' => str_repeat('a', 61), 'lastName' => 'V', 'email' => 'a@b.com', 'password' => '12345678'],
    (new App\Http\Requests\Auth\RegisterRequest)->rules()
)->fails();     // true — veritabanına hiç gitmeden yakalandı
```

Bu, migration kılavuzu §3.2'deki katmanlı savunmanın üst katmanı: doğrulama
önce yakalar, `VARCHAR(60)` kısıtı yine de arkada durur.

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **FormRequest** | Doğrulama ve yetkiyi taşıyan istek sınıfı |
| **User enumeration** | Hata farkından kayıtlı hesapları tespit etme açığı |
| **Race condition** | İki eşzamanlı işlemin sıraya bağlı hatalı sonuç üretmesi |
| **Normalizasyon** | Veriyi tek bir kanonik biçime indirgeme |
| **Entropi** | Bir parolanın tahmin edilebilirlik ölçüsü |
| **NIST SP 800-63B** | ABD standardı — dijital kimlik ve parola rehberi |
| **DoS** | Kaynak tüketerek servisi durdurma saldırısı |
| **MX kaydı** | Bir alan adının posta sunucusunu bildiren DNS kaydı |
| **Kaçış kapısı** | Aracın denetimini bilinçli olarak devre dışı bırakma |

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| Enumeration kuralı (H6) | `docs/08-HATA-SOZLESMESI.md` §3.1 |
| E-posta mutator'ı | [`app/Models/User.md`](../../../Models/User.md) §3.6 |
| Kolon uzunluğu kısıtı | [`create_users_table.md`](../../../../database/migrations/0001_01_01_000000_create_users_table.md) §3.2 |
| Hata zarfı üretimi | [`ApiExceptionRenderer.md`](../../../Exceptions/ApiExceptionRenderer.md) |
| Katman sorumlulukları | [`CLAUDE.md`](../../../../../../CLAUDE.md) §1 |
| Sıradaki dosya | `app/Actions/Auth/RegisterUserAction.php` (2.5) |
