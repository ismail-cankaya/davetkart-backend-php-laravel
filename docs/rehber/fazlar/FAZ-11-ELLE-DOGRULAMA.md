# FAZ 11 — Elle Doğrulama Betiği

> **Tarih:** 23 Ekim 2026 · **Adım sayısı:** 16
> **Neden var:** `composer check` bulut çalışma alanında yeşil koştu ama **bu makinede
> koşmadı**. Ayrıca testlerin göremediği üç şey var: gerçek PostgreSQL 18'de kısıtların
> kurulması, komutların gerçek diske CSV yazması, uçtan uca HTTP akışı.
> **Ortam:** Windows + Laravel Herd + PostgreSQL 18

---

## Adım 0 — Kapı

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
git switch faz-11-hediye-kodu
php artisan migrate
composer check
```

🔴 **Son satıra bak.** Beklenen: `pint passed` · `phpstan 0` · `Katalog guncel` ·
`tests: 684 passed`. Kırmızıysa çıktıyı olduğu gibi kaydet.

---

## Adım 1 — Kısıtlar PostgreSQL 18'de kuruldu mu?

```powershell
psql -U postgres davetkart -c "\d gift_codes"
psql -U postgres davetkart -c "\d gift_code_redemptions"
psql -U postgres davetkart -c "SELECT conname FROM pg_constraint WHERE conrelid = 'orders'::regclass ORDER BY 1;"
```

**Görmen gereken:**
- `gift_codes`: altı CHECK (`benefit`, `tier`, `free_has_tier`, `percent`, `amount_off`, `max_redemptions`) + `code_hash` UNIQUE
- `gift_code_redemptions`: `order_id` UNIQUE, `discount_check`
- `orders`: `orders_amount_minor_check`, `orders_discount_minor_check`, `orders_gift_amount_check`

Kısıtı tetikle:

```powershell
psql -U postgres davetkart -c "UPDATE orders SET amount_minor = 0 WHERE provider <> 'gift';"
```

**Beklenen:** sipariş varsa `violates check constraint "orders_gift_amount_check"`. Tablo boşsa
`UPDATE 0`, bu adımı Adım 7'den sonra tekrar dene.

---

## Adım 2 — Sabit kod oluştur

```powershell
php artisan gift-codes:create dugun-2026 --benefit=percent --percent=50 --max-uses=3 --label="Elle test"
php artisan gift-codes:create HEDIYEGOLD --benefit=free --tier=gold --max-uses=5
```

**Beklenen:** iki `INFO Kod olusturuldu` tablosu. Kodun kendisi yazılmıyor, ipucu `…2026` ve `…GOLD`.

```powershell
psql -U postgres davetkart -c "SELECT code_hint, benefit, tier, percent_off, max_redemptions FROM gift_codes;"
psql -U postgres davetkart -c "SELECT count(*) FROM gift_codes WHERE code_hash LIKE '%DUGUN%' OR label LIKE '%DUGUN%';"
```

🔴 İkinci sorgu **0** dönmeli: düz kod hiçbir kolonda yok.

---

## Adım 3 — Hatalı seçenekler

```powershell
php artisan gift-codes:create DUGUN2026 --benefit=percent --percent=10
php artisan gift-codes:create ABCD --benefit=free
php artisan gift-codes:create ABCD --benefit=fixed --amount=1.005
php artisan gift-codes:create ABCD --benefit=percent --percent=10 --expires=2020-01-01
```

**Beklenen (sırayla):** `zaten var` · `--tier zorunlu` · `en fazla 2 ondalik` · `gecmiste kaliyor`.
Hiçbirinde satır yazılmaz.

---

## Adım 4 — Toplu üretim ve CSV

```powershell
php artisan gift-codes:generate --count=5 --benefit=fixed --amount=100 --label="CSV testi"
```

**Görmen gereken:**
- Konsolda **hiç** `DK-` ile başlayan kod yok
- `CSV: D:\...\storage\app\private\gift-codes\<batch>.csv` yolu
- Dosyayı aç: başlık + 5 satır, her kod `DK-XXXX-XXXX-XXXX`, `amount_off_minor` = `10000`

```powershell
php artisan gift-codes:list --batch=<batch>
```

5 satır, hepsi `0/1`, `100,00 TL`, `aktif`.

---

## Adım 5 — Sunucu ve kimlik

```powershell
php artisan serve
```

Yeni bir PowerShell'de:

```powershell
$base = "http://127.0.0.1:8000/api"
$h = @{ "Accept" = "application/json"; "Content-Type" = "application/json" }

