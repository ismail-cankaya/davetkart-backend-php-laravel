# `app/Services/Rsvp/TierRsvpQuotaResolver.php`

> **Kod dosyası:** `app/Services/Rsvp/TierRsvpQuotaResolver.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.6
> **Arayüzü:** [`../../Contracts/RsvpQuotaResolver.md`](../../Contracts/RsvpQuotaResolver.md)
> — **önce onu oku**; buradaki kararlar oradaki gerekçelere dayanıyor.

---

## 1. Bu sınıf bilerek eksiktir

```php
private const FALLBACK_TIER = 'standart';
```

Bu satır şunu söylüyor: *"bugün her davetiye standart plandan sayılıyor."*

Doğru değil — kullanıcılar Gold ve Elit satın alacak. Ama `subscriptions`
tablosu Faz 7'de doğacak (K42) ve o güne kadar bir davetiyenin **gerçek**
planını sorabileceğimiz bir yer yok.

🔴 Önemli olan **hangi yönde yanıldığımız**:

| Varsayım | Faz 7'ye kadar sonuç |
|---|---|
| Herkes `standart` (limit 100) ✅ | Kota **uygulanır**. Gold müşterisi haksızca 100'de durur — ama Faz 7'den önce Gold satın alma da yok |
| Herkes sınırsız ❌ | Kota **hiç uygulanmaz**. Kod yazılmış olur ama bir gün bile çalışmaz — ve o gün geldiğinde ilk kez çalışacağı için yanlış olduğu ancak orada anlaşılır |

İkinci seçenek Faz 4'ün 34. dersinin tuzağı olurdu: *"beklediğim yanıtı aldım"
ile "beklediğim sebeple aldım" farklı şeylerdir.* Testler yeşil yanardı çünkü
kota kodu hiç tetiklenmezdi.

Ayrıca K47'nin Faz 4'te koruduğu şeyi bozardı: **paywall'sız bir bedava yol
açmamak.**

---

## 2. Neden config'te bir "varsayılan plan" anahtarı değil?

`config/davetkart.php`'ye `'default_tier' => 'standart'` yazabilirdik. Yazmadık.

Sebep: **config kalıcı iş ayarlarının evidir.** Oraya konan her şey bir
*özellik* gibi görünür ve kalır. Oysa `FALLBACK_TIER` bir özellik değil,
**geçici bir eksikliğin adıdır**.

Sınıf sabiti olarak durduğunda:

- Faz 7'de bu sınıf silinince sabit de **onunla birlikte** gider.
- Config'te olsaydı, kimse silmeyi hatırlamaz; yıllar sonra biri "bu ayar ne
  işe yarıyor?" diye sorardı.

**Ders:** geçici olanı geçici görünen bir yere koy.

---

## 3. Yapılandırma hatasında neden exception fırlıyor?

```php
if (! is_array($tier) || ! array_key_exists('rsvp_limit', $tier)) {
    throw new RuntimeException('Configuration error: ...');
}
```

Alternatif `return null` (yani "sınırsız") olurdu. Bu **çok kötü** bir
varsayılan:

> Config bozulduğunda ödemeli bir sınır **sessizce kalkardı.** Hiçbir hata
> görünmez, sadece kimse kotaya takılmaz olur.

`RuntimeException` fırlatmak bunu `500`'e çevirir — gürültülü, loglanır, fark
edilir. **Faz 3, ders 30'un akrabası:** *savunma kodu güven sınırına yazılır*;
ve Faz 4, ders 38: *bir optimizasyon (ya da varsayılan) altındaki hatayı
düzeltmez, gizler.*

`HasErrorCode` uygulanmadı — bilerek. Bu bizim hatamız (sunucu tarafı), istemci
hatası değil; `SERVER_ERROR`/500 tam olarak doğru sınıflandırma (`08` §4.1).

---

## 4. `Config::array()` neden düz `config()` değil?

```php
$tiers = Config::array('davetkart.tiers');
```

Düz `config('davetkart.tiers')` `mixed` döner. PHPStan level 8'de (Faz 5 sonunda
açılıyor, K22) `mixed` üzerinde dizi erişimi yapmak hatadır.

Laravel'in tipli yardımcıları (`Config::array/string/integer/boolean`) dönüş
tipini garanti eder ve yanlış tip varsa **erken** patlar. Aynı gerekçeyle
`Invitation::publicCacheKey()` içinde `Config::string()`, `StoreRsvpRequest`
içinde `Config::integer()` kullanılmıştı.

**Y1** de hatırlanmalı: kod içinde asla `env()` çağrılmaz. `env()` yalnızca
`config/` dosyalarında geçer, çünkü `config:cache` sonrası sessizce `null`
döner.

---

## 5. `$invitation` parametresi neden kullanılmıyor?

```php
public function limitFor(Invitation $invitation): ?int
```

Gövde `$invitation`'a hiç bakmıyor — bugün bakacak bir şey yok. Yine de
imzada duruyor, çünkü **arayüzün sözleşmesi bu**. Faz 7'de gövde şuna dönecek:

```php
return $this->tiers->resolveFor($invitation)->rsvpLimit();
```

Kullanılmayan parametre burada bir kusur değil, bir **taahhüt**: soru
"davetiyenin kotası" olarak soruluyor, "sistemin kotası" olarak değil. İmza
doğru olduğu için Faz 7 çağrı yerlerine hiç dokunmayacak.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Config bozukken `null` (sınırsız) dönmek | Ödemeli sınır sessizce kalkar |
| 2 | `FALLBACK_TIER`'i `'gold'` yapmak | Kota fiilen uygulanmaz; kod bir gün bile çalışmaz |
| 3 | `Config::array()` yerine `config()` | PHPStan level 8'de `mixed` hatası |
| 4 | Sınıfı `singleton` bağlamak | Bugün zararsız; yarın veritabanına bakınca istek içi bayat veri tutar |
| 5 | Faz 7'de bu sınıfı silmeyi unutmak | İki kota kaynağı yan yana kalır (C3) |
| 6 | Kota mantığını (SUM, karşılaştırma) buraya taşımak | Bu sınıf **limiti** söyler, **kullanımı** değil. İkisi karışırsa arayüz anlamını kaybeder |

---

## 7. Kendin dene

```php
// php artisan tinker
$inv = App\Models\Invitation::factory()->create();
$r = app(App\Contracts\RsvpQuotaResolver::class);

