# `app/Http/Resources/PublicInvitationResource.php`

> **Kod dosyası:** `app/Http/Resources/PublicInvitationResource.php`
> **Faz:** 4 — Public davetiye, dosya 4.2b
> **Önce oku:** [`PublicTimelineEventResource.md`](PublicTimelineEventResource.md) ·
> [`InvitationPayloadResource.md`](InvitationPayloadResource.md) — sahibin sürümü

---

## 1. İki okuyucu, iki sözleşme

4.2a'da iki Resource'un farkı tek bir alandı (`id`). Burada fark **şeklin
kendisi**: aynı satır, iki farklı JSON üretiyor.

| | Sahip (`InvitationResource`) | Misafir (bu sınıf) |
|---|---|---|
| `id` | ✅ | ✅ |
| `status` | ✅ | ❌ — her zaman `published`, bilgi taşımaz |
| `updatedAt` | ✅ (editörde "son kaydetme") | ❌ — kimse okumuyor |
| Kapalı modülün verisi | ✅ (düzenlemeye devam edecek) | ❌ — **gönderilmez** |
| Program adımı `id`'si | ✅ (K44) | ❌ (4.2a) |

Bu, Faz 3'te yazdığımız **C4** kuralının asıl sınavı:

> **C4** — Aynı veri, farklı okuyucular için **farklı Resource**'a çıkar.

O zaman kuralın gerekçesini şöyle yazmıştık: *"Sahibin IBAN'ını maskelemek onu
sessizce silerdi."* Yani tek bir Resource'a maskeleme koysaydık, davetiye
sahibi kendi editöründe kendi IBAN'ını göremez olurdu. İki sınıf, iki niyet.

---

## 2. Şekil: neden `{ id, invitation: {...} }`?

```json
{
  "data": {
    "id": "01k3rjm2q8v5h7p0w9x4nzabcd",
    "invitation": { "title": "...", "showGift": false, ... }
  }
}
```

Üç karar var burada:

**`{data: ...}` zarfı** — K11: auth uçları zarfsız, **diğer her şey zarflı**.
Bu bir auth ucu değil, zarf var. Laravel `JsonResource` bunu kendiliğinden
ekliyor.

**`invitation` altında iç içe** — sahibin sürümüyle simetrik. Frontend'in
`services/invitations.ts` içindeki hidrasyon mantığı (Faz 3, F2) böylece
yeniden kullanılabiliyor. Düz gönderseydik (`{id, title, subtitle, ...}`) iki
farklı ayrıştırma yolu doğardı.

**`id`'nin taşınması** — misafir zaten kimliği URL'den biliyor
(`/invite/:id`), yani bugün şart değil. Yine de gönderiyoruz: Faz 5'te LCV
formu `POST /api/public/invitations/{id}/rsvps` çağıracak ve kimliği
gövdeden okumak, URL'i yeniden ayrıştırmaktan daha az kırılgan. Bir kaynağın
kendi kimliğini taşıması REST'in olağan davranışıdır — C5'in yasakladığı
"kimsenin istemediği alan" kategorisine girmiyor.

---

## 3. 🔴 Kapalı modülün verisi gönderilmez

Fazın güvenlik kararı bu.

```php
if ($this->show_gift) {
    $design += [
        'bankName' => ..., 'accountHolder' => ..., 'iban' => ..., 'giftOptions' => ...,
    ];
}
```

`show_gift` kapalıysa bu dört anahtar JSON'da **hiç yoktur**.

### Tehdit modeli: bu neden gerçek bir sorun?

Somut senaryo: kullanıcı davetiyeyi hazırlarken hediye modülünü açıyor,
IBAN'ını giriyor, sonra fikrini değiştirip modülü kapatıyor ve linki WhatsApp
grubuna atıyor.

Modül kapalı olduğu için ekranda hiçbir şey görünmez. Ama veri gövdedeyse:

```
Sağ tık → İncele → Network → invitations/01k3... → Response
{ "iban": "TR33 0006 1005 1978 6457 8413 26", ... }
```

**Ekranda görünmemek ile gönderilmemek aynı şey değildir.** Frontend'de bir
şeyi gizlemek bir *sunum* kararıdır; onu göndermemek bir *güvenlik* kararıdır.
İkincisi olmadan birincisi bir perde, duvar değil.

IBAN burada en görünür örnek çünkü finansal veri. Ama aynı kural diğer
modüllere de uygulandı ve gerekçe her birinde aynı: **kullanıcı o modülü
kapatarak "bu misafire gitmesin" demiştir.**

