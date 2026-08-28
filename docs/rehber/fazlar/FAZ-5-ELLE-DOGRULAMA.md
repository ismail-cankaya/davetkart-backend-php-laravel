# Faz 5 — Elle Doğrulama Betiği

> **Amaç:** Faz 5 kodunun **gerçekten çalıştığını** ilk kez kanıtlamak.
> **Süre:** ~35 dakika · **Önkoşul:** yok — kurulum bu betiğin 1. adımı

---

## 🔴 Bu belge diğerlerinden farklı

Faz 2, 3 ve 4'ün elle doğrulama betikleri, `composer check` yeşil olduktan
**sonra** koşuyordu; amaçları testlerin kanıtlayamadığı boşlukları kapatmaktı.

Bu betik farklı: **Faz 5'in kodu hiçbir kalite kapısından geçmedi.**
`pint`, `phpstan` ve `phpunit` bir kez bile koşmadı (gerekçe:
[`FAZ-5.md`](FAZ-5.md) §0). Koşan tek kontrol her dosya için `php -l` —
yani yalnızca sözdizimi.

Dolayısıyla bu betik hem **kurulum**, hem **kalite kapısı**, hem **elle
doğrulama**. Adım 4 geçmeden diğerlerine bakmanın anlamı yok.

> ⚠️ Bir şey kırılırsa bu **beklenen** bir durumdur, bir felaket değil. Hatanın
> ne olduğunu ve hangi dosyada olduğunu not al; düzeltmek genelde tek satırdır.
> Faz 3 ve Faz 4 de üçer kırılmayla açılmıştı.

---

## 0. Hazırlık

Üç terminal:

```powershell
# 1. terminal — sunucu
cd <proje-klasoru>\davetkart-backend-php-laravel

# 2. terminal — tinker
cd <proje-klasoru>\davetkart-backend-php-laravel

# 3. terminal — curl
cd <proje-klasoru>\davetkart-backend-php-laravel
```

> ⚠️ `curl` yerine PowerShell takma adı çalışmaz; **`curl.exe`** yaz.
>
> ⚠️ PowerShell JSON gövdelerindeki tırnakları bozar. Bu yüzden gövdeleri
> **dosyaya** yazıp `--data "@dosya.json"` ile göndereceğiz. Daha uzun ama
> tırnak kaçışıyla uğraşmaktan hızlı.

Faz 5 dalını al:

```powershell
git fetch <bundle-yolu>\faz-5.bundle faz-5:faz-5
git checkout faz-5
git log --oneline | Select-Object -First 16
```

**Beklenen:** `5.1` ile `5.16` arasında commit'ler ve altta `4.9`.

---

## 1. Kurulum

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
```

`.env` dosyasını aç, tek satırı doldur:

```env
DB_PASSWORD=<postgres parolan>
```

İki veritabanı (yoksa):

```powershell
psql -U postgres -c "CREATE DATABASE davetkart;"
psql -U postgres -c "CREATE DATABASE davetkart_test;"
```

**Kontrol:**

```powershell
php -r "echo 'ARGON2ID: ', (defined('PASSWORD_ARGON2ID') ? 'VAR' : 'YOK'), PHP_EOL;"
php -m | Select-String -Pattern "^(pdo_pgsql|fileinfo)$"
```

🔴 `ARGON2ID: YOK` görürsen **dur**. K32 gereği `HASH_DRIVER=argon2id`; desteği
olmayan bir PHP derlemesinde her kayıt/giriş çalışma anında patlar ve
`composer check` sana 10+ `AuthTest` kırmızısı gösterir — sebebi kodda ararsın.

---

## 2. Migration ve iki CHECK kısıtı

```powershell
php artisan migrate
php artisan migrate:status | Select-String "rsvps"
```

Kısıtların **gerçekten oluştuğunu** kanıtla:

```powershell
psql -U postgres -d davetkart -c "\d rsvps"
```

**Beklenen:** çıktının sonunda iki satır —
`rsvps_status_check` ve `rsvps_guest_count_check`.

🔴 Görünmüyorlarsa `DB::statement` çağrıları çalışmamıştır. Bir korumanın var
olduğunu, ancak bir şeyi **reddettiğini gördüğünde** bilirsin (Faz 4, ders 35).

---

## 3. Seeder — B5'in hatırlattığı şey

```powershell
php artisan db:seed
```

**Beklenen çıktı:** `Demo hesap: test@ornek.test / ...`

> 🔴 **B5:** *hiçbir otomatik kontrolün yolunda olmayan dosyayı elle çalıştırmak
> senin sorumluluğun.* `composer check` seeder'ı koşturmaz — ve Faz 3'te
> `DatabaseSeeder`'ın Faz 0'dan beri bozuk olduğu (var olmayan `name` kolonuna
> yazıyordu) tam olarak bu yüzden aylarca fark edilmemişti.

---

## 4. 🔴 `composer check` — SON satıra bak

```powershell
composer check
```

**Zincir fail-fast:**

```
pint --test  →  phpstan  →  errors:export --check  →  phpunit
     ↓ kırılırsa hiçbiri koşmaz
