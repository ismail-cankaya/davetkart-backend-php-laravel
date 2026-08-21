# `app/Http/Requests/Invitation/InvitationRequest.php`

> **Kod dosyası:** `app/Http/Requests/Invitation/InvitationRequest.php` (abstract)
> **Faz:** 3 — Invitation dilimi, dosya 3.8 (1/3)
> **Alt sınıflar:** [`StoreInvitationRequest.md`](StoreInvitationRequest.md) ·
> [`UpdateInvitationRequest.md`](UpdateInvitationRequest.md)
> **Bağlantılı:** [`RegisterRequest.md`](../Auth/RegisterRequest.md) — FormRequest temelleri orada

---

## 1. Katmandaki yeri

```
Route → Middleware → [FormRequest] → Controller → Action → Model → Resource
                          ↑
                     bu dosya
```

FormRequest iki iş yapar:

1. **Doğrular** — geçersiz veri Action'a hiç ulaşmaz
2. **Çevirir** — camelCase istek alanlarını snake_case kolonlara eşler

İkincisi bu katmanın işidir çünkü **HTTP alan adlarını bilmek Action'ın işi
değildir** (D4). Action saf veri alır; yarın alan adı değişirse yalnızca burası
değişir.

---

## 2. 🔴 Gövde neden `{ invitation: {...} }` diye sarılı?

Frontend zaten böyle gönderiyor (`services/invitations.ts`), ama biz onu
kolaylık olsun diye korumuyoruz. Bu sarmalın **yapısal bir güvenlik anlamı** var.

Kayıt iki farklı veri sınıfı taşır:

| Sınıf | Örnek | Sahibi |
|---|---|---|
| **Sunucu üstverisi** | `id`, `status`, `updatedAt`, `publishedAt` | Backend |
| **Tasarım verisi** | `title`, `showGift`, `timelineEvents`… | Kullanıcı |

Düz bir gövde kullansaydık ikisi aynı düzlemde olurdu ve `status` göndermeye
çalışan bir istek "yanlışlıkla geçebilir mi?" sorusunu her katmanda yeniden
sormamız gerekirdi.

Sarmalla birlikte cevap yapısal: **`status` diye bir alan `invitation` içinde
tanımlı değil**, dolayısıyla `validated()` onu hiç görmez. `#[Fillable]` (3.4)
zaten ikinci savunma hattıydı; bu üçüncüsü değil, **birincisi**.

Yanıt tarafı da simetrik olacak (3.9):

```json
{ "data": { "id": "01K3...", "status": "saved", "updatedAt": "...",
            "invitation": { "title": "...", "showGift": false } } }
```

---

## 3. Kurallar neden camelCase yazılıyor? (D1)

```php
'invitation.categoryId' => ['required', 'string', 'max:32'],
```

`category_id` değil `categoryId`. Sebep: **doğrulama gelen veriye bakar.**
İstek `categoryId` gönderiyorsa kural da onu aramalıdır.

İkinci bir sonucu daha var — hata yanıtındaki alan adları da bu adları taşır:

```json
{ "error": { "code": "VALIDATION_FAILED",
             "fields": { "invitation.categoryId": [ { "rule": "required" } ] } } }
```

Frontend `categoryId` alanını biliyor, `category_id`'yi bilmiyor. Hata mesajını
doğru input'un altına yazabilmesi için anahtarın **gönderdiği adla** dönmesi
gerekir.

### Noktalı gösterim (`dot notation`)

`'invitation.timelineEvents.*.time'` üç katmanı ifade eder:

```
invitation          → nesne
  timelineEvents    → dizi
    *               → dizinin HER elemani
      time          → o elemanin alani
```

`*` joker karakterdir: kural dizideki tüm elemanlara ayrı ayrı uygulanır ve hata
anahtarları gerçek indeksle döner (`invitation.timelineEvents.2.time`) — yani
frontend hangi satırın hatalı olduğunu bilir.

---

## 4. 🔴 Neredeyse hiçbir alan `required` değil

```php
'invitation.title' => ['sometimes', 'nullable', 'string', 'max:120'],
```

Davetiyenin başlığı zorunlu olmalı gibi görünüyor. Değil — ve sebebi 3.2'de
kolonları `nullable` yapmamızla aynı: **autosave.**

