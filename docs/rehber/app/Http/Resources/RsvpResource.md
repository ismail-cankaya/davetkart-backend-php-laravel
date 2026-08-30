# `app/Http/Resources/RsvpResource.php`

> **Kod dosyası:** `app/Http/Resources/RsvpResource.php`
> **Faz:** 5 — RSVP/LCV dilimi, dosya 5.8
> **Kardeş dosyalar:** [`InvitationResource.md`](InvitationResource.md) ·
> [`PublicInvitationResource.md`](PublicInvitationResource.md)

---

## 1. Resource ne işe yarar?

Resource, veritabanı satırının **dış dünyaya gösterilen yüzüdür**. Üç iş yapar:

1. `snake_case` kolon adlarını `camelCase` sözleşmeye çevirir (`CLAUDE.md` §1).
2. Hangi alanların dışarı çıkacağına karar verir — **beyaz liste** (C1).
3. Tipleri sözleşmeye uydurur (enum → string, tarih → ISO 8601).

🔴 **C1'in neden bir beyaz liste olduğu:** API'ye alan eklemek kolaydır,
çıkarmak neredeyse imkânsızdır. Bir alan bir kez yayınlandıysa birileri ona
bağlanmıştır. Bu yüzden varsayılan "kapalı"dır: model ne taşırsa taşısın,
yalnızca bu dosyada adı geçen alanlar dışarı çıkar.

---

## 2. 🔴 `ip_hash` neden burada yok?

Model `ip_hash` kolonunu taşıyor. Resource onu **hiç** göstermiyor — sahibe
bile.

"Sahibi zaten kendi davetiyesi, görsün" demek yanlış olurdu:

- `ip_hash` bir **kişisel veriden türetilmiş izdir**. KVKK'nın *amaç
  sınırlaması* ilkesi: veriyi topladığın amaç dışında kullanamazsın. Onu tekrar
  gönderim tespiti için saklıyoruz, sahibe göstermek için değil.
- Gösterilseydi, sahip "aynı hash iki kez var" diyerek misafirleri
  eşleştirebilirdi — toplamadığımızı iddia ettiğimiz bir bilgiyi dolaylı olarak
  vermiş olurduk.

**Ders:** bir veriyi saklamamaya karar vermek yetmez; **türevini de
yaymayacaksın**. Faz 4'te `C6` aynı fikri modüller için söylemişti: *ekranda
görünmemek ile gönderilmemek farklı şeylerdir.*

`invitation_id` de yok — ama farklı sebeple: zaten URL'de (`/invitations/{id}/rsvps`).
Gövdede tekrarlamak bilgi eklemez, yalnızca sözleşmeyi büyütür (**C5**).

---

## 3. `whenNotNull` — `null` ile "yok" farkı

```php
'message' => $this->whenNotNull($this->message),
```

Frontend sözleşmesi (`types.ts`):

```ts
message?: string;      // string | undefined
```

TypeScript'te `?` **`undefined`** demektir, `null` değil. JSON'da `null`
gönderirsek `message: null` gelir ve `string | undefined` bekleyen kod tip
hatası verir (ya da daha kötüsü, `strictNullChecks` kapalıysa çalışma anında
patlar).

`whenNotNull()` değer `null` ise alanı **tamamen düşürür** — anahtar bile
gönderilmez. Bu, `C6`'nın küçük ölçekli hâli.

> ⚠️ Ters yönde bir tercih de var: `menuPreference` `null` ise `''` gönderiliyor,
> düşürülmüyor. Çünkü sözleşme onu **zorunlu** (`menuPreference: string`)
> tanımlıyor ve `LiveRsvpPanel` `rsvp.menuPreference || 'Belirtilmedi'` diyerek
> kendi varsayılanını koyuyor. Yani: **sözleşme neyi zorunlu diyorsa o her
> zaman gider; opsiyonel olan yoksa hiç gitmez.**

---

## 4. `status` neden `->value`?

```php
'status' => $this->status->value,
```

Model cast'i sayesinde `$this->status` bir `RsvpStatus` **enum örneğidir**.
JSON'a doğrudan konsaydı Laravel onu `"attending"` olarak serileştirirdi —
şu an çalışırdı ama sözleşmeyi **örtük** bir davranışa bağlardı.

