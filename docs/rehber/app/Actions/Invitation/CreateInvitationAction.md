# `app/Actions/Invitation/CreateInvitationAction.php`

> **Kod dosyası:** `app/Actions/Invitation/CreateInvitationAction.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.10 (2/3)
> **Önce oku:** [`SyncTimelineEventsAction.md`](SyncTimelineEventsAction.md)

---

## 1. Action katmanı neyi çözüyor?

`CLAUDE.md` §1: *Her bir kullanıcı işlemi için ayrı bir sınıf oluşturulur. Her
sınıf sadece TEK bir eylemi gerçekleştirir.*

Bu kodun tamamı controller'a da yazılabilirdi. Yazmıyoruz çünkü **değişme
sebepleri farklı**:

| Katman | Ne zaman değişir |
|---|---|
| Controller | HTTP değişince (rota, durum kodu, yanıt biçimi) |
| **Action** | **İş kuralı değişince** (transaction, ilişkiler, sıralama) |

İkisini birleştirirsek her HTTP değişikliğinde iş kuralına, her iş kuralı
değişikliğinde HTTP'ye dokunmak zorunda kalırız. Buna **Single Responsibility**
denir.

Somut kazanç: bu Action'ı HTTP olmadan, `tinker`'da veya bir testte doğrudan
çağırabiliyorsun. Faz 7'de aynı iş kuralı bir konsol komutundan da çağrılabilir.

---

## 2. 🔴 Sahiplik ilişkiden gelir

```php
$invitation = $user->invitations()->create($attributes);
```

`$attributes` içinde `user_id` **yok** — ve olamaz da:

- 3.4'te `#[Fillable]` listesine koymadık
- 3.8'de `COLUMN_MAP` içine koymadık

Yani istemciden gelen veri bu kolona **hiçbir yoldan** ulaşamıyor. `user_id`'yi
Eloquent, ilişkiden okuyarak kendisi yazıyor.

Alternatifi şu olurdu:

```php
Invitation::create([...$attributes, 'user_id' => $request->user()->id]);   // ❌
```

Çalışır ama savunma **hatırlamaya** bağlı hâle gelir: yeni bir çağrı yeri
eklendiğinde satırı kopyalamayı unutabilirsin. İlişkiden oluşturmak savunmayı
**yapısal** yapar.

> **Kalıp:** Alt kaydı her zaman üst kaydın ilişkisinden oluştur. Aidiyet
> doğrulanması gereken bir girdi olmaktan çıkıp yapısal bir garanti olur.

---

### 🔴 Peki `status`? Aynı ailenin ikinci yarısı (Faz 4 düzeltmesi)

`user_id` gibi `status` de **sunucunun malıdır** ve `#[Fillable]` listesinde
yoktur (3.4). Ama sahiplikten bir farkı var: `user_id`'yi ilişki **yazıyor**,
`status`'ü hiçbir PHP kodu yazmıyordu. Değeri yalnızca migration'daki
`->default('saved')` biliyordu.

Faz 3'te bu satır şöyleydi:

```php
$invitation = $user->invitations()->create($attributes);   // ❌ status yok
```

`create()` yalnızca kendisine verilen alanları `INSERT`'e koyar. Veritabanı
`status` kolonunu varsayılanıyla doldurur — **ama Eloquent bunu geri okumaz.**
Bellekteki model insert'ten sonra da `status` niteliği taşımaz:

```php
$invitation->status;          // null
$invitation->status->value;   // 💥 Attempt to read property "value" on null
```

`InvitationResource:30` tam olarak burada 500 veriyordu.

#### Neden katı kip bunu yakalamadı?

Faz 0'da `shouldBeStrict()` açıldı ve o `preventAccessingMissingAttributes()`'ı
da içerir — eksik bir alana erişmek normalde `MissingAttributeException`
fırlatır. Ama kaynağa bakınca korumanın bir istisnası olduğu görülüyor
(`vendor/.../Concerns/HasAttributes.php:518`):

