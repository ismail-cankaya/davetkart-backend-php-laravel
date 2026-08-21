# `database/factories/InvitationFactory.php`

> **Kod dosyası:** `database/factories/InvitationFactory.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.6 (1/3)
> **Aynı adımda:** `TimelineEventFactory` · `DatabaseSeeder` · `TimelineEvent`e `HasFactory`
> **Bağlantılı:** [`UserFactory.md`](UserFactory.md) — fabrika kavramının temeli orada

---

## 1. Fabrika ne işe yarar?

Bir testte davetiye lazım olduğunda 20 kolonu elle doldurmak istemezsin:

```php
// ❌ her testte tekrar eden 20 satır
$invitation = new Invitation();
$invitation->user_id = $user->id;
$invitation->category_id = 'dugun';
// ... 18 satır daha
```

Fabrika bunu tek satıra indirir:

```php
$invitation = Invitation::factory()->create();     // ✅
```

Ve asıl kazanç şu: testte **konuyla ilgili olan** alanı yazarsın, gerisini
fabrika doldurur.

```php
$invitation = Invitation::factory()->create(['show_gift' => true]);
```

Bu satırı okuyan biri "bu test hediye modülüyle ilgili" der. 20 satırlık kurulumda
o bilgi kaybolur. **Fabrika, testin niyetini görünür kılar.**

---

## 2. 🔴 Rastgelelik nerede olur, nerede olmaz?

`fake()` rastgele veri üretir. Nerede kullandığımıza dikkat et:

```php
'title'    => 'Hayatimizin En Anlamli Gunu',   // SABIT
'subtitle' => fake()->sentence(),               // rastgele
'status'   => InvitationStatus::default(),      // SABIT
'show_gift'=> false,                            // SABIT
```

Ölçüt tek cümle:

> **Rastgelelik yalnızca davranışı etkilemeyen alanlarda olur.**

| Alan | Rastgele olsa ne olurdu |
|---|---|
| `subtitle` | Hiçbir şey — kimse ona bakmıyor |
| `status` | Test bazen taslakla, bazen yayınla çalışır → **kararsız test** |
| `show_gift` | Paywall testi bazen geçer bazen kalır |
| `user_id` | Sahiplik testleri anlamsızlaşır |

Kararsız (flaky) test, güvenilir olmayan testtir; güvenilmeyen test de bakılmayan
teste dönüşür. Bu, Faz 2'nin **T12** kuralının aynı ailesi: *ölçümü kararsız olan
şey teste konmaz.*

### `show_*` alanları neden hepsi `false`?

Modül açıklığı **paywall'ın konusu**. Fabrikada varsayılan olarak açık
bırakırsak, Faz 7'de "Standart plan galeriyi açamaz" testini yazarken fabrika
zaten galeriyi açmış olur ve test yanlış yerden geçer.

Varsayılan **kapalı**: bir modül açıksa, testi yazan kişi onu **bilerek** açmıştır.

---

## 3. `'user_id' => User::factory()` — fabrika içinde fabrika

```php
'user_id' => User::factory(),
```

Bu satır bir `User` **nesnesi** değil, bir **fabrika** döndürüyor. Laravel bunu
tanır: davetiye oluşturulurken önce kullanıcıyı üretir, sonra id'sini buraya
yazar.

```php
Invitation::factory()->create();
// => 1 kullanici + 1 davetiye olustu
```

Sahibi kendin vermek istersen iki yol var:

```php
Invitation::factory()->for($user)->create();               // ✅ okunakli
Invitation::factory()->create(['user_id' => $user->id]);   // ✅ ayni sey
```

`for()` "bu kaydın üstü şudur" demenin ilişki-farkında yoludur; `belongsTo`
ilişkileri için tercih edilir.

---

## 4. 🔴 Fabrika `#[Fillable]` korumasını neden aşabiliyor?

3.4'te `status`, `user_id` ve `published_at` alanlarını **bilerek** fillable
listesinin dışında bırakmıştık. Ama fabrika onları yazabiliyor. Çelişki mi?

Hayır — Laravel fabrikaları `Model::unguarded()` içinde çalıştırır, yani toplu
atama koruması fabrika süresince **kapalıdır**.

Ve bu doğru tasarım:

| | Ne için var |
|---|---|
| `#[Fillable]` | **İstemci girdisine** karşı savunma — HTTP'den gelen veri |
| Fabrika | **Test kurulumu** — kodun kendisi, kendi veritabanında |

Koruma "hiç kimse bu kolonu yazamasın" demiyor; "**dışarıdan gelen veri** bu
kolonu yazamasın" diyor. Test, dışarısı değildir.

Aksi hâlde "yayınlanmış davetiye" durumunu test etmek imkânsız olurdu — yayına
geçmek için ödeme akışını taklit etmek gerekirdi.

> **Genel ilke:** Güvenlik sınırı **güven sınırıdır**, mutlak bir kilit değil.
> Sınırın hangi tarafında olduğunu bilmek, kuralı ne zaman uygulayacağını
> belirler.

---

## 5. `state()` — durum metotları

```php
public function published(): static
{
    return $this->state(fn (array $attributes): array => [
        'status' => InvitationStatus::Published,
        'published_at' => now(),
    ]);
}
```

Kullanımı:

```php
Invitation::factory()->published()->create();
```

`state()` varsayılanların **üstüne yazan** bir katman ekler. Zincirlenebilir:

```php
Invitation::factory()->published()->withTimeline()->for($user)->create();
```

### İki alanı neden birlikte değiştiriyoruz?

`status = published` ama `published_at = null` olan bir satır **tutarsızdır**.
Böyle bir kayıt gerçek hayatta oluşamaz; testte oluşursa, testin doğruladığı şey
gerçeği temsil etmez.

> **Kural:** Durum metodu, bir kaydı **geçerli** bir duruma taşır. Yarım bırakılan
> her alan, ileride "bu nasıl oldu?" diye bakacağın bir hata kaynağıdır.

### Neden `fn (array $attributes)` — parametre kullanılmıyor ki?

`state()` closure'a mevcut alanları verir; koşullu durumlar için gerekir:

```php
->state(fn (array $attributes): array => [
    'names' => $attributes['category_id'] === 'dugun' ? 'Gelin & Damat' : 'Kutlama',
])
```

Bizim durumlarımız koşulsuz olduğu için parametreyi kullanmıyoruz, ama imza
Laravel'in beklediği biçimdir.

---

## 6. `withTimeline()` — ilişkili kayıt üretmek

```php
public function withTimeline(int $count = 3): static
{
    return $this
        ->state(fn (array $attributes): array => ['show_timeline' => true])
        ->has(
            TimelineEvent::factory()
                ->count($count)
                ->sequence(fn (Sequence $sequence): array => ['sort_order' => $sequence->index]),
            'timelineEvents'
        );
}
```

Üç parça var.

### `has()` — alt kayıtları da üret

`has(fabrika, 'iliskiAdi')` davetiyeyi oluşturduktan sonra o ilişkiden `$count`
adet kayıt üretir ve `invitation_id`'yi kendisi bağlar.

İkinci parametre ilişki metodunun adıdır (`Invitation::timelineEvents()`).
Yazmasan Laravel model adından tahmin etmeye çalışır; açıkça yazmak daha güvenli.

### `sequence()` — her kayda farklı değer

`count(3)` aynı fabrikayı üç kez çalıştırır; hepsi `sort_order = 0` olurdu ve
sıralama testi anlamsızlaşırdı.

`sequence()` her üretimde çağrılır ve `$sequence->index` 0, 1, 2… diye artar:

```
0. adim → sort_order = 0
1. adim → sort_order = 1
2. adim → sort_order = 2
```

### `show_timeline => true` neden burada?

Program adımı olan ama modülü kapalı bir davetiye tutarsızdır (§5'teki kuralın
aynısı): misafir sayfasında program görünmezdi ama veritabanında dururdu.

Modülü kapalı tutup adım eklemek isteyen bir test bunu açıkça yazabilir:

```php
Invitation::factory()->withTimeline()->create(['show_timeline' => false]);
```

`create()`'e verilen alanlar en son uygulanır, yani durum metodunu ezer.

---

## 7. `@extends Factory<Invitation>` ve eksik dönüş tipi

```php
/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
```

