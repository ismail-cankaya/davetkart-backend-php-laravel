# `config/database.php` — Kılavuz

Veritabanı bağlantılarını tanımlar. `default` anahtarı hangisinin kullanılacağını
söyler; diğerleri hazır bekler.

## Yapı

```php
'default' => env('DB_CONNECTION', 'pgsql'),
'connections' => [
    'sqlite' => [...],
    'mysql'  => [...],
    'pgsql'  => [...],   ← bizim kullandığımız
],
```

Aynı anda birden fazla bağlantı tanımlı olabilir; kod `DB::connection('...')` ile
birine açıkça bağlanabilir. Bizde buna gerek yok.

## DavetKart kararı: her ortamda PostgreSQL 18 (K19)

| Ortam | Veritabanı |
|---|---|
| Geliştirme | `davetkart` |
| Test | `davetkart_test` |
| Üretim | `davetkart` (ayrı sunucu) |

**Neden üç ortamda da aynı?** 12-Factor App'in X. maddesi: *dev/prod parity*.
Ortamlar farklı veritabanı kullanırsa, hatalar laptop'ta değil **üretimde**
ortaya çıkar.

### Karar geçmişi

1. **Başlangıç:** "Geliştirmede SQLite, üretimde MySQL 8." SQLite'ın gerekçesi
   *"Herd ücretsiz sürümünde MySQL yok"* idi — teknik üstünlük değil, kurulum
   kolaylığı.
2. **K9':** Üretim MySQL 8 → PostgreSQL 18. MySQL 8'in yüksek RAM tabanı,
   PostgreSQL'in `jsonb` ve güçlü kısıt desteği.
3. **K19:** Geliştirme de PostgreSQL. MySQL gitince SQLite'ın gerekçesi düştü.

## 🔴 SQLite ile PostgreSQL farkları — neden önemliydi

| Konu | SQLite | PostgreSQL |
|---|---|---|
| `ENUM` kolon tipi | Yok, `varchar`'a düşer | Var |
| `jsonb` | Yok, düz metin | İndekslenebilir |
| `CHECK` kısıtı | Kısıtlı | Tam |
| Eşzamanlı yazma | Dosya kilidi — tek yazıcı | Satır kilidi |
| Kısmi indeks | Yok | Var |
| Yabancı anahtar | Varsayılan **kapalı** | Açık |
| Kolon değiştirme | Tabloyu yeniden yaratır | `ALTER` |

Bu farklar bizde soyut değil:

- **6 enum** var → `ENUM` desteği işe yarıyor
- `gift_options` **JSON** kolonu → `jsonb`
- `guest_count > 0` gibi **CHECK** kısıtları
- LCV seli senaryosu → **eşzamanlı yazma**
- `WHERE status = 'published'` → **kısmi indeks**

> **Ortadan kalkan taviz:** Daha önce "SQLite'ta ENUM yok, o yüzden `string`
> kolon + PHP enum cast kullanacağız" demiştik. Bu, veritabanı kısıtı yüzünden
> tasarımı eğmekti. Artık gerçek `ENUM` veya `CHECK` kısıtı kullanabiliriz —
> hangisinin tercih edileceği Faz 3'te (migration'lar) kararlaştırılacak.

## PostgreSQL'e özgü ayarlar

| Anahtar | Değer | Not |
|---|---|---|
| `charset` | `utf8` | PostgreSQL'de tek doğru seçim |
| `search_path` | `public` | Şema adı. Çoklu kiracı yapıda değişir |
| `sslmode` | `prefer` (yerel) / `require` (üretim) | Üretimde şifresiz bağlantı olmamalı |

## Test veritabanı neden ayrı?

Testler `RefreshDatabase` ile her koşuda tabloları temizler. Geliştirme
veritabanınla aynı olsaydı, test koşmak **elle girdiğin tüm veriyi silerdi.**

`phpunit.xml` içinde `DB_DATABASE=davetkart_test` tanımlanır; bu değer
`.env`'dekini ezer.

## `redis` bölümü

Şu an kullanılmıyor. Üretimde cache ve kuyruk için Redis'e geçildiğinde
devreye girer.

## Dikkat

- `pdo_pgsql` PHP eklentisi kurulu olmalı: `php -m | Select-String "pgsql"`
- `php artisan migrate:fresh` tüm tabloları siler — yerelde serbest, üretimde asla.
- PostgreSQL'de tablo/kolon adları **küçük harfe** çevrilir; Laravel zaten
  `snake_case` kullandığı için sorun çıkmaz.
- Bağlantı bilgileri `.env`'de: `DB_HOST=127.0.0.1`, `DB_PORT=5432`,
  `DB_DATABASE=davetkart`, `DB_USERNAME=postgres`, `DB_PASSWORD=...`