| Modül kapalıysa | Gönderilmeyen |
|---|---|
| `showTimeline` | `timelineEvents` — sürpriz bir program gövdeden okunmasın |
| `showGallery` | `galleryImages` — Faz 6'da gerçek URL'ler olacak |
| `showGift` | `bankName`, `accountHolder`, `iban`, `giftOptions` |
| `showRSVP` | `rsvpDeadline`, `askMenuPreference` |

### Maskeleme değil, **atma**

`'iban' => ''` yazmak da ekranda aynı sonucu verirdi. Yazmadık, çünkü:

| | `'iban' => ''` | Anahtar hiç yok |
|---|---|---|
| Sözleşmedeki yeri | **Durur** — frontend ona bağlanabilir | Yok |
| Bir gün biri "neden hep boş?" derse | Doldurmaya kalkar | Sorunun kendisi doğmaz |
| Yeni bir gizli alan eklenirse | "Boş değeri" ne? (`false`? `0`? `null`?) | Kural aynı kalır |
| Niyet | Gizlenmiş | **Görünür biçimde alınmamış** |

> **Kalıp:** Bir veriyi göndermemenin en güvenli yolu, onu **hiç
> serileştirmemektir**. Boş bir değer hâlâ bir alandır; alan varsa bir gün
> dolar.

### Frontend kırılmaz — bu bir varsayım değil, okunmuş bir gerçek

`davetkart-frontent/src/components/templates/shared/InvitationComposition.tsx`:

```tsx
{invitation.showTimeline && <Timeline invitation={invitation} ... />}
{invitation.showGallery  && <Gallery  invitation={invitation} ... />}
{invitation.showGift     && <GiftRegistry invitation={invitation} ... />}
{invitation.showRSVP     && <RSVPForm invitation={invitation} ... />}
```

Kapalı modülün bileşeni **hiç mount olmuyor**. `GiftRegistry.tsx:112`
`invitation.giftOptions.length` yazıyor ve korumasız — ama o satır yalnızca
`showGift` açıkken çalışıyor. Yani eksik anahtar çalışma anında hiçbir yerde
okunmuyor.

Geriye tek iş kalıyor: `types.ts`'te bu alanların **isteğe bağlı** olması
(`iban?: string`). Bu 4.8'in işi. TypeScript'in tipi ile gerçekte gelen gövde
ayrışırsa tip bir yalana dönüşür — Faz 3'ün 32. dersi: *sözleşme değişince
adı da değiştir.*

---

## 4. Bayraklar neden **her zaman** gidiyor?

```php
'showGift' => $this->show_gift,     // false olsa bile gider
```

Kapalı modülün *verisini* göndermiyoruz ama *bayrağını* gönderiyoruz. Çelişki
gibi görünür, değil:

- **Veri** = kullanıcının yazdığı içerik → gizlenecek olan bu
- **Bayrak** = şablonun hangi bölümü çizeceğine karar vermesi için gereken
  talimat

Bayrağı göndermezsek `InvitationComposition` `undefined && <Gift/>` yapar —
sonuç yine çizilmez, ama bu **tesadüfen** doğrudur. Sözleşmede `showGift`
zorunlu bir `boolean`; onu göndermek şablonun kararını **açık** kılar.

Ayrıca bayrağın kendisi bir sır değil: misafir zaten ekranda hediye bölümü
olmadığını görüyor.

---

## 5. `mergeWhen()` neden kullanılmadı?

Laravel'in bu iş için hazır bir yardımcısı var:

```php
$this->mergeWhen($this->show_gift, [
    'iban' => $this->iban ?? '',
]),
```

Çalışırdı. Kaynağa baktığımızda iç içe dizilerde de işlediği görülüyor —
`ConditionallyLoadsAttributes::filter()` (satır 30-34) `is_array($value)` olan
her değere **kendini yeniden çağırıyor**, yani `invitation` altındaki
`MergeValue` da çözülüyor.

Kullanmama sebebimiz tip: `mergeWhen()` bir `MergeValue` nesnesi döndürür ve bu
nesnenin diziye **sayısal anahtarla** konması gerekir. O zaman metodun dönüş
tipi artık `array<string, mixed>` olamaz, `array<array-key, mixed>` olur —
PHPStan'a "bu dizinin anahtarları metin değil" demiş oluruz, oysa **sonuçta**
öyle. Bildirilen tipi zayıflatmak, kazandığı okunabilirlikten pahalı.

`if` bloğu ayrıca bir şey daha yapıyor: kararı **gözle görülebilir** kılıyor.
Bu dosyayı güvenlik gözüyle okuyan biri dört `if`'i sayabilir.