```php
if ($this->exists &&
    ! $this->wasRecentlyCreated &&              // ← istisna burada
    static::preventsAccessingMissingAttributes()) {
    throw new MissingAttributeException($this, $key);
}
```

Yeni oluşturulmuş modellerde koruma **bilerek** devre dışı: insert sonrası
modelin her kolonu bellekte olmayabilir, her erişimde patlamak kullanılamaz bir
framework üretirdi. Yani güvenlik ağının deliği tam olarak bu hatanın durduğu
yerdeydi — bu yüzden hata gürültülü bir exception yerine sessiz bir `null`
olarak çıktı.

#### Çözüm: `create()` yerine `make()` + açık atama

```php
$invitation = $user->invitations()->make($attributes);
$invitation->status = InvitationStatus::default();
$invitation->save();
```

`make()`, `create()`'in kaydetmeyen ikizidir. Kaynağı
(`vendor/.../Relations/HasOneOrMany.php:61`):

```php
public function make(array $attributes = [])
{
    return tap($this->related->newInstance($attributes), function ($instance) {
        $this->setForeignAttributesForCreate($instance);   // user_id yine ilişkiden
        $this->applyInverseRelationToModel($instance);
    });
}
```

Yani §2'de anlatılan yapısal sahiplik garantisi **aynen korunuyor**; araya
yalnızca "kaydetmeden önce sunucunun kendi alanını yazması" adımı giriyor.

#### 🔴 `$fillable` atamayı değil, TOPLU atamayı korur

Burada kafa karıştıran nokta şu: `status` `#[Fillable]` listesinde yok, peki
`$invitation->status = ...` nasıl çalışıyor?

| Yol | `$fillable` denetlenir mi? |
|---|---|
| `create([...])` · `fill([...])` · `make([...])` | **Evet** — liste dışı anahtar düşer (katı kipte exception) |
| `$model->status = ...` · `setAttribute()` | **Hayır** — hiç uğramaz |

`$fillable` bir **toplu atama (mass assignment)** savunmasıdır: dışarıdan gelen
bir *diziyi* modele dökerken hangi anahtarların geçebileceğini söyler. Tek tek
atama zaten programcının bilinçli bir kararıdır; korunacak bir şey yoktur.

Bu ayrım tam da istediğimiz şeyi mümkün kılıyor: **istemci `status` gönderemez,
ama sunucu yazabilir.** Aynı alan, iki farklı yazara karşı iki farklı davranış.

#### Veritabanı varsayılanı neden duruyor?

`->default('saved')` migration'da kaldı. İki katman çakışmıyor, **farklı yolları
kapatıyorlar**:

| Yol | Kim doldurur |
|---|---|
| `CreateInvitationAction` (Eloquent) | Bu satır — ve bellekteki model de doğru olur |
| `DB::table('invitations')->insert(...)` (ham SQL, seeder, konsol) | Veritabanı varsayılanı |

İkisi de aynı kaynaktan besleniyor: `InvitationStatus::default()`. Migration'daki
metin de o enum'dan üretilmişti (3.2). Yani **iki katman var, tek doğruluk
kaynağı var** — E1'in yasakladığı şey iki *kaynak*, iki *katman* değil.

> **Kural (E7):** Sunucunun sahip olduğu bir alanın değerini **sunucu kodu**
> söyler. Veritabanı varsayılanı bir yedektir, tek beyan değil — çünkü ORM onu
> geri okumaz ve bellekteki nesne yanlış kalır.

#### Değerlendirilip seçilmeyen alternatif

`Invitation` modeline bir `creating` olayı koymak da mümkündü:

```php
protected static function booted(): void
{
    static::creating(fn (self $i) => $i->status ??= InvitationStatus::default());
}
```

