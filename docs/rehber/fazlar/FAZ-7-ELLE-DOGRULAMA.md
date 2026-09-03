# FAZ 7 — Elle Doğrulama Betiği

> **20 adım.** Faz 7 bu betik yeşil bitene kadar **kapanmamıştır**.
> **Süre:** ~50 dakika
> 🔴 **Adım 0, 3, 12, 15 ve 17 kritik** — hiçbir otomatik test onları kapatmıyor.

---

## 0. Neden bu dosya var?

`composer check` Faz 7'de **hiç koşmadı** (`FAZ-7.md` §0). Ayrıca bazı şeyler
otomatik testle **doğrulanamaz**:

| Doğrulanamayan | Neden |
|---|---|
| Eşzamanlı iki webhook (kilit) | **T15** ailesi — tek süreçli testte kurulamaz |
| Eşzamanlı iki yayın isteği | Aynı |
| `hash_equals` yerine `===` kullanılsa | Zamanlama saldırısı ölçülemez |
| Gerçek bir tarayıcıdan paywall akışı | Backend testi frontend'i bilmez |
| `errors:export` çıktısının bayt bayt eşleşmesi | Katalog **elle** düzenlendi 🔴 |
| PostgreSQL'in CHECK kısıtlarını kabul etmesi | Testler kısıtı değil davranışı sınar |

---

## 🔴 Adım 0 — Faz 6'nın kapanış listesi

Faz 6 **hâlâ kapanmadı**. Önce onu bitir:

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel

php artisan storage:link      # 🔴 hiç çalıştırılmadı; onsuz her medya URL'i 404
dir public\storage
```

**Beklenen:** `public\storage` → `storage\app\public` sembolik bağı.
Windows'ta **yönetici hakları** isteyebilir.

Sonra `FAZ-6.md` §11'deki listeyi işaretle. Bu adım atlanırsa Faz 7'nin
doğrulaması Faz 6'nın hatalarını da üzerine alır.

---

## 1. Ortam ve dal

```powershell
git log --oneline -1          # 7.25 görünmeli
git status --short            # temiz olmalı
```

---

## 2. Şema

```powershell
php artisan migrate
```

pgAdmin'de:

```sql
\d orders
-- id char(26) PK · user_id · invitation_id (nullable) · tier · status
-- amount_minor · currency · provider · provider_ref UNIQUE
-- paid_at · expires_at · created_at · updated_at
-- INDEX (user_id, status) · INDEX (invitation_id, status)
-- CHECK orders_tier_check · orders_status_check
-- CHECK orders_amount_minor_check · orders_paid_at_check

\d invitations
-- timezone varchar(64) NULL
```

**Beklenen:** dört CHECK, bir UNIQUE, iki indeks ve yeni kolon yerinde.

---

## 3. 🔴 Katalog senkronizasyonu — bu adım atlanırsa `composer check` kırılır

`ErrorCode::allowedParams()` değişti ve `contracts/error-codes.json` **elle**
düzenlendi. Komutun ürettiğiyle bayt bayt aynı olmayabilir:

```powershell
php artisan errors:export
git diff contracts/error-codes.json
```

**Beklenen:** `generatedAt` dışında **hiçbir fark yok**.

Fark varsa: komutun ürettiği hâli commit et (kaynak enum'dur, dosya değil).

```powershell
php artisan errors:export --check
```

**Beklenen:** `Katalog guncel.`

> 🔴 **K34**: bu kontrol `composer check` zincirinde **testlerden önce**
> koşuyor. Kırılırsa testler **hiç çalışmaz** ve "testlerim yeşil" sanısı
> doğar (fail fast).

---

## 4. Kalite kapısı

```powershell
composer lint      # Pint DÜZELTİR
composer check     # 🔴 SON satıra bak
```

Zincir (fail-fast): `pint --test` → `phpstan` → `errors:export --check` →
`php artisan test`

**Beklenen son satır:** `Tests: 156 passed`

> `composer check` içindeki `pint --test` **düzeltmez**, sadece bakar (ders 12).
> Kırılırsa `composer lint` çalıştır, sonra `composer check`'i tekrarla.

Sadece bu fazın testleri:

```powershell
php artisan test --filter=PaywallTest      # 33 passed
```

---

## 5. Sunucu ve kimlik

```powershell
php artisan serve
```

Yeni bir PowerShell'de:

```powershell
$base = "http://127.0.0.1:8000/api"
$h = @{ "Accept" = "application/json"; "Content-Type" = "application/json" }