> Bu, **R6**'nın ("framework'ün hazır çözümü varsa elle yazma") istisnası
> değil. R6, elle yazılan şeyin framework'ün ürettiği bir değerle **sessizce
> ayrışabildiği** durumlar için: rota deseni ULID üretecinden ayrışmıştı.
> Burada `if ($this->show_gift)` hiçbir şeyle ayrışamaz.

---

## 6. ⚠️ Açık soru: `date` ve saat dilimi

```php
'date' => $this->event_at?->format('Y-m-d\TH:i') ?? '',   // '2026-08-21T19:00'
```

Bu değer **saat dilimi taşımıyor** — buna *duvar saati* (wall clock) denir.
Sahibin sürümünde de böyleydi, çünkü orada `<input type="datetime-local">`
besliyordu ve o girdi saat dilimli bir değeri **reddeder**.

Misafir tarafında ise iki iş birden yapılıyor:

| İş | Duvar saati doğru mu? |
|---|---|
| Ekranda "21 Ağustos 19:00" yazmak | ✅ Herkeste doğru |
| Geri sayım sayacı (`showTimer`) | ❌ **Bakan kişinin saat dilimine göre kayar** |

`new Date('2026-08-21T19:00')` JavaScript'te **yerel saat** olarak
yorumlanır. Berlin'deki bir davetli için sayaç, İstanbul'daki düğüne 1 saat
geç sayar.

Tam ISO-8601 (`2026-08-21T19:00:00+03:00`) göndermek sayacı düzeltir ama
görüntüyü bozar: `toLocaleString()` Berlin'de "18:00" yazar — bir düğün daveti
için yanlış.

Yani doğru çözüm ikisinden biri değil: davetiyenin **kendi saat diliminin**
saklanması ve iki alanın birlikte gönderilmesi gerekir. Bu bir migration
demektir (`invitations.timezone`).

🔴 **Bu dosya bilinçli olarak Faz 3'le aynı davranışı sürdürüyor** — yani
sorunu ne çözüyor ne de büyütüyor. Karar Faz 4 içinde ayrıca alınacak;
`FAZ-3.md` §9.3 bu soruyu zaten Faz 4'e devretmişti.

---

## 7. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Sahibin Resource'unu misafire vermek | IBAN, taslak program, satır sayaçları sızar | Ayrı Resource (C4) |
| 2 | `'iban' => ''` ile maskelemek | Alan sözleşmede kalır, bir gün dolar | Anahtarı hiç koyma (§3) |
| 3 | Bayrağı da göndermemek | Şablon `undefined` üzerinden karar verir — tesadüfen doğru | Bayrak her zaman gider (§4) |
| 4 | `status` göndermek | Her zaman `published`; sıfır bilgi, fazladan alan | Gönderme (C5) |
| 5 | `if` yerine frontend'de gizlemek | Gövde hâlâ ağdan geçer; DevTools açan görür | Sunucuda at |
| 6 | `timelineEvents`'i `whenLoaded()` ile sarmak | İlişki yüklü değilse anahtar sessizce düşer — açık modül boş görünür | Action `with()` ile yüklüyor (Faz 3 sapması) |
| 7 | Bu Resource'u yayınlanmamış bir davetiyeyle kullanmak | Taslak sızar | Kaynağı **yalnızca** `ResolvePublicInvitationAction` verir (4.1) |

> 7. madde önemli: bu sınıf "yayınlanmış mı?" diye **sormaz**. O soru 4.1'de,
> sorgunun kapsamında cevaplanıyor. Her katman kendi işini yapar — Resource'a
> da bir durum kontrolü koymak, iki doğruluk kaynağı üretirdi.

---

## 8. Kendin dene

```powershell
php artisan tinker
```

```php
use App\Http\Resources\PublicInvitationResource;
use App\Models\Invitation;

// 1) Hediye modülü KAPALI, IBAN dolu bir davetiye
$inv = Invitation::factory()->withTimeline(2)->create([
    'show_gift' => false,
    'iban' => 'TR330006100519786457841326',
    'bank_name' => 'Ziraat',
]);

$body = (new PublicInvitationResource($inv->load('timelineEvents')))->toArray(request());
$design = $body['invitation'];

array_key_exists('iban', $design);        // ⇒ false   🔴 asıl sınav
array_key_exists('bankName', $design);    // ⇒ false
$design['showGift'];                      // ⇒ false   ← bayrak yine de gitti

// 2) Program modülü AÇIK — adımlar geliyor, ama id'siz (4.2a)
array_key_exists('timelineEvents', $design);   // ⇒ true

// 3) Modülü aç, IBAN artık gitmeli
$inv->update(['show_gift' => true]);
$acik = (new PublicInvitationResource($inv->fresh()->load('timelineEvents')))->toArray(request());
$acik['invitation']['iban'];              // ⇒ 'TR330006100519786457841326'

// 4) Kapalı program modülü
$inv->update(['show_timeline' => false]);
$k = (new PublicInvitationResource($inv->fresh()->load('timelineEvents')))->toArray(request());
array_key_exists('timelineEvents', $k['invitation']);   // ⇒ false

// 5) Sahibin sürümüyle karşılaştır — o HER ZAMAN her alanı verir
(new \App\Http\Resources\InvitationResource($inv->fresh()->load('timelineEvents')))
    ->toArray(request())['invitation']['iban'];         // ⇒ IBAN duruyor ✅

// Temizlik
Invitation::withTrashed()->forceDelete();
```

