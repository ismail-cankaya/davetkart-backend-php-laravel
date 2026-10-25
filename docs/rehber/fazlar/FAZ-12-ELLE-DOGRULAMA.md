# FAZ 12 — Elle Doğrulama Betiği (canlı Shopier)

> **Tarih:** 24 Ekim 2026 · **Adım sayısı:** 9 (+ Adım 0)
> **Neden var:** Testler sahte sır ve kendi hesapladığımız imzalarla koştu. **Gerçek
> Shopier'e hiçbir istek gitmedi.** İmza formülü, form alanları, callback ve OSB biçimi
> ancak burada, gerçek kartla sınanır. Shopier'in test ortamı yok (§A-f): ödemeler gerçek.
> **Ortam:** Üretim sunucusu (`deploy.sh`) + Shopier paneli + gerçek kart (en ucuz plan)

Her adımın sonucunu tabloya yaz. "Beklenen" ile "gözlenen" farklıysa **gözleneni** yaz
(B7). Gözlenmeyen bir şeye ✅ konmaz.

---

## Adım 0 — Kapı (yerel makine)

```powershell
cd D:\Projects\davetkart\davetkart-backend-php-laravel
git switch faz-12-shopier
php artisan migrate
composer check
```

Beklenen: `pint passed` · `phpstan 0` · `Katalog guncel` · `tests: 781 passed`.

---

## Adım 1 — Deploy ve `.env`

Sunucuda `deploy.sh`. Sonra `.env`:

```dotenv
PAYMENT_PROVIDER=shopier
SHOPIER_API_KEY=…            # panelden
SHOPIER_API_SECRET=…         # panelden — ekran görüntüsüne, sohbete, repoya GİRMEZ
SHOPIER_WEBSITE_INDEX=1
SHOPIER_CONFIRMATION=osb
SHOPIER_OSB_USERNAME=…
SHOPIER_OSB_KEY=…
PAYMENT_SUCCESS_URL=https://davetkart.com/odeme/basarili
PAYMENT_FAILURE_URL=https://davetkart.com/odeme/hata
```

```bash
php artisan config:cache     # 🔴 unutulursa eski config okunur
php artisan migrate --force
```

**Kontrol:** giriş yapmış bir kullanıcıyla checkout'u başlat (DevTools → Network) →
yanıtta `data.checkout.type = "form"` ve `fields` var, `API_SECRET` **yok**. 503
geliyorsa bir sır ya da OSB kimliği eksik (K123).

---

## Adım 2 — Panel adresleri

Shopier panelinde:

| Alan | Değer |
|---|---|
| Geri dönüş (callback) | `https://api.davetkart.com/api/public/payments/shopier/callback` |
| OSB adresi | `https://api.davetkart.com/api/public/payments/shopier/osb` |

Menü yolunu §A-a / §A-c satırına yaz.

---

## Adım 3 — Mutlu yol

1. Standart plan → "Güvenli ödeme sayfasına geç" → Shopier sayfası açılıyor mu?
   **Açılmıyor ve "imza hatası" diyorsa** formül (K94) yanlış: dur, Shopier'in hata
   metnini kaydet.
2. Gerçek kartla öde → `/odeme/basarili?order=…` → "doğrulanıyor" → (OSB gelince) "onaylandı".
3. Veritabanı:

```sql
SELECT id, status, paid_at, provider, provider_ref, provider_payment_id, amount_minor
FROM orders ORDER BY created_at DESC LIMIT 1;
```

Beklenen: `paid`, `paid_at` dolu, `provider_ref = id`, `provider_payment_id` dolu.
4. Davetiyeli siparişte sayfa davetiyeyi **kendiliğinden** yayına alıyor mu (frontend 11.6, `useAutoPublish`)? "Davetiyeniz yayında" + `invitations.status = published`.

---

## Adım 4 — %50 hediye koduyla

`gift-codes:create` ile %50'lik kod → checkout'ta uygula → Shopier sayfasındaki tutar
**indirimli** mi? (199,50 ₺ gibi). Sonra `amount_minor` ve OSB ile `paid` mi?