```

🔴 **Çıktının SON satırına bak, ilkine değil.** "Üç fazda üç kez 'kapandı'
sanılan faz kapanmamıştı" (çalışma kuralı 13).

### 4.1 PHPStan level 8 patlarsa

Faz 0-4 kodu level 6 altında yazıldı; yükseltme (5.14) doğrulanamadı.
Hatalar **eski dosyalarda** da çıkabilir. O zaman:

```powershell
git log --oneline | Select-String "5.14"
git revert <o-commit-hash> --no-edit
composer check
```

Bu yalnızca yükseltmeyi geri alır; fazın geri kalanı etkilenmez. Hataları
sonra tek tek düzeltip yükseltmeyi tekrar denersin.

🔴 **Yapma:** `ignoreErrors`'a toplu susturma eklemek. **K4** her satır için
gerekçe yorumu ister.

### 4.2 Pint kırılırsa

```powershell
composer lint      # düzeltir
composer check     # tekrar
```

`pint --test` yalnızca **bakar**, düzeltmez (Faz 1, ders 12).

---

## 5. Testler

```powershell
php artisan test --filter=RsvpTest
```

**Beklenen:** 29 test yeşil.

```powershell
php artisan test
```

**Beklenen:** Faz 0-4'ün tüm testleri + 29 = toplam **76 test**.

🔴 `AuthTest` kırılırsa ilk bakılacak yer **5.5**'tir: iki auth exception'ı
`HasErrorCode` arayüzüne taşındı ve davranışın birebir aynı kaldığını
kanıtlayan tek şey o testler.

---

## 6. Rota yüzeyi

```powershell
php artisan route:list --path=api
```

**Beklenen üç yeni satır:**

```
POST    api/public/invitations/{invitation}/rsvps  public.invitations.rsvps.store
GET     api/invitations/{invitation}/rsvps         invitations.rsvps.index
DELETE  api/rsvps/{rsvp}                           rsvps.destroy
```

Middleware sütununda: `POST`'ta `throttle:rsvp` **var**, `GET`'te **yok**.
(Varsa okuma polling'i 15 sn'de bir gelip kovayı doldurur.)

---

## 7. Mutlu yol

```powershell
# 1. terminal
php artisan serve
```

```php
// 2. terminal (tinker)
use App\Models\Invitation;

$inv = Invitation::where('title', 'Yayindaki Davetiye')->first();
$inv->id;                 // ⇒ kopyala
$inv->show_rsvp;          // true
$inv->rsvp_deadline;      // bir ay sonrası
```

```powershell
# 3. terminal
$id  = "<yukaridaki ulid>"
$url = "http://127.0.0.1:8000/api/public/invitations/$id/rsvps"

'{"guestName":"Can Dogan","guestCount":2,"status":"attending"}' |
    Out-File -Encoding ascii gecerli.json

curl.exe -s -X POST $url -H "Content-Type: application/json" --data "@gecerli.json"
```

**Beklenen:** `201` ve
`{"data":{"id":"01k...","guestName":"Can Dogan","guestCount":2,"menuPreference":"","status":"attending","createdAt":"..."}}`

🔴 **Gövdede `ipHash` veya `invitationId` OLMAMALI** (C1).

---

## 8. Görünürlük

```php
// tinker
$taslak = App\Models\Invitation::where('title', 'Taslak Davetiye')->first();
$taslak->id;                          // ⇒ kopyala