Avantajı: **her** Eloquent oluşturma yolu kapsanır, ikinci bir Action yazılırsa
hatırlamaya gerek kalmaz. Dezavantajı: karar görünmez bir olay dinleyicisine
taşınır; `CreateInvitationAction`'ı okuyan biri sunucunun ne karar verdiğini
göremez. Bugün tek oluşturma yolu bu Action olduğu için **görünürlük** tercih
edildi. Faz 7'de ikinci bir oluşturma yolu doğarsa karar yeniden tartışılmalı.

---

## 3. `DB::transaction()` — sınır neden burada?

```php
return DB::transaction(function () use (...) {
    $invitation = $user->invitations()->create($attributes);
    if ($timelineEvents !== null) {
        $this->syncTimelineEvents->handle($invitation, $timelineEvents);
    }
    ...
});
```

**Transaction nedir?** Birden çok veritabanı işlemini tek bir "ya hep ya hiç"
paketine sokar. İçindeki herhangi bir adım hata verirse, o ana kadar yapılanların
**hepsi geri alınır** (rollback).

Faz 2'de kurulan **E4** kuralı: *birden çok yazma varsa transaction.* Ölçütü de
vermişti: *"yarım kalan durum kimin için ne anlama gelir?"*

Burada iki tabloya yazıyoruz. Transaction olmasaydı:

```
1. invitations satiri olusur              ✅
2. timeline_events yazilirken hata olur   💥
3. Sonuc: programsiz, yarim bir davetiye — ve kullanici
   "kaydettim ama program gitmis" der
```

### Neden sınır burada, `SyncTimelineEventsAction` içinde değil?

Transaction **en dış işlem sınırında** açılır. Sync'in içine koysaydık:

```
[create invitations]              ← transaction DISINDA
  [transaction: sync]             ← sadece program korumali
```

Davetiye oluşur, program yazılırken hata alır, sync geri alınır — ama davetiye
kalır. Yarım durum yine oluşur.

Kural: **transaction, "birlikte anlamlı olan" işlemlerin tamamını kapsar.** Bu
sınırı bilen, işi başlatan katmandır — yani Action.

Laravel iç içe `DB::transaction()` çağrılarını *savepoint* ile yönetir, yani
sync'e de koysak çalışırdı; ama sınırın nerede olduğu belirsizleşirdi.

---

## 4. `$timelineEvents !== null` kontrolü

```php
if ($timelineEvents !== null) {
    $this->syncTimelineEvents->handle($invitation, $timelineEvents);
}
```

3.8'in `timelineEvents()` metodu üç durumu ayırıyordu:

| Dönen | Anlamı | Burada |
|---|---|---|
| `null` | Alan istekte yok | Sync çağrılmaz |
| `[]` | Boş dizi gönderildi | Sync çağrılır, hiçbir şey oluşmaz |
| `[...]` | Adımlar var | Sync çağrılır |

Oluşturma anında `null` ile `[]` aynı sonucu verir (yeni davetiyede zaten satır
yok). Ayrımı yine de koruyoruz, çünkü `UpdateInvitationAction`'da fark **kritik**
hâle geliyor ve iki Action'ın aynı sözleşmeyi konuşması gerekiyor.

---

## 5. `->load('timelineEvents')` neden şart?

```php
return $invitation->load('timelineEvents');
```

İki sebep:

**1. Resource ilişkinin yüklü olmasını bekliyor.** 3.9'da `whenLoaded`
kullanmama kararı vermiştik; `InvitationPayloadResource` doğrudan
`$this->timelineEvents` diyor. Yüklü değilse yerelde `LazyLoadingViolationException`
fırlar.

**2. Senkronizasyon sonrası bellekteki koleksiyon bayattır.** `sync` satırları
veritabanında oluşturdu, ama `$invitation`'ın belleğindeki ilişki koleksiyonu ya
boştur ya eski hâlidir. `load()` onu **yeniden okur**.

`load()` ile `with()` farkı:

| | Ne zaman |
|---|---|
| `with('iliski')` | Sorgu **kurulurken** — "getirirken bunu da getir" |
| `load('iliski')` | Model **elde varken** — "şimdi de bunu getir" |