Adım 5, C4'ün neden var olduğunun kanıtı: **aynı satır, iki okuyucu, iki
sonuç.** Tek Resource'a maskeleme koysaydık sahip kendi IBAN'ını göremezdi.

```powershell
composer check
```

---

## 9. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Duvar saati (wall clock)** | Saat dilimi taşımayan zaman değeri: "19:00" |
| **ISO-8601** | Saat dilimli standart zaman biçimi: `2026-08-21T19:00:00+03:00` |
| **Serileştirme** | Nesneyi aktarılabilir bir biçime (JSON) çevirme |
| **`MergeValue`** | Laravel'in koşullu alan birleştirme için kullandığı ara nesne |
| **Zarf (envelope)** | Yanıtı saran dış anahtar — bizde `{data: ...}` |
| **Tehdit modeli** | "Kim, neyi, nasıl elde etmeye çalışır" sorusunun cevabı |

---

## 10. Sırada ne var?

**4.3 — `PublicInvitationController`.** Elimizde artık iki parça var: *"hangi
davetiye açık?"* (4.1) ve *"hangi alanlar görünür?"* (4.2). Controller ikisini
birleştirecek ve fazın üçüncü sorusunu ekleyecek: **"veritabanına kaç kere
gidilecek?"**

Cache oraya girecek — ve cache'lenen şey Eloquent modeli değil, bu sınıfın
ürettiği **dizi** olacak. Sebebi 4.1'de konuşmuştuk: cache'te serileştirilmiş
model tutmak, model şeması değişince bayat bir nesneyi canlandırır. Dizi
tutmak ayrıca ETag'i (4.5) doğrudan aynı veriden hesaplanabilir kılar.

---

## 🆕 Faz 7 eklemesi — `timezone` (K63)

```php
'timezone' => $this->timezone ?? Config::string('davetkart.default_timezone'),
```

### 1. Faz 4'ün açık sorusu kapandı

Bu kılavuzun §6'sı `date` alanı için şöyle diyordu: *"duvar saati, saat dilimi
TAŞIMAZ. Açık soru olarak duruyor."* Artık taşıyor — ayrı bir alanda.

### 2. 🔴 Neden **her zaman** dolu? (C7)

`InvitationPayloadResource` (sahibin sürümü) `null` gelince `''` gönderiyor;
burada **config varsayılanı** gönderiliyor. İki farklı okuyucu, iki farklı
karar (**C4**):

| Okuyucu | `null` gelince | Neden |
|---|---|---|
| Sahip (editör) | `''` | "Seçilmemiş" bir durumdur; editör tarayıcının dilimini önerebilir |
| Misafir (sayaç) | config varsayılanı | 🔴 Sayaç boş dizeyle **hesaplayamaz** |

**C7** (Faz 5): *"sözleşmede zorunlu alan her zaman gider."* Boş gönderseydik
frontend cihazın dilimini varsayardı — yani **sorun geri gelirdi**.

> Misafire "bilmiyorum" demek, ona sessizce **yanlış saati** göstermekten
> kötüdür. Varsayılan bir tahmindir ama **açıklanabilir** bir tahmindir.

### 3. Neden `show_*` bayrağına bağlı değil?

`timezone` bir **modül verisi değil**, tarihin okunma biçimidir. `date` alanı
her zaman gidiyorsa onu yorumlayan alan da her zaman gitmeli — **C6** (kapalı
modülün verisi gövdeye hiç girmez) burada geçerli değil, çünkü kapalı bir
modüle ait değil.

### 4. Frontend'e düşen

`types.ts` → `Invitation`'a `timezone: string` eklenmeli ve geri sayım bu
dilimde hesaplanmalı. Ayrıntı: `FAZ-7.md` §8.
