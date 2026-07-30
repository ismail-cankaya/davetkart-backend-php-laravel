# `lang/tr/validation.php` — Kılavuz

> **Kod dosyası:** `lang/tr/validation.php`
> **Faz:** 0 — Zemin ve kalite kapıları (adım 0.7)

---

## 1. Bu dosya ne işe yarar?

Kullanıcı kayıt formunu boş gönderdiğinde frontend'in göreceği yanıt:

```json
{
  "message": "Ad soyad alanı zorunludur. (ve 2 hata daha)",
  "errors": {
    "fullName": ["Ad soyad alanı zorunludur."],
    "email": ["E-posta alanı geçerli bir e-posta adresi olmalıdır."],
    "password": ["Parola alanı en az 8 karakter olmalıdır."]
  }
}
```

Bu metinleri üreten yer burasıdır. Kod tarafında hiçbir yerde `"zorunludur"`
yazmayacağız — sadece kural adı yazacağız (`'fullName' => 'required'`), metni
Laravel bu dosyadan bulacak.

### Neden kod içine yazmıyoruz?

Frontend **10 dil** destekliyor. Hata mesajı kodun içine gömülü olsaydı,
Almanca desteği eklemek için Action ve Request dosyalarını tek tek düzenlemek
gerekirdi. Şimdi ise `lang/de/validation.php` eklemek yeterli — **kod hiç
değişmez**.

Bu, *"değişen şeyi değişmeyenden ayır"* ilkesinin uygulanışıdır. Faz 8'de
`SetLocaleFromHeader` middleware'i `Accept-Language` başlığına bakıp
`App::setLocale('de')` diyecek; Laravel de aynı kural adı için `lang/de/`
klasörüne bakacak.

---

## 2. PHP dosyası olarak yapısı

```php
<?php

declare(strict_types=1);

return [
    'required' => ':attribute alanı zorunludur.',
];
```

Üç şeye dikkat:

**`<?php`** — Dosyanın PHP olduğunu söyleyen açılış etiketi. Kapanış etiketi
(`?>`) **bilinçli olarak yok**: kapanıştan sonraki bir boşluk veya satır sonu
HTTP yanıtına sızar ve JSON'u bozar. PSR-12 standardı da salt-PHP dosyalarında
kapanış etiketini yasaklar.

**`declare(strict_types=1);`** — PHP'nin sessiz tip dönüşümünü kapatır. Normalde
`int` bekleyen bir fonksiyona `"5"` verirsen PHP onu sessizce `5` yapar. Bu
kolaylık, gerçek hataları gizler:

```php
function ver(int $x) { return $x; }

ver("5");      // strict yok  → 5      (sessizce dönüştürdü)
ver("5");      // strict var  → TypeError (hatayı yüzüne söyledi)
```

Projede **her PHP dosyasında** bulunacak. Bu bir zorunluluk değil, disiplin
kararıdır — hataların üretimde değil laptop'ta çıkması için.

**`return [...]`** — Dosya bir **ilişkisel dizi** (associative array) döndürür.
Laravel bu dosyayı `require` ile okur ve dönen diziyi kullanır. Yani dosyanın
tamamı tek bir `return` ifadesidir.

### `=>` ve `[]` sözdizimi

```php
['anahtar' => 'değer', 'baska' => 'deger2']
```

`=>` PHP'nin anahtar-değer bağlama operatörüdür. JavaScript'teki `:`
karşılığıdır:

```js
{ anahtar: 'değer' }      // JS
```

```php
['anahtar' => 'değer']    // PHP
```

Diziler **iç içe** olabilir — `max` gibi kurallar veri türüne göre farklı mesaj
verir:

```php
'max' => [
    'string' => ':attribute alanı :max karakterden uzun olamaz.',
    'file'   => ':attribute alanı :max kilobayttan büyük olamaz.',
],
```

Laravel doğrulanan değerin türüne bakıp doğru alt anahtarı seçer. `max:100`
kuralı bir metne uygulanınca "100 karakter", bir dosyaya uygulanınca "100
kilobayt" der.

---

## 3. Yer tutucular (`:attribute`, `:min`, `:other`)

İki nokta ile başlayan kelimeler **yer tutucudur**; Laravel çalışma anında
gerçek değerle değiştirir.

| Yer tutucu | Neyle değişir |
|---|---|
| `:attribute` | Alan adı — `attributes` dizisindeki karşılığı, yoksa ham adı |
| `:min` / `:max` / `:size` | Kuralın parametresi (`max:255` → `255`) |
| `:other` | Karşılaştırılan diğer alanın adı (`same:password`) |
| `:values` | İzin verilen değerler listesi |
| `:date` / `:format` | Tarih kuralının parametresi |

Örnek zincir:

```php
// Kural
'password' => 'min:8'

// Şablon
'min' => ['string' => ':attribute alanı en az :min karakter olmalıdır.']

// attributes dizisi
'password' => 'Parola'

// Sonuç
"Parola alanı en az 8 karakter olmalıdır."
```

---

## 4. 🔴 Türkçe ek sorunu — neden her mesajda "alanı" var?

İngilizce çeviriyi birebir Türkçeleştirirsek:

```
:attribute zorunludur     →  "E-posta zorunludur"     ✅ kulağa doğru
:attribute geçersiz       →  "Parola geçersiz"        ✅
:attribute :min karakter  →  "Parola 8 karakter"      ⚠️ eksik
```

Asıl sorun **durum ekleri** gerektiğinde çıkar:

```
":attribute'ı kontrol edin"
   → "E-posta'ı kontrol edin"     ❌ (doğrusu: "E-postayı")
   → "Parola'ı kontrol edin"      ❌ (doğrusu: "Parolayı")
```

Türkçede ek, kelimenin **son ünlüsüne** göre değişir (ünlü uyumu) ve son harf
ünlüyse araya kaynaştırma harfi girer. Laravel'in yer tutucu mekanizması bunu
bilemez — düz metin değiştirme yapar.

**Çözüm: araya değişmeyen bir kelime koymak.** Her mesajda `:attribute` sonrası
**"alanı"** yazdım:

```
"E-posta alanı zorunludur."
"Parola alanı en az 8 karakter olmalıdır."
```

Artık ek her zaman `alanı` kelimesine takılıyor ve o sabit. Biraz resmi durur
ama **her alan adıyla dilbilgisel olarak doğru** çalışır — 30 farklı alan adı
için tek tek istisna yazmaktan iyidir.

> **İstisnalar:** `enum`, `in`, `exists` gibi kurallarda "Seçilen :attribute
> geçersiz." yazdım — çünkü bunlar açılır listelerde kullanılır ve "Seçilen
> Katılım durumu geçersiz." cümlesi doğal duruyor.

---

## 5. 🔴 `attributes` dizisi neden camelCase?

Bu dosyanın en kritik kısmı. Yanlış yazılırsa **sessizce çalışmaz** — hata
vermez, sadece ham alan adı görünür.

```php
'attributes' => [
    'fullName' => 'Ad soyad',    // ✅ DOĞRU
    'full_name' => 'Ad soyad',   // ❌ hiç eşleşmez
],
```

### Neden?

Katmanların **çalışma sırasını** hatırla:

```
Frontend                Backend
--------                -------
{ "fullName": "" }  →   FormRequest (doğrulama)  ← BURASI
                             ↓
                        Action (iş kuralı)
                             ↓
                        Model → veritabanı (full_name)
                             ↓
                        Resource (snake_case → camelCase)  ← DÖNÜŞÜM BURADA
                             ↓
                    {"data": {"fullName": "..."}}
```

Doğrulama **girişte**, Resource ise **çıkışta** çalışır. FormRequest'in gördüğü
şey frontend'in gönderdiği ham JSON'dur — henüz hiçbir dönüşüm olmamıştır.

Bu, `CLAUDE.md` §1'deki kuralın doğal sonucu:
> *"`app/Http/Resources/`: snake_case alan adlarının camelCase'e dönüştürüldüğü
> **TEK** yerdir."*

Tek yer olduğu için, o yerden önce her şey camelCase'tir.

### `attributes` boş bırakılırsa ne olur?

Hata vermez, sadece çirkin olur:

```
attributes doluysa  →  "Ad soyad alanı zorunludur."
attributes boşsa    →  "fullName alanı zorunludur."   ← kullanıcı ne anlasın
```

Frontend bu metni doğrudan formun altında gösteriyor. Kullanıcıya `fullName`
göstermek kabul edilemez.

---

## 6. `custom` dizisi — alana özel istisna

Genel kural yetmediğinde tek bir alan için mesaj ezilebilir:

```php
'custom' => [
    'password' => [
        'min' => 'Parola en az :min karakter olmalıdır.',
    ],
],
```

Bu, `min.string` şablonunu **sadece `password` alanı için** değiştirir. Diğer
alanlar genel mesajı kullanmaya devam eder.

Öncelik sırası:

```
1. custom.<alan>.<kural>      ← en spesifik, kazanır
2. <kural>.<tür>              ← 'min' => ['string' => ...]
3. <kural>                    ← 'required' => ...
```

`custom`'u **idareli kullan**. Her alana özel mesaj yazmak, dosyayı bakımı zor
bir istisna listesine çevirir. Genel mesaj anlaşılırsa dokunma.

---

## 7. Laravel bu dosyayı nasıl buluyor?

Kural adından metne giden yol:

```
1. Kural çalıştı, başarısız     →  'required'
2. Aktif dil nedir?             →  config('app.locale') = 'tr'
3. lang/tr/validation.php içinde 'required' anahtarı var mı?
      ├─ Var  → kullan
      └─ Yok  → config('app.fallback_locale') = 'en'
                lang/en/validation.php içinde ara
4. Yer tutucuları doldur
```