$reg = Invoke-RestMethod "$base/auth/register" -Method Post -Headers $h -Body (@{
  firstName="Hediye"; lastName="Test"; email="faz11@test.com"; password="sifre1234"
} | ConvertTo-Json)

$auth = $h + @{ "Authorization" = "Bearer $($reg.token)" }
```

---

## Adım 6 — Önizleme

```powershell
Invoke-RestMethod "$base/gift-codes/preview" -Method Post -Headers $auth `
  -Body (@{ code="DUGUN 2026"; tier="gold" } | ConvertTo-Json) | ConvertTo-Json
```

**Beklenen:** `benefit: percent`, `priceMinor: 39900`, `discountMinor: 19950`, `totalMinor: 19950`,
`currency: TRY`. Boşluklu ve küçük harfli yazım da eşleşir.

```powershell
psql -U postgres davetkart -c "SELECT count(*) FROM gift_code_redemptions;"
```

🔴 **0**: önizleme hiçbir şey ayırmaz.

---

## Adım 7 — %50 kodla ödeme (fake sağlayıcı)

```powershell
$r = Invoke-RestMethod "$base/payments/checkout" -Method Post -Headers $auth `
  -Body (@{ tier="gold"; giftCode="dugun2026"; amount=1; discount=39900 } | ConvertTo-Json)
