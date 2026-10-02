# `config/logging.php` — Kılavuz

Uygulamanın ne yaptığını ve nerede patladığını yazdığı defter.

## Kanal (channel) kavramı

Kanal = bir log hedefi. `stack` kanalı, birden fazla kanalı aynı anda besler.

| Kanal | Nereye yazar |
|---|---|
| `single` | Tek dosya: `storage/logs/laravel.log` |
| `daily` | Günlük dosyalar, eskiyi siler |
| `stack` | Birden fazla kanala aynı anda |
| `stderr` | Standart hata çıkışı (Docker/bulut ortamları) |
| `slack` | Slack kanalına webhook ile |

`.env` şu an: `LOG_CHANNEL=stack`, `LOG_STACK=single`.

## Seviyeler

`debug < info < notice < warning < error < critical < alert < emergency`

`LOG_LEVEL`, hangi seviyeden itibaren yazılacağını belirler. Yerelde `debug`
(her şey), üretimde genelde `warning` veya `error`.

## DavetKart'ta ne loglayacağız?

| Olay | Seviye | Neden |
|---|---|---|
| Ödeme webhook'u alındı/işlendi | `info` | Para akışı denetlenebilir olmalı |
| Paywall ihlali denemesi | `warning` | Saldırı göstergesi |
| LCV kota aşımı | `info` | İş kuralı çalıştı |
| AI sağlayıcı hatası | `error` | Dış servis arızası |

## 🔴 Loglanmayacaklar (KVKK + güvenlik)

- **Ham IP adresi** — hash'lenmiş hâli bile log'a yazılmaz, sadece DB'de durur
- **Şifre / token** — request gövdesini olduğu gibi loglamak en yaygın sızıntıdır
- **API anahtarları** — exception mesajlarında sızabilir
- **Misafir adı, telefon** gibi kişisel veriler

Laravel'in exception raporlaması bazen request gövdesini ekler; Adım 6'da
`bootstrap/app.php` içinde hassas alanları maskeleyeceğiz.

## `production` ayarı

Üretimde `daily` kanalı tercih edilir (`LOG_CHANNEL=daily`): tek dosya
gigabaytlara ulaşıp diski doldurabilir, `daily` eski dosyaları otomatik siler.

## Dikkat

- `dd()` ve `dump()` hata ayıklama araçlarıdır, **log değildir** ve koda
  bırakılırsa API yanıtını bozar.
- Log yazmak diske yazmaktır; sıcak yollarda (public davetiye endpoint'i) aşırı
  log performansı düşürür.

---

## 🆕 Faz 10 (10.55b) — `sentry` kanalı

```php
'sentry' => [
    'driver' => 'sentry',
    'level' => 'critical',
],
```

Üretimde yığına eklenir: `LOG_STACK=daily,sentry` (`docs/10`). Geliştirmede yığında yok,
çünkü DSN boş ve gidecek yer yok.

### Neden gerekiyordu?

Sentry'ye iki yol var ve ikisi farklı şey taşıyor:

| Yol | Ne taşır |
|---|---|
| `Integration::handles()` (`bootstrap/app.php`) | **İstisnalar** |
| `sentry` log kanalı | **Log satırları** |

`HandlePaymentCallbackAction`'daki *"para alındı, hak açılamadı"* bir istisna değil, bir
`Log::critical` satırı (kod çalışmaya devam ediyor, sağlayıcıya 204 dönüyor). Faz 10'a
kadar bu satır yalnızca sunucudaki log dosyasına düşüyordu: kimse okumazsa kimse bilmezdi.

### Neden `critical`, neden `error` değil?

Laravel yakalanmamış bir istisnayı **hem** Sentry'ye yollar **hem** log'a `error`
seviyesinde yazar. Kanal `error`'u da alsaydı aynı hata Sentry'ye iki kez gelirdi: biri
istisna, biri log satırı. Paketin kendiliğinden kaydettiği `sentry` kanalı seviyesiz
(`debug`), yani olduğu gibi yığına eklemek her şeyi iki kez yollardı. Kendi tanımımız onu
geçersiz kılıyor.

**Kural:** istisna olmayan ama birinin **hemen** bakması gereken durum `Log::critical`.
Gerisi `warning`/`error` ile dosyada kalır.

**Test:** `ExceptionReportingTest::the_sentry_log_channel_takes_critical_but_not_error`.
Mutasyon: seviye silinince, `error`'a ya da `emergency`'ye çekilince kırılıyor.
