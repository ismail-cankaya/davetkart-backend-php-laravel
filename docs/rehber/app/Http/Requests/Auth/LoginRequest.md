# `app/Http/Requests/Auth/LoginRequest.php` — Eğitim Dokümanı

> **Kapsanan dosya:** `app/Http/Requests/Auth/LoginRequest.php`
> **Yol haritasındaki yeri:** Faz 2, dosya 2.8b
> **Bağlantılı:** [`RegisterRequest.md`](RegisterRequest.md) (FormRequest temelleri orada) ·
> `docs/08-HATA-SOZLESMESI.md` §3.1 ·
> [`AppServiceProvider.md`](../../../Providers/AppServiceProvider.md) §5.5

---

## 0. Bir dakikalık özet

`RegisterRequest`'ten **daha kısa** — ve kısalığın her satırı bilinçli.

```php
'email' => ['required', 'string', 'email:rfc', 'max:255'],
'password' => ['required', 'string', 'max:255'],
```

İki alan, altı kural. Bu dosyanın öğrettiği şey **ne yazdığımız değil, ne
yazmadığımız**:

| Yazmadığımız | Neden |
|---|---|
| `Password::min(8)` | 🔴 Mevcut kullanıcıları kilitler (§3.1) |
| `exists:users,email` | 🔴 Enumeration açığı (§3.2) |
| `confirmed` | Giriş formunda parola tekrarı yok |

> FormRequest'in genel mekaniği ([`RegisterRequest.md`](RegisterRequest.md) §1-2)
> burada tekrarlanmayacak. Bu doküman yalnızca **farkları** anlatır.

---

## 1. Giriş, kaydın aynası değildir

Refleks olarak `RegisterRequest`'i kopyalayıp e-postayla parolayı bırakmak
isterdik. Yanlış olurdu — çünkü iki uç noktanın **soruları farklı**:

| | `register` | `login` |
|---|---|---|
| Sorusu | *"Bu veri **kabul edilebilir** mi?"* | *"Bu kişi **o kişi** mi?"* |
| Veri | Yeni, henüz hiçbir kurala uymuyor | Eski, zaten kurallara uymuş |
| Doğrulama işi | Kalite standardı uygulamak | İsteği **taşınabilir** hâle getirmek |

Kayıt bir **kapı görevlisidir** (evrakın tam mı?). Giriş bir **kimlik
kontrolüdür** (bu sen misin?). Kimlik kontrolünde "parolanız yeterince güçlü
değil" demek anlamsızdır — parola zaten kabul edilmiş, kayıtlı bir paroladır.

---

## 2. Alınan kararlar

### 2.1 🔴 `Password::min(8)` neden YOK — en kritik karar

Giriş formuna parola gücü kuralı koymak sezgisel görünür ama **ciddi bir hata**dır.

**Senaryo:** Bugün minimum 8 karakter. Altı ay sonra politikayı 12'ye
yükseltiyorsun (`Password::min(12)`) ve aynı kuralı `LoginRequest`'e de
kopyalamışsın.

```
Ayşe'nin parolası:  "gizli1234"  (9 karakter — kayıt olduğunda geçerliydi)
                              ↓
Giriş denemesi → LoginRequest → min:12 ihlali → 422
                              ↓
Ayşe DOĞRU parolasıyla hesabına GİREMİYOR.
```

Ayşe'nin parolası yanlış değil; **kuralımız değişti** ve biz onu geçmişe dönük
uyguladık. Ayşe'ye "parolanızı sıfırlayın" demekten başka çare kalmaz — kendi
hatamız yüzünden.

> **Genel ilke:** Bir kural **veri üretilirken** uygulanır, **veri okunurken**
> değil. Kayıt üretim anıdır, giriş okuma anıdır.
>
> Bu, veritabanı dünyasındaki *"kısıtlar yazmada uygulanır"* ilkesiyle aynı
> fikirdir. Eski satırları yeni kurala göre reddeden bir sistem kullanılamaz hâle
> gelir.

**İkincil sebep:** Kural, **parola politikasını sızdırır**. Saldırgan
`"a"` gönderip *"en az 8 karakter"* hatası alırsa, sözlük saldırısının arama
uzayını daraltır — 1-7 karakterlik tüm adayları eler.

