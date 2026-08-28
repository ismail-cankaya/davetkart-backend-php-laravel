# `database/factories/RsvpFactory.php`

> **Kod dosyası:** `database/factories/RsvpFactory.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.12
> **Birlikte değişen:** `database/seeders/DatabaseSeeder.php`
> **Kardeş dosyalar:** [`InvitationFactory.md`](InvitationFactory.md) ·
> [`TimelineEventFactory.md`](TimelineEventFactory.md)

---

## 1. Fabrika nedir, neden var?

Bir test şuna benzer bir cümleyle başlar: *"elimde 3 kişilik bir LCV yanıtı
olsun."* Fabrika o cümleyi tek satıra indirir:

```php
Rsvp::factory()->guests(3)->create();
```

Fabrika olmasaydı her test yedi alanı elle doldururdu; bir kolon eklendiğinde
**bütün** testler kırılırdı.

---

## 2. 🔴 Rastgelelik nerede serbest, nerede yasak?

`InvitationFactory` şu kuralı koymuştu:

> Rastgelelik yalnızca **davranışı etkilemeyen** alanlarda.

Bu fabrikada ayrım şöyle:

| Alan | Değer | Neden |
|---|---|---|
| `guest_name` | 🎲 `fake()->name()` | Hiçbir kural bu değere bakmıyor |
| `ip_hash` | 🎲 rastgele IP'nin hash'i | Bugün hiçbir kural buna bakmıyor |
| **`guest_count`** | 🔒 sabit `1` | **Kotayı belirler** |
| **`status`** | 🔒 sabit `Attending` | **Kotaya girip girmediğini belirler** (K50) |
| `menu_preference`, `message` | 🔒 `null` | Opsiyonel alanların yokluğu varsayılan olmalı |

`guest_count` rastgele olsaydı (`fake()->numberBetween(1, 10)`), şu test bazen
geçer bazen kalırdı:

```php
Rsvp::factory()->count(20)->for($inv)->create();
// kota 100 -> toplam 20 mi, 200 mü? Bilinmiyor.
```

**T12**'nin akrabası: *ölçümü kararsız olan şey teste konmaz.* Flaky test
güveni yok eder — ve bir kez "bazen kırılıyor, tekrar çalıştır" alışkanlığı
yerleşince gerçek hatalar da o çöp kutusuna atılır.

---

## 3. `ip_hash` — test verisinde bile ham IP yok

```php
'ip_hash' => hash('sha256', fake()->ipv4()),
```

Fabrika `str_repeat('a', 64)` de yazabilirdi. Gerçek bir hash üretmesinin iki
sebebi var:

1. **Alışkanlık.** Test verisinde ham IP tutmaya başlarsan, bir gün bir
   fixture'ı üretime taşırsın. KVKK alışkanlığı her yerde aynı olmalı.
2. **Gerçekçilik.** Her satır farklı bir hash taşır; ileride "aynı IP'den ikinci
   yanıt" gibi bir kural yazılırsa fabrika onu doğal olarak destekler.

`hash('sha256', ...)` her zaman 64 karakter üretir — kolonun tam genişliği
(5.2 §6).

---

## 4. Durum metotları (state) — testin niyetini söylemek

```php
Rsvp::factory()->declined()->create();
Rsvp::factory()->pending()->guests(4)->create();
```

Alternatif şuydu:

```php
Rsvp::factory()->create(['status' => RsvpStatus::Declined, 'guest_count' => 4]);
```

İkisi de çalışır. Farkı **okunurluk ve niyet**:

- `->declined()` "bu testin konusu, misafirin gelmiyor demesi" der.
- `->create(['status' => ...])` "bu alanı şu yaptım" der.

Kota testinde bu fark belirleyici:

```php
Rsvp::factory()->for($inv)->guests(60)->create();
Rsvp::factory()->for($inv)->declined()->guests(50)->create();

// -> 60 kotadan yer tutar, 50 tutmaz. Test bunu SÖYLÜYOR.
```

`state()` metodu bir kapanış (closure) alır ve mevcut alanların üzerine yazar.
`static` dönüş tipi zincirlemeyi mümkün kılar (`->pending()->guests(4)`).

### `guests(int $count)` — neden ayrı bir metot?

`guest_count` bu fazın **en kritik sayısıdır**: kota `COUNT(*)` ile değil bu
alanın toplamıyla ölçülüyor. Ona bir ad vermek, kota testlerini okunur kılıyor.

---

## 5. `'invitation_id' => Invitation::factory()`

Üst kayıt verilmezse fabrika kendi davetiyesini üretir. Verilirse:

```php
Rsvp::factory()->for($invitation)->create();
```

`for()` ilişkiyi kurar ve fabrikanın kendi davetiyesini üretmesini engeller.

> ⚠️ Fabrika `invitation_id`'yi **doğrudan** yazıyor, oysa `#[Fillable]`
> listesinde yok. Çelişki değil: fabrikalar toplu atama korumasını **bilerek**
> atlar (`Model::unguarded` bağlamında çalışırlar). Beyaz liste *istemciden
> gelen veriye* karşı bir savunmadır; test kodu istemci değildir.

