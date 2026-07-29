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
