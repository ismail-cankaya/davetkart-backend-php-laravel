# `app/Console/Commands/ListGiftCodes.php` — `gift-codes:list`

> **Faz:** 11, adım 11.10
> **Test:** `tests/Feature/GiftCodeAdminCommandsTest.php`

---

## 1. Kullanım

```powershell
php artisan gift-codes:list                       # en yeni 50 kod
php artisan gift-codes:list --active              # yalnızca şu an kullanılabilenler
php artisan gift-codes:list --batch=01m5m334nn…   # bir toplu üretimin kodları
php artisan gift-codes:list --limit=200
```

```
+----------------------------+-------------------+-----------+-------+-----------+------------------+---------+
| Kimlik                     | Ipucu / etiket    | Fayda     | Plan  | Aktif/Max | Bitis            | Durum   |
| 01m5m333m57b7mnh6cegg6e0bm | …2026 · Instagram | %20       | hepsi | 2/50      | 2027-01-31 23:59 | aktif   |
| 01m5m39g3gh0jc8ehvdrhmvzdn | …T001             | 150,50 TL | elit  | 0/10      | suresiz          | kapali  |
| 01m5m2wqznk1bpx0t240n3v1av | …RIS1             | ucretsiz  | gold  | 1/1       | suresiz          | tukendi |
```

| Sütun | Kaynak |
|---|---|
| Ipucu | `code_hint`. Kodun kendisi gösterilemez çünkü saklanmıyor (K109) |
| Aktif/Max | Aktif kullanım sayısı (K110: `pending` + `paid` + `refunded`) / `max_redemptions` |
| Bitis | İstanbul saatiyle |
| Durum | Öncelik: `kapali` > `suresi doldu` > `tukendi` > `aktif` |

## 2. 🔴 N+1 yok

```php
GiftCode::query()->withActiveRedemptionCount()->latest()->get();
```

Sayım alt sorgu olarak aynı SELECT'in içinde. 50 kod için 51 değil **1** sorgu.
Test (`counting_runs_in_a_single_query...`) 15 kodla sorgu günlüğünü sayıyor: `gift_code`
geçen tek bir sorgu olmalı. Döngü içinde `$code->redemptions()->active()->count()` yazılsaydı
test 16 sorgu görürdü.

## 3. `--active` ve `--limit` sırası

"Tükenmemiş" koşulu sayıma bağlı. Sayım bir alt sorgu **sütunu** olduğu için PostgreSQL onu
`WHERE`'de kullanamaz (`SELECT` takma adı `WHERE`'den sonra hesaplanır). Bu yüzden:

1. Kapalı ve süresi dolmuş kodlar SQL'de elenir
2. Tükenmişler PHP'de süzülür
3. `--limit` **en sonda** uygulanır

Limit önce uygulansaydı en yeni 50 kodun hepsi tükenmiş olduğunda liste "Kod yok" derdi, oysa
daha eski aktif kodlar var (`the_limit_applies_after_the_active_filter`). Bedeli: `--active`
bütün açık kodları belleğe alır. Birkaç bin satırda sorun değil.

## 4. Neden Action yok?

Salt okunur, tek bir sorgu ve bir tablo. `ListContactMessages` ile aynı karar
(`PurgeExpiredData.md` §3.5). Okumanın tek tüketicisi bu komut.

## 5. Etiketteki kontrol karakterleri

`--label` operatörden geliyor ama bir gün başka bir yoldan da gelebilir. `\e[2J` ekranı
silerdi; `safe()` onu `\u001b[2J` olarak gösterir. `ListContactMessages::safe()` ile aynı.