$reg = Invoke-RestMethod "$base/auth/register" -Method Post -Headers $h -Body (@{
  firstName="Test"; lastName="Kullanici"; email="faz7@test.com"; password="sifre1234"
} | ConvertTo-Json)

$token = $reg.token
$auth = $h + @{ "Authorization" = "Bearer $token" }
```

---

## 6. Galerili bir davetiye oluştur (Elit gerektirir)

```powershell
$inv = Invoke-RestMethod "$base/invitations" -Method Post -Headers $auth -Body (@{
  invitation = @{
    categoryId="dugun"; imageTheme="moda-gece"; palette="midnight"
    title="Faz 7 Testi"; showGallery=$true; showRSVP=$true
    timezone="Europe/Istanbul"
  }
} | ConvertTo-Json -Depth 5)

$id = $inv.data.id
$id
```

**Beklenen:** `data.status` = `saved`, `data.invitation.timezone` =
`Europe/Istanbul`.

---

## 7. Ödemesiz yayın denemesi → 402 `PAYMENT_REQUIRED`

```powershell
try {
  Invoke-RestMethod "$base/invitations/$id/publish" -Method Post -Headers $auth
} catch { $_.ErrorDetails.Message }
```

**Beklenen:**

```json
{"error":{"code":"PAYMENT_REQUIRED","params":{"requiredTier":"elit"}}}
```

Durum **402**. Davetiye hâlâ `saved`.

🔴 Bu, fazın **bitti ölçütünün ilk yarısıdır** (`docs/09`).

---

## 8. Yetersiz planla checkout → 402 `PAYWALL_TIER_INSUFFICIENT`

```powershell
try {
  Invoke-RestMethod "$base/invitations/$id/checkout" -Method Post -Headers $auth `
    -Body (@{ tier="standart" } | ConvertTo-Json)
} catch { $_.ErrorDetails.Message }
```

**Beklenen:** `402`, kod `PAYWALL_TIER_INSUFFICIENT`, `requiredTier: "elit"`.
**Ve sipariş oluşmamış olmalı** (`SELECT count(*) FROM orders;` → 0).

---

## 9. Doğru planla checkout → 201

```powershell
$order = Invoke-RestMethod "$base/invitations/$id/checkout" -Method Post -Headers $auth `
  -Body (@{ tier="elit"; price=1 } | ConvertTo-Json)

$order.data
```

**Beklenen:**

```json
{ "orderId": "01J…", "tier": "elit", "status": "pending", "redirectUrl": "/odeme/basarili?order=01J…" }
```

🔴 **Yanıtta `providerRef`, `provider` veya `amountMinor` OLMAMALI.**

pgAdmin'de:

```sql
SELECT tier, status, amount_minor, currency, provider, provider_ref, paid_at
FROM orders;
```

**Beklenen:** `amount_minor = 54900` — 🔴 gövdedeki `price=1` **yok sayıldı**.
`paid_at` NULL, `provider_ref` `fake_…` ile başlıyor.

---

## 10. Ödenmemiş siparişle yayın hâlâ reddediliyor

```powershell
try { Invoke-RestMethod "$base/invitations/$id/publish" -Method Post -Headers $auth }
catch { $_.ErrorDetails.Message }
```

**Beklenen:** yine `402 PAYMENT_REQUIRED` — sipariş `pending`, hak vermiyor.

---

## 11. Webhook: imzasız istek → 404

```powershell
$ref = (Invoke-Sqlcmd ...)   # ya da pgAdmin'den kopyala
$ref = "<orders.provider_ref değeri>"

$body = "{`"providerRef`":`"$ref`",`"status`":`"paid`"}"

try {
  Invoke-RestMethod "$base/public/payments/webhook" -Method Post `
    -Headers @{ "Accept"="application/json"; "Content-Type"="application/json" } -Body $body
} catch { $_.ErrorDetails.Message }
```

**Beklenen:** `404` + `{"error":{"code":"RESOURCE_NOT_FOUND"}}`
🔴 **401 veya 403 DEĞİL.** Sipariş hâlâ `pending`.

---

## 12. 🔴 Webhook: imzalı istek → 204

İmzayı tinker ile üret (ham gövde birebir aynı olmalı):

```powershell
php artisan tinker
```

```php
$ref = 'fake_…';                       // orders.provider_ref
$body = '{"providerRef":"'.$ref.'","status":"paid"}';
hash_hmac('sha256', $body, config('app.key'));
```

```powershell
$sig = "<tinker çıktısı>"