`Factory` generic bir sınıftır: hangi modeli ürettiğini tip düzeyinde bildirmek
gerekir. Bu satır olmadan PHPStan `Invitation::factory()->create()` ifadesinin
`Invitation` döndürdüğünü bilemez.

`definition()` metodunda ise **bilerek** `@return` yazmadık — Faz 2'nin
**19. dersi**:

> Docblock, üst sınıftakinden daha iyi bilgi taşımıyorsa yazılmamalıdır.

`Factory::definition()`'ın dönüş tipini kopyalamaya çalışmak `UserFactory`'de
kovaryansı bozmuştu; hiç yazmayınca üst sınıftan devralınıyor ve tip **daha iyi**
oluyor.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `'status' => fake()->randomElement(...)` | Test bazen geçer bazen kalır | Sabit varsayılan |
| 2 | `show_*` varsayılanını `true` yapmak | Paywall testleri yanlış yerden geçer | Hepsi `false` |
| 3 | Durumda yalnızca `status` değiştirmek | `published_at` null kalır → tutarsız kayıt | İkisini birlikte |
| 4 | `count(3)` ile `sequence()` yazmamak | Üç adım da `sort_order = 0` | `sequence()` |
| 5 | `definition()`'a `@return array` yazmak | PHPStan kovaryans hatası | Yazma, devral |
| 6 | `@extends Factory<Invitation>` unutmak | `create()` tipi bilinmez, PHPStan kırılır | Docblock'u yaz |
| 7 | Fabrikayı üretimde kullanmak | Koruma kapalı çalışır | Yalnızca test ve seeder |
| 8 | Testte assert edeceğin değeri fabrikaya bırakmak | Rastgele değere assert edersin | `create([...])` ile açıkça geç |

---

## 9. Kendin dene

```powershell
php artisan tinker
```

```php
use App\Models\Invitation;
use App\Models\User;

// En sade kullanim: kullaniciyi da kendisi uretir
$inv = Invitation::factory()->create();
$inv->user->email;                   // => rastgele bir e-posta
$inv->status->value;                 // => "saved"
$inv->show_gift;                     // => false

// Sahibi kendin ver
$user = User::factory()->create();
$inv2 = Invitation::factory()->for($user)->create();
$inv2->user_id === $user->id;        // => true

// Yayinlanmis + programli
$inv3 = Invitation::factory()->published()->withTimeline()->create();
$inv3->status->value;                // => "published"
$inv3->published_at;                 // => CarbonImmutable (null DEGIL)
$inv3->show_timeline;                // => true
$inv3->timelineEvents->pluck('sort_order')->all();
// => [0, 1, 2]                      ✅ sequence() calisti

// create() durum metodunu ezer
$inv4 = Invitation::factory()->published()->create(['title' => 'Elle Verilen']);
$inv4->title;                        // => "Elle Verilen"

// Uretmeden gorelim (veritabanina yazmaz)
Invitation::factory()->make()->toArray();

// Temizlik
Invitation::query()->forceDelete();
User::query()->delete();
```

```powershell
composer check
```

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Fabrika** (*factory*) | Test verisi üreten sınıf |
| **`definition()`** | Bir kaydın varsayılan alanları |
| **Durum** (*state*) | Varsayılanların üstüne yazan adlandırılmış katman |
| **`make()` / `create()`** | Nesneyi üret / üretip veritabanına yaz |
| **`for()`** | Üst kaydı belirtmek (`belongsTo` yönü) |
| **`has()`** | Alt kayıtları da üretmek (`hasMany` yönü) |
| **`sequence()`** | Her üretimde farklı değer vermek |
| **`unguarded`** | Toplu atama korumasının geçici olarak kapatıldığı blok |
| **Kararsız test** (*flaky*) | Kod değişmeden bazen geçen bazen kalan test |
| **Kovaryans** | Alt sınıfın dönüş tipini daraltabilmesi kuralı |

---

## 11. Sırada ne var?

Bu adımın diğer iki dosyası:
[`TimelineEventFactory.md`](TimelineEventFactory.md) ·
[`DatabaseSeeder.md`](../seeders/DatabaseSeeder.md)

Ardından **3.7 — `app/Policies/InvitationPolicy.php`**: fazın en kritik güvenlik
dosyası. "Bu davetiye senin mi?" sorusu ve reddin neden **403 değil 404** olduğu.