---

## Adım 5 — Reddedilen kart (§A-d)

Bilerek reddedilecek bir kartla dene (yetersiz bakiye). Sunucu log'u ve Nginx erişim
log'u: callback adresine **POST geldi mi?**

```bash
grep "shopier/callback" /var/log/nginx/access.log | tail -5
```

Sonucu §A-d'ye yaz. **Gövdeyi kaydet** (6. adım için): DevTools → Network → callback
isteği → Payload.

---

## Adım 6 — 🔴 Çevrilmiş durum saldırısı

5. adımdaki **imzalı** gövdeyi yalnızca `status=failed` → `status=success` yaparak tekrar gönder:

```bash
curl -i -X POST https://api.davetkart.com/api/public/payments/shopier/callback \
  --data-urlencode "platform_order_id=<5. adımdaki>" \
  --data-urlencode "random_nr=<5. adımdaki>" \
  --data-urlencode "signature=<5. adımdaki>" \
  --data-urlencode "status=success"
```

Beklenen: `303` → `/odeme/basarili?order=…`, ama sipariş **`pending` kalır** (`osb` modu).
`paid` olduysa: **DUR**, `SHOPIER_CONFIRMATION`'ı kontrol et, K117 çiğnendi.

---

## Adım 7 — 🔴 OSB tek başına çalışıyor mu? (eşleme)

Ödemeyi tamamla, Shopier "başarılı" der demez **sekmeyi kapat** (callback hiç gelmesin).
Birkaç dakika bekle:

```sql
SELECT status, provider_payment_id FROM orders WHERE id = '<sipariş>';
```

```bash
grep -E "Shopier OSB|amount or currency mismatch" storage/logs/laravel-*.log | tail
```

| Gözlem | Anlamı |
|---|---|
| `paid` | OSB geliyor ve `platform_order_id` ile eşleşiyor ✅ |
| `pending` + `Shopier OSB could not be matched` | OSB geliyor ama bizim kimliğimizi taşımıyor → `matchOsbToOrder` değişmeli. Log'daki `shopier_order_id`'yi ve OSB gövdesinin alanlarını kaydet |
| `pending` + `amount or currency mismatch` | `price`/`currency` biçimi farklı → log'daki `notified_*` değerleri |
| `pending` + log yok | OSB hiç gelmiyor (panel ayarı? API ödemelerinde OSB yok mu? §A-c) |

---

## Adım 8 — İade

Panelden 3. adımdaki ödemeyi iade et. Bize bir bildirim geliyor mu (log, `orders.status`)?
Gelmiyorsa: davetiye yayında kalır (K100 tetiklenmez). §15'e *"`orders:refund` komutu
gerekli"* yazılır.

---

## Adım 9 — Karar

Sonuçlara göre `SHOPIER_CONFIRMATION` kesinleşir ve K94 güncellenir:

| 5. adım | 6. adım | 7. adım | Karar |
|---|---|---|---|
| — | `pending` ✅ | `paid` ✅ | `osb` kalır. **Önerilen** |
| Başarısızda callback **gelmiyor** | — | OSB yok | `callback` tartışılabilir (K117'nin ikinci koşulu) |
| Başarısızda callback geliyor | — | OSB yok | 🔴 Güvenli bir kanal yok: canlıya çıkılmaz, Shopier'e sorulur |

## Sonuç tablosu

| Adım | Beklenen | Gözlenen | ✅/❌ |
|---|---|---|---|
| 0 | 781 passed | | |
| 1 | `checkout.type=form`, sır yok | | |
| 2 | Panel adresleri kaydedildi | | |
| 3 | `paid` + `provider_payment_id` | | |
| 4 | Shopier'de indirimli tutar | | |
| 5 | Callback geldi mi? | | |
| 6 | `pending` kaldı | | |
| 7 | OSB ile `paid` | | |
| 8 | İade bildirimi? | | |
| 9 | `SHOPIER_CONFIRMATION=` | | |