Invoke-WebRequest "$base/public/payments/webhook" -Method Post `
  -Headers @{ "Accept"="application/json"; "Content-Type"="application/json"; "X-Signature"=$sig } `
  -Body $body
```

**Beklenen:** `204 No Content`.

```sql
SELECT status, paid_at FROM orders;
-- paid | <zaman damgası>
```

🔴 **`invitations.status` hâlâ `saved` olmalı** — ödeme yayınlamaz (K67):

```sql
SELECT status, published_at FROM invitations;
-- saved | NULL
```

---

## 13. 🔴 İdempotans — aynı webhook ikinci kez

`paid_at` değerini not al, **1 dakika bekle**, aynı isteği tekrar gönder.

**Beklenen:** yine `204`, ve:

```sql
SELECT count(*), status, paid_at FROM orders GROUP BY status, paid_at;
-- 1 satır, paid_at DEĞİŞMEMİŞ
```

🔴 Damga değiştiyse `OrderStatus::canTransitionTo()` bozuk demektir.

---

## 14. Ödenmiş siparişle yayın → 200

```powershell
$pub = Invoke-RestMethod "$base/invitations/$id/publish" -Method Post -Headers $auth
$pub.data.status
```

**Beklenen:** `published`. Ve:

```sql
SELECT status, published_at FROM invitations;
-- published | <zaman damgası>
```

🔴 Bu, fazın **bitti ölçütünün ikinci yarısıdır**.

---

## 15. 🔴 İkinci yayın → 409

```powershell
try { Invoke-RestMethod "$base/invitations/$id/publish" -Method Post -Headers $auth }
catch { $_.ErrorDetails.Message }
```

**Beklenen:** `409` + `{"error":{"code":"INVITATION_ALREADY_PUBLISHED"}}`

Sessizce `200` dönüyorsa K68 ihlal edilmiş demektir.

---

## 16. Uçtan uca: misafir davetiyeyi görüyor mu?

```powershell
Invoke-RestMethod "$base/public/invitations/$id"
```

**Beklenen:** `200`, `data.invitation.timezone` = `Europe/Istanbul`,
`data.invitation.galleryImages` alanı **var** (galeri açık).

Tarayıcıda: `http://localhost:5173/invite/<id>` (frontend çalışıyorsa).

---

## 17. 🔴 K63 — saat dilimi gerçekten işe yarıyor mu?

Bu adımın otomatik karşılığı var (`PaywallTest`), ama **davranışı gözle
görmek** ayrı bir doğrulamadır.

```php
// php artisan tinker
use App\Models\Invitation;
use App\Actions\Rsvp\ResolveOpenRsvpInvitationAction;

$a = Invitation::factory()->published()->create([
    'show_rsvp' => true,
    'timezone' => 'Pacific/Kiritimati',       // UTC+14
    'rsvp_deadline' => now()->subDay()->toDateString(),
]);

$b = Invitation::factory()->published()->create([
    'show_rsvp' => true,
    'timezone' => 'Pacific/Niue',             // UTC-11
    'rsvp_deadline' => now()->toDateString(),
]);

$r = app(ResolveOpenRsvpInvitationAction::class);

$r->handle($a->id);   // 🔴 RsvpDeadlinePassedException bekleniyor
$r->handle($b->id);   // sorunsuz dönmeli
```