**Peki `max:255` neden var?** O bir *kalite* kuralı değil, bir **kaynak
sınırıdır**. 10 MB'lık bir "parola" gönderilirse `Hash::check()` onu Argon2id'ye
sokar ve sunucu boşuna 64 MB + CPU harcar. Sınır bu kapıyı kapatır — geçmişe
dönük kimseyi etkilemez, çünkü kimsenin parolası 255 karakterden uzun değil
(kayıtta da aynı sınır vardı).

### 2.2 🔴 `exists:users,email` neden YOK

`RegisterRequest`'teki `unique` yasağının aynadaki görüntüsü:

```php
'email' => [..., 'exists:users,email'],   // ❌ ASLA
```

Yazsaydık kayıtlı olmayan bir e-posta şunu döndürürdü:

```json
{"error":{"code":"VALIDATION_FAILED","fields":{"email":[{"rule":"exists"}]}}}
```

Saldırgan **parola bilmeden**, sadece e-posta listesi göndererek hangilerinin
kayıtlı olduğunu öğrenir. Kayıt formundan daha beteri: burada hesap bile
oluşturmuyor, sessizce tarıyor.

`08-HATA-SOZLESMESI.md` §3.1'in şartı:

> `POST /auth/login` → *"Parola hatalı" / "Kullanıcı bulunamadı" ayrımı yasak.*
> Her iki durumda **`INVALID_CREDENTIALS`**, `fields` **yok**.

Kullanıcının varlığı kontrolü `LoginUserAction`'da (2.8c) yapılacak ve sonucu
**tek bir kod** olacak.

### 2.3 `prepareForValidation()` — burada işlevsel bir zorunluluk

`RegisterRequest`'te normalizasyon *veri kalitesi* içindi. Burada **giriş
çalışsın diye** zorunlu.

```
Kayıt:   "Ayse@Ornek.TEST"  → mutator → veritabanında "ayse@ornek.test"
Giriş:   "Ayse@Ornek.TEST"  → küçültülmezse → WHERE email = 'Ayse@Ornek.TEST'
                                            → PostgreSQL harf duyarlı → BULUNAMAZ
```

Sonuç: kullanıcı **doğru parolasıyla** giriş yapamaz ve `INVALID_CREDENTIALS`
alır — sebebini asla anlayamayacağı bir hata.

> Model mutator'ı burada **işe yaramaz**: o yalnızca **yazarken** çalışır.
> Sorgu bir okuma işlemidir. Bu yüzden normalizasyon iki yerde gerekli:
> mutator yazmayı, `prepareForValidation` okumayı korur.

`trim` de duruyor ama `TrimStrings` global middleware'i onu zaten yapıyor
(bkz. [`istek-yasam-dongusu.md`](../../../kavramlar/istek-yasam-dongusu.md) §6.1)
— küçültme ise yalnızca burada yapılıyor.

### 2.4 `email:rfc` — burada bilgi sızdırır mı?

Sızdırmaz. Biçim geçerliliği saldırganın **kendi girdisi** hakkındadır; sistemin
durumu hakkında hiçbir şey söylemez. `"abc"` gönderen biri zaten onun geçerli bir
e-posta olmadığını bilir.

Kazancı: biçimsel olarak imkânsız adresler veritabanına **hiç sorgu atmadan**
elenir. Her elenen istek, ödemediğimiz bir Argon2id maliyetidir.

### 2.5 `credentials()` — `userAttributes()`'ın kardeşi

```php
public function credentials(): array
{
    /** @var array{email: string, password: string} $data */
    $data = $this->validated();

    return $data;
}
```

Burada camelCase → snake_case eşlemesi **yok**, çünkü iki alan da her iki dünyada
aynı ad. Metot yine de var, iki sebeple:

1. **`validated()` zorunluluğunu kapıya koyar.** Controller `$request->all()`
   yazamaz; okuduğu tek şey bu metottur.
2. **Şekilli dönüş tipi.** `array{email: string, password: string}` PHPStan
   tarafından denetlenir; `$credentials['emial']` yazımı yakalanır.

`RegisterRequest::userAttributes()` ile aynı desen — HTTP sınırı, veriyi bir
sonraki katmanın anlayacağı biçimde teslim eder.

---

## 3. Bu dosyanın YAPMADIĞI iş

