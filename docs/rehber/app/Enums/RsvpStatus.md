# `app/Enums/RsvpStatus.php`

> **Kod dosyası:** `app/Enums/RsvpStatus.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.1
> **Bu dosya kimin için:** PHP'yi ilk kez gören biri için. Baştan sona okunur.
> **Kardeş dosya:** [`InvitationStatus.md`](InvitationStatus.md) — aynı kalıbın
> Faz 3'teki hâli. İkisini yan yana okumak, "aynı desen, farklı iş kuralı"
> fikrini görmenin en hızlı yolu.

---

## 1. Bu dosya ne işe yarar?

Bir misafir davetiye linkine tıklayıp formu doldurduğunda üç şeyden birini
söyler:

| Durum | Arayüzde gördüğü | Anlamı |
|---|---|---|
| `attending` | "Katılıyorum" | Geliyor |
| `pending` | "Belirsiz" | Henüz karar vermedi |
| `declined` | "Katılamıyorum" | Gelmiyor |

Bu dosya o üç seçeneği **tipe dönüştürür**. `'attending'` bir metin parçası
olmaktan çıkar; PHP'nin tanıdığı, yanlış yazılamayan bir değere dönüşür.

🔴 **Ve bu dosya aynı zamanda bir iş kuralının evidir:** hangi yanıtın davetiye
sahibinin **kotasından yer tuttuğu** burada yazılıdır. Bu kural sorgunun içine
gömülseydi, kotayı ikinci bir yerden hesaplamak gerektiğinde (rapor, panel,
fatura) kural kopyalanır ve bir gün ikisi ayrışırdı.

---

## 2. Kodun satır satır PHP karşılığı

```php
<?php

declare(strict_types=1);
```

`declare(strict_types=1)` **K1**'in gereği ve her dosyanın ilk satırıdır. Onsuz
PHP, `"3"` metnini beklenen yerde sessizce `3` sayısına çevirir. Sessiz dönüşüm
hatayı gizler; katı kip onu **anında** hataya dönüştürür.

```php
namespace App\Enums;
```

Namespace, sınıfın **tam adını** belirler: `App\Enums\RsvpStatus`. Composer'ın
PSR-4 otomatik yükleyicisi `App\` önekini `app/` klasörüne eşlediği için dosya
yolu ile sınıf adı birbirini birebir yansıtmak zorundadır. `app/Enums/` içinde
`namespace App\Models;` yazsaydın sınıf **hiç bulunamazdı** — ve hata mesajı
"class not found" derdi, yani sana belirtiyi söylerdi, sebebi değil.

```php
enum RsvpStatus: string
```

`enum` PHP 8.1 ile gelen bir tiptir. `: string` kısmı onu **backed enum**
(değeri olan enum) yapar: her case'in veritabanına yazılabilir bir karşılığı
olur. Bu olmasaydı Eloquent enum'u bir kolona yazamazdı.

```php
    case Attending = 'attending';
```

Sol taraf (`Attending`) **PHP'de kullandığın ad**, sağ taraf (`'attending'`)
**veritabanına ve API'ye giden değer**. İkisinin farklı olması tesadüf değil:
PHP tarafı `StudlyCase` (dil geleneği), tel üzerindeki değer `lower_snake`
(sözleşme geleneği).

```php
    public function consumesQuota(): bool
```

`public` → her yerden çağrılabilir. Dönüş tipi `bool` yazıldığı için bu metot
`null` veya `1` döndüremez; döndürmeye kalkarsa `TypeError` fırlar.

Metodun `static` **olmaması** önemli: soru "bu durum kotadan yer tutar mı?"
şeklinde **tek bir case hakkında** sorulur, dolayısıyla `$this` gerekir.
`RsvpStatus::Attending->consumesQuota()` diye çağrılır.

```php
        return match ($this) {
            self::Attending, self::Pending => true,
            self::Declined => false,
        };
```

`match` bir **ifadedir**, `switch` gibi bir deyim değil — yani değer üretir ve
doğrudan `return` edilebilir. Ayrıca `===` (katı) karşılaştırma yapar ve
`break` gerektirmez.

🔴 **`default` kolunun bilerek yazılmaması bu dosyanın en önemli satırıdır.**
Bugün üç durum var. Yarın biri `case Maybe = 'maybe';` eklerse:

- `default => false` yazsaydık → yeni durum **sessizce** kotasız sayılırdı,
  kimse fark etmezdi, kota yanlış hesaplanırdı.
- `default` yokken → PHP `UnhandledMatchError` fırlatır, testler kırmızı yanar
  ve geliştirici **karar vermeye zorlanır**.

Bu, projede tekrar eden bir tasarım ilkesinin örneği: *unutmanın bedeli sessiz
olmamalı.* Aynı ilkeyi `/api/public/` önekinde (K12) ve `#[Fillable]`
listelerinde de görüyorsun.

```php
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
```

