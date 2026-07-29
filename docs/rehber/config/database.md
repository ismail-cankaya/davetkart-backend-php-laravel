# `config/database.php` — Kılavuz

Veritabanı bağlantılarını tanımlar. `default` anahtarı hangisinin kullanılacağını
söyler; diğerleri hazır bekler.

## Yapı

```php
'default' => env('DB_CONNECTION', 'sqlite'),
'connections' => [
    'sqlite' => [...],
    'mysql'  => [...],
    'pgsql'  => [...],
],
```

Aynı anda birden fazla bağlantı tanımlı olabilir; kod `DB::connection('mysql')`
ile birine açıkça bağlanabilir. Bizde buna gerek yok.

## DavetKart kararı: yerelde SQLite, üretimde MySQL 8

| Ortam | Sürücü | Neden |
|---|---|---|
| Geliştirme | **SQLite** | Herd'ün ücretsiz sürümünde MySQL yok. Laravel 11+ varsayılanı. Tek dosya: `database/database.sqlite` |
| Üretim | **MySQL 8** | TR hosting'lerde yaygın, eşzamanlı yazmada güçlü |

Geçiş `.env`'de tek satır (`DB_CONNECTION=mysql`) — migration'lar aynı kalır.
Eloquent ve Schema Builder, SQL farklarını bizden gizler.

## 🔴 SQLite ↔ MySQL farkları (migration yazarken önemli)

| Konu | SQLite | MySQL | Ne yapacağız |
|---|---|---|---|
| `ENUM` kolon tipi | Yok, `varchar`'a düşer | Var | Kolonu `string` yapıp doğrulamayı **PHP enum** ile yaparız (Adım 2 & 3) |
| Kolon değiştirme | Kısıtlı | Serbest | Migration'da `change()` yerine yeni migration tercih |
| Eşzamanlı yazma | Dosya kilidi — tek yazıcı | Satır kilidi | Yerelde sorun değil, üretimde MySQL |
| `JSON` kolon | Metin olarak saklar | Yerel tip | Eloquent cast'i her ikisinde de aynı çalışır |
| Yabancı anahtar | Varsayılan **kapalı** | Açık | Laravel açıyor; yine de test edilir |

**Sonuç:** `enum(...)` yerine `string` + PHP enum cast kullanacağız. Bu zaten daha
iyi bir tasarım: veritabanı ENUM'unu değiştirmek tablo kilidi gerektirir, PHP
enum'unu değiştirmek bir deploy'dur.

## `foreign_key_constraints`

SQLite bölümündeki bu anahtar `true` olmalı. Aksi hâlde `invitation_id`
silinen bir davetiyeye işaret edebilir ve yetim (orphan) kayıtlar oluşur —
yerelde fark edilmez, üretimde patlar.

## `redis` bölümü

Şu an kullanılmıyor (`.env`'de Redis kurulu değil). Üretimde cache ve kuyruk
için Redis'e geçersek burası devreye girer.

## Dikkat

- SQLite dosyası `database/database.sqlite` — **git'e girmez** (`.gitignore`'da).
- `php artisan migrate:fresh` tüm tabloları siler; yerelde serbest, üretimde asla.
