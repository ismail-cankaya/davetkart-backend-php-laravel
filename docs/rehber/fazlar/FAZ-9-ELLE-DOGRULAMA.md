# FAZ 9 — Elle Doğrulama Betiği

> **Tarih:** 11 Eylül 2026 · **Adım sayısı:** 22
> **Neden var:** Faz 9 bir **ortam** fazıdır ve `composer check` bu fazın
> değiştirdiği hiçbir şeyi göremez: testler `array` cache, `sync` kuyruk ve
> `local` disk ile koşar. Aşağıdaki her adım *"çalıştı"* değil **"şunu koş,
> şunu gör"** biçimindedir.
> **Ortam:** Windows + Laravel Herd + PostgreSQL 18

---

## Adım 0 — Kapı

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
php artisan migrate
composer check
```

🔴 **Son satıra bak.** Beklenen: `Pint` PASS · `PHPStan 0 hata` ·
`Katalog guncel` · `Tests: 245 passed`.

> 9.8–9.14 arası yedi adım kapı koşmadan yazıldı. Bu adım kırmızı gelirse
> **normaldir**; çıktıyı olduğu gibi kaydet, hangi adımdan geldiğini birlikte
> ayıralım.

---

## Adım 1 — `route:cache` gerçekten çalışıyor mu? (9.1)

```powershell
php artisan route:cache
php artisan route:list
curl.exe -i http://127.0.0.1:8000/api/ping    # 200
curl.exe -i http://127.0.0.1:8000/up          # 200
curl.exe -i http://127.0.0.1:8000/            # 404 (beklenen)
php artisan route:clear
```

🔴 **Görmen gereken:** `route:cache` hatasız bitiyor **ve** sonrasında
`/api/*` hâlâ cevap veriyor. Cache üretildikten sonra rota dosyası okunmaz;
bir uç kaybolursa sorun serileştirmededir.

---

## Adım 2 — Temiz klon tatbikatı (B10)

```powershell
cd $env:TEMP
git clone -b faz-9 D:\Projects\davetkart\davetkart-backend-php-laravel dk-temiz
cd dk-temiz
composer install
Copy-Item .env.example .env       # 🔴 ŞABLON kopyalanıyor, gerçek .env değil
php artisan key:generate
# .env içinde DB_PASSWORD'ü ve DB_DATABASE=davetkart_test'i doldur
composer check
```

🔴 **Görmen gereken:** yerelle **birebir aynı** sonuç. Fark çıkarsa, fark
sürümlenmemiş bir şeyin adıdır.

Bu tatbikat iki soruyu birden sınıyor: depo taşınabilir mi **ve**
`.env.example` eksiksiz mi.

---

## Adım 3 — `scope` kolonu ve iki CHECK (9.3)

pgAdmin 4 → Query Tool:

```sql
SELECT scope, invitation_id IS NULL AS bos, count(*) FROM orders GROUP BY 1,2;
```

Beklenen: yalnızca `('account', true)` ve `('invitation', false)` satırları.

```sql
-- Geçersiz kapsam
UPDATE orders SET scope = 'paket' WHERE id = (SELECT id FROM orders LIMIT 1);
-- ERROR: ... "orders_scope_check"

-- Paket + davetiye birlikte
UPDATE orders SET scope = 'account'
WHERE id = (SELECT id FROM orders WHERE invitation_id IS NOT NULL LIMIT 1);
-- ERROR: ... "orders_account_scope_has_no_invitation_check"

-- 🔴 NOT NULL gerçekten kuruldu mu? (9.5)
UPDATE orders SET scope = NULL WHERE id = (SELECT id FROM orders LIMIT 1);
-- ERROR: null value in column "scope" ... violates not-null constraint
```

🔴 Üçüncüsü **9.3 aşamasında `UPDATE 1` diyordu**. Aradaki fark, CHECK ile
`NOT NULL` arasındaki farkın kendisi: CHECK bir satırı yalnızca sonuç `FALSE`
olduğunda reddeder, `NULL` (bilinmiyor) geçer.

---

## Adım 4 — Serbest bırakma, pencere AÇIK (9.7)

```php
php artisan tinker
>>> use App\Models\{Invitation, Order};
>>> use App\Actions\Invitation\DeleteInvitationAction;
>>> $inv = Invitation::factory()->create(['status' => 'published', 'published_at' => now()->subDay()]);
>>> $o = Order::factory()->paid()->forInvitation($inv)->create();
>>> app(DeleteInvitationAction::class)->handle($inv);
>>> $o->refresh();
>>> $o->invitation_id;   // null            ← serbest
>>> $o->scope;           // OrderScope::Invitation  ← 🔴 DEĞİŞMEDİ
```

🔴 İkinci satır bu fazın tamamının özeti. `scope` `'account'` olsaydı sipariş
pakete dönüşür ve hesabın **tüm** davetiyelerini açardı.

---

## Adım 5 — Serbest bırakma, pencere KAPALI

```php
>>> $inv2 = Invitation::factory()->create(['status' => 'published', 'published_at' => now()->subDays(5)]);
>>> $o2 = Order::factory()->paid()->forInvitation($inv2)->create();
>>> app(DeleteInvitationAction::class)->handle($inv2);
>>> $o2->refresh()->invitation_id;   // hâlâ $inv2->id   ← hak yandı
```

---

## Adım 6 — Serbest hak yalnızca BİR davetiye açar (9.8)

Bu fazın en önemli elle doğrulaması. Tarayıcı ya da curl ile:

1. Kullanıcı oluştur, giriş yap, token al.
2. İki davetiye oluştur (`A` ve `B`).
3. Tinker'dan bir serbest sipariş üret:
   ```php
   >>> Order::factory()->paid()->released()->create(['user_id' => <id>]);
   ```
4. `POST /api/invitations/A/publish` → **200**
5. `POST /api/invitations/B/publish` → 🔴 **402** `PAYMENT_REQUIRED`

🔴 İkinci istek 200 dönerse delik **açık** demektir.

---

## Adım 7 — Yeten en düşük harcanıyor mu? (K83)

```php
>>> $ucuz  = Order::factory()->paid()->tier(App\Enums\SubscriptionTier::Standart)->released()->create(['user_id' => $u->id]);
>>> $pahali = Order::factory()->paid()->tier(App\Enums\SubscriptionTier::Elit)->released()->create(['user_id' => $u->id]);
```

Sade bir davetiye yayınla (Standart yeter), sonra:

```php
>>> $ucuz->refresh()->invitation_id;    // dolu   ← harcandı
>>> $pahali->refresh()->invitation_id;  // null   ← dokunulmadı
```

---

## Adım 8 — `orders:expire` (9.9)

```powershell
php artisan tinker
>>> App\Models\Order::factory()->create(['expires_at' => now()->subDay()]);
>>> exit

php artisan orders:expire --dry-run    # "1 siparis suresi dolmus gorunuyor"
php artisan orders:expire              # "1 siparis failed isaretlendi."
php artisan orders:expire              # "0 siparis failed isaretlendi."
```

🔴 Üçüncü koşu **0** demeli. Bir bakım komutunun idempotan olması,
zamanlayıcıdan güvenle koşmasının önkoşuludur.

---

## Adım 9 — `orders:expire` ödenmiş siparişe dokunmuyor

```php
>>> $p = App\Models\Order::factory()->paid()->create(['expires_at' => now()->subDay()]);
>>> exit
php artisan orders:expire
php artisan tinker
>>> $p->refresh()->status;   // OrderStatus::Paid   ← değişmedi
```

---

## Adım 10 — `media:prune-orphans` (9.10)

```php
php artisan tinker
>>> $m = App\Models\Media::factory()->rsvpPhoto()->create(['created_at' => now()->subDays(2)]);
>>> Storage::disk($m->disk)->put($m->path, 'test-icerik');
>>> $m->path;
>>> exit

php artisan media:prune-orphans --dry-run   # "1 yetim yukleme bulundu"
php artisan media:prune-orphans             # "1 yetim yukleme silindi."
```

🔴 Şimdi **diskte** doğrula — testin göremediği şey bu:

```powershell
dir storage\app\public\media\rsvp_photo
```

Dosya gitmiş olmalı. `Storage::fake()` gerçek diski hiç görmez (Faz 6'nın
`storage:link` dersi).

---

## Adım 11 — Galeri medyasına dokunulmuyor

```php
>>> $g = App\Models\Media::factory()->create(['created_at' => now()->subYear()]);
>>> exit
php artisan media:prune-orphans
php artisan tinker
>>> App\Models\Media::find('<id>');   // 🔴 HÂLÂ ORADA
```

Galeri dosyası bir LCV yanıtına bağlanmaz — davetiyenin galerisinin
**kendisidir**. Silinseydi komut bütün galerileri süpürürdü.

---

## Adım 12 — Zamanlayıcı kayıtlı mı? (9.11)

```powershell
php artisan schedule:list
```

Üç satır görmelisin: `orders:expire` (saatlik) · `media:prune-orphans`
(03:15) · `sanctum:prune-expired` (günlük).

```powershell
php artisan schedule:test     # 🔴 bu fazın en faydalı komutu
```

Bir işi seç, hemen koşsun. **Bir bakım işini ilk kez zamanlayıcıdan görmek,
onu hiç görmemektir.**

---

## Adım 13 — Cron kurulumu (sunucuda)

```bash
crontab -e
* * * * * cd /var/www/davetkart && php artisan schedule:run >> /dev/null 2>&1
```

🔴 Ertesi gün **doğrula**:

```sql
SELECT count(*) FROM orders WHERE status = 'failed';
```

Sayı artmadıysa cron koşmuyor demektir — ve **hiçbir şey hata vermez**
(`FAZ-9.md` §8 madde 1).

---

## Adım 14 — CORS: izinli origin (9.12)

```powershell
curl.exe -i -H "Origin: http://localhost:5173" http://127.0.0.1:8000/api/ping
```

`Access-Control-Allow-Origin: http://localhost:5173` görmelisin.

---

## Adım 15 — CORS: yabancı origin

```powershell
curl.exe -i -H "Origin: https://kotu-site.example" http://127.0.0.1:8000/api/ping
```

🔴 `Access-Control-Allow-Origin` başlığı **GELMEMELİ**. Gelirse
`allowed_origins` hâlâ `['*']` demektir.

---

## Adım 16 — 🔴 ETag açığa çıkıyor mu? (Faz 4 ve 5 buna bağlı)

```powershell
curl.exe -i -H "Origin: http://localhost:5173" http://127.0.0.1:8000/api/public/invitations/<ULID>
```

`Access-Control-Expose-Headers: ETag` satırını ara.

En kesin kanıt tarayıcıda — frontend'in **gerçek** origin'inden, DevTools
konsolunda:

```js
const r = await fetch('http://127.0.0.1:8000/api/public/invitations/XXX');
r.headers.get('etag');    // 🔴 null dönerse exposed_headers eksik
```

`null` dönerse `If-None-Match` gönderilemez, 304 hiç gelmez ve K7'nin
polling optimizasyonu **sessizce** ölür.

---

## Adım 17 — Güvenlik başlıkları

```powershell
curl.exe -i http://127.0.0.1:8000/api/ping
```

Beş başlık: `X-Content-Type-Options: nosniff` · `X-Frame-Options: DENY` ·
`Referrer-Policy: no-referrer` · `X-XSS-Protection: 0` ·
`Content-Security-Policy: default-src 'none'; …`

🔴 `Strict-Transport-Security` **OLMAMALI** — istek `http`.

---

## Adım 18 — Başlıklar hata yolunda da var mı?

```powershell
curl.exe -i http://127.0.0.1:8000/api/boyle-bir-yol-yok
```

404 **ve** aynı başlıklar. Yoksa middleware `api` grubuna eklenmiş demektir —
ders 21: rota eşleşmezse grup middleware'i hiç çalışmaz.

---

## Adım 19 — `config:cache` ve sır dosyası

```powershell
php artisan config:cache
php artisan config:show payment.default      # 'fake'
curl.exe -i http://127.0.0.1:8000/api/ping   # 200 — hiçbir env() null'a düşmedi
php artisan config:clear
```

🔴 Cache üretildikten sonra `bootstrap/cache/config.php` dosyasını **aç ve
bak**: veritabanı parolası ve API anahtarları orada **düz metin**. Üretimde:

```bash
chmod 640 bootstrap/cache/config.php
```

---

## Adım 20 — `APP_DEBUG=false` ilk kez

`.env` içinde `APP_DEBUG=false` yap, `php artisan config:clear`, sonra bilerek
bir hata üret (örn. geçersiz gövdeyle bir uç).

🔴 Yanıtta `error.debug` bloğu **olmamalı**. Ayrıca `APP_ENV=production`
yaparsan `Model::shouldBeStrict(false)` devreye girer — N+1 koruması **kapanır**
(Faz 0'da bilinçli karar).

---

## Adım 21 — HTTPS (yalnızca üretimde)

```bash
curl -sI https://api.davetkart.com/api/ping | grep -i strict-transport
# strict-transport-security: max-age=31536000; includeSubDomains
```

🔴 Bu başlığın **varlığı** yalnızca gerçek bir HTTPS isteğiyle görülebilir;
hiçbir test onu göremez.

Ayrıca reverse proxy arkasındaysan `APP_URL=https://...` **ve** `TrustProxies`
ayarlı olmalı — değilse üretilen medya/ödeme URL'leri `http` kalır.

---

## Kapanış

| Adım | Konu | ✓ |
|---|---|:-:|
| 0 | `composer check` yeşil | ⬜ |
| 1 | `route:cache` | ⬜ |
| 2 | Temiz klon tatbikatı | ⬜ |
| 3 | `scope` + 2 CHECK + NOT NULL | ⬜ |
| 4–5 | Serbest bırakma penceresi | ⬜ |
| 6–7 | Yeniden bağlama + yeten en düşük | ⬜ |
| 8–9 | `orders:expire` | ⬜ |
| 10–11 | `media:prune-orphans` | ⬜ |
| 12–13 | Zamanlayıcı + cron | ⬜ |
| 14–16 | CORS + ETag | ⬜ |
| 17–18 | Güvenlik başlıkları | ⬜ |
| 19–20 | `config:cache` + `APP_DEBUG` | ⬜ |
| 21 | HTTPS / HSTS | ⬜ |

🔴 **Adım 6 ve Adım 16 atlanmamalı.** Birincisi bu fazın kapattığı deliğin,
ikincisi Faz 4 ve 5'in hayatta kalmasının kanıtı.
