# Faz 4 — Elle Doğrulama Betiği

> **Amaç:** Otomatik testlerin kanıtladıklarını gözle görmek ve
> **kanıtlayamadıklarını** elle kapatmak.
> **Süre:** ~15 dakika · **Önkoşul:** `composer check` yeşil

🔴 **Bu belge süs değil.** Faz 4'ün en kritik davranışı — cache'in gerçek bir
commit sonrası düşmesi — `RefreshDatabase` yüzünden testte gözlenemiyor
(`FAZ-4.md` §2.9). **Adım 12 o boşluğu kapatan tek şeydir.**

---

## 0. Hazırlık

Üç terminal açık olacak.

```powershell
# 1. terminal — backend
cd D:\Projects\davetkart\davetkart-backend-php-laravel
php artisan migrate
php artisan serve
```

```powershell
# 2. terminal — tinker
cd D:\Projects\davetkart\davetkart-backend-php-laravel
php artisan tinker
```

```powershell
# 3. terminal — curl
cd D:\Projects\davetkart\davetkart-backend-php-laravel
```

> ⚠️ `curl` yerine PowerShell'in takma adı çalışmaz; **`curl.exe`** yaz.

---

## 1. Yayınlanmış bir davetiye üret

Yayın ucu Faz 7'de (K47), o yüzden durumu elle çeviriyoruz.

```php
// 2. terminal (tinker)
use App\Models\Invitation;
use App\Enums\InvitationStatus;

$inv = Invitation::factory()->published()->withTimeline(3)->create([
    'title' => 'Dugunumuz',
    'names' => 'Ayse & Mehmet',
    'show_timeline' => true,
    'show_gift' => false,                              // 🔴 modül KAPALI
    'iban' => 'TR330006100519786457841326',            // ama veri DOLU
    'bank_name' => 'Ziraat',
    'account_holder' => 'Ayse Yilmaz',
]);

$inv->id;   // ⇒ kopyala
```

```powershell
# 3. terminal
$id  = "<yukaridaki ulid>"
$url = "http://127.0.0.1:8000/api/public/invitations/$id"
```

**Beklenen:** ULID **küçük harfli** (`01k3...`). Büyük harfli görüyorsan
`HasUlids` ezilmiş demektir ve rota kısıtı hakkında yanlış bir varsayımla
ilerlersin.

---

## 2. Uç auth'suz çalışıyor mu?

```powershell
curl.exe -s $url
```

**Beklenen:** `{"data":{"id":"01k3...","invitation":{...}}}` — token yok, 200 var.

---

## 3. 🔴 Kapalı modülün verisi sızıyor mu?

```powershell
curl.exe -s $url | Select-String -Pattern "iban|bankName|accountHolder|TR3300"
```

**Beklenen:** **hiçbir eşleşme yok.**

Bir eşleşme çıkarsa fazın sözleşme kararı (C6) delinmiş demektir — dur ve
`PublicInvitationResource`'a bak.

```powershell
curl.exe -s $url | Select-String -Pattern "showGift"
```

**Beklenen:** `"showGift":false` **var**. Veri gitmiyor ama bayrak gidiyor —
şablon neyi çizmeyeceğine ona bakarak karar veriyor.

---

## 4. Modülü aç, veri gelsin (T6: yokluğun yanında varlık)

```php
// tinker
$inv->update(['show_gift' => true]);
```

```powershell
curl.exe -s $url | Select-String -Pattern "TR3300"
```

**Beklenen:** IBAN **görünüyor**.

> Bu adım aynı zamanda cache invalidation'ın çalıştığının ilk ipucudur: veri
> değişti ve yanıt güncellendi. Adım 12 bunu kesin olarak sınayacak.

```php
// tinker — geri kapat
$inv->update(['show_gift' => false]);
```

---

## 5. Program adımlarında `id` var mı?

```powershell
curl.exe -s $url | Select-String -Pattern '"timelineEvents"'
```

**Beklenen:** adımlar var, ama içlerinde `"id"` **yok** — yalnızca `time`,
`title`, `description`.

Sebep: artan bigint kimlik, K40'ın ULID ile kapattığı sayım sızıntısını arka
kapıdan geri getirirdi (4.2a).

---

## 6. Sunucu üstverisi sızıyor mu?

```powershell
curl.exe -s $url | Select-String -Pattern '"status"|"updatedAt"'
```

**Beklenen:** eşleşme yok. Misafirin işine yaramayan alan gönderilmiyor (C5).

---

## 7. 🔴 Taslak sızıyor mu?

```php
// tinker
$inv->update(['status' => InvitationStatus::Saved]);
```