Kullanıcı başlığı silip yenisini yazmak için duraklarsa, o boş hâl 1,5 saniye
sonra sunucuya gider. `required` olsaydı editör "kaydedilemedi" derdi.

| An | Beklenti |
|---|---|
| Kaydetme (autosave) | Hiçbir şey — yarım veri normaldir |
| Yayınlama (Faz 7) | Eksiksizlik aranır |

Bu, Faz 2'nin **D3** kuralının biçimidir: *kalite kuralı yalnızca üretim anında
uygulanır.*

### `sometimes` ile `nullable` farkı

Sık karıştırılır:

| Kural | Anlamı |
|---|---|
| `sometimes` | Alan **istekte yoksa** kalan kuralları hiç uygulama |
| `nullable` | Alan var ama değeri `null` ise kalan kuralları uygulama |

İkisi birlikte üç durumu da kapsar:

```
alan hic yok        → sometimes devreye girer, sorun yok
alan var, null      → nullable devreye girer, sorun yok
alan var, dolu      → string, max:120 calisir
```

Boş string (`""`) hangi duruma girer? Laravel'in `ConvertEmptyStringsToNull`
global middleware'i onu `null`'a çevirir, yani ikinci duruma. Frontend
`mapUrl: ''` gönderiyor ve bu sayede sorunsuz geçiyor — Faz 2'nin **20. dersi**:
*savunma kodu yazmadan önce framework'ün ne yaptığını oku.*

---

## 5. Katalog anahtarlarında içerik doğrulaması yok

```php
'invitation.categoryId' => ['required', 'string', 'max:32'],
```

`Rule::in(['dugun', 'kina', 'nisan', ...])` yazmadık. 3.2 §4'teki sahiplik
ölçütünün doğrudan sonucu:

> `categoryId`, `imageTheme` ve `palette` **frontend kataloğunun anahtarlarıdır.**

Geçerli listeyi backend'e yazsaydık, tasarımcının eklediği her yeni tema bir
backend deploy'u gerektirirdi. Sunum katmanındaki değişikliğin backend'i
kilitlemesi yanlış bir bağımlılıktır.

Koruma yine de var: `max:32` bir sınırdır, ve kolon `VARCHAR(32)`. Geçersiz bir
tema anahtarı gelirse sonuç, frontend'in o temayı bulamayıp varsayılana
düşmesidir — veri bozulmaz, güvenlik açığı doğmaz.

⚠️ Faz 7'de bu değişebilir: paywall "hangi temanın hangi planı gerektirdiğini"
bilmek zorunda kalırsa, liste `config/davetkart.php`'ye taşınır — deploy değil,
config değişikliği olur.

---

## 6. K44'ün sözleşmesi: `timelineEvents.*.id`

```php
'invitation.timelineEvents.*.id' => ['nullable', 'string', 'max:64'],
```

Üç karar var burada.

**1. `string`, `integer` değil.** Veritabanında `bigint` ama API sözleşmesinde
tüm id'ler metindir (3.3 §6). Resource `(string) $this->id` döndürdüğü için
gidiş-dönüş her zaman metin olur.

**2. `nullable` — K44'ün kalbi.** `id: null` "bu yeni bir adım" demektir; backend
kimliği kendisi üretir.

**3. `exists` kuralı BİLEREK yok.** Yazabilirdik:

```php
Rule::exists('timeline_events', 'id')->where('invitation_id', $id)   // ❌
```

İki sebeple yazmadık:

- **Bayat id 422 üretmemeli.** Kullanıcı bir adımı iki sekmede birden silmişse,
  ikinci sekmenin autosave'i artık var olmayan bir id gönderir. Doğru davranış
  "hata" değil, "o satır zaten yok, yenisini oluştur"dur. `exists` bunu 422'ye
  çevirip **autosave'i kilitlerdi.**
- **Güvenlik zaten başka yerde.** 3.10 eşleştirmeyi `$invitation->timelineEvents()
  ->find($id)` ile yapacak; ilişki sorguya `WHERE invitation_id = ?` ekler.
  Eşleşmeyen id yeni satır olur — **hiçbir durumda başkasının satırına
  yazılmaz.**

> **İlke:** Doğrulama katmanı **biçimi** denetler, **aidiyeti** değil. Aidiyet
> veriyi okuyan sorgunun kendi sorumluluğudur; oraya taşınırsa unutulamaz.

