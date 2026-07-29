# `config/services.php` — Kılavuz

Üçüncü parti servislerin kimlik bilgileri için Laravel'in hazır ortak dosyası.
Varsayılan olarak Postmark, SES, Resend ve Slack girdilerini taşır.

## Ne zaman burası, ne zaman ayrı dosya?

| Durum | Nereye |
|---|---|
| Tek bir anahtar/gizli çift, ek iş kuralı yok | `config/services.php` |
| Birden fazla sağlayıcı, sürücü seçimi, iş kuralı | **Ayrı dosya** |

DavetKart'ta ödeme ve AI için **ayrı dosyalar** açtık:

- `config/payment.php` → `default` sağlayıcı seçimi + `providers` listesi
- `config/ai.php` → aynı yapı

Sebep: her ikisinde de "hangi sürücü aktif?" kararı var ve bu karar servis
sağlayıcıda (`AppServiceProvider`) arayüzü somut sınıfa bağlarken okunacak.
Bunu `services.php` içine sıkıştırmak dosyayı çöplüğe çevirirdi —
**Separation of Concerns**.

## Bu dosya bizde ne zaman kullanılır?

İleride tek anahtarlık basit entegrasyonlar eklenirse: Google Maps Geocoding,
SMS sağlayıcısı, hata izleme (Sentry) gibi.

## Sır yönetimi kuralı (tüm dosyalar için)

```
.env  →  config/*.php  →  Service sınıfı
```

- Anahtar `.env`'de durur, git'e **girmez**.
- Config dosyası onu `env()` ile okur (tek meşru yer).
- Sadece `app/Services/` altındaki ilgili sınıf `config(...)` ile erişir.
- Controller, Action, Resource **asla** anahtara dokunmaz.
- Frontend'e **hiçbir koşulda** gitmez. Vite yalnızca `VITE_` önekli değişkenleri
  paketler; mimari olarak sızma yolu yoktur.

## Dikkat

- Buraya yazılan bir anahtarın varsayılanını (`env('X', 'gercek-anahtar')`)
  koda gömmek, `.env` yokken sırrı repoya sızdırır. Varsayılan **daima** `null`
  veya zararsız bir değer olmalı.