```powershell
curl.exe -s -i $url | Select-Object -First 1
curl.exe -s $url
```

**Beklenen:** `HTTP/1.1 404 Not Found` ve
`{"error":{"code":"RESOURCE_NOT_FOUND"}}`.

Başlık ya da içerik görünüyorsa fazın en önemli kuralı delinmiş demektir.

---

## 8. Yayınlanmamış ile hiç var olmayan ayırt edilebiliyor mu?

```powershell
$yok = "http://127.0.0.1:8000/api/public/invitations/01arz3ndektsv4rrffq69g5fav"

curl.exe -s $url
curl.exe -s $yok
```

**Beklenen:** iki gövde **birebir aynı**. Tek karakter fark bile bir
enumeration kanalıdır (08 §3.2).

```php
// tinker — yayına geri al
$inv->update(['status' => InvitationStatus::Published]);
```

---

## 9. Biçimsiz kimlik veritabanına gidiyor mu?

```powershell
curl.exe -s -i "http://127.0.0.1:8000/api/public/invitations/bu-bir-ulid-degil" | Select-Object -First 1
```

**Beklenen:** `404`. Ve 1. terminaldeki `php artisan serve` günlüğünde bu istek
için **hiçbir SQL** görünmemeli (rota eşleşmedi, `whereUlid` kapıda kesti).

---

## 10. Cache gerçekten devrede mi?

```php
// tinker
use Illuminate\Support\Facades\Cache;

$key = Invitation::publicCacheKey($inv->id);
$key;                    // ⇒ 'davetkart:public-invitation:01k3...'
Cache::has($key);        // ⇒ true   (adım 2'deki istek doldurdu)
```

> ⚠️ `.env`'de `CACHE_STORE=file` — yani cache `storage/framework/cache/` altında
> dosya olarak duruyor ve `php artisan serve` ile tinker **aynı** cache'i
> görüyor. `array` sürücüsünde bu adım çalışmazdı.

```php
Cache::get($key)['invitation']['title'];    // ⇒ 'Dugunumuz'
is_array(Cache::get($key)['invitation']['timelineEvents'][0]);   // ⇒ true
```

Son satır önemli: cache'te **düz dizi** duruyor, Eloquent nesnesi değil (O1).

---

## 11. Sıfır sorgu doğrulaması

```php
// tinker
use Illuminate\Support\Facades\DB;
use App\Actions\Invitation\ResolvePublicInvitationAction;
use App\Http\Resources\PublicInvitationResource;
use Illuminate\Support\Facades\Config;

Cache::forget($key);
$resolve = app(ResolvePublicInvitationAction::class);
$build = fn (): array => PublicInvitationResource::make($resolve->handle($inv->id))->resolve(request());
$ttl = Config::integer('davetkart.cache.public_invitation_ttl');

DB::enableQueryLog();
Cache::remember($key, $ttl, $build);
count(DB::getQueryLog());     // ⇒ 2   (davetiye + program)

DB::flushQueryLog();
Cache::remember($key, $ttl, $build);
count(DB::getQueryLog());     // ⇒ 0   🔴 fazın kalbi
```

---

## 12. 🔴🔴 EN ÖNEMLİ ADIM: gerçek commit sonrası cache düşüyor mu?

**Bunu hiçbir otomatik test kanıtlayamıyor.** `RefreshDatabase` her testi bir
transaction'a sarıp rollback ediyor; `ClearInvitationCache`
`ShouldHandleEventsAfterCommit` olduğu için testte hiç koşmuyor
(`FAZ-4.md` §2.9, `T15`).

Burada gerçek bir HTTP isteği ve gerçek bir commit kullanıyoruz.

```powershell
# 3. terminal — cache'i doldur
curl.exe -s $url | Select-String -Pattern "Dugunumuz"
```

```php
// 2. terminal (tinker) — cache dolu mu?
Cache::has($key);        // ⇒ true

// Gerçek bir güncelleme (tinker transaction içinde DEĞİL → gerçek commit)
$inv->update(['title' => 'YENI BASLIK']);

Cache::has($key);        // ⇒ false   🔴🔴 ASIL KANIT
```

```powershell
# 3. terminal — yeni veri geliyor mu?
curl.exe -s $url | Select-String -Pattern "YENI BASLIK"
```

**Beklenen:** `Cache::has()` **`false`**, ve bir sonraki istek yeni başlığı
döndürüyor.

`true` görüyorsan zincirin bir halkası kopmuş demektir. Sırayla bak:

| Halka | Nasıl bakılır |
|---|---|
| Model → olay | `Invitation` içinde `$dispatchesEvents` haritası duruyor mu |
| Olay → dinleyici | `Event::getRawListeners()[App\Events\InvitationChanged::class]` |
| Dinleyici → cache | `Invitation::publicCacheKey()` ile controller'ın anahtarı aynı mı |

### 12.1 Silme de düşürüyor mu?

```php
curl.exe -s $url > $null;   // (3. terminalde) cache'i doldur
```

```php
// tinker
Cache::has($key);   // ⇒ true
$inv->delete();
Cache::has($key);   // ⇒ false
$inv->restore();
```

---

## 13. ETag ve 304

```powershell
# 3. terminal
curl.exe -s -i $url | Select-String -Pattern "^HTTP|^ETag"
```

**Beklenen:**

```
HTTP/1.1 200 OK
ETag: "9f2c4e1a08b7d3f6..."
```

```powershell
# ETag'i yakala
$etag = (curl.exe -s -D - -o NUL $url | Select-String '^ETag:').ToString().Split(' ')[1].Trim()
$etag

# 🔴 Fazın bitiş ölçütü
curl.exe -s -i -H "If-None-Match: $etag" $url | Select-String -Pattern "^HTTP"
```

**Beklenen:** `HTTP/1.1 304 Not Modified` — ve altında **gövde yok**.

```powershell
# '*' her sürümle eşleşir (RFC 7232)
curl.exe -s -i -H "If-None-Match: *" $url | Select-Object -First 1     # ⇒ 304

# Bayat ETag tam gövde getirir
curl.exe -s -i -H 'If-None-Match: "bayat"' $url | Select-Object -First 1  # ⇒ 200

# POST'a ETag konmaz
curl.exe -s -i -X POST $url | Select-String -Pattern "^HTTP|^ETag"       # 405, ETag YOK
```

### 13.1 Veri değişince ETag de değişiyor mu?

```php
// tinker
$inv->update(['title' => 'UCUNCU BASLIK']);
```

```powershell
curl.exe -s -D - -o NUL $url | Select-String '^ETag:'
```

**Beklenen:** ETag **farklı**. Eski ETag ile istek artık 200 döner:

```powershell
curl.exe -s -i -H "If-None-Match: $etag" $url | Select-Object -First 1   # ⇒ 200
```

---

## 14. Frontend uçtan uca

```powershell
# 4. terminal
cd D:\Projects\davetkart\davetkart-frontent
npm run dev
```

Tarayıcıda `http://localhost:5173/invite/<id>`:

| Deneme | Beklenen |
|---|---|
| Normal pencere | Davetiye çizilir, gerçek başlık görünür |
| **Gizli pencere** (oturum yok) | **Aynı** sonuç — auth gerekmiyor |
| DevTools → Network → yanıt gövdesi | `iban` anahtarı **yok** |
| `status`'ü `saved` yapıp yenile | "Bu davetiye bulunamadı." |
| `/invite/gecersiz` | "Bu davetiye bulunamadı." |
| `php artisan serve`'i durdurup yenile | **"Davetiye şu an açılamıyor."** — farklı mesaj |

Son iki satır 4.8'in ayrımını kanıtlıyor: 404 ile taşıma hatası aynı şey
değil. Aynı mesajı gösterseydik, geçici bir kesintide misafire "davetiye yok"
derdik.

> ⚠️ Tarayıcı XHR'de 304'ü şeffafça 200'e çevirir; Network sekmesinde 304
> göremeyebilirsin. Bu **hata değil**, tarayıcı davranışı — 304'ün kanıtı adım
> 13'teki curl çıktısıdır.

---

## 15. Temizlik

```php
// tinker
Cache::forget(Invitation::publicCacheKey($inv->id));
Invitation::withTrashed()->forceDelete();
App\Models\User::query()->delete();
```

```powershell
composer check
```

---

## Kontrol listesi

- [ ] 3 — Kapalı modülün verisi gövdede **yok**, bayrağı **var**
- [ ] 5 — Program adımlarında `id` **yok**
- [ ] 6 — `status` / `updatedAt` **yok**
- [ ] 7 — Taslak **404**
- [ ] 8 — Taslak ile var olmayan **birebir aynı** gövde
- [ ] 9 — Biçimsiz kimlik veritabanına **gitmiyor**
- [ ] 11 — İkinci `remember` çağrısı **0 sorgu**
- [ ] **12 — Gerçek commit sonrası cache düşüyor** 🔴 (testin kapatamadığı boşluk)
- [ ] 13 — `If-None-Match` ile **304**, gövde yok
- [ ] 14 — `/invite/:id` gerçek veriyi çiziyor; 404 ile taşıma hatası ayrı
