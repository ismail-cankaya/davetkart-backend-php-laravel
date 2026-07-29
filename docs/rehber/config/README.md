# `config/` Klasörü — Kılavuz Dizini

Laravel açılırken `config/` içindeki **her `.php` dosyasını** okur ve dosya adını
anahtar yaparak tek bir dizi kurar:

```
config/app.php       → config('app.name')
config/davetkart.php → config('davetkart.tiers.gold.price')
```

Dosyayı bu klasöre koymak onu kaydetmeye yeter; ekstra bir tanımlama yoktur.

## İki kural

1. **`env()` sadece `config/` içinde çağrılır.** `php artisan config:cache` sonrası
   `.env` bir daha okunmaz; kod içindeki `env()` sessizce `null` döner.
2. **Sır `.env`'de, iş kuralı `config/`'te.** Ortama göre değişen (şifre, anahtar)
   `.env`; ortamdan bağımsız ama değişebilen (fiyat, limit) `config/`.

## Dosyalar

| Dosya | Ne ayarlar | DavetKart'ta önemi | Kılavuz |
|---|---|:---:|---|
| `davetkart.php` | ⭐ Plan fiyatları, kotalar, limitler | 🔴 Yüksek | [davetkart.md](davetkart.md) |
| `payment.php` | ⭐ Ödeme sağlayıcı + sırlar | 🔴 Yüksek | [payment.md](payment.md) |
| `ai.php` | ⭐ AI sağlayıcı + API anahtarı | 🔴 Yüksek | [ai.md](ai.md) |
| `app.php` | Uygulama adı, ortam, dil, zaman dilimi | Orta | [app.md](app.md) |
| `auth.php` | Kimlik doğrulama guard ve provider'ları | 🔴 Yüksek | [auth.md](auth.md) |
| `sanctum.php` | API token davranışı | 🔴 Yüksek | [sanctum.md](sanctum.md) |
| `database.php` | Veritabanı bağlantıları | 🔴 Yüksek | [database.md](database.md) |
| `cache.php` | Cache sürücüsü | 🔴 Yüksek | [cache.md](cache.md) |
| `queue.php` | Kuyruk sürücüsü | Orta | [queue.md](queue.md) |
| `filesystems.php` | Dosya diskleri | 🔴 Yüksek | [filesystems.md](filesystems.md) |
| `session.php` | Oturum çerezi | Düşük (API'de kullanılmıyor) | [session.md](session.md) |
| `logging.php` | Log kanalları | Orta | [logging.md](logging.md) |
| `mail.php` | E-posta gönderimi | Orta | [mail.md](mail.md) |
| `services.php` | 3. parti servis kimlikleri | Düşük | [services.md](services.md) |

## `.env` düzeltme listesi (yapılacak)

| Satır | Şu an | Olması gereken | Neden |
|---|---|---|---|
| `APP_NAME` | `Laravel` | `DavetKart` | Mail başlıkları ve log'larda görünür |
| `APP_LOCALE` | `en` | `tr` | Doğrulama hataları Türkçe dönmeli |
| `APP_FAKER_LOCALE` | `en_US` | `tr_TR` | Seeder'da Türkçe isimler üretsin |
| `CACHE_STORE` | `database` | `file` (yerel) | SQLite'ta cache tablosu ek yazma yükü |

## Faydalı komutlar

```powershell
php artisan config:clear   # config cache'ini temizle (yerelde değişiklik sonrası)
php artisan config:cache   # üretimde performans için derle
php artisan tinker         # config('...') değerlerini canlı test et
```