`self::cases()` enum'un tüm case'lerini **tanımlandıkları sırayla** bir dizi
olarak verir. `array_column($dizi, 'value')` ise her elemandan `value`
özelliğini çeker. Sonuç: `['attending', 'pending', 'declined']`.

Bu metot birazdan (5.2) veritabanı CHECK kısıtını ve doğrulama kuralını
besleyecek. Listeyi elle yazsaydık, enum'a bir durum eklendiğinde kısıt sessizce
eskirdi — `InvitationStatus` ile birebir aynı gerekçe.

```php
    public static function quotaConsumingValues(): array
    {
        $values = [];

        foreach (self::cases() as $case) {
            if ($case->consumesQuota()) {
                $values[] = $case->value;
            }
        }

        return $values;
    }
```

Burada `array_column` yerine açık bir `foreach` var. İki sebebi var:

1. **Filtreleme gerekiyor.** `array_column` filtrelemez; `array_filter` +
   `array_column` zinciri yazılabilirdi ama okunurluğu düşerdi.
2. **PHPStan level 8.** Faz 5 sonunda katılık 6'dan 8'e çıkıyor (K22). Açıkça
   `$values[] = $case->value;` yazmak, aracın dönüş tipinin gerçekten
   `list<string>` olduğunu **tereddütsüz** görmesini sağlar.

`$values[] = ...` sözdizimi "dizinin sonuna ekle" demektir; PHP'de `push`
metodu yerine bu kullanılır.

---

## 3. Neden değerler İngilizce?

Frontend bugün şunu yazıyor (`src/types.ts` satır 1):

```ts
export type RsvpStatus = 'Katılıyor' | 'Bekleniyor' | 'Katılamıyor';
```

Yani **gösterim metni, veri değeri olarak** kullanılmış. Bu üç şeyi birden
bozar:

| Bozulan | Nasıl |
|---|---|
| Dil | Uygulama İngilizceye çevrildiği gün `WHERE status = 'Katılıyor'` sorgusu ne yapacak? |
| Veri | Etiketi "Geliyorum" diye güzelleştirmek, **veritabanındaki tüm satırları** güncellemek demek |
| Kodlama | `ı`, `ü` gibi karakterler URL'de, indekste ve sıralamada (collation) sürprizler üretir |

**K21** bunu zaten yasaklamıştı: *backend tek dil konuşur, çeviri frontend'in
işidir.* Bu enum o kararın LCV modülündeki uygulamasıdır (**K49**).

Bedeli açık: frontend'de beş dosya değişecek (`types.ts`, `data.ts`,
`RsvpModal.tsx`, `RSVPForm.tsx`, `LiveRsvpPanel.tsx`). Faz 3'te `K35` ve `K38`
için de aynı bedel ödendi — ve orada öğrenilen şey (ders 32) burada da geçerli:
**önce `types.ts` değişir**, sonra TypeScript kalan işi derleme hatası olarak
sana listeler.

---

## 4. Neden üç durum? `pending` neden atılmadı?

Faz 3'te `InvitationStatus`'ten `draft` durumunu **atmıştık**. Gerekçe şuydu:

> Bir durum makinesine, ancak onu **doğuran bir olay** varsa durum eklenir.

Aynı testi burada da uyguluyoruz — ve bu kez sonuç "kalsın" çıkıyor:

| Durum | Onu doğuran olay var mı? |
|---|---|
| `attending` | ✅ Misafir "Katılıyorum" düğmesine basıyor |
| `pending` | ✅ `RsvpModal.tsx:119` üç seçenek sunuyor, "Belirsiz" bilinçli bir seçim |
| `declined` | ✅ "Katılamıyorum" düğmesi |

🔴 **Bir kural bir kez uygulanıp geçilmez; her seferinde yeniden sorulur.**
"Faz 3'te durum atmıştık, burada da atalım" demek kuralı değil, sonucunu
taşımak olurdu.

> ⚠️ Küçük bir tutarsızlık not edilmeli: `RSVPForm.tsx:233` misafire yalnızca
> **iki** seçenek sunuyor (`Katılıyor`/`Katılamıyor`), `RsvpModal.tsx` ise üç.
> Backend üçünü de kabul eder; hangi şablonun kaçını göstereceği bir **sunum**
> kararıdır ve frontend'e aittir.

---

## 5. `consumesQuota()` — K50 kararı

Kota `SUM(guest_count)` ile hesaplanır, `COUNT(*)` ile değil. Sebebi
`docs/09` §Faz 5'te yazılı: `LiveRsvpPanel` toplamları misafir sayısıyla
hesaplıyor; backend kayıt sayısıyla hesaplasaydı 100 kayıt × 4 kişi = **400
misafir** kotayı aşmadan geçerdi.

Peki hangi satırlar toplanacak? Üç seçenek vardı:

| Seçenek | Sorun |
|---|---|
| Sadece `attending` | "Belirsiz" diyen misafir düğüne gelirse kontenjan aşılır |
| Tüm satırlar | 100'lük kotanın 40'ını "katılamıyorum" yanıtları yer; sahip haklı olarak şikâyet eder |
| **`attending` + `pending`** ✅ | Yer tutan yanıtlar sayılır, reddeden saymaz |

