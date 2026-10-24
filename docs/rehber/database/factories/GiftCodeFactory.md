# `database/factories/GiftCodeFactory.php` ve `GiftCodeRedemptionFactory.php`

> **Faz:** 11, adım 11.4

---

## 1. Varsayılan kod

`GiftCode::factory()->create()`: bütün planlarda **%20**, **10** kullanımlık, süresiz, açık.

Kod rastgele üretilir (`GiftCodeFormat::generate()`) ve **saklanmaz**. Fabrika da üretim kodu
gibi yalnızca özeti yazar (K109). Testin kodu bilmesi gerekiyorsa kendisi verir:

```php
GiftCode::factory()->withCode('DUGUN2026')->free(SubscriptionTier::Gold)->create();
$this->postJson(..., ['giftCode' => 'DUGUN2026']);
```

## 2. Durumlar (states)

| State | Değiştirdiği alanlar |
|---|---|
| `withCode($kod)` | `code_hash`, `code_hint` |
| `free($tier)` | `benefit=free`, `tier`, `percent_off=null`, `amount_off_minor=null` |
| `percent($n, ?$tier)` | `benefit=percent`, `percent_off`, `amount_off_minor=null` |
| `fixed($kuruş, ?$tier)` | `benefit=fixed`, `amount_off_minor`, `percent_off=null` |
| `maxUses($n)` | `max_redemptions` |
| `expired()` / `disabled()` | `expires_at` / `disabled_at` bir dakika önce |

🔴 **Alanlar birlikte değişir.** `free()` yalnızca `benefit`'i değiştirseydi varsayılandan
kalan `percent_off = 20` ile `gift_codes_percent_check` patlardı. CHECK kısıtı burada bir
engel değil bir öğretmen: fabrika da alanların tek bir olguyu anlattığını bilmek zorunda.
`OrderFactory::paid()` (status + paid_at) ile aynı desen.

## 3. `GiftCodeRedemptionFactory`

Varsayılan sipariş `pending`, yani kullanım **aktif**. Sahiplik siparişten taşınır:
`user_id` = siparişin `user_id`'si. Üretimde kullanım ile sipariş hep aynı kişinindir;
fabrikanın farklısını üretmesi testleri gerçekte olmayan bir duruma dayandırırdı.

```php
GiftCodeRedemption::factory()->for($code)->forOrder($order)->create();
```

İade olmuş bir kullanım için siparişi testin kendisi `failed` ya da `expired` yapar.

## 4. Sık yapılan hata

| Hata | Sonuç |
|---|---|
| `GiftCode::factory()->create(['benefit' => 'free'])` | CHECK hatası: `tier` yok, `percent_off` dolu |
| `withCode()` olmadan kodu checkout'a göndermek | Kod bilinmiyor → `GIFT_CODE_INVALID` |