$r.data | ConvertTo-Json
```

**Beklenen:** `status: pending`, `discountMinor: 19950`, `redirectUrl` dolu.

```sql
SELECT provider, status, amount_minor, discount_minor FROM orders ORDER BY created_at DESC LIMIT 1;
-- fake | pending | 19950 | 19950
```

🔴 `amount=1` ve `discount=39900` **yok sayıldı**: tutar sunucuda hesaplandı.

Siparişi ödenmiş yap: `FAZ-7-ELLE-DOGRULAMA.md` Adım 12'deki imzalı webhook (`provider_ref`
bu siparişinki). Beklenen `204` ve `status = paid`.

---

## Adım 8 — Aynı kod, aynı kişi

```powershell
try {
  Invoke-RestMethod "$base/payments/checkout" -Method Post -Headers $auth `
    -Body (@{ tier="standart"; giftCode="DUGUN2026" } | ConvertTo-Json)
} catch { $_.ErrorDetails.Message }
```

**Beklenen:** `422` + `{"error":{"code":"GIFT_CODE_ALREADY_USED"}}`.

---

## Adım 9 — Ücretsiz kodla anında yayın hakkı

Gold gerektiren bir davetiye (zaman çizelgeli) oluştur:

```powershell
php artisan tinker
```

```php
$u = App\Models\User::where('email', 'faz11@test.com')->first();
$i = App\Models\Invitation::factory()->create(['user_id' => $u->id, 'show_timeline' => true]);
$i->id;
```

```powershell
$inv = "<tinker çıktısı>"

# Önce: ödeme yok → 402
try { Invoke-RestMethod "$base/invitations/$inv/publish" -Method Post -Headers $auth } catch { $_.ErrorDetails.Message }

# Ücretsiz kod
$r = Invoke-RestMethod "$base/invitations/$inv/checkout" -Method Post -Headers $auth `
  -Body (@{ tier="gold"; giftCode="HEDIYEGOLD" } | ConvertTo-Json)
$r.data | ConvertTo-Json

# Sonra: 200
Invoke-RestMethod "$base/invitations/$inv/publish" -Method Post -Headers $auth
```

**Beklenen:** ilk istek `402 PAYMENT_REQUIRED`; checkout `status: paid`, `discountMinor: 39900`,
`redirectUrl` = `/odeme/basarili?order=<id>`; yayın `200`.

```sql
SELECT provider, status, amount_minor, discount_minor, provider_ref, paid_at, expires_at
FROM orders WHERE provider = 'gift';
-- gift | paid | 0 | 39900 | gift_01… | <damga> | NULL
```

🔴 `php artisan serve` log'unda fake sağlayıcıya giden bir istek **yok**.

---

## Adım 10 — Yanlış plan

```powershell
php artisan gift-codes:create SADECEGOLD --benefit=free --tier=gold
try {
  Invoke-RestMethod "$base/gift-codes/preview" -Method Post -Headers $auth `
    -Body (@{ code="SADECEGOLD"; tier="elit" } | ConvertTo-Json)
} catch { $_.ErrorDetails.Message }
```

**Beklenen:** `422` + `GIFT_CODE_NOT_APPLICABLE` + `"params":{"tier":"gold"}`.

---

## Adım 11 — Dört geçersiz sebep, tek yanıt

```powershell
php artisan gift-codes:create KAPANACAK --benefit=percent --percent=10
php artisan gift-codes:list      # KAPANACAK'ın kimliğini al
php artisan gift-codes:disable <kimlik>

foreach ($c in @("YOKBOYLEKOD", "KAPANACAK")) {
  try {
    Invoke-RestMethod "$base/gift-codes/preview" -Method Post -Headers $auth `
      -Body (@{ code=$c; tier="gold" } | ConvertTo-Json)
  } catch { $_.ErrorDetails.Message }
}
```

🔴 **İki satır karakteri karakterine aynı olmalı**: `{"error":{"code":"GIFT_CODE_INVALID"}}`.
`.env`'de `APP_DEBUG=true` ise `debug` bloğu farklılaşabilir (satır numarası). Bu adımı
`APP_DEBUG=false` ile tekrarla.

---

## Adım 12 — Disable iki kez

```powershell
php artisan gift-codes:disable <aynı kimlik>
```

**Beklenen:** `Kod zaten kapali (<ilk tarih>)`. Tarih değişmedi.

---

## Adım 13 — Hız sınırı

```powershell
1..11 | ForEach-Object {
  try {
    Invoke-RestMethod "$base/gift-codes/preview" -Method Post -Headers $auth `
      -Body (@{ code="TAHMIN$_"; tier="gold" } | ConvertTo-Json) | Out-Null
  } catch { "$_ :: " + $_.ErrorDetails.Message }
}
```

**Beklenen:** ilk 10 `GIFT_CODE_INVALID`, 11. `429 RATE_LIMITED`. Hemen ardından **kodsuz**
bir checkout hâlâ `201` döner:

```powershell
Invoke-RestMethod "$base/payments/checkout" -Method Post -Headers $auth `
  -Body (@{ tier="standart" } | ConvertTo-Json) | Out-Null; "kodsuz checkout ok"
```

---

## Adım 14 — Süresi dolan ödeme kodu geri verir

```powershell
php artisan gift-codes:create GERIGELIR --benefit=percent --percent=10 --max-uses=1
Invoke-RestMethod "$base/payments/checkout" -Method Post -Headers $auth `
  -Body (@{ tier="gold"; giftCode="GERIGELIR" } | ConvertTo-Json) | Out-Null
php artisan gift-codes:list      # GERIGELIR: 1/1, tukendi
```

Siparişi süresi dolmuş say ve komutu koş:

```sql
UPDATE orders SET expires_at = now() - interval '1 minute'
WHERE status = 'pending' AND discount_minor > 0;
```

```powershell
php artisan orders:expire
php artisan gift-codes:list      # GERIGELIR: 0/1, aktif
```

---

## Adım 15 — 🔴 Canlıda (SSH)

Deploy (`deploy/deploy.sh`) migration'ı zaten koşar. Sonra:

```bash
ssh deploy@sunucu
cd /var/www/davetkart/backend

sudo -u www-data php artisan gift-codes:create DUGUN2026 --benefit=percent --percent=20 --max-uses=50 --expires=2026-12-31 --label="Instagram"
sudo -u www-data php artisan gift-codes:generate --count=20 --benefit=free --tier=standart --label="Fuar"
sudo -u www-data php artisan gift-codes:list --active
```

CSV'yi kendi bilgisayarına indir:

```powershell
scp deploy@sunucu:/var/www/davetkart/backend/storage/app/private/gift-codes/<batch>.csv .
```

`Permission denied` alırsan (dosyanın sahibi `www-data`):

```bash
sudo cp storage/app/private/gift-codes/<batch>.csv ~/ && sudo chown deploy ~/<batch>.csv
```

İndirdikten sonra **iki kopyayı da** sunucudan sil:

```bash
rm ~/<batch>.csv
sudo -u www-data rm storage/app/private/gift-codes/<batch>.csv
```

---

## Sonuç tablosu

| Adım | Sonuç | Not |
|---|---|---|
| 0 | ⬜ | |
| 1 | ⬜ | |
| 2 | ⬜ | |
| 3 | ⬜ | |
| 4 | ⬜ | |
| 5–6 | ⬜ | |
| 7 | ⬜ | |
| 8 | ⬜ | |
| 9 | ⬜ | |
| 10 | ⬜ | |
| 11 | ⬜ | |
| 12 | ⬜ | |
| 13 | ⬜ | |
| 14 | ⬜ | |
| 15 | ⬜ | canlıya alınınca |