---

## 6. Seeder'a eklenen üç yanıt

`DatabaseSeeder` artık yayındaki davetiyeyi LCV'ye açıyor ve üç yanıt üretiyor:

| Misafir | Durum | Kişi | Kotadan yer tutar mı |
|---|---|---|---|
| Can Doğan | `attending` | 3 | ✅ |
| Elif Yılmaz | `pending` | 1 | ✅ |
| Mert Kaya | `declined` | 2 | ❌ |

Toplam **6 kişi**, kotadan yer tutan **4**. Sayılar bilerek farklı: elle
doğrulamada "panelde 6 mı 4 mü görüyorum?" sorusu **anlamlı** olsun diye.

🔴 **B5 hatırlatması:** *hiçbir otomatik kontrolün yolunda olmayan dosyayı elle
çalıştırmak senin sorumluluğun.* `composer check` seeder'ı **koşturmaz**. Faz
3'te `DatabaseSeeder`'ın Faz 0'dan beri bozuk olduğu (var olmayan `name`
kolonuna yazıyordu) tam olarak bu yüzden aylarca fark edilmemişti.

Yani: `php artisan db:seed` komutunu elle çalıştır ve çıktısına bak.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `guest_count`'u rastgele yapmak | Kota testleri flaky olur (T12) |
| 2 | `status`'ü rastgele yapmak | Aynı sorun; kota bazen tutar bazen tutmaz |
| 3 | `definition()`'a `: array` dönüş tipi yazmak | Üst sınıfla çakışır (Faz 2, ders 19) |
| 4 | `->for($invitation)` yerine `['invitation_id' => $inv->id]` | Çalışır ama ilişkiyi kullanmayan alışkanlık üretir (N1) |
| 5 | Fabrikada ham IP saklamak | Alışkanlık bozulur |
| 6 | Seeder'ı çalıştırmadan "çalışıyor" varsaymak | B5 — Faz 3'ün acı dersi |
| 7 | `menu_preference`'a varsayılan metin koymak | Testler "boş menü tercihi" senaryosunu hiç görmez |

---

## 8. Kendin dene

```powershell
php artisan db:seed
```

```php
// php artisan tinker
$inv = App\Models\Invitation::where('title', 'Yayindaki Davetiye')->first();

$inv->rsvps()->count();                     // 3 (kayıt)
$inv->rsvps()->sum('guest_count');          // 6 (misafir)

// 🔴 Kotadan yer tutanlar — K50
$inv->rsvps()
    ->whereIn('status', App\Enums\RsvpStatus::quotaConsumingValues())
    ->sum('guest_count');                   // 4

// Fabrika durumları
App\Models\Rsvp::factory()->declined()->guests(9)->make()->toArray();
```

`count()` 3, `sum()` 6 — bu fark, kotanın neden `COUNT(*)` ile ölçülemeyeceğinin
en kısa kanıtıdır.

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Factory** | Test/seed verisi üreten sınıf |
| **State** | Fabrikanın varsayılanlarını değiştiren adlandırılmış varyant |
| **Seeder** | Veritabanını başlangıç durumuna getiren sınıf |
| **Idempotans** | Aynı işlemin tekrarının tek etki üretmesi |
| **Flaky test** | Kod değişmeden bazen geçen bazen kalan test |
| **Fixture** | Testin dayandığı hazır veri |

---

## 10. Sırada ne var?

**5.13 — `RsvpTest`.** Fazın kanıtı: 30 test, bir mutasyon tablosu ve honeypot
için `assertDatabaseCount` ile yazılmış bir **etki** doğrulaması.

| İlgili | Nerede |
|---|---|
| Model | [`../../app/Models/Rsvp.md`](../../app/Models/Rsvp.md) |
| Durum enum'u | [`../../app/Enums/RsvpStatus.md`](../../app/Enums/RsvpStatus.md) |
| Kardeş fabrika | [`InvitationFactory.md`](InvitationFactory.md) |