**`lang/en/` klasörünü silme.** Fallback zinciri ona bağlı; Türkçesi eksik kalan
bir kural olursa boş string yerine İngilizcesi görünür — kullanıcı için kötü,
ama geliştirici için teşhis edilebilir.

Ayrıca Laravel'in **çekirdek dosyaları** (framework'ün kendi hata mesajları)
`vendor/` içindeki `en` klasörünü kullanır; oradaki kopya proje köküne
taşınmadıkça değiştirilemez.

---

## 8. Sık yapılan hatalar

| Hata | Sonuç | Doğrusu |
|---|---|---|
| `attributes`'ta `full_name` yazmak | Kullanıcı `fullName` görür | camelCase — frontend ne gönderiyorsa o |
| Dosyayı **UTF-8 dışı** kodlamayla kaydetmek | `ş`, `ğ`, `İ` bozulur (`ÅŸ`) | Editörü UTF-8 (BOM'suz) yap |
| Dosya sonuna `?>` koymak | Sonrasındaki boşluk JSON'u bozar | Kapanış etiketi yazma |
| Dizide son öğeden sonra virgül unutmak | Yeni satır eklerken sözdizimi hatası | PHP sondaki virgülü kabul eder, **koy** |
| `:attribute` yerine `{attribute}` yazmak | Değişmez, olduğu gibi basılır | İki nokta ön eki |
| `lang/en/` klasörünü silmek | Türkçesi eksik kural boş metin döner | Fallback için tut |
| `APP_LOCALE=tr` yapmayı unutmak | Dosya yazıldı ama İngilizce dönüyor | `.env` + `config:clear` |
| Mesajda tek tırnak içinde kesme işareti | `'E-posta'nın'` sözdizimi hatası | `\'` ile kaçır veya çift tırnak kullan |

---

## 9. Deneme adımları

**1. Sözdizimi doğru mu?** (dosyayı çalıştırmadan denetler)

```powershell
php -l lang/tr/validation.php
```

Beklenen: `No syntax errors detected`.

**2. Dizi gerçekten yükleniyor mu?**

```powershell
php artisan tinker
```

```php
config('app.locale');
// "tr"

__('validation.required', ['attribute' => 'E-posta']);
// "E-posta alanı zorunludur."

trans('validation.attributes.fullName');
// "Ad soyad"
```

**3. Gerçek doğrulama ile dene** — asıl kanıt bu:

```php
$v = Validator::make(
    ['email' => 'gecersiz'],
    ['email' => 'required|email', 'fullName' => 'required']
);

$v->errors()->all();
```

Beklenen çıktı:

```
[
  "E-posta alanı geçerli bir e-posta adresi olmalıdır.",
  "Ad soyad alanı zorunludur.",
]
```

> `__()` ve `trans()` aynı fonksiyondur; `__()` kısa yazımıdır. `Validator`
> cephesini tinker'da kullanmak için ek `use` gerekmez — tinker Laravel'in
> cephelerini otomatik tanır.

**Türkçe karakterler bozuk görünüyorsa** (`Ã§`, `ÅŸ`) dosya UTF-8 değil.
VS Code'da sağ alt köşedeki kodlama göstergesine tıkla → `Save with Encoding`
→ `UTF-8`.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **İlişkisel dizi** (associative array) | Anahtarları metin olan dizi. JS'teki nesne karşılığı |
| **Yer tutucu** (placeholder) | Çalışma anında gerçek değerle değişen işaretçi (`:attribute`) |
| **Locale** | Dil ve bölge kodu (`tr`, `en`, `tr_TR`) |
| **Fallback locale** | Çevirisi bulunamayan anahtar için yedek dil |
| **i18n** | *internationalization* — 18 harf arada. Uygulamayı çok dilli yapabilme |
| **FormRequest** | Doğrulama kurallarını taşıyan sınıf. Controller'a girmeden çalışır |
| **Cephe** (facade) | `Validator::make()` gibi statik görünümlü çağrı |
| **`declare(strict_types=1)`** | Sessiz tip dönüşümünü kapatan bildirim |
| **PSR-12** | PHP kod stili standardı. Pint bunu uygular |

---

## 11. Bağlantılar

| İlgili | Nerede |
|---|---|
| `APP_LOCALE` ayarı | [`../../env.md`](../../env.md) |
| Uygulama dil yapılandırması | [`../../config/app.md`](../../config/app.md) |
| Bu dosyayı kullanacak ilk kod | `app/Http/Requests/Auth/RegisterRequest.php` (Faz 2) |
| Çok dilli yanıt middleware'i | `SetLocaleFromHeader` (Faz 8) |
| camelCase sözleşmesinin kaynağı | `davetkart-frontent/src/types.ts` |