$kapali = App\Models\Invitation::factory()->published()
    ->create(['show_rsvp' => false]);
$kapali->id;                          // ⇒ kopyala
```

```powershell
$taslakId = "<...>"; $kapaliId = "<...>"
$base = "http://127.0.0.1:8000/api/public/invitations"

curl.exe -s -X POST "$base/$taslakId/rsvps" -H "Content-Type: application/json" --data "@gecerli.json"
curl.exe -s -X POST "$base/$kapaliId/rsvps" -H "Content-Type: application/json" --data "@gecerli.json"
curl.exe -s -X POST "$base/01arz3ndektsv4rrffq69g5fav/rsvps" -H "Content-Type: application/json" --data "@gecerli.json"
```

**Beklenen:** üçü de **birebir aynı** gövde:
`{"error":{"code":"RESOURCE_NOT_FOUND"}}`

Ve hiçbiri yazmamış olmalı:

```powershell
php artisan tinker --execute="echo App\Models\Rsvp::count();"
```

---

## 9. Doğrulama sözleşmesi

```powershell
'{"guestName":"Can","guestCount":2,"status":"Katiliyor"}' | Out-File -Encoding ascii gecersiz.json
curl.exe -s -X POST $url -H "Content-Type: application/json" --data "@gecersiz.json"
```

**Beklenen:** `422`, ve `error.fields.status[0].rule` = **`"in"`**

🔴 `"illuminate_validation_rules_enum"` görüyorsan **D6 ihlali** var: biri
`Rule::enum()` yazmış ve framework sınıf adı sözleşmeye sızıyor. Faz 3'te
`Password::min(8)` ile birebir aynı hata yaşanmıştı.

```powershell
'{"guestName":"Can","guestCount":50,"status":"attending"}' | Out-File -Encoding ascii cokfazla.json
curl.exe -s -X POST $url -H "Content-Type: application/json" --data "@cokfazla.json"
```

**Beklenen:** `rule` = `"max"`, `params.max` = `10` (H9 beyaz listesi çalışıyor).

---

## 10. 🔴🔴 HONEYPOT — bu betiğin en kritik adımı

Faz 4'ün 12. adımı neyse, bu odur: **yanıt sana hiçbir şey söylemez.**

```powershell
'{"guestName":"Bot Test","guestCount":1,"status":"attending","website":"http://spam.example"}' |
    Out-File -Encoding ascii bot.json

curl.exe -s -X POST $url -H "Content-Type: application/json" --data "@bot.json"
```

**Beklenen yanıt:** `201` — gerçek bir kayıtla **ayırt edilemez**.

🔴 **Asıl kontrol bu:**

```powershell
php artisan tinker --execute="echo App\Models\Rsvp::where('guest_name','Bot Test')->count();"
```

| Çıktı | Anlam |
|---|---|
| `0` | ✅ Honeypot çalışıyor |
| `1` | ❌ **Savunma yok** — ve yanıt `201` olduğu için başka hiçbir şey sana bunu söylemez |

Bu, **T14**'ün ("yanıtı değil etkiyi doğrula") en saf örneği. `assertStatus(201)`
yazan bir test, honeypot bloğu tamamen silinse de yeşil kalırdı.

---

## 11. Son tarih — bir gün kayması var mı?

```php
// tinker
$bugun = App\Models\Invitation::factory()->published()
    ->create(['show_rsvp' => true, 'rsvp_deadline' => now()->toDateString()]);
$bugun->id;

$dun = App\Models\Invitation::factory()->published()
    ->create(['show_rsvp' => true, 'rsvp_deadline' => now()->subDay()->toDateString()]);