Seçilen: **`attending` + `pending`** (K50). Dayanağı **K28**'de zaten yazılıydı:
*kota bir **kapasite** sınırıdır, bir hız sınırı değil.* Kapasite "kaç kişi
gelebilir" sorusudur; gelmeyeceğini bildiren kişi kapasiteden yer kaplamaz.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Kota listesini sorguya elle yazmak (`whereIn('status', ['attending','pending'])`) | Kural iki yere düşer; biri değişince diğeri sessizce eskir (C3) |
| 2 | `match`'e `default` eklemek | Yeni durum sessizce kotasız sayılır |
| 3 | Türkçe etiketi `value` yapmak | Bkz. §3 — dil, veri ve kodlama birden bozulur |
| 4 | Enum'a `label(): string` metodu eklemek | K21 ihlali. Backend metin üretmez; üretse bile frontend onu kullanmaz → ölü kod (ders 26) |
| 5 | `RsvpStatus::from($girdi)` kullanmak | Geçersiz değerde `ValueError` fırlar → 500. Kullanıcı girdisinde `tryFrom()` ya da doğrulama kuralı kullanılır |
| 6 | Durumu `int` olarak saklamak (0/1/2) | Veritabanına elle bakan biri hiçbir şey anlamaz; migration ile sıra değişirse tüm veri anlamını kaybeder |

### 4. maddenin ayrıntısı: `label()` neden yok?

`docs/07` §Faz 5'in dosya listesinde *"`RsvpStatus.php` — DB İngilizce,
`label()` Türkçe"* yazıyor. **Bu satır K21'den önce yazılmış ve K21 tarafından
geçersiz kılınmıştır** — tıpkı `lang/tr/validation.php`'nin silinmesi ve
`SetLocaleFromHeader` middleware'inin iptali gibi (`08` §10).

Backend'de bir `label()` yazsaydık, döndürdüğü Türkçe metni **hiçbir yanıt
taşımayacaktı**. Yani hiç çağrılmayan bir metot: ders 26'nın tanımı.

---

## 7. Kendin dene

Kurulum bittikten sonra `php artisan tinker` içinde:

```php
// 1) Case'ler ve değerleri
RsvpStatus::cases();
RsvpStatus::Attending->value;          // 'attending'
RsvpStatus::Attending->name;           // 'Attending'

// 2) Kota kuralı
RsvpStatus::Attending->consumesQuota(); // true
RsvpStatus::Declined->consumesQuota();  // false
RsvpStatus::quotaConsumingValues();     // ['attending', 'pending']

// 3) Metinden enum'a
RsvpStatus::tryFrom('attending');       // enum örneği
RsvpStatus::tryFrom('Katılıyor');       // null  ← eski sözleşme artık geçersiz
RsvpStatus::from('Katılıyor');          // ValueError fırlatır
```

**Mutasyon denemesi** (kural 14 — "bu korumayı silsem hangi test kırılır?"):

1. `consumesQuota()` içindeki `self::Pending` kolunu `false` tarafına al.
2. `php artisan test --filter=RsvpTest` çalıştır.
3. Kota testinin kırılması gerekir. Kırılmıyorsa test kotayı gerçekten
   ölçmüyordur — testi düzelt, kodu değil.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Enum** | Sınırlı ve sabit bir değer kümesini tip hâline getiren yapı |
| **Backed enum** | Her case'in skaler bir değeri (`string`/`int`) olan enum |
| **Pure enum** | Değeri olmayan enum; veritabanına yazılamaz |
| **`match` ifadesi** | Katı karşılaştırma yapan, değer üreten `switch` benzeri |
| **`UnhandledMatchError`** | `match` hiçbir kola uymadığında fırlayan hata |
| **PSR-4** | Namespace ↔ klasör eşleme standardı |
| **Katı kip (`strict_types`)** | Sessiz tip dönüşümünü kapatan bildirim |
| **Sihirli string** | Kodun içine serpiştirilmiş, denetlenmeyen metin sabiti |
| **Kota (quota)** | Bir kaynağın üst sınırı — burada: davetiye başına misafir sayısı |
| **Kapasite sınırı** | "Kaç tane" sınırı (403); hız sınırından (429) farklıdır |

---

## 9. Sırada ne var?

**5.2 — `rsvps` tablosunun migration'ı.** Bu enum'un `values()` metodu orada
`CHECK (status IN (...))` kısıtını üretecek: PHP tarafındaki kural ile
veritabanı tarafındaki kural **aynı kaynaktan** beslenecek.

| İlgili | Nerede |
|---|---|
| Kardeş enum | [`InvitationStatus.md`](InvitationStatus.md) |
| Faz özeti | [`../../fazlar/FAZ-5.md`](../../fazlar/FAZ-5.md) |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
| Kod standartları | `CLAUDE.md` |
