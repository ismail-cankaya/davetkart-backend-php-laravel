# `app/Http/Middleware/SetEtag.php`

> **Kod dosyası:** `app/Http/Middleware/SetEtag.php`
> **Faz:** 4 — Public davetiye, dosya 4.5
> **Önce oku:** [`ForceJsonResponse.md`](ForceJsonResponse.md) — ilk middleware'imiz ·
> [`../Controllers/Api/V1/PublicInvitationController.md`](../Controllers/Api/V1/PublicInvitationController.md)

---

## 1. İkinci katman: gövdeyi hiç göndermemek

4.3'te birinci katmanı kurduk: cache sayesinde davetiye **veritabanına
gitmeden** üretiliyor. Ama her istek hâlâ ~5 KB'lık bir JSON'u ağdan geçiriyor.

500 misafirin 3'er kez sayfayı yenilediğini düşün: 1500 × 5 KB ≈ 7.5 MB — hepsi
**birebir aynı** bayt dizisi.

```
1. katman — Cache      → veritabanına HİÇ gitme        (4.3) ✅
2. katman — ETag / 304 → gövdeyi HİÇ gönderme          (bu dosya)
```

İkisi birbirinin yerine geçmez, **üst üste biner**: cache sunucunun işini,
ETag ağın işini azaltır.

---

## 2. ETag nedir? — koşullu istek (conditional request)

**ETag** = *entity tag*, yani bir kaynağın belirli bir sürümünün **parmak
izi**. HTTP'nin bu mekanizmasına *koşullu istek* denir ve iki adımda çalışır:

**İlk istek:**

```http
GET /api/public/invitations/01k3rj... HTTP/1.1

HTTP/1.1 200 OK
ETag: "9f2c4e1a08b7d3f6"
Content-Length: 4812

{"data":{...}}
```

Tarayıcı yanıtı **ve** ETag'i saklar.

**İkinci istek — tarayıcı elindekini haber verir:**

```http
GET /api/public/invitations/01k3rj... HTTP/1.1
If-None-Match: "9f2c4e1a08b7d3f6"

HTTP/1.1 304 Not Modified
ETag: "9f2c4e1a08b7d3f6"
```

**Gövde yok.** Tarayıcı "değişmemiş" cevabını alır ve kendi kopyasını kullanır.
4812 bayt yerine ~150 bayt başlık gitmiş olur.

> `304 Not Modified` bir **hata değildir**. 3xx ailesi "yönlendirme" diye
> öğretilir ama 304 aslında "senin kopyan geçerli" demektir — başarılı bir
> yanıttır.

### Neden `Last-Modified` değil?

HTTP'nin ikinci bir doğrulama mekanizması daha var: `Last-Modified` +
`If-Modified-Since`. Kullanmıyoruz çünkü:

| | `Last-Modified` | **ETag** |
|---|---|---|
| Çözünürlük | **1 saniye** — aynı saniyedeki iki değişiklik ayırt edilemez | Bayt düzeyinde |
| Neyi ölçer | Kaydın zaman damgasını | **Gönderilen gövdeyi** |
| Sunum değişirse | Fark etmez — Resource'a yeni alan eklesen bile aynı kalır | Değişir |

Son satır bizim için kritik: `PublicInvitationResource`'a bir alan eklediğimiz
gün `updated_at` değişmez ama gövde değişir. `Last-Modified` kullansaydık
misafirlerin tarayıcısı **eski gövdeyi** göstermeye devam ederdi.

---

## 3. Kod okuması

```php
$response = $next($request);
```

Middleware'in **sonra** çalışan türü. `ForceJsonResponse` isteği *girerken*
değiştiriyordu (`Accept` başlığı); bu middleware yanıtı *çıkarken* işliyor.
`$next($request)` çağrısı, zincirin geri kalanının (rota + controller) çalışıp
yanıt üretmesidir; bizim kodumuz o satırdan sonra devam eder.

```
İstek  →  [ForceJsonResponse]  →  [SetEtag]  →  Controller
                                       ↑              │
Yanıt  ←──────────────────────────────┴──────────────┘
```

### `isMethodCacheable()` — neden yalnızca GET/HEAD?

```php
if (! $request->isMethodCacheable() || $response->getStatusCode() !== Response::HTTP_OK) {
    return $response;
}
```