$dun->id;
```

```powershell
curl.exe -s -X POST "$base/<bugunId>/rsvps" -H "Content-Type: application/json" --data "@gecerli.json"
curl.exe -s -X POST "$base/<dunId>/rsvps"   -H "Content-Type: application/json" --data "@gecerli.json"
```

| Davetiye | Beklenen |
|---|---|
| Son tarih **bugün** | `201` ✅ — son gün **dâhildir** |
| Son tarih **dün** | `403` + `{"error":{"code":"RSVP_DEADLINE_PASSED"}}` |

🔴 Birincisi `403` dönüyorsa `SubmitRsvpAction`'da `isPast()` yazılmış demektir
(E8). Bu hata üretimde *"bazı kullanıcılar son gün gönderemiyor"* olarak
görünür ve logda hiçbir iz bırakmaz.

---

## 12. 🔴 Kota — `SUM` mu `COUNT` mu?

```php
// tinker
config(['davetkart.tiers.standart.rsvp_limit' => 5]);   // yalnızca bu oturumda!
```

> ⚠️ `tinker` ile `curl` **ayrı süreçlerdir**; `config()` değişikliği HTTP
> isteğine geçmez. Bu yüzden kalıcı değiştir:
> `config/davetkart.php` → `'standart' => [... 'rsvp_limit' => 5]`
> ve testten sonra **geri al**.

```php
// tinker — 4 kişilik tek bir yanıt
$kota = App\Models\Invitation::factory()->published()->create(['show_rsvp' => true]);
App\Models\Rsvp::factory()->for($kota)->guests(4)->create();
$kota->id;
```

```powershell
'{"guestName":"Ikinci Grup","guestCount":2,"status":"attending"}' | Out-File -Encoding ascii iki.json
curl.exe -s -X POST "$base/<kotaId>/rsvps" -H "Content-Type: application/json" --data "@iki.json"
```

| Ölçüm | Hesap | Beklenen |
|---|---|---|
| `SUM(guest_count)` ✅ | 4 + 2 = 6 > 5 | `403` `RSVP_QUOTA_EXCEEDED` |
| `COUNT(*)` ❌ | 1 + 1 = 2 ≤ 5 | `201` — **yanlış** |

🔴 **Sızıntı kontrolü:** yanıt gövdesinde `params`, `remaining` veya `limit`
kelimeleri **geçmemeli** (H9 — anonim misafir iç sayaçları öğrenmemeli).

Şimdi `declined`'ın saymadığını doğrula:

```php
// tinker
App\Models\Rsvp::factory()->for($kota)->declined()->guests(50)->create();
```

```powershell
curl.exe -s -X POST "$base/<kotaId>/rsvps" -H "Content-Type: application/json" --data "@iki.json"
```

**Beklenen:** hâlâ `403` (çünkü `attending` 4 + gelen 2 = 6 > 5), ama 50 kişilik
`declined` yanıt hesabı **değiştirmemiş** olmalı — yani hata mesajı aynı, kota
`54` gibi bir sayıya fırlamamış. (K50)

---

## 13. Hız sınırı

```powershell
1..12 | ForEach-Object {
    $kod = curl.exe -s -o NUL -w "%{http_code}" -X POST $url `
        -H "Content-Type: application/json" --data "@gecerli.json"
    "$_ -> $kod"
}
```

**Beklenen:** ilk 10 istek `201`, 11. ve 12. **`429`**.

```powershell
curl.exe -s -X POST $url -H "Content-Type: application/json" --data "@gecerli.json"
```

**Beklenen gövde:** `{"error":{"code":"RATE_LIMITED","params":{"retryAfter":NN}}}`

> `retryAfter` beyaz listede olduğu için dışarı çıkıyor (H9) — standart HTTP
> davranışı ve kullanıcıya yararlı.

Bir dakika bekleyip tekrar dene: kova boşalmalı.

---

## 14. Sahibin paneli

```powershell
# Giriş
'{"email":"test@ornek.test","password":"parola"}' | Out-File -Encoding ascii giris.json
curl.exe -s -X POST "http://127.0.0.1:8000/api/auth/login" -H "Content-Type: application/json" --data "@giris.json"
$token = "<yanittaki token>"
```

> Parola `UserFactory::PASSWORD` sabitinde; adım 3'ün çıktısında da yazıyordu.

```powershell
$liste = "http://127.0.0.1:8000/api/invitations/$id/rsvps"

# 14.1 Liste
curl.exe -s -H "Authorization: Bearer $token" $liste

# 14.2 ETag
curl.exe -s -D - -o NUL -H "Authorization: Bearer $token" $liste | Select-String "ETag"
$etag = '"<yukaridaki-deger>"'
curl.exe -s -o NUL -w "%{http_code}`n" -H "Authorization: Bearer $token" `
    -H "If-None-Match: $etag" $liste
```

**Beklenen:** `304` — K46'nın karşılığı alındı, Faz 4'ün ETag katmanı yeni bir
uçta yeniden kullanıldı.

```powershell
# 14.3 🔴 Sızıntı: ip_hash
curl.exe -s -H "Authorization: Bearer $token" $liste | Select-String "ip_hash|ipHash"
```

**Beklenen:** hiçbir eşleşme (L4/C1).

```powershell
# 14.4 🔴 IDOR — ikinci bir hesap aç ve onun token'ıyla dene
```

**Beklenen:** `404` — **`403` değil** (H7).

---

## 15. 🔴 Eşzamanlılık — testin kapatamadığı boşluk

`RsvpTest` bu senaryoyu **doğrulayamaz**: tek bir test süreci var, iki isteğin
yarışı taklit edilemez (Faz 4'ün **T15** durumunun aynısı).

```php
// tinker — kota 5, mevcut 4 kişi
$yaris = App\Models\Invitation::factory()->published()->create(['show_rsvp' => true]);
App\Models\Rsvp::factory()->for($yaris)->guests(4)->create();
$yaris->id;
```

İki isteği **aynı anda** gönder:

```powershell
$u = "$base/<yarisId>/rsvps"
$j1 = Start-Job { curl.exe -s -o NUL -w "%{http_code}" -X POST $using:u `
        -H "Content-Type: application/json" --data "@$using:PWD\iki.json" }
$j2 = Start-Job { curl.exe -s -o NUL -w "%{http_code}" -X POST $using:u `
        -H "Content-Type: application/json" --data "@$using:PWD\iki.json" }
Receive-Job $j1, $j2 -Wait
```

| Sonuç | Anlam |
|---|---|
| Biri `201`, diğeri `403` | ✅ `lockForUpdate()` çalışıyor |
| İkisi de `201` | ❌ Yarış koşulu açık — kota 5 iken 6 kişi yazıldı |

Doğrula:

```powershell
php artisan tinker --execute="echo App\Models\Invitation::find('<yarisId>')->rsvps()->sum('guest_count');"
```

**Beklenen:** `6` (4 + 2), **`8` değil**.

> ⚠️ Yarış koşulları doğaları gereği her koşuda tetiklenmez. İkisi de `201`
> dönerse birkaç kez tekrarla; tutarlı olarak `201/201` alıyorsan kilit
> çalışmıyordur.

---

## 16. Mutasyon denemesi ve frontend kontrolü

### 16.1 En az beş mutasyon (T16)

[`../tests/Feature/RsvpTest.md`](../tests/Feature/RsvpTest.md) §3'teki 18
satırlık tablodan **en az beşini** dene. Her biri için:

1. Kodu boz
2. `php artisan test --filter=RsvpTest`
3. Belirtilen testin **kırıldığını** gör
4. `git checkout -- <dosya>` ile geri al

🔴 Kırılmayan bir satır bulursan **dur ve testi incele**. Faz 4'ün 34. dersi:
üç IDOR testi `404` bekliyordu ve `404` alıyordu — ama Policy'den değil,
eşleşmeyen rotadan.

Önerilen beş: tablo satırları **1, 6, 8, 13, 15**.

### 16.2 Frontend honeypot kontrolü

Backend testleri honeypot alanını **kendileri gönderiyor**, yani frontend'de
alan yoksa hiçbir test bunu söylemez.

Frontend uyarlaması ([`FAZ-5.md`](FAZ-5.md) §8) yapıldıktan sonra:

1. Misafir sayfasını aç, LCV formunu doldur, gönder → kayıt düşmeli
2. Tarayıcı DevTools → Network → istek gövdesinde `website` alanı **olmalı**
   (boş)
3. DevTools → Elements → `input[name="website"]` **görünmez** olmalı
4. DevTools'tan o alana bir değer yaz ve gönder → `201` dönmeli ama
   **kayıt düşmemeli**

---

## ✅ Bittiğinde

[`FAZ-5.md`](FAZ-5.md) §11'deki kapanış listesini işaretle ve **durum alanını
güncelle** (B7):

```
> **Durum:** ✅ Tamamlandı · <tarih> · composer check yeşil (76 test)
```

Bir şey kırıldıysa **durum alanını değiştirme** — kırılanı not al. Bu projede
"yeşil" yazmak, yeşil görmekle aynı şey değildir.
