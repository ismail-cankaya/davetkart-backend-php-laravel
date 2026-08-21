# `database/factories/TimelineEventFactory.php`

> **Kod dosyası:** `database/factories/TimelineEventFactory.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.6 (2/3)
> **Önce oku:** [`InvitationFactory.md`](InvitationFactory.md) — fabrika kavramları orada

---

## 1. Küçük ama iki karar taşıyor

```php
public function definition(): array
{
    return [
        'invitation_id' => Invitation::factory(),
        'time' => '19:00',
        'title' => fake()->words(2, true),
        'description' => fake()->sentence(),
        'sort_order' => 0,
    ];
}
```

---

## 2. `'invitation_id' => Invitation::factory()` — özyineleme riski var mı?

Haklı bir soru. `InvitationFactory::withTimeline()` `TimelineEvent::factory()`
çağırıyor; bu fabrika da `Invitation::factory()` çağırıyor. Sonsuz döngü olmaz mı?

Olmaz. `has()` alt kayıtları oluştururken `invitation_id`'yi **kendisi**
doldurur ve `definition()` içindeki fabrika hiç çalışmaz.

```php
Invitation::factory()->withTimeline()->create();
// 1 kullanici + 1 davetiye + 3 adim   (2 davetiye DEGIL)
```

Fabrika-içinde-fabrika yalnızca üst kayıt **verilmediğinde** devreye girer:

```php
TimelineEvent::factory()->create();
// => bir davetiye de uretir (o davetiye de bir kullanici uretir)
```

Bu, tek bir program adımını izole test etmek isteyen için kolaylıktır: neyin
gerektiğini düşünmeden yazarsın, fabrika zinciri kurar.

---

## 3. `sort_order` neden sabit `0`?

`InvitationFactory::withTimeline()` sıralamayı `sequence()` ile veriyordu. Burada
sabit 0.

Sebep **sorumluluk dağılımı**:

| Kim | Neyi bilir |
|---|---|
| `TimelineEventFactory` | Tek bir adımın nasıl göründüğünü |
| Çağıran (`withTimeline`, test) | Kaç adım olduğunu ve **hangi sırayla** dizildiğini |

Sıra bir **koleksiyon** özelliğidir; tek bir elemanın kendi başına sırası yoktur.
Fabrikaya `fake()->numberBetween(0, 10)` yazsaydık, çakışan ve anlamsız sıralar
üretirdik — ve sıralama testleri tesadüfe bağlanırdı (§`InvitationFactory.md` §2).

Sıraya ihtiyacı olan çağırır:

```php
TimelineEvent::factory()->count(3)
    ->sequence(fn ($s) => ['sort_order' => $s->index])
    ->for($invitation)
    ->create();
```

---

## 4. `time` neden sabit, `title` neden rastgele?

Aynı ölçüt: **davranışı etkileyen sabit, etkilemeyen rastgele.**

- `time` bir gün doğrulama kuralına konu olacak (`date_format:H:i`, 3.8).
  Rastgele bir saat üretip ara sıra geçersiz biçim üretmek istemeyiz.
- `title` yalnızca ekranda görünür; kimse ona assert etmez.

`fake()->words(2, true)` iki kelimeyi **metin olarak** döndürür. `true`
olmadan dizi döner (`['lorem', 'ipsum']`) ve kolona yazılamaz — küçük ama sık
yapılan bir hata.

---

## 5. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `fake()->words(2)` (`true` yok) | Dizi döner, kolona yazılamaz | `words(2, true)` |
| 2 | `sort_order` rastgele | Sıralama testleri tesadüfe bağlanır | Sabit 0, sırayı çağıran verir |
| 3 | `time` rastgele | Ara sıra geçersiz biçim | Sabit `'19:00'` |
| 4 | `@extends Factory<TimelineEvent>` unutmak | PHPStan tipi bilemez | Docblock'u yaz |
| 5 | Özyineleme korkusuyla `invitation_id`'yi çıkarmak | Tek başına üretim NOT NULL ihlali verir | Fabrikayı bırak |

---

## 6. Kendin dene

```php
use App\Models\Invitation;
use App\Models\TimelineEvent;

// Tek basina: zincirin tamamini kurar
$e = TimelineEvent::factory()->create();
$e->invitation->user->email;        // => calisir, hepsi uretildi

// Ust kayit verilince ozyineleme yok
$inv = Invitation::factory()->create();
TimelineEvent::factory()->count(2)->for($inv)->create();
Invitation::query()->count();       // => 2  (ilk fabrika + bu)  — 4 degil

// Siralamayi cagiran verir
$inv2 = Invitation::factory()->withTimeline(4)->create();
$inv2->timelineEvents->pluck('sort_order')->all();   // => [0, 1, 2, 3]

Invitation::query()->forceDelete();
```

---

## 7. Sırada ne var?

Bu adımın son dosyası: [`DatabaseSeeder.md`](../seeders/DatabaseSeeder.md)
