# `tests/Feature/TrustedProxiesTest.php`

> **Faz:** 10 — Sertleştirme, adım 10.18 · **7 test**
> **Test edilen:** [`config/trustedproxy.md`](../../config/trustedproxy.md)
> **Kurallar:** **T6** · **T14** (yanıta değil etkiye) · **T16** (mutasyon)

---

## 1. Neyi sınıyor?

Tek bir soruyu: **`$request->ip()` kimi gösteriyor?** Ve bu sorunun cevabının
neden önemli olduğunu: hız sınırı kovaları.

| Bölüm | Test | Ne kanıtlar |
|---|---|---|
| `ip()` | `forwarded_for_is_ignored_when_no_proxy_is_trusted` | 🔴 Varsayılan güvenli: sahte başlık hiçbir şey değiştirmez |
| | `a_trusted_proxy_passes_the_client_ip_through` | Güvenilen vekilin başlığı okunur |
| | `a_caller_that_is_not_the_trusted_proxy_cannot_spoof_its_ip` | 🔴 Vekil tanımlıyken bile vekil **olmayan** biri başlık uyduramaz |
| | `a_cidr_range_can_be_trusted` | Gerçek yapılandırma (ALB) bir aralıktır |
| config | `the_env_variable_reaches_the_config_key_and_empty_means_nobody` | `TRUSTED_PROXIES` → `trustedproxy.proxies` bağı; boş → `null` |
| kovalar | `without_trust_every_client_behind_the_proxy_shares_one_bucket` | 🔴 Raporun §2.3 sorunu, birebir: A'nın denemeleri B'yi kilitler |
| | `with_trust_each_client_gets_its_own_bucket` | Güvenle her ziyaretçinin kendi kovası var |

---

## 2. Yoklama rotası

```php
Route::get(self::PROBE, fn (Request $request): array => ['ip' => $request->ip()]);
```

Hiçbir gerçek uç `ip()`'yi yanıtta döndürmüyor, döndürmemeli de (KVKK: ham IP
saklanmaz ve gösterilmez). Test bu yüzden **yalnızca kendisinin gördüğü** bir
rota tanımlıyor. `TrustProxies` **global** middleware olduğu için bu rotada da
çalışıyor. Rota uygulamanın rota dosyalarına girmiyor, üretimde yok.

## 3. Adresler neden bunlar?

| Sabit | Adres | Kaynak |
|---|---|---|
| `PROXY` | `10.0.0.5` | Özel ağ: ALB'nin VPC içindeki adresi gibi |
| `CLIENT_A` / `CLIENT_B` | `203.0.113.9` / `.10` | RFC 5737 belgeleme aralığı: gerçek bir makineye ait değil |
| `OUTSIDER` | `198.51.100.7` | RFC 5737: dengeleyiciyi atlayıp doğrudan bağlanan biri |

## 4. Kova testleri: neden `auth` limiti?

Giriş ucu `e-posta + IP` başına dakikada **5** deneme veriyor (K36). Beş yanlış
deneme bir kovayı doldurmaya yetiyor; `throttle:api`'nin 60'ı ya da IP başına
20'si çok daha uzun testler isterdi. İki test aynı senaryoyu iki yapılandırmayla
koşuyor:

```
İstek her zaman ALB'den (REMOTE_ADDR = 10.0.0.5), ziyaretçiyi yalnızca başlık söylüyor.

Güven YOK:  A ×5 → 401 ×5 · B → 429   ← B hiç deneme yapmadı ama kilitli
Güven VAR:  A ×5 → 401 ×5 · A → 429 · B → 401   ← herkesin kendi kovası
```

İkinci testteki `A → 429` T6'nın gereği: yalnızca *"B kilitlenmedi"* yazsaydık,
hız sınırını tamamen kapatan bir değişiklik de testi geçerdi.

## 5. Config bağı testi: neden `require`?

İlk dört test anahtarı `Config::set()` ile **kendileri** yazıyor. 10.18'de
denendi: `config/trustedproxy.php` **silinse** bile hepsi yeşil kaldı. Oysa
üretimde `TRUSTED_PROXIES`'i middleware'e taşıyan tek şey o dosya.

```php
$_SERVER['TRUSTED_PROXIES'] = $value;
try {
    $config = require config_path('trustedproxy.php');
} finally {
    unset($_SERVER['TRUSTED_PROXIES']);
}
```

`env()` `$_SERVER`'ı her çağrıda yeniden okuyor (`phpunit.md` §4.1). Dosya
doğrudan `require` edildiği için yüklü config'e dokunulmuyor, başka bir teste
sızmıyor. `finally` testin ortasında bir hata olsa bile değişkeni temizliyor.

---

## 6. Mutasyon kanıtı (1 Ekim 2026, İsmail'in makinesi, PHP 8.5)

| # | Mutasyon | Kırılan test |
|---|---|---|
| M1 | Varsayılan `'*'` (`env(...) ?: '*'`) | `forwarded_for_is_ignored_…` · `without_trust_every_client_…` |
| M2 | `config/trustedproxy.php` silindi | `the_env_variable_reaches_…` (bu test eklenmeden önce: **hiçbiri**) |
| M3 | `?: null` kaldırıldı | `the_env_variable_reaches_…` |
| M4 | Yanlış değişken adı (`TRUSTED_PROXY`) | `the_env_variable_reaches_…` |
| M5 | *(testte)* yabancı testinde vekil `'*'` | `a_caller_that_is_not_…`: testin yorumundaki iddianın kanıtı |

---

## 7. Çalıştırma

```powershell
php artisan test --filter=TrustedProxiesTest
# 7 passed
```
