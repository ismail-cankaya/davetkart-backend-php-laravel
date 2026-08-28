# `app/Http/Requests/Rsvp/StoreRsvpRequest.php`

> **Kod dosyası:** `app/Http/Requests/Rsvp/StoreRsvpRequest.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.4
> **Kardeş dosya:** [`../Invitation/InvitationRequest.md`](../Invitation/InvitationRequest.md)
> **Ön okuma:** [`../../../../kavramlar/istek-yasam-dongusu.md`](../../../../kavramlar/istek-yasam-dongusu.md)

---

## 1. FormRequest zincirin neresinde?

Faz 2'de çıkarılan istek zincirini hatırla — FormRequest **8. halkadır**:

```
... → [throttle] → Router → FormRequest → Controller → Action → Model → Resource
                                ↑
                        BU DOSYA BURADA
```

Yani Controller'a gelen veri **zaten doğrulanmıştır**. `CLAUDE.md` §1 bunu
kural hâline getiriyor: *Action'a gelen veri saf ve güvenilir kabul edilir.*

🔴 Ama "güvenilir" ne demek? **Sadece biçimsel olarak.** Bu dosya şunları
bilir:

- `guestCount` bir tam sayı mı? ✅
- `status` üç geçerli değerden biri mi? ✅
- `guestName` 120 karakteri aşıyor mu? ✅

Şunları **bilmez ve bilmemelidir**:

- LCV son tarihi geçmiş mi? ❌
- Davetiyenin LCV modülü açık mı? ❌
- Davetiye yayında mı? ❌
- Kota dolmuş mu? ❌

Bunların hepsi **iş kuralıdır** ve 5.7'deki `SubmitRsvpAction`'a aittir. Ayrım
keyfî değil: bir iş kuralı HTTP'siz test edilebilmeli, ve `422` yerine `403`
dönmeli (kota bir doğrulama hatası değil, bir kapasite reddidir — K28).

---

## 2. Gövde neden düz, `{ invitation: {...} }` gibi sarmalı yok?

`StoreInvitationRequest` gövdeyi `{ invitation: {...} }` içine sarıyordu ve bu
**yapısal bir güvenlik sınırıydı**: `status` diye bir alan tanımlı olmadığı için
`validated()` onu hiç göremiyordu.

Burada sarmal yok. Üç sebep:

1. **`status` zaten meşru bir girdi.** Misafirin cevabının kendisi o. Sarmalın
   koruduğu şey burada korunacak bir şey değil.
2. **Frontend düz gönderiyor** (`src/services/rsvps.ts` → `api.post('/rsvps', payload)`).
   Sarmal eklemek, hiçbir güvenlik kazancı olmayan bir sözleşme kırılması olurdu.
3. **Sınır başka yerde kuruluyor:** `COLUMN_MAP` beyaz listesi + `#[Fillable]`
   beyaz listesi. `invitation_id` gövdede gelse bile ikisinden de geçemez.

**Ders:** bir deseni "geçen sefer işe yaradı" diye taşımak, kuralı değil
sonucunu taşımaktır. Her seferinde *bu desen burada neyi koruyor?* diye sorulur.

---

## 3. Kurallar, tek tek

### `guestName` → `['required', 'string', 'min:2', 'max:120']`

`required` var, `sometimes` **yok**. Fark önemli:

| Kural | Anlamı |
|---|---|
| `sometimes` | "Alan istekte varsa doğrula" — kısmi güncelleme (autosave) için |
| `required` | "Alan olmak zorunda" — tek seferlik form gönderimi için |

Davetiye düzenleyicisi autosave yapıyordu, yarım veri normaldi. Misafir formu
**tek seferde** gönderilir; yarım LCV diye bir şey yok.

`min:2` bir kalite kuralı: tek karakterlik bir isim neredeyse her zaman ya bir
test ya bir bot girdisidir. **D3**'ü ihlal etmiyoruz — D3 *"kalite kuralı
yalnızca üretim anında uygulanır, okuma anında değil"* diyordu; bu bir üretim
anıdır.

### `guestCount` → üst sınır config'ten

```php
'max:'.Config::integer('davetkart.rsvp.max_guests_per_entry'),
```

Sınır koda gömülmedi. **E6**'nın ikinci yüzü: veritabanı kısıtı yalnızca
backend'in sahibi olduğu kurallara konur; iş tercihleri **config**'te yaşar ve
oradan doğrulamayı besler. Yarın Gold plan "20 kişi" derse tek satır değişir,
migration gerekmez.

`Config::integer()` (düz `config()` değil) bilerek: dönüş tipini garanti eder,
PHPStan level 8'de `mixed`'i `max:` ile birleştirmek hata verirdi.

### `status` → 🔴 `in:` kuralı, `Rule::enum` **değil**

