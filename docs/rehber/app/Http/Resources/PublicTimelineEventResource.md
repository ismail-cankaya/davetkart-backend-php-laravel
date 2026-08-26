# `app/Http/Resources/PublicTimelineEventResource.php`

> **Kod dosyası:** `app/Http/Resources/PublicTimelineEventResource.php`
> **Faz:** 4 — Public davetiye, dosya 4.2a
> **Önce oku:** [`TimelineEventResource.md`](TimelineEventResource.md) — sahibin sürümü
> **Sonra:** [`PublicInvitationResource.md`](PublicInvitationResource.md) (4.2b)

---

## 1. Neden ikinci bir sınıf? (C4)

Faz 3'te kurduğumuz kural:

> **C4** — Aynı veri, farklı okuyucular için **farklı Resource**'a çıkar.

Elimizde tek bir `timeline_events` satırı var, ama onu okuyan **iki ayrı kitle**
var:

| Okuyucu | Ne yapacak | `id`'ye ihtiyacı var mı |
|---|---|---|
| **Sahip** (editör) | Adımı düzenleyecek, silecek, sıralayacak | **Evet** — `id: "7"` = "bu satırı güncelle" (K44) |
| **Misafir** (davetiye) | Sadece okuyacak | **Hayır** |

Bu yüzden iki sınıf var ve aralarındaki **tek** fark `id` alanı.

### "Tek alan için ayrı sınıf abartı değil mi?"

Haklı bir soru — bu projede `Repository Pattern`'i tam da bu gerekçeyle
reddettik (K4: *"anlamsız aracı üretir"*). Aradaki fark şu:

Repository, aynı işi yapan bir **ara katman** olurdu. Bu sınıf ise bir **ara
katman değil, bir sözleşme beyanı**. `PublicTimelineEventResource` dosyasını
açan kişi, misafirin göreceği alanların tam listesini görüyor. Alternatifte
(sahibin Resource'unu koşullu hâle getirmek) o liste bir `if` bloğunun içine
gömülürdü ve iki sözleşme tek dosyada iç içe geçerdi.

> **Ölçüt:** Bir sınıf *iş yapıyorsa* ve o iş başka bir yerde zaten yapılıyorsa
> gereksizdir. Bir sınıf *bir şey beyan ediyorsa*, beyan ettiği şey kadar
> değerlidir.

---

## 2. 🔴 `id` neden dışarı çıkmıyor?

İlk bakışta zararsız görünür: `id: "7"` bir sayı, kimin ne umurunda?

Ama Faz 3'te **K40** kararını tam olarak bu yüzden almıştık:

> `invitations.id` = ULID. *Ardışık integer'da misafir `/invite/1,2,3` gezer.*

Yani davetiyenin kimliğini tahmin edilemez yaptık. Şimdi aynı davetiyenin
**içinde** ardışık bir bigint göndersek ne olurdu?

```json
{ "timelineEvents": [ { "id": "48213", ... } ] }
```

Bir misafir birkaç farklı davetiyeye bakıp bu sayıların nasıl arttığını
görebilir. Örneğin bir haftada `48213` → `51907` olduysa, sistemde haftada ~3700
program adımı yazıldığı — yani kabaca kaç davetiye üretildiği — anlaşılır. Buna
**German tank problem** denir: seri numaralarından üretim hacmini tahmin etme.

Ticari bir SaaS için bu, rakibe bedava verilen bir metrik. Tek başına felaket
değil; ama **bedeli sıfır olan bir savunmayı** almamak için sebep yok.

> **C5 hatırlatması:** *Gövdeye giden alanlar da beyaz listedir.* Bir alanın
> "zararsız" görünmesi onu göndermek için gerekçe değildir; **birinin ona
> ihtiyacı olması** gerekçedir.

### Peki React `key` ihtiyacı ne olacak?

Bu soruyu frontend zaten cevaplamış. `davetkart-frontent/src/types.ts:51`:

```ts
/**
 * Yalnızca React listesinin `key` ihtiyacı için yerel anahtar. Sunucuya
 * GÖNDERİLMEZ — `invitationService` istek gövdesini kurarken düşürür.
 * `id` null olan yeni adımların da kararlı bir anahtarı olsun diye var.
 */
localKey: string;
```

Faz 3'te K44 için üretilmiş olan `localKey`, burada ikinci bir işe yarıyor:
misafir tarafında `id` hiç gelmediği için React anahtarı zaten yerelde
üretiliyor. **Bir kararın beklenmedik bir yerde ikinci kez işe yaraması, o
kararın doğru katmanda alındığının işaretidir.**

Ayrıca `Timeline.tsx` bileşenini okuduğumuzda `id`'nin çizimde hiç
kullanılmadığı görülüyor — yalnızca `time`, `title`, `description`.

---

## 3. Kod okuması

```php
final class PublicTimelineEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'time' => $this->time ?? '',
            'title' => $this->title ?? '',
            'description' => $this->description ?? '',
        ];
    }
}
```

### `@mixin TimelineEvent` ne işe yarıyor?

Sınıfın PHPDoc'unda duran bu satır **çalışma anında hiçbir şey yapmaz** — bir
statik analiz ipucudur. `JsonResource` içinde `$this->time` yazabilmemizin
sebebi, `JsonResource`'un `__get()` sihriyle çağrıları sarmaladığı
`$this->resource`'a (yani modele) yönlendirmesidir.

PHPStan bu sihri kendiliğinden bilemez. `@mixin TimelineEvent` ona *"bu sınıfın
üzerinde `TimelineEvent`'in tüm özelliklerini de arayabilirsin"* der. Bu sayede
`$this->titel` yazarsan (yazım hatası) `composer analyse` yakalar.

### `?? ''` neden var?

Kolonlar `nullable` (3.3): autosave yarım veri yazabiliyor, yeni bir adım boş
başlıkla doğabiliyor. Frontend tipi ise `title: string` — zorunlu string.

`null` gönderirsek TypeScript'in inandığı şey ile gerçekte gelen şey ayrışır ve
`invitation.title.toUpperCase()` gibi bir satır çalışma anında patlar. `?? ''`
bu **sınır dönüşümünü** tek yerde yapıyor.

> Faz 3, ders 30: *Savunma kodu her yere değil, **güven sınırına** yazılır.*
> Resource katmanı backend ile frontend arasındaki sınırdır; tip uyumsuzluğunu
> çözecek doğru yer burasıdır.

### Neden `final`?

Miras alınabilir bırakmak, birinin `toArray()`'i ezip listeye alan eklemesine
kapı açardı — beyaz listenin (C1) anlamı kalmazdı. Projedeki tüm Resource'lar
`final`.

---

## 4. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Sahibin `TimelineEventResource`'unu misafire de vermek | Ardışık `id` dışarı sızar (§2) | Ayrı sınıf |
| 2 | Sahibin Resource'una `if ($public)` eklemek | İki sözleşme tek dosyada iç içe geçer; hangi okuyucunun ne aldığı okunmaz olur | Ayrı sınıf (C4) |
| 3 | `sort_order`'ı da göndermek | Sıra zaten dizinin kendi sırası; iki bilgi çelişirse hangisine inanılacağı belirsiz (N3) | Gönderme |
| 4 | `?? ''` yazmayıp `null` göndermek | Frontend tipi yalan söyler, çizimde patlar | Sınırda dönüştür |
| 5 | `created_at` / `updated_at` eklemek | Kimsenin istemediği alan; bir gün birileri ona bağlanır (C1) | Beyaz listede yeri yok |

---

## 5. Kendin dene

Bu sınıf tek başına bir uca bağlı değil (4.2b ve 4.3 gelecek), o yüzden
`tinker`'da doğrudan çağırıyoruz.

```powershell
php artisan tinker
```

```php
use App\Http\Resources\PublicTimelineEventResource;
use App\Http\Resources\TimelineEventResource;
use App\Models\Invitation;

$inv = Invitation::factory()->withTimeline(2)->create();
$event = $inv->timelineEvents()->first();

// 1) Sahibin sürümü — id VAR
(new TimelineEventResource($event))->toArray(request());
// ⇒ ['id' => '1', 'time' => '...', 'title' => '...', 'description' => '...']

// 2) Misafirin sürümü — id YOK
(new PublicTimelineEventResource($event))->toArray(request());
// ⇒ ['time' => '...', 'title' => '...', 'description' => '...']

// 3) Anahtar gerçekten yok mu? (assertJsonMissingPath'in tinker karşılığı)
array_key_exists('id', (new PublicTimelineEventResource($event))->toArray(request()));
// ⇒ false   ← 'id' => '' DEĞİL, anahtarın kendisi yok

// 4) Boş alanlar null değil boş string mi?
$bos = $inv->timelineEvents()->create([]);
(new PublicTimelineEventResource($bos))->toArray(request());
// ⇒ ['time' => '', 'title' => '', 'description' => '']

// Temizlik
Invitation::withTrashed()->forceDelete();
```

Adım 3 en önemlisi. **Maskeleme ile atmak farklı şeylerdir:** `'id' => ''`
yazsaydık alan sözleşmede kalır, frontend ona bağlanabilir ve bir gün birisi
"neden hep boş?" diye sorup doldurmaya kalkardı. Anahtarı hiç göndermemek,
kararı **görünür** kılar.

```powershell
composer check
```

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Resource** | Model → API gövdesi dönüşümünü yapan sınıf; bir beyaz listedir |
| **Beyaz liste** | "Yalnızca burada adı geçenler geçer" kuralı |
| **`@mixin`** | Statik analize "bu sınıf şu sınıfın üyelerini de taşıyor" diyen PHPDoc etiketi |
| **German tank problem** | Seri numaralarından toplam üretim hacmini tahmin etme |
| **Güven sınırı** | Sistemin kontrol ettiği veri ile etmediği verinin ayrıldığı yer |
| **`localKey`** | Frontend'in React `key`'i için ürettiği yerel anahtar (sunucuya gitmez) |

---

## 7. Sırada ne var?

**4.2b — `PublicInvitationResource`.** Bu sınıf tek bir satırı çevirdi; sıradaki
sınıf davetiyenin tamamını çevirecek ve C4'ün asıl sınavını verecek:

> `show_gift = false` iken `iban`, `bankName`, `accountHolder`, `giftOptions`
> gövdeye **hiç girmeyecek** — boş string olarak değil, anahtar olarak da yok.

Aynı ilkeyi kapalı olan her modüle uygulayacağız: kapalı modülün verisi
gönderilmez. Frontend tarafında bunun karşılığı `InvitationComposition.tsx`'te
zaten hazır — kapalı modülün bileşeni hiç mount olmuyor.