`->value` yazmak niyeti açık eder: dışarı giden şey **koddur**, gösterim metni
değil (K21/K49). Aynı kalıp `InvitationResource`'ta `$this->status->value`
olarak zaten kullanılıyordu.

---

## 5. Tek Resource, iki okuyucu — C4 neden gerekmiyor?

**C4** diyordu ki: *aynı veri, farklı okuyucular için farklı Resource'a çıkar.*
Faz 4'te `InvitationResource` (sahip) ve `PublicInvitationResource` (misafir)
bu yüzden ayrılmıştı.

Burada ayırmıyoruz. Kural değişmedi — **girdi değişti**:

| | Faz 4 (davetiye) | Faz 5 (LCV) |
|---|---|---|
| Sahibin gördüğü | IBAN, taslak durumu, `updatedAt` | Ad, kişi sayısı, durum, mesaj |
| Misafirin gördüğü | Bunların bir kısmı **görmemeli** | **Kendi yazdığı veri** |
| Ayrım gerekli mi? | ✅ Evet | ❌ Hayır |

Misafir `POST` yanıtında kendi gönderdiği veriyi geri alıyor. Ondan
saklanabilecek bir alan yok.

🔴 Bir kuralı uygulamak, onu her yere kopyalamak değildir. **C4'ün sorusu "iki
okuyucu var mı?" değil, "birinin görmemesi gereken bir alan var mı?"**

> **Bu kararın ne zaman değişeceği:** Faz 6'da `photoUrl`/`videoUrl` gelecek.
> Eğer moderasyon eklenirse (sahip onaylamadan medya yayınlanmasın), o zaman
> misafir ile sahip farklı şeyler görmeye başlar ve C4 devreye girer.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `ip_hash`'i "sahip görsün" diye eklemek | KVKK amaç sınırlaması ihlali; misafir eşleştirme mümkün olur |
| 2 | `message` için `?? ''` kullanmak | `string \| undefined` sözleşmesi `""` ile karışır; "boş mesaj" ile "mesaj yok" ayrımı kaybolur |
| 3 | `menuPreference` için `whenNotNull` kullanmak | Sözleşme zorunlu diyor; frontend `undefined` alır ve `|| 'Belirtilmedi'` çalışmaz |
| 4 | `$this->status` (enum) doğrudan koymak | Çalışır ama sözleşme örtük davranışa bağlanır |
| 5 | `createdAt` yerine `created_at` göndermek | camelCase sözleşmesi kırılır (`CLAUDE.md` §1) |
| 6 | `$this->resource->toArray()` ile toplu döndürmek | Beyaz liste ortadan kalkar; yeni bir kolon eklendiği gün sessizce dışarı çıkar |
| 7 | Ham tarih (`$this->created_at`) göndermek | Biçim sürücüye/ortama göre değişir; ISO 8601 sözleşmedir |

---

## 7. Kendin dene

```php
// php artisan tinker
$rsvp = App\Models\Rsvp::first();
(new App\Http\Resources\RsvpResource($rsvp))->resolve(request());

// Beklenen anahtarlar:
// id, guestName, guestCount, menuPreference, status, createdAt
// (message yalnızca doluysa)

// 🔴 Sızıntı kontrolü — bu ikisi ASLA çıkmamalı:
$ciktisi = (new App\Http\Resources\RsvpResource($rsvp))->resolve(request());
array_key_exists('ip_hash', $ciktisi);        // false
array_key_exists('invitationId', $ciktisi);   // false
```