Laravel'in doğal yolu şu olurdu:

```php
'status' => ['required', Rule::enum(RsvpStatus::class)],   // ❌ KULLANMIYORUZ
```

Neden kullanmıyoruz? **D6**, Faz 3'te kanla yazılmış bir kural:

> Doğrulama kuralının **adı** sözleşmenin parçasıdır; kural nesnesi değil
> **string kural** kullanılır.

Faz 3'te `Password::min(8)` yazılmıştı ve hata yanıtına şu düşüyordu:

```json
{ "rule": "illuminate_validation_rules_password" }
```

Yani **framework'ün iç sınıf adı** API sözleşmesine sızıyordu. Frontend'in
çeviri anahtarı Laravel'in namespace'ine bağlanmış oluyordu — Laravel sınıfı
taşısa çeviri kırılırdı. `Rule::enum` da aynı şeyi yapar
(`illuminate_validation_rules_enum`).

`in:` kuralı ise sabit bir ad (`"rule": "in"`) verir, üstelik geçerli değerleri
de parametre olarak taşır — frontend "geçerli seçenekler" listesini oradan bile
türetebilir.

Değerler `RsvpStatus::values()`'tan geliyor: liste elle yazılsaydı enum'a bir
durum eklendiğinde doğrulama **sessizce eskirdi**.

---

## 4. 🔴 Honeypot — sessizliğin savunma olduğu yer

```php
public const HONEYPOT_FIELD = 'website';
```

**Nasıl çalışır?** Frontend forma insana görünmeyen bir input koyar:

```html
<input type="text" name="website" tabindex="-1" autocomplete="off"
       aria-hidden="true" style="position:absolute; left:-9999px" />
```

- **İnsan** onu göremez → boş bırakır.
- **Bot** DOM'u okur, "her input'u doldur" der → doldurur.

Dolu gelen `website` alanı, gönderenin bot olduğunun iyi bir işaretidir.

### Neden bir doğrulama kuralı yok?

`'website' => ['prohibited']` yazabilirdik. **Yazmıyoruz**, çünkü o zaman bot
şunu alırdı:

```json
{ "error": { "code": "VALIDATION_FAILED", "fields": { "website": [...] } } }
```

Yani bota **"seni yakaladım"** demiş olurduk. Bot yazarı bir kere bunu görür,
alanı boş bırakmayı öğrenir ve savunma ölür.

Doğru davranış `docs/09` §Faz 5'te yazılı: *"doluysa bot → sessizce 200 dön,
kaydetme."* Bot başarılı olduğunu sanır, tekrar denemez, ve biz hiçbir şey
yazmayız.

### Neden kararı bu sınıf vermiyor?

`isHoneypotTripped()` bir **olgu** bildirir, bir **karar** vermez. Kararı
(sessizce başarılı görünmek) 5.7'deki Action verir. Sebep:

- FormRequest `200` döndüremez — sadece geçer ya da `422` fırlatır.
- **H10**: HTTP yanıtı üretmek Action'ın da işi değil, Controller'ın işi.
- Ve asıl önemlisi: "bot yakalanınca ne olur?" sorusunun cevabı bir **iş
  kararıdır** (belki yarın loglayacağız, belki IP'yi işaretleyeceğiz). İş
  kararları Action'da yaşar.

### Neden `input()`, `validated()` değil?

Alanın kuralı yok, dolayısıyla `validated()` içinde **hiç bulunmaz**. `input()`
ile okuyoruz.

**D2** (*"`prepareForValidation` içindeki veri güvenilmezdir"*) burada ihlal
edilmiyor: okuduğumuz şey bir **değer** değil, bir **varlık/yokluk sinyali**.
İçeriğine hiçbir yerde güvenmiyoruz — kaydetmiyoruz, loglamıyoruz, geri
döndürmüyoruz. `website[]=x` gibi dizi gönderilse bile karşılaştırma `!==` ile
yapıldığı için `TypeError` doğmaz.

> ⚠️ **Frontend borcu:** bu alan `RsvpModal.tsx` ve `RSVPForm.tsx`'e eklenmezse
> honeypot hiçbir şey yapmaz — ve hiçbir test bunu söylemez, çünkü backend
> testleri alanı kendileri gönderir. `FAZ-5-ELLE-DOGRULAMA.md`'de bunun için
> ayrı bir adım var.

---

## 5. `rsvpAttributes()` — ikinci beyaz liste

```php
foreach (self::COLUMN_MAP as $field => $column) {
    if (array_key_exists($field, $data)) {
        $attributes[$column] = $data[$field];
    }
}
```

**D4**: camelCase → snake_case eşlemesi bu katmanda yapılır; Action, HTTP alan
adlarını bilmez. Eşleme `Str::snake()` ile türetilmez — Faz 3'te `showRSVP`
alanının `show_r_s_v_p`'ye dönüşmesi bunun neden kötü bir fikir olduğunun
kanıtıydı.

`array_key_exists`, `isset` değil: `isset(null)` `false` döndürür, yani
kullanıcı `message` alanını **temizleyemezdi**.

Ve haritada olmayan alan sessizce düşer — `photoUrl`, `videoUrl` bugün buraya
girmiyor (medya Faz 6). Frontend onları gönderse bile hiçbir yere yazılmaz.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `Rule::enum()` kullanmak | Framework sınıf adı sözleşmeye sızar (D6) |
| 2 | Honeypot'a `prohibited` kuralı koymak | Bota yakalandığını söylersin; savunma bir kez kullanılıp ölür |
| 3 | Son tarih/kota kontrolünü buraya yazmak | `422` dönerdi; oysa bunlar `403`. Ayrıca iş kuralı HTTP'siz test edilemez olurdu |
| 4 | `$request->all()` ile Action'ı beslemek | D5 ihlali; beklenmeyen alanlar geçer |
| 5 | `guestCount` üst sınırını koda gömmek | Fiyat değişikliği kod değişikliği gerektirir |
| 6 | `status` değerlerini elle yazmak | Enum değişince doğrulama sessizce eskir |
| 7 | Honeypot alanının adını `honeypot` koymak | Bot adından anlar |
| 8 | `authorize()` içinde davetiye sahipliği sorgulamak | **M4**'ün akrabası: model yüklenmeden karar verilmez; ayrıca bu uç zaten herkese açık |

---

## 7. Kendin dene

Sunucu ayaktayken (`php artisan serve`) ve yayınlanmış bir davetiyen varken:

```powershell
$id = "<yayinlanmis-davetiye-ulid>"

# 1) Geçerli gönderim -> 201
curl.exe -s -X POST "http://localhost:8000/api/public/invitations/$id/rsvps" `
  -H "Content-Type: application/json" `
  -d '{\"guestName\":\"Can Dogan\",\"guestCount\":2,\"status\":\"attending\"}'

# 2) Geçersiz durum -> 422, rule adı "in"
curl.exe -s -X POST "http://localhost:8000/api/public/invitations/$id/rsvps" `
  -H "Content-Type: application/json" `
  -d '{\"guestName\":\"Can\",\"guestCount\":2,\"status\":\"Katiliyor\"}'
# {"error":{"code":"VALIDATION_FAILED","fields":{"status":[{"rule":"in",...}]}}}

# 3) Kişi sayısı sınırı -> 422, rule "max", params.max = 10
#    -d '{...,\"guestCount\":50,...}'

# 4) 🔴 Honeypot -> 201 gibi görünmeli AMA veritabanına yazılmamalı
curl.exe -s -X POST "http://localhost:8000/api/public/invitations/$id/rsvps" `
  -H "Content-Type: application/json" `
  -d '{\"guestName\":\"Bot\",\"guestCount\":1,\"status\":\"attending\",\"website\":\"http://spam\"}'
```

4. adımdan sonra **mutlaka** doğrula (T14: yanıtı değil **etkiyi** doğrula):

```powershell
php artisan tinker --execute="echo App\Models\Rsvp::where('guest_name','Bot')->count();"
# 0 yazmalı
```

`1` yazıyorsa honeypot çalışmıyor demektir — ve yanıt `201` olduğu için bunu
**başka hiçbir şey sana söylemez**.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **FormRequest** | Doğrulama ve yetkiyi controller'dan ayıran istek sınıfı |
| **Honeypot** | Yalnızca botların dolduracağı görünmez tuzak alan |
| **`required` / `sometimes`** | "Olmak zorunda" / "varsa doğrula" |
| **Kural nesnesi** | `Rule::enum()` gibi sınıf tabanlı doğrulama kuralı |
| **String kural** | `'in:a,b,c'` gibi metin tabanlı kural; adı sabittir |
| **Beyaz liste** | Varsayılan kapalı, yalnızca sayılanlar açık |
| **Biçimsel geçerlilik** | Verinin şekli doğru — anlamı doğru demek değil |

---

## 9. Sırada ne var?

**5.5 — `HasErrorCode` arayüzü ve iki yeni exception.** Faz 4 devir notunda
*"üçüncü exception gelince"* diye ertelenen iş: `ApiExceptionRenderer`'ın her
yeni exception için bir `match` kolu büyütmesini durduracağız.

| İlgili | Nerede |
|---|---|
| Durum enum'u | [`../../../Enums/RsvpStatus.md`](../../../Enums/RsvpStatus.md) |
| Kardeş request | [`../Invitation/InvitationRequest.md`](../Invitation/InvitationRequest.md) |
| Hata sözleşmesi | `docs/08-HATA-SOZLESMESI.md` |