get_class($r);          // App\Services\Rsvp\TierRsvpQuotaResolver  ← bağlama çalışıyor
$r->limitFor($inv);     // 100

// Sınırsız planı taklit et
config(['davetkart.tiers.standart.rsvp_limit' => null]);
app()->forgetInstance(App\Contracts\RsvpQuotaResolver::class);
app(App\Contracts\RsvpQuotaResolver::class)->limitFor($inv);   // null

// Bozuk config -> gürültülü hata
config(['davetkart.tiers.standart' => 'bozuk']);
app(App\Contracts\RsvpQuotaResolver::class)->limitFor($inv);   // RuntimeException
```

Üçüncüsü **sessizce `null` dönerse** kod yanlıştır — ve o hatayı üretimde
kimse fark etmez.

---

## 8. Sırada ne var?

**5.7 — `SubmitRsvpAction`**: bu limitin `SUM(guest_count)` ile
karşılaştırıldığı yer.

| İlgili | Nerede |
|---|---|
| Arayüz | [`../../Contracts/RsvpQuotaResolver.md`](../../Contracts/RsvpQuotaResolver.md) |
| Plan tanımları | [`../../../config/davetkart.md`](../../../config/davetkart.md) |
| Sağlayıcı | [`../../Providers/AppServiceProvider.md`](../../Providers/AppServiceProvider.md) |