Bunun yan faydası: frontend henüz `tl-1` gibi eski id'ler gönderiyorken de
autosave çalışmaya devam eder (K44 geçiş dönemi).

---

## 7. 🔴 `array_key_exists`, `isset` değil

```php
foreach (self::COLUMN_MAP as $field => $column) {
    if (array_key_exists($field, $data)) {
        $attributes[$column] = $data[$field];
    }
}
```

PHP'de bu ikisi **aynı şey değildir**:

```php
$data = ['title' => null];

isset($data['title']);              // false  ← deger null diye "yok" sayar
array_key_exists('title', $data);   // true   ← anahtar VAR
```

`isset` yazsaydık somut sonucu şu olurdu: **kullanıcı bir alanı temizleyemezdi.**
Başlığı silip kaydeder, istek `title: null` taşır, `isset` onu atlar, eski başlık
veritabanında kalır. Kullanıcı sayfayı yenileyince sildiği metin geri gelir.

Sessiz, açıklanması zor ve gerçek bir hata. `array_key_exists` "gönderildi mi?"
sorusunu doğru sorar.

### Neden döngü, neden doğrudan `$data` değil?

Eşleme aynı zamanda bir **beyaz listedir** (C1). `phoneBackground` ve
`galleryImages` haritada yok:

| Alan | Neden dışarıda |
|---|---|
| `phoneBackground` | K41 — `preset_id`'den türetilir, kolonu yok |
| `galleryImages` | Faz 6'nın konusu, `media` tablosuna gidecek |

İkisi de istekte gelmeye devam ediyor, hata üretmiyor, ama Action'a **hiç
ulaşmıyor.**

---

## 8. `showRSVP` — sihirli dönüşümün neden olmadığının kanıtı

`CLAUDE.md` §1: *"Dönüşümler sihirli fonksiyonlarla değil, açıkça yazılmalıdır."*

Bunun somut kanıtı bu alanda:

```php
Str::snake('showEnvelope');   // => 'show_envelope'   ✅
Str::snake('showRSVP');       // => 'show_r_s_v_p'    ❌ boyle bir kolon yok
```

Otomatik dönüşüm 20 alanın 19'unda çalışır, birinde **sessizce** yanlış kolon
adı üretir. Sonuç: modül kaydedilmez, hiçbir hata çıkmaz, kullanıcı "işaretledim
ama kaydolmuyor" der.

Açık harita 21 satır yer kaplıyor. Karşılığında dönüşüm **denetlenebilir** —
okuduğunda ne olduğunu görürsün, tahmin etmezsin.

---

## 9. `timelineEvents()` — `null` ile `[]` neden farklı?

```php
public function timelineEvents(): ?array
{
    if (! array_key_exists('timelineEvents', $data)) {
        return null;
    }
    return $data['timelineEvents'];
}
```

| Dönen | İstekte ne vardı | 3.10 ne yapacak |
|---|---|---|
| `null` | Alan hiç gönderilmemiş | **Dokunma** — program aynen kalsın |
| `[]` | Boş dizi gönderilmiş | **Hepsini sil** — kullanıcı tüm adımları kaldırdı |

İkisini birleştirseydik (örneğin ikisine de `[]` deseydik), kısmi bir güncelleme
kullanıcının programını sessizce silerdi.

Bu, `array_key_exists` kararının koleksiyon düzeyindeki karşılığıdır: **"yok" ile
"boş" farklı bilgilerdir.**

---

## 10. Neden abstract sınıf, neden kopyalama değil?

30 küsur kural iki istekte de aynı. Kopyalasaydık, yarın `max:120`'yi
`max:150` yapan biri iki dosyadan birini unuturdu — ve fark ancak birileri uzun
başlık yazınca ortaya çıkardı.

Faz 2'nin **C3** kuralı: *aynı sözleşmeyi üreten iki uç tek yerden üretir.*

Değişen tek şey, katalog anahtarlarının zorunluluğu:

| | Store | Update |
|---|---|---|
| `catalogPresence()` | `['required']` | `['sometimes', 'required']` |

Alt sınıflar **yalnızca** bu farkı taşıyor — dörder satır. Buna *Template
Method* deseni denir: iskelet üst sınıfta, değişen adım alt sınıfta.

---

## 11. `authorize()` neden `true`?

