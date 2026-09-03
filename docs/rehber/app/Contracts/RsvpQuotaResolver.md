# `app/Contracts/RsvpQuotaResolver.php`

> **Kod dosyası:** `app/Contracts/RsvpQuotaResolver.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.6
> **Uygulaması:** ~~`TierRsvpQuotaResolver`~~ (Faz 5, **silindi**) →
> [`../Services/Rsvp/SubscriptionRsvpQuotaResolver.md`](../Services/Rsvp/SubscriptionRsvpQuotaResolver.md) (Faz 7)
> **Bağlama yeri:** `app/Providers/AppServiceProvider.php` → `register()`

---

## 1. Problem: bugün olmayan bir bilgiye bugün ihtiyaç var

Faz 5'in kota kuralı şu: `SUM(guest_count) < limit`.

Peki `limit` nereden geliyor? Cevap **K42**'de yazılı: davetiyenin planından —
ki plan bilgisi ya tekil satın almadan ya paket abonelikten gelir, ve o
kayıtların ikisi de **Faz 7'de** doğacak.

Yani elimizde klasik bir sıralama problemi var:

```
Faz 5: kotayı UYGULAMAK zorunda        (docs/09 §Faz 5, 08 §8)
Faz 7: kotanın KAYNAĞI doğacak         (K42, K43)
```

Üç seçenek vardı:

| Seçenek | Sonuç |
|---|---|
| **A** — Action config'ten okusun | Faz 7'de **Action'ın içi** değişir. Kota kuralı doğru yazılmış olsa bile yeniden test edilmesi gereken bir dosya olur |
| **B** — Kotayı Faz 7'ye ertele | K47'nin Faz 4'te engellediği şey: paywall'sız bir "bedava" yol açılır. Ayrıca testler de ertelenir |
| **C** ✅ — Araya bir **arayüz** koy | Faz 7'de yalnızca **bağlama satırı** değişir. Action, testler, kurallar aynı kalır |

Seçilen **C** (K51). Bu bir *seam* — **dikiş yeri**: kodun, ileride
değişeceğini bildiğin bir noktada bilerek bıraktığı ayrılma çizgisi.

---

## 2. Arayüz ne diyor?

```php
interface RsvpQuotaResolver
{
    public function limitFor(Invitation $invitation): ?int;
}
```

Tek metot, tek soru: *"bu davetiyenin kaç misafirlik kotası var?"*

Sorunun **nasıl** cevaplandığı arayüzün umurunda değil. Bugün config'ten
okunuyor, yarın `subscriptions` tablosundan okunacak, öbür gün belki bir
kampanya kuralı eklenecek. Arayüzü kullanan kod (5.7'deki `SubmitRsvpAction`)
bunların hiçbirini bilmez.

Bu, **Dependency Inversion Principle** (SOLID'in D'si): üst seviye politika
(kota kuralı) düşük seviye ayrıntıya (config dosyası, veritabanı tablosu)
bağımlı olmaz; ikisi de **soyutlamaya** bağımlı olur.

---

## 3. 🔴 `?int` — neden `null` sınırsız demek?

```php
public function limitFor(Invitation $invitation): ?int;
```

`?int` = "ya bir `int` ya `null`". `config/davetkart.php` zaten bu dili
konuşuyor:

```php
'standart' => ['rank' => 0, 'price' => 249, 'rsvp_limit' => 100],
'gold'     => ['rank' => 1, 'price' => 399, 'rsvp_limit' => null],   // sınırsız
```

Neden başka bir gösterim değil?

| Alternatif | Neden reddedildi |
|---|---|
| `0` = sınırsız | "Kota yok" ile "kota sıfır" ayrımı kaybolur. Bir gün "deneme planı: 0 LCV" istenirse ifade edilemez |
| `PHP_INT_MAX` | Sınırsızlığı bir **sayı** gibi gösterir. "Kaç kişi kaldı?" hesabı 9223372036854775707 gibi saçma bir değer üretir |
| `-1` | Sihirli sayı. `-1 < 100` karşılaştırması sessizce yanlış davranır |
| `null` ✅ | "Bu soru bu plan için geçersiz" demenin tek dürüst yolu |

🔴 Bu bir tip tasarımı dersidir: **bir değerin yokluğunu, o değerin
uzayındaki bir sayıyla temsil etme.** Aksi hâlde bir gün o sayı gerçekten
gerekir ve iki anlam çakışır.

Karşılığı 5.7'de şöyle görünecek:

```php
if ($limit === null) {
    return;   // sınırsız plan: kota kontrolü hiç yapılmaz, SORGU DA AÇILMAZ
}
```

Yan kazanç: sınırsız planlarda `SUM()` sorgusu **hiç çalışmaz**.

---

## 4. Arayüz sınıfa nasıl bağlanıyor?

`app/Providers/AppServiceProvider.php`:

```php
public function register(): void
{
    $this->app->bind(RsvpQuotaResolver::class, TierRsvpQuotaResolver::class);
}
```

`bind()` Laravel'in **servis konteynerine** şunu söyler: *"biri
`RsvpQuotaResolver` isterse ona `TierRsvpQuotaResolver` ver."*

Sonra Action sadece tip bildirir:

```php
public function __construct(
    private readonly RsvpQuotaResolver $quota,
) {}
```

Konteyner tip bildirimini okur, bağlamaya bakar, nesneyi üretir ve verir. Buna
**autowiring** denir; Faz 2'de `LoginUserAction` ile ilk kez görmüştük.

### `register()` mi `boot()` mu?

- `register()`: **yalnızca bağlama yapılır.** Başka servisleri kullanmak
  yasaktır — henüz hepsi kayıtlı olmayabilir.
- `boot()`: tüm sağlayıcılar kayıtlı, artık servisler kullanılabilir.

Bağlama bir "kim kimdir" beyanıdır, iş yapmaz → `register()`'a ait. Faz 0'da
yazılan `configureModels()`, `configureRateLimiting()` ise gerçek iş yapar →
`boot()`'ta.

---

## 5. ✅ Faz 7'de ne değişti? (söz tutuldu)

Tek satır — **ve gerçekten tek satır oldu:**

```php
- $this->app->bind(RsvpQuotaResolver::class, TierRsvpQuotaResolver::class);
+ $this->app->bind(RsvpQuotaResolver::class, SubscriptionRsvpQuotaResolver::class);
```

> 🔴 Bu bölüm Faz 5'te bir **tahmin** olarak yazılmıştı. Faz 7'de doğrulandı:
> `SubmitRsvpAction`, `RsvpTest`'in kota testleri, `RsvpQuotaExceededException`
> ve `docs/08` **hiç değişmedi**. Ayrıntı:
> [`../Services/Rsvp/SubscriptionRsvpQuotaResolver.md`](../Services/Rsvp/SubscriptionRsvpQuotaResolver.md) §1.

Değişmeyecekler: `SubmitRsvpAction`, `RsvpTest`'teki kota testleri, hata
sözleşmesi, `RsvpQuotaExceededException`.

🔴 Ve testte kotayı değiştirmek için artık config kurcalamaya gerek yok:

```php
$this->app->bind(RsvpQuotaResolver::class, fn () => new class implements RsvpQuotaResolver {
    public function limitFor(Invitation $invitation): ?int { return 5; }
});
```

Bu, arayüzün ikinci ve daha az konuşulan kazancı: **test edilebilirlik**. Faz 4'ün
40. dersi *"test edilebilirlik ile doğruluk çatışırsa testi uyarla"* diyordu;
burada çatışma **hiç doğmuyor**, çünkü sınır doğru yere çizildi.

---

## 6. `app/Contracts/` yeni bir klasör — neden `app/Services/` değil?

`CLAUDE.md` §1 `app/Services/` için şunu diyor: *"dış servislerle (Ödeme, AI,
Depolama) olan iletişim arayüzler üzerinden burada yapılır."*

Kota çözümleyicisi bir **dış** servis değil — kendi veritabanımıza bakacak.
Ama paylaştığı özellik aynı: **değiştirilebilir bir sağlayıcının arkasına
saklanan bir soru.**

Bu yüzden:

- **Arayüz** → `app/Contracts/` (Laravel'in kendi konvansiyonu:
  `Illuminate\Contracts\*`)
- **Uygulama** → `app/Services/Rsvp/`

> ⚠️ Bu, `CLAUDE.md`'nin lafzının küçük bir genişletmesidir ve **İsmail'in
> onayına açıktır**. Alternatif: ikisini de `app/Services/Rsvp/` altına koymak.
> Karar `FAZ-5.md` §7'de plandan sapma olarak kayıtlı.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Action'ın doğrudan `config()` okuması | Faz 7'de Action değişir; dikiş yeri kaybolur |
| 2 | Arayüze `remaining()` metodu eklemek | H9: kalan kota anonim misafire sızmamalı. Sınıra yaklaşan her metot bir sızıntı adayıdır |
| 3 | `?int` yerine `int` kullanıp `0`'ı sınırsız saymak | İki anlam çakışır; "sıfır kotalı plan" ifade edilemez |
| 4 | Bağlamayı `boot()`'a yazmak | Çalışır ama yanlış yerdedir; `register()` bağlamaların yeridir |
| 5 | `singleton()` kullanmak | Gereksiz: sınıf durumsuz. Yarın veritabanına bakarsa istek içi bayat veri tutabilir |
| 6 | Arayüzü uygulamayan bir sınıf bağlamak | Konteyner çalışma anında patlar; PHPStan bunu **yazarken** yakalar |

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Seam (dikiş yeri)** | İleride değişeceği bilinen yerde bilerek bırakılan ayrılma çizgisi |
| **DIP** | Dependency Inversion — somuta değil soyutlamaya bağımlı olmak |
| **Servis konteyneri** | Bağımlılıkları üreten ve enjekte eden Laravel bileşeni |
| **Binding** | "Bu arayüz istendiğinde şu sınıfı ver" kaydı |
| **Autowiring** | Tip bildiriminden bakarak bağımlılığı çözme |
| **Singleton** | Konteynerde tek örnek olarak paylaşılan servis |
| **Nullable tip (`?int`)** | Değerin `null` da olabileceğini bildiren tip |
| **Anonim sınıf** | Adı olmayan, yerinde tanımlanan sınıf (testlerde yararlı) |

---

## 9. Sırada ne var?

**5.7 — `SubmitRsvpAction`.** Fazın kalbi: katmanlı savunmanın (defense in
depth) sırayla uygulandığı yer. Görünürlük → son tarih → honeypot → kota → IP
hash → kayıt.

| İlgili | Nerede |
|---|---|
| Uygulama | [`../Services/Rsvp/TierRsvpQuotaResolver.md`](../Services/Rsvp/TierRsvpQuotaResolver.md) |
| Kota exception'ı | [`../Exceptions/RsvpQuotaExceededException.md`](../Exceptions/RsvpQuotaExceededException.md) |
| Plan tanımları | [`../../config/davetkart.md`](../../config/davetkart.md) |
