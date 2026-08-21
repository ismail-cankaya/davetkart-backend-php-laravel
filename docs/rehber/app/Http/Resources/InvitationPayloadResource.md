# `app/Http/Resources/InvitationPayloadResource.php`

> **Kod dosyası:** `app/Http/Resources/InvitationPayloadResource.php`
> **Faz:** 3 — Invitation dilimi, dosya 3.9 (2/3)
> **Önce oku:** [`InvitationResource.md`](InvitationResource.md)

---

## 1. `types.ts → Invitation` ile birebir

Bu sınıf tek bir işi yapıyor: veritabanı satırını, frontend'in `Invitation`
arayüzünün beklediği 24 alana çevirmek.

Sözleşmenin kaynağı `davetkart-frontent/src/types.ts`. Oradaki her alan burada
**bilerek** karşılanmış olmalı; olmayan alan frontend'de `undefined` olur ve
`loadInvitation()` onu varsayılanla doldurur — yani sessizce yanlış veri gösterir.

---

## 2. 🔴 C1 — Resource bir beyaz listedir

Faz 2'de kurulan kural: *Resource bir beyaz listedir. API'ye alan eklemek kolay,
çıkarmak imkânsıza yakındır.*

Burada adı geçmeyen hiçbir kolon dışarı çıkmaz. Dışarıda kalanlar:

| Kolon | Neden çıkmıyor |
|---|---|
| `user_id` | Sahiplik dahili bilgi; istemcinin işine yaramaz |
| `published_at` | Sözleşmede yok; `status` yeterli |
| `deleted_at` | Soft delete iç mekanizma |
| `created_at` | Sözleşmede yok |

`$invitation->toArray()` yazsaydık bunların **hepsi** yanıta girerdi. Ve bir kez
girdikten sonra çıkarmak "kırıcı değişiklik" olurdu — bir istemci ona bağlanmış
olabilir.

> Alan eklemek geri alınabilir bir karardır; alan çıkarmak değildir. Bu yüzden
> varsayılan **kapalı**dır.

---

## 3. İki tarih alanı, iki farklı biçim

```php
'date' => $this->event_at?->format('Y-m-d\TH:i') ?? '',
'rsvpDeadline' => $this->rsvp_deadline?->format('Y-m-d') ?? '',
```

`InvitationResource`'ta `updatedAt` için ISO-8601 kullanmıştık. Burada
kullanmıyoruz — ve sebebi **HTML'in kendisi**.

Frontend bu iki alanı doğrudan bir input'a bağlıyor:

```html
<input type="datetime-local" value="2026-09-12T19:00">   ← 'date'
<input type="date"           value="2026-09-30">          ← 'rsvpDeadline'
```

Bu input türleri **saat dilimi kabul etmez.** ISO-8601 gönderirsek:

```
"2026-09-12T19:00:00+03:00"   →  input degeri REDDEDER, alan bos gorunur
```

Kullanıcı tarihini girer, kaydeder, sayfayı yeniler — alan boştur. Sonraki
autosave `null` yazar ve **tarih gerçekten silinir.** Sessiz veri kaybı.

| Alan | Okuyucu | Biçim |
|---|---|---|
| `updatedAt` | JS `Date` nesnesi | ISO-8601 (`toIso8601String()`) |
| `date` | `<input type="datetime-local">` | `Y-m-d\TH:i` |
| `rsvpDeadline` | `<input type="date">` | `Y-m-d` |

> **İlke:** Biçim kararı, veriyi **kimin okuduğuna** bakarak verilir. "Standart
> olan" biçim, okuyucunun kabul etmediği biçimse yanlış biçimdir.

`\T` içindeki ters bölü, PHP'nin `format()` fonksiyonunda "bu harfi kod olarak
değil, harf olarak yaz" demektir — `T` tek başına saat dilimi kısaltmasını
üretirdi.

---

## 4. `null` → `''` neden?

```php
'title' => $this->title ?? '',
'giftOptions' => $this->gift_options ?? [],
```

Kolonlar `nullable` (3.2), ama `types.ts` bunları **zorunlu** tanımlıyor:

```ts
title: string;            // string | null DEGIL
giftOptions: number[];    // dizi, null degil
```

`null` gönderseydik frontend'de `value={invitation.title}` ifadesi React'te
kontrolsüz input uyarısı üretirdi ve `giftOptions.map()` çağrısı **çökerdi**.

Yani `??` burada bir kolaylık değil, **sözleşme uyumu**. Veritabanı "değer yok"
diyebilir; sözleşme diyemiyor.

Ters yönü de tutarlı: kullanıcı alanı temizleyince frontend `''` gönderir,
`ConvertEmptyStringsToNull` onu `null`'a çevirir, kolona `null` yazılır. Gidiş
ve dönüş birbirinin tersi.

---

## 5. 🔴 `whenLoaded` kullanmadım — planda yazıyordu, sapıyorum

Yol haritası bu dosya için *"`whenLoaded()` ile N+1 önleme"* diyor. Uygulamadım
ve gerekçemi söyleyip onayını istiyorum (kural 5).