🔴 **Hız sınırı burada değil.** `login` rotası `throttle:auth` middleware'i
altında; limit `AppServiceProvider::configureRateLimiting()`'de tanımlı (K36).

Neden burada değil? Çünkü rate limit **doğrulamadan önce** çalışmalı. Bu dosya
çalıştığı anda istek zaten kabul edilmiş, doğrulama maliyeti ödenmiştir. Sınırın
işi isteği **daha kapıya varmadan** çevirmektir.

Laravel Breeze bunu `LoginRequest` içinde yapar (`ensureIsNotRateLimited()`); biz
middleware'i tercih ettik çünkü:

| Middleware | FormRequest içinde |
|---|---|
| Doğrulamadan **önce** çalışır | Doğrulamadan sonra |
| `register` ile **paylaşılır** | Her Request'e kopyalanır |
| Hata yolu Faz 1'de hazır (`RATE_LIMITED`) | Elle exception fırlatmak gerekir |

---

## 4. Sık yapılan hatalar

| Hata | Ne olur | Doğrusu |
|---|---|---|
| `Password::min(8)` eklemek | 🔴 Politika değişince mevcut kullanıcılar kilitlenir | Yalnızca `required, string, max` |
| `exists:users,email` eklemek | 🔴 Enumeration açığı | Kontrol Action'da, tek kodla |
| `prepareForValidation` atlamak | Büyük harfli e-postayla giriş **hiç çalışmaz** | Küçültme zorunlu |
| Model mutator'ına güvenmek | Mutator yalnızca **yazarken** çalışır | Okuma için ayrı normalizasyon |
| `max:255` kaldırmak | Dev parolayla Argon2id DoS'u | Kaynak sınırı olarak kalmalı |
| Rate limit'i buraya koymak | Doğrulama maliyeti önce ödenir | Middleware (§3) |
| `all()` kullanmak | Beklenmeyen alanlar geçer | `credentials()` → `validated()` |

---

## 5. Kendin dene

```powershell
php artisan tinker
```

**1. Kısa parola giriş için geçerli mi?** (geçerli olmalı!)

```php
Validator::make(
    ['email' => 'a@b.com', 'password' => 'abc'],
    (new App\Http\Requests\Auth\LoginRequest)->rules()
)->fails();     // false — GEÇTİ
```

Aynı veri `RegisterRequest` kurallarında **kalır**:

```php
Validator::make(
    ['firstName' => 'A', 'lastName' => 'B', 'email' => 'a@b.com', 'password' => 'abc'],
    (new App\Http\Requests\Auth\RegisterRequest)->rules()
)->fails();     // true — kaldı
```

İkisini yan yana koy: **aynı parola, iki farklı uç nokta, iki farklı sonuç.**
§2.1'in somut kanıtı.

**2. Kayıtlı olmayan e-posta doğrulamayı geçiyor mu?** (geçmeli — kontrol Action'da)

```php
Validator::make(
    ['email' => 'hicyok@ornek.test', 'password' => 'abc'],
    (new App\Http\Requests\Auth\LoginRequest)->rules()
)->fails();     // false
```

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Kimlik doğrulama** | "Bu kişi o kişi mi?" — authentication |
| **Yetkilendirme** | "Bu kişi bunu yapabilir mi?" — authorization |
| **User enumeration** | Yanıt farkından kayıtlı hesapları tespit etme |
| **Sözlük saldırısı** | Olası parola listesini sırayla deneme |
| **Geçmişe dönük kural** | Eski veriyi yeni kurala göre reddetme hatası |
| **Kanonik biçim** | Bir verinin tek doğru kabul edilen yazımı |

---

## 7. Bağlantılar

| İlgili | Nerede |
|---|---|
| FormRequest temelleri | [`RegisterRequest.md`](RegisterRequest.md) §1-2 |
| Enumeration kuralı (H6) | `docs/08-HATA-SOZLESMESI.md` §3.1 |
| Rate limit tanımı (K36) | [`AppServiceProvider.md`](../../../Providers/AppServiceProvider.md) §5.5 |
| `TrimStrings` global middleware | [`istek-yasam-dongusu.md`](../../../kavramlar/istek-yasam-dongusu.md) §6.1 |
| Sıradaki dosya | `app/Actions/Auth/LoginUserAction.php` (2.8c) — zamanlama saldırısı |