Bu kontrolün testteki karşılığı bir **sızıntı testidir** (Faz 1'de tanımlandı):
bir bilginin yanıta **girmediğini** doğrulayan test. `assertJsonMissingPath`
ile yazılır ve `RsvpTest`'te yer alacak.

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Resource** | Modelin API sözleşmesine çevrildiği katman |
| **Beyaz liste** | Varsayılan kapalı; yalnızca sayılanlar dışarı çıkar |
| **`MissingValue`** | Laravel'in "bu alanı hiç gönderme" işareti |
| **Amaç sınırlaması** | KVKK: veriyi topladığın amaç dışında kullanamama ilkesi |
| **ISO 8601** | `2026-08-28T12:00:00+00:00` biçimindeki standart tarih gösterimi |
| **Sızıntı testi** | Bir bilginin yanıta girmediğini doğrulayan test |

---

## 9. Sırada ne var?

**5.9 — `RsvpPolicy`.** Sahiplik: bir LCV yanıtını kim silebilir? Cevap
davetiyeden geliyor ve **P1** gereği kural kopyalanmayacak.

| İlgili | Nerede |
|---|---|
| Model | [`../../Models/Rsvp.md`](../../Models/Rsvp.md) |
| Kardeş Resource | [`PublicInvitationResource.md`](PublicInvitationResource.md) |

---

## 🆕 Faz 6 eklemesi — `photoUrl` / `videoUrl`

```php
'photoUrl' => $this->whenNotNull($this->photoMedia?->url()),
'videoUrl' => $this->whenNotNull($this->videoMedia?->url()),
```

### 🔴 Şemada kimlik, sözleşmede URL

| Katman | Ne tutuyor |
|---|---|
| `rsvps.photo_media_id` | ULID — `media` satırının kimliği |
| API yanıtı | `photoUrl` — kullanılabilir bir adres |

Bu bir tutarsızlık değil, **E1**'in (*türetilebilen veri saklanmaz*) doğal
sonucu. URL üç parçadan hesaplanıyor: `media.disk` + `media.path` + o diskin
`url` yapılandırması.

Ham URL saklasaydık `APP_URL` veritabanına gömülür, alan adı değişimi ve S3
göçü **her satırı** kırardı. Kimlik saklayıp URL türetmek, depolama kararını
sözleşmeden **tamamen** ayırıyor: yarın S3'e geçtiğimizde `types.ts` bir
karakter bile değişmeyecek.

### `?->` ve `whenNotNull` birlikte ne yapıyor?

```php
$this->photoMedia?->url()
```

`?->` **null-safe operatörü**: `photoMedia` `null` ise `url()` **hiç çağrılmaz**
ve ifade `null` olur. `$this->photoMedia->url()` yazsaydık fotoğrafsız her LCV
`Call to a member function url() on null` ile patlardı.

`whenNotNull()` ise sonucu sözleşmeye çevirir: değer `null` ise **anahtar hiç
gönderilmez**.

**C7**: *sözleşmede zorunlu alan her zaman gider; opsiyonel alan yoksa hiç
gitmez.* `types.ts` `photoUrl?: string` diyor — yani `string | undefined`.
`null` göndermek o tip sözleşmesini **kırar**.

```json
// fotoğraflı
{ "data": { "id": "...", "guestName": "Melis", "photoUrl": "http://.../x.jpg" } }

// fotoğrafsız — anahtar YOK
{ "data": { "id": "...", "guestName": "Can" } }
```

### 🔴 Eager loading borcu — iki uçta iki farklı çözüm

Bu Resource artık **ilişkilere dokunuyor** ve `Model::preventLazyLoading()`
geliştirmede açık. Yani ilişkiler önceden yüklenmezse yerelde exception,
üretimde N+1.

| Uç | Çözüm | Neden bu |
|---|---|---|
| `RsvpController::index` (liste) | `->with(['photoMedia', 'videoMedia'])` | 50 LCV = **101 sorgu** olurdu |
| `PublicRsvpController::store` (tek kayıt) | `->loadMissing([...])` | Tek model; sorgu zaten açılmış |

`loadMissing()` honeypot yolunda da güvenli: orada dönen model **kaydedilmemiş**
bir `Rsvp` ve ilişki kimliği `null` — Eloquent sorgu açmaz, ilişki `null` kalır.
Yani bot yanıtı gerçek bir yanıttan ayırt edilemez olmaya devam eder (**L2**).

Yükleme kararının Resource'ta değil **çağıranda** olması Faz 3'ün 3.9
kararıydı: *maliyeti görünür kılmak.* Resource sessizce sorgu açan bir sınıf
olsaydı N+1 bir listede fark edilmeden çoğalırdı.

### `ip_hash` hâlâ yok — ve bu kural genişledi

Faz 5'te `ip_hash` sözleşme dışında bırakılmıştı (**L4**: *kişisel veri
hash'lenerek saklanır ve türevi de yayılmaz*).

Faz 6 aynı ilkeyi medya tarafında uyguluyor: `photo_media_id` de yanıta
**girmiyor**. Sahip için kimliğin bir anlamı yok — göreceği şey fotoğrafın
kendisi. Kimliği vermek, `media` tablosunun iç kimlik uzayını sözleşmeye
sızdırmak olurdu (**C1**: Resource bir beyaz listedir).