```php
'timelineEvents' => TimelineEventResource::collection($this->timelineEvents),
```

### `whenLoaded` ne yapar?

İlişki eager-load edilmişse alanı ekler, **edilmemişse anahtarı tamamen atar.**

```php
'timelineEvents' => TimelineEventResource::collection($this->whenLoaded('timelineEvents')),
```

Controller'da `with('timelineEvents')` yazmayı unutursan yanıt şöyle olur:

```json
{ "invitation": { "title": "Dugunumuz" } }      ← timelineEvents anahtari YOK
```

### Neden bu bizim için kötü?

Frontend'in `loadInvitation()` metodu eksik alanları **varsayılanla doldurur**:

```ts
invitation: { ...INITIAL_INVITATION, ...invitation }
```

`timelineEvents` eksik gelince `DEFAULT_TIMELINE_EVENTS` devreye girer — yani
kullanıcı, **hiç yazmadığı** bir programı ("Karşılama & Kokteyl", "Nikâh
Töreni"…) ekranında görür. Sonraki autosave onu veritabanına yazar.

Sonuç: unutulan bir `with()` çağrısı → kullanıcının programı sessizce **başka
bir programla değiştirilir.**

### Doğrudan erişimde ne olur?

`$this->timelineEvents` ilişki yüklenmemişse Eloquent onu tembel yüklemeye
çalışır. Faz 0'da açtığımız `Model::shouldBeStrict()` bunu exception'a çevirir:

```
LazyLoadingViolationException: Attempted to lazy load [timelineEvents] on model [Invitation]
```

Yani hata **yerelde, ilk çalıştırmada, gürültüyle** çıkar.

### Karşılaştırma

| | `whenLoaded` | Doğrudan erişim |
|---|---|---|
| `with()` unutulursa | Anahtar düşer → **sessiz yanlış veri** | Yerelde exception → **gürültülü hata** |
| Üretimde `with()` unutulursa | Sessiz yanlış veri | N+1 sorgu (yavaş ama **doğru**) |
| Sözleşmeye uygunluk | Anahtar zorunluyken opsiyonel davranır | Anahtar her zaman var |

### `whenLoaded` ne zaman doğru?

**Opsiyonel** ilişkiler için — istemcinin `?include=comments` diyerek istediği,
olmayabilir de olan veriler. Bizim `timelineEvents` alanımız sözleşmede
**zorunlu**; opsiyonel bir mekanizmayla ifade etmek yanlış eşleşme olurdu.

> N+1 problemi bir **performans** sorunudur. Yanlış veri göstermek bir
> **doğruluk** sorunudur. İkisi arasında seçim yapmak zorundaysan doğruluğu
> seçersin — ve performansı `with()` ile ayrıca garanti edersin (3.11).

İtirazın varsa `whenLoaded`'a döneriz; o durumda 3.12'ye "with() olmadan
çağrıldığında anahtar düşmüyor" testi eklemek şart olur.

---

## 6. `phoneBackground` burada **türetiliyor** (K41)

```php
'phoneBackground' => $this->preset_id,
'imageTheme' => $this->preset_id,
```

3.2'de `phone_background` kolonu **açmamıştık**. Sebep: frontend'de bu alan
hiçbir yerde okunmuyor ve `selectTemplate()` her zaman `imageTheme` ile aynı
değeri yazıyor — türetilebilen veri saklanmaz (E1).

Ama sözleşmede alan **var**, çünkü `types.ts` onu zorunlu tanımlıyor. Bu yüzden
saklamıyoruz, **üretiyoruz**.

İki doğruluk kaynağı doğmadı: tek kaynak `preset_id`, iki alan ondan besleniyor.

---

## 7. `galleryImages` neden sabit `[]`?

```php
'galleryImages' => [],
```

Galeri Faz 6'nın konusu — `media` tablosu henüz yok. Sözleşme alanı zorunlu
olduğu için boş dizi dönüyoruz.

Bu bir **yalan değil**: "bu davetiyenin galeri görseli yok" doğru bir ifade,
çünkü gerçekten yok. Yalan olurdu eğer dokümanda "galeri çalışıyor" deseydik
(B4). Kod yorumunda ve burada, Faz 6'da dolacağı açıkça yazılı.

---

## 8. 🔴 Hediye verisi neden burada maskelenmiyor?

3.2'nin kılavuzunda şöyle bir not düşmüştüm:

> *`iban`, `bank_name` ve `account_holder` misafire açık yanıta yalnızca
> `show_gift = true` ise girmeli.*

Doğru ama **yeri burası değil** — ve bu ayrımı netleştirmek önemli.

Bu Resource **sahibin** gördüğü biçimdir. Sahibi kendi IBAN'ını her zaman
görmelidir. Maskeleseydik:

```
1. Kullanici IBAN'ini girer, hediye modulunu ACIK birakir
2. Fikrini degistirir, modulu KAPATIR      → autosave
3. Sayfayi yeniler                          → IBAN alani BOS gelir
4. Bir sey duzenler                         → autosave IBAN'i null yazar
5. Modulu tekrar acar                       → IBAN GITMIS
```

Kullanıcı hiçbir şey silmedi; savunma yanlış katmana konduğu için verisi
kayboldu.

Maskeleme **Faz 4'te**, misafire açık `PublicInvitationResource` içinde
yapılacak. Aynı model, iki farklı okuyucu, iki farklı Resource:

| Okuyucu | Sınıf | `show_gift = false` iken `iban` |
|---|---|---|
| Sahip (`GET /api/invitations/{id}`) | `InvitationPayloadResource` | ✅ döner |
| Misafir (`GET /api/public/...`) | `PublicInvitationResource` (Faz 4) | ❌ dönmez |

> **İlke:** "Bu veri gizli mi?" sorusunun cevabı veriye değil, **kime
> gösterildiğine** bağlıdır. Gizlilik kararı okuyucuyu bilen katmanda verilir.

---

## 9. `boolean` alanlar cast'e neden güveniyoruz?

```php
'showGift' => $this->show_gift,
```

Burada `(bool)` cast'i yok, çünkü 3.4'te modelde tanımlanmıştı:

```php
'show_gift' => 'boolean',
```

Tip belirsizliği **sınırda**, modelde çözüldü. Burada tekrar çözmek E3'ün
ihlali olurdu: *yolun üzerindeki dönüşüm çağrı yerlerinde tekrarlanmaz.*

Model cast'ini kaldıran biri olursa sonuç JSON'da `"showGift": 1` olur ve
frontend'in `=== true` karşılaştırması sessizce yanılır — bu yüzden 3.12'de tip
kontrolü olan bir test yazacağız.

---

## 10. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | `toIso8601String()` ile `date` döndürmek | `<input>` reddeder → **tarih silinir** | `format('Y-m-d\TH:i')` |
| 2 | `format('Y-m-d\TH:i')` yerine `'Y-m-dTH:i'` | `T` saat dilimine dönüşür | Ters bölü ile kaçır |
| 3 | `null` döndürmek | React kontrolsüz input uyarısı, `map()` çöker | `?? ''` / `?? []` |
| 4 | `whenLoaded` kullanmak | `with()` unutulunca sessiz yanlış program | Doğrudan erişim |
| 5 | Hediye alanlarını burada maskelemek | Sahibin IBAN'ı sessizce silinir | Faz 4'te maskele |
| 6 | `phone_background` kolonu açmak | İki doğruluk kaynağı (E1) | `preset_id`'den türet |
| 7 | `$invitation->toArray()` | `user_id`, `deleted_at` sızar | Beyaz liste (C1) |
| 8 | `(bool)` cast'ini burada tekrarlamak | İki yerde dönüşüm (E3) | Modele güven |

---

## 11. Kendin dene

```php
use App\Models\Invitation;
use App\Http\Resources\InvitationResource;

$inv = Invitation::factory()->withTimeline(2)->create([
    'title' => 'Dugunumuz',
    'event_at' => '2026-09-12 19:00',
    'iban' => 'TR330006100519786457841326',
    'show_gift' => false,
]);

// 🔴 Iliski yuklu DEGIL — exception beklenir
$inv->refresh();
(new InvitationResource($inv))->toArray(request());
// => LazyLoadingViolationException   ✅ gurultulu hata, sessiz yanlis veri degil

// Dogru kullanim
$inv = Invitation::query()->with('timelineEvents')->find($inv->id);
$out = (new InvitationResource($inv))->toArray(request());

$out['id'];                               // => "01K3..."  (metin)
$out['status'];                           // => "saved"    (enum degil)
$out['invitation']['date'];               // => "2026-09-12T19:00"   ← saat dilimi YOK
$out['updatedAt'];                        // => "2026-08-19T...+03:00" ← saat dilimi VAR
$out['invitation']['phoneBackground'];    // => preset_id ile ayni    (K41)
$out['invitation']['galleryImages'];      // => []
$out['invitation']['iban'];               // => "TR33..."  ✅ modul kapali ama SAHIBE doner

// Beyaz liste kaniti
array_key_exists('user_id', $out['invitation']);       // => false
array_key_exists('publishedAt', $out['invitation']);   // => false

Invitation::query()->forceDelete();
```

```powershell
composer check
```

---

## 12. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Beyaz liste** | İzin verilenleri sayma; sayılmayan çıkmaz |
| **Türetilen alan** | Saklanmayıp başka alandan hesaplanan değer |
| **Eager loading** | İlişkiyi önceden, tek sorguda yükleme (`with()`) |
| **Lazy loading** | İlişkiyi ilk erişimde yükleme — bizde exception |
| **Maskeleme** | Bir alanı koşullu olarak yanıttan çıkarma |
| **Kırıcı değişiklik** | İstemciyi bozan sözleşme değişikliği |

---

## 13. Sırada ne var?

[`TimelineEventResource.md`](TimelineEventResource.md) — ailenin son üyesi ve
`sort_order`'ın neden yanıta **girmediği**.