```php
public function authorize(): bool
{
    return true;
}
```

FormRequest yetki de kontrol edebilir. Etmiyoruz, çünkü yetki kararı 3.7'de
`InvitationPolicy`'ye verildi ve controller `authorizeResource` ile onu
çağıracak (3.11).

Buraya da yazsaydık kural **iki yerde** olurdu — Policy'yi yazma gerekçemizin
tam tersi. `true` demek "yetki kontrolü yok" değil, **"yetki kontrolü burada
değil"** demektir.

---

## 12. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | Kuralları snake_case yazmak | Doğrulama hiç çalışmaz, `fields` yanlış anahtar döner | camelCase (D1) |
| 2 | `isset` kullanmak | Kullanıcı alanı **temizleyemez** | `array_key_exists` |
| 3 | `Str::snake()` ile otomatik eşleme | `showRSVP` → `show_r_s_v_p` | Açık harita |
| 4 | İçerik alanlarına `required` | Autosave 422 alır | `sometimes` + `nullable` |
| 5 | `timelineEvents.*.id`'ye `exists` | Bayat id autosave'i kilitler | Aidiyet 3.10'da |
| 6 | `null` ile `[]`'i aynı saymak | Kısmi güncelleme programı siler | Ayır |
| 7 | Kuralları iki dosyaya kopyalamak | Biri güncellenir, diğeri unutulur | Ortak taban (C3) |
| 8 | `all()` kullanmak | Beklenmeyen alanlar geçer | `validated()` (D5) |
| 9 | Katalog anahtarlarına `Rule::in` | Her yeni tema backend deploy'u ister | Yalnızca uzunluk |

---

## 13. Kendin dene

Bu dosya HTTP olmadan denenemez; 3.11'de rotalar yazılınca tam turu göreceğiz.
Şimdilik kural mantığını `tinker`'da sınayabilirsin:

```php
use Illuminate\Support\Facades\Validator;

$kurallar = [
    'invitation.title' => ['sometimes', 'nullable', 'string', 'max:120'],
    'invitation.timelineEvents.*.time' => ['nullable', 'string', 'date_format:H:i'],
];

// Alan hic yok → gecer
Validator::make(['invitation' => []], $kurallar)->passes();                    // => true

// Alan var, null → gecer (kullanici temizledi)
Validator::make(['invitation' => ['title' => null]], $kurallar)->passes();     // => true

// Gecersiz saat → kalir
$v = Validator::make(
    ['invitation' => ['timelineEvents' => [['time' => '25:99']]]],
    $kurallar
);
$v->passes();            // => false
$v->failed();            // => ["invitation.timelineEvents.0.time" => ["DateFormat" => ["H:i"]]]
```

Son satır önemli: hata anahtarı **hangi satırın** hatalı olduğunu söylüyor
(`...0.time`). Frontend bu bilgiyle doğru input'u işaretleyebilir.

`isset` tuzağını da gör:

```php
$data = ['title' => null];
isset($data['title']);              // => false
array_key_exists('title', $data);   // => true
```

```powershell
composer check
```

---

## 14. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **FormRequest** | Doğrulama ve yetki kontrolünü taşıyan istek sınıfı |
| **`sometimes`** | Alan istekte yoksa kalan kuralları atla |
| **`nullable`** | Alan `null` ise kalan kuralları atla |
| **Noktalı gösterim** | İç içe yapıya `a.b.c` ile erişim |
| **Joker** (`*`) | Dizinin her elemanına kural uygulama |
| **Beyaz liste** | İzin verilenleri sayma; sayılmayan düşer |
| **Template Method** | İskeleti üst sınıfta, değişen adımı alt sınıfta tutan desen |
| **`validated()`** | Yalnızca kuralı olan alanları döndüren dizi |

---

## 15. Sırada ne var?

**3.9 — `InvitationResource` ailesi**

Yanıt tarafı. Orada:

- 🔴 **C1** sınavı: Resource bir beyaz listedir — `iban` kapalı modülde sızmamalı
- `phoneBackground` burada **türetilir** (K41)
- `id` alanları metne çevrilir
- `whenLoaded('timelineEvents')` ile N+1 önleme
- Üç sınıf: `InvitationResource` (üstveri) · `InvitationPayloadResource` (tasarım)
  · `TimelineEventResource`