Elimizde zaten model olduğu için `load()`.

---

## 6. Kurucu enjeksiyonu (constructor injection)

```php
public function __construct(
    private readonly SyncTimelineEventsAction $syncTimelineEvents,
) {}
```

`SyncTimelineEventsAction`'ı `new` ile yaratmıyoruz; Laravel'in **servis
kapsayıcısı** onu üretip veriyor. Controller `CreateInvitationAction`'ı tip
belirterek isteyince, kapsayıcı zinciri kendisi kuruyor.

`private readonly` = yalnızca kurucuda atanabilir, sonra değiştirilemez. PHP
8.1'in *constructor property promotion* yazımıyla tek satırda hem parametre hem
özellik tanımlanıyor.

Kazancı testte görünür: sahte bir sync nesnesi geçirip bu Action'ı izole test
edebilirsin.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `Invitation::create(['user_id' => ...])` | Savunma hatırlamaya bağlı | İlişkiden oluştur |
| 2 | Transaction'ı sync'in içine koymak | Yarım davetiye kalabilir | En dış sınırda |
| 3 | `load()` unutmak | `LazyLoadingViolationException` veya bayat program | `load('timelineEvents')` |
| 4 | `load()` yerine `with()` | Elde model varken yanlış araç | `load()` |
| 5 | Action'da `response()->json()` | Katman ihlali (`CLAUDE.md` §1) | Saf model döndür |
| 6 | Action'da yetki kontrolü | İki doğruluk kaynağı | Policy (3.7) |

---

## 8. Kendin dene

```php
use App\Models\User;
use App\Actions\Invitation\CreateInvitationAction;

$user = User::factory()->create();
$action = app(CreateInvitationAction::class);      // kapsayici zinciri kurar

$inv = $action->handle($user, [
    'category_id' => 'dugun',
    'preset_id'   => 'moda-gece',
    'palette'     => 'midnight',
    'title'       => 'Dugunumuz',
], [
    ['id' => null, 'time' => '17:00', 'title' => 'Karsilama'],
    ['id' => null, 'time' => '19:00', 'title' => 'Nikah'],
]);

$inv->user_id === $user->id;                  // => true   ✅ iliskiden geldi
$inv->status->value;                          // => "saved"
$inv->relationLoaded('timelineEvents');       // => true   ✅ load() calisti
$inv->timelineEvents->pluck('sort_order')->all();   // => [0, 1]

// Transaction kaniti: gecersiz program hata verirse davetiye de olusmamali
$oncekiSayi = App\Models\Invitation::query()->count();

try {
    $action->handle($user, ['category_id' => 'dugun', 'preset_id' => 'x', 'palette' => 'y'], [
        ['id' => null, 'title' => str_repeat('a', 500)],   // 120 karakter siniri asiliyor
    ]);
} catch (Throwable $e) {
    get_class($e);      // => QueryException
}

App\Models\Invitation::query()->count() === $oncekiSayi;
// => true   ✅ geri alindi, yarim davetiye kalmadi

App\Models\Invitation::query()->forceDelete();
User::query()->delete();
```

Son deneme E4'ün kanıtı.

```powershell
composer check
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Action** | Tek bir kullanıcı işlemini gerçekleştiren sınıf |
| **Transaction** | "Ya hep ya hiç" çalışan işlem paketi |
| **Rollback** | Transaction içindeki değişikliklerin geri alınması |
| **Savepoint** | İç içe transaction'lar için ara işaret |
| **Kurucu enjeksiyonu** | Bağımlılığın kurucudan verilmesi |
| **`load()` / `with()`** | İlişkiyi sonradan / sorguyla birlikte yükleme |
| **`readonly`** | Yalnızca kurucuda atanabilen özellik (PHP 8.1) |

---

## 10. Sırada ne var?

[`UpdateInvitationAction.md`](UpdateInvitationAction.md) — güncelleme yolu ve
`updated_at`'in bayat kalmasını önleyen `wasChanged()` inceliği.