Ve misafir sürümü saat dilimini **her zaman** taşımalı:

```php
$c = Invitation::factory()->published()->create(['timezone' => null]);
```

```powershell
(Invoke-RestMethod "$base/public/invitations/<c-id>").data.invitation.timezone
# Europe/Istanbul  (config varsayılanı) — BOŞ DİZE DEĞİL
```

---

## 18. Paket alım (K42'nin ikinci kolu)

```powershell
$pkg = Invoke-RestMethod "$base/payments/checkout" -Method Post -Headers $auth `
  -Body (@{ tier="gold" } | ConvertTo-Json)
```

```sql
SELECT tier, invitation_id FROM orders WHERE invitation_id IS NULL;
-- gold | NULL
```

Bu siparişi webhook ile `paid` yap (adım 12'nin aynısı), sonra **yeni** bir
davetiye oluşturup (galerisiz, zaman çizelgeli → Gold gerekir) yayınla.

**Beklenen:** `200` — tekil bir sipariş olmadan, **paket** sayesinde yayınlandı.

🔴 Bu adım K42'nin ikinci kolunun gerçekten çalıştığını kanıtlar.

---

## 19. IDOR: başkasının davetiyesi

İkinci bir kullanıcı kaydet ve onun token'ıyla dene:

```powershell
try { Invoke-RestMethod "$base/invitations/$id/publish" -Method Post -Headers $auth2 }
catch { $_.ErrorDetails.Message }
```

**Beklenen:** `404 RESOURCE_NOT_FOUND` — **403 DEĞİL** (H7).

Aynısını `$base/invitations/$id/checkout` için de dene.

---

## 20. 🔴 Mutasyon denemeleri (T16 — en az 5 satır)

`docs/rehber/tests/Feature/PaywallTest.md` §7'deki tablodan en az beşini dene.
Önerilen beşli (her biri farklı bir katmanı sınar):

| # | Mutasyon | Kırılması gereken test |
|---|---|---|
| 9 | `PublishInvitationAction`'daki `covers()` kontrolünü sil | `a_gold_order_cannot_publish_a_gallery_invitation` |
| 15 | `amount_minor` → `$request->input('price')` | `the_order_amount_comes_from_the_server_side_price` |
| 19 | `hash_equals(...)` → `true` | `the_webhook_rejects_an_invalid_signature` |
| 1 | `canTransitionTo()`'da `Pending` kolunu `true` yap | `the_same_webhook_twice_does_not_move_paid_at` |
| 28 | `CarbonImmutable::now($timezone)` → `now()` | `the_rsvp_deadline_is_evaluated_in_the_invitation_timezone` |

Her mutasyondan sonra `git checkout -- <dosya>` ile geri al.

🔴 **Kırılmayan bir satır bulursan testte bir boşluk var demektir** — ve o
boşluk `PaywallTest.md` §7.1'de zaten kayıtlı değilse **yeni** bir boşluktur.

---

## Kapanış

Buraya kadar her adım beklendiği gibi bittiyse:

1. `FAZ-7.md` §12 kapanış listesini işaretle
2. `FAZ-7.md` **durum alanını** güncelle (**B7**): *"25/25 adım tamamlandı ve
   doğrulandı"*
3. `claude/PHP-LARAVEL-SETUP-EK-FAZ-7.md`'yi master dosyaya işle
4. §9'daki **6 açık kararı** cevapla — özellikle 1. madde (paket alım kaç yayın
   açar) ticari bir açık kapı
5. Frontend uyarlamasına geç (`FAZ-7.md` §8)

Ancak ondan sonra **Faz 8**.