`POST`/`PUT`/`DELETE` yanıtlarının doğrulanacak bir "sürümü" yoktur; istemci
onları saklamaz. Symfony'nin `isMethodCacheable()`'ı `GET` ve `HEAD` için
`true` döner.

Durum kodu kontrolü de aynı ailedendir: `201`, `204`, `404`, `500` — hiçbirinde
istemcinin saklayıp tekrar doğrulayacağı bir gövde yoktur. Yalnızca `200`.

### `$content === false` neden kontrol ediliyor?

`getContent()` dönüş tipi `string|false`'tur. `BinaryFileResponse` veya
`StreamedResponse` gibi **akan** yanıtlarda gövde bellekte değildir — dosya
diskten parça parça gönderilir. Böyle bir yanıtın özetini alamayız.

Bugün bu uçta akan yanıt yok; ama middleware ileride başka rotalara da
takılacak (Faz 5, Faz 6'nın medya ucu). Kontrol, **bu sınıfın başka yerde
kullanılabilmesini** güvenli kılıyor.

### 🔴 `isNotModified()` — mantığı neden elle yazmadık?

```php
$response->setEtag(hash(self::HASH_ALGORITHM, $content));
$response->isNotModified($request);
```

İkinci satır kandırıcı derecede kısa. Elle yazsaydık şunları düşünmemiz
gerekirdi:

| Kural (RFC 7232) | Symfony'de nerede |
|---|---|
| `If-None-Match` **birden çok** ETag taşıyabilir (virgülle) | `$request->getETags()` üzerinde döngü |
| Karşılaştırma **zayıf** yapılmalı: `W/` öneki soyulur | `strncmp($etag, 'W/', 2)` |
| `*` her sürümle eşleşir | `'*' === $ifNoneMatchEtag` |
| `If-None-Match` varsa `If-Modified-Since` **dikkate alınmaz** | `elseif` dalı |
| 304 yanıtında `Content-Type`, `Content-Length`, `Last-Modified` **bulunamaz** | `setNotModified()` bunları siler |

Beşi de `vendor/symfony/http-foundation/Response.php:1118` ve `:1051`'de zaten
yazılı. Bu tam olarak **R6**'nın konusu:

> **R6** — Framework'ün hazır bir çözümü varsa deseni elle yazma.

Faz 4'ün başında bu kuralı bir rota regex'i yüzünden öğrenmiştik: elle yazılan
desen sessizce yanlıştı ve aylarca fark edilmedi. Burada elle yazılan bir
karşılaştırma da sessizce yanlış olurdu — 304 hiç dönmez, kimse fark etmez,
"optimizasyon yaptık" sanılırdı.

> ⚠️ `isNotModified()` bir **yan etkili** metottur: `true` dönerken yanıtı da
> 304'e çevirir. Dönüş değerini kullanmıyoruz çünkü ihtiyacımız olan tam olarak
> o yan etki. Bu Symfony'nin yerleşik kalıbıdır.

### `setEtag()` tırnakları kendisi koyuyor

RFC'ye göre ETag değeri **çift tırnak** içinde olmalıdır (`ETag: "abc"`).
Symfony bunu bizim yerimize yapıyor (`Response.php:959`), o yüzden hash'i çıplak
veriyoruz. Elle tırnak eklemek çift tırnaklı bir başlık üretirdi.

### `xxh128` — neden `md5`/`sha256` değil?

```php
private const HASH_ALGORITHM = 'xxh128';
```

Burada sorulan soru **"bu gövde değişti mi?"**. Sorulmayan soru: "bu gövdeyi
kim üretti, biri kurcaladı mı?".

| Amaç | Gereken özellik | Uygun algoritma |
|---|---|---|
| Parola saklama | **Yavaş** olmalı (K32: Argon2id) | Argon2id, bcrypt |
| İmza / bütünlük | Çakışma üretmek **hesaplama olarak imkânsız** olmalı | SHA-256 |
| **Eşitlik parmak izi** | **Hızlı** olmalı, çakışma pratikte olmamalı | **xxHash**, CRC |

Argon2id'yi **bilerek yavaş** seçmiştik (Faz 2, K32); burada tam tersini
istiyoruz, çünkü bu hash her istekte gövdenin tamamı üzerinde çalışacak.
xxHash kriptografik değildir ama 128 bitlik çıktıda kazara çakışma olasılığı
astronomik olarak küçüktür — ve bir saldırganın çakışma üretmesi burada bir
şey kazandırmaz (kendi tarayıcısının bayat veri göstermesini sağlar, o kadar).

> **Ders:** Hash seçimi bir güvenlik refleksi değil, bir **amaç** sorusudur.
> "En güçlüsünü kullan" her yerde doğru değil — parolada yavaşlık erdemdi,
> burada kusur olurdu.

Makinende var mı diye bakmak istersen: `php -r "print_r(hash_algos());"`
çıktısında `xxh128` görünmeli (PHP 8.1+ ile geliyor).

---

## 4. 🔴 ETag neyi kurtarır, neyi kurtarmaz?

Dürüst olmak gerekirse bu katmanın sınırı var:

```
İstek gelir
  → SetEtag: $next() çağrılır
      → Controller çalışır
      → Cache HIT (sorgu yok) ✅
      → JSON gövdesi ÜRETİLİR   ← bu iş yapıldı
  → SetEtag: hash alınır
  → If-None-Match eşleşti → gövde ATILIR
  → 304 döner
```

Yani 304 dönerken bile **gövde bir kez üretildi**. Kazandığımız şey:

| Kaynak | Kazanç |
|---|---|
| Ağ bant genişliği | ✅ Büyük — 5 KB yerine ~150 bayt |
| İstemci işleme (JSON parse, React render) | ✅ Büyük |
| Veritabanı | ✅ (4.3 sayesinde, bu katmandan bağımsız) |
| Sunucu CPU / bellek | ❌ Kazanç yok — gövde yine üretildi |

Daha agresif bir tasarım mümkündü: ETag'i gövdeyi üretmeden **önce**
hesaplamak (örneğin `updated_at` + sürüm numarasından) ve eşleşirse
controller'a hiç girmemek. Yapmadık çünkü:

1. Bu middleware o zaman **hangi modelden** bahsettiğini bilmek zorunda kalırdı
   — genel bir katman olmaktan çıkar, `Invitation`'a bağlanırdı
2. Faz 5'te LCV polling ucuna da takacağız; genel kalması değerli
3. Gövde üretimi cache hit'te zaten ucuz (sorgu yok, sadece `json_encode`)

> **Kalıp:** Bir optimizasyonun ne kazandırdığını **ve kazandırmadığını**
> yaz. Aksi hâlde altı ay sonra biri "ETag var, CPU sorunu olamaz" der.

---

## 5. Nereye kaydedildi ve neden oraya?

```php
// routes/api.php
Route::prefix('public')->name('public.')->middleware(SetEtag::class)->group(...);
```

**M2**: middleware **gruba/rotaya** kaydedilir, global listeye değil. Kapsam
açıkça sınırlanır.

Global kaydetseydik her yanıtın gövdesi hash'lenirdi — `POST /auth/login`
dahil. Boşuna iş, ve daha kötüsü: bir gün biri "neden auth yanıtında ETag var?"
diye haklı olarak sorardı.

Neden `bootstrap/app.php`'de takma ad (alias) tanımlamadık? Çünkü tek bir yerde
kullanılıyor ve sınıf adıyla yazmak **daha okunur**: rota dosyasını okuyan kişi
hangi sınıfın devrede olduğunu görüyor, bir takma adı aramak zorunda kalmıyor.
Üçüncü kullanım yerinde takma ad değerlenir.

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur | Doğrusu |
|---|---|---|---|
| 1 | ETag karşılaştırmasını elle yazmak | `W/` öneki, çoklu ETag, `*` gözden kaçar; 304 hiç dönmez | `isNotModified()` (R6) |
| 2 | Hash'i tırnak içinde vermek | `ETag: ""abc""` — geçersiz başlık | Symfony tırnaklıyor |
| 3 | Global middleware yapmak | Her yanıt hash'lenir, auth uçlarında bile | Gruba kaydet (M2) |
| 4 | `POST` yanıtına ETag koymak | İstemci saklamaz; anlamsız | `isMethodCacheable()` |
| 5 | Hata yanıtlarına ETag koymak | 404 "cache'lenmiş" gibi davranabilir | Yalnızca `200` |
| 6 | 304'te gövde/`Content-Type` bırakmak | RFC 7232 ihlali; bazı vekiller bozulur | `setNotModified()` siler |
| 7 | `sha256` kullanmak | Her istekte gereksiz CPU | Amaç eşitlik → hızlı hash (§3) |
| 8 | "ETag var, sunucu yükü çözüldü" sanmak | Gövde yine üretiliyor | §4'ü oku |

---

## 7. Kendin dene

🔴 Tarayıcı bu işi *kendi* yaptığı için (XHR'de 304'ü şeffafça 200'e çevirir)
doğrulamayı **curl** ile yapıyoruz — koşullu isteği elle kuracağız.

Önce yayınlanmış bir davetiye:

```powershell
php artisan tinker
```

```php
$inv = App\Models\Invitation::factory()->withTimeline(2)->create([
    'status' => App\Enums\InvitationStatus::Published,
    'published_at' => now(),
    'show_timeline' => true,
]);
$inv->id;   // ⇒ kopyala
```

Ayrı bir terminalde sunucuyu başlat:

```powershell
php artisan serve
```

Üçüncü terminalde (PowerShell):

```powershell
$id  = "<yukarida kopyaladigin ulid>"
$url = "http://127.0.0.1:8000/api/public/invitations/$id"

# 1) İlk istek — 200 ve ETag başlığı
curl.exe -i $url
```

Çıktının başında şunu görmelisin:

```
HTTP/1.1 200 OK
ETag: "9f2c4e1a08b7d3f6..."
```

```powershell
# 2) ETag'i alıp koşullu istek at — 304 ve GÖVDE YOK
$etag = (curl.exe -s -D - -o NUL $url | Select-String '^ETag:').ToString().Split(' ')[1].Trim()
curl.exe -i -H "If-None-Match: $etag" $url
```

```
HTTP/1.1 304 Not Modified      🔴 asıl kanıt
```

```powershell
# 3) Yanlış ETag ile — 200 ve tam gövde
curl.exe -i -H 'If-None-Match: "yanlis"' $url

# 4) * her sürümle eşleşir (RFC 7232) — 304
curl.exe -i -H 'If-None-Match: *' $url
```

```powershell
# 5) POST'a ETag konmuyor mu? (405 döner, ETag başlığı OLMAMALI)
curl.exe -i -X POST $url
```

Adım 4, elle yazsaydık kaçıracağımız kuralın kanıtı: `*` özel bir değerdir.

Son olarak kalite kapısı:

```powershell
composer check
```

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **ETag** | Entity Tag — kaynağın belirli bir sürümünün parmak izi |
| **Koşullu istek** | "Şu sürüm hâlâ geçerliyse gövde gönderme" diyen istek |
| **`If-None-Match`** | İstemcinin elindeki ETag'i bildirdiği istek başlığı |
| **304 Not Modified** | "Senin kopyan geçerli" anlamına gelen başarılı yanıt |
| **Zayıf karşılaştırma** | `W/` önekini yok sayan ETag eşitlik kontrolü |
| **Cacheable method** | Yanıtı saklanabilen HTTP metodu (GET, HEAD) |
| **Kriptografik hash** | Çakışma üretmesi hesaplama olarak imkânsız özet (SHA-256) |
| **Streamed response** | Gövdesi bellekte tutulmayan, parça parça gönderilen yanıt |

---

## 9. Sırada ne var?

**4.6 — `InvitationPublished` olayı + `ClearInvitationCache` dinleyicisi.**

Şu an bir sorun duruyor ve 4.3'ün deneme adımlarında görmüştük: davetiye
güncellenince cache **bayat kalıyor**. TTL 6 saat, yani sahibi bir yazım
hatasını düzeltse misafirler yarım gün eskisini görebilir.

İlginç olan şu: ETag bu sorunu **büyütüyor**. Bayat gövde bayat bir ETag
üretiyor, tarayıcı da onu doğruluyor ve 304 alıyor — yani yanlış veri artık
daha da verimli biçimde servis ediliyor. *Bir optimizasyon, altındaki hatayı
düzeltmez; onu hızlandırır.*
