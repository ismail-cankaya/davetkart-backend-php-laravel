# `tests/Feature/ReadinessTest.php`

> **Faz:** 19.7 (`OPS-03`) · sınadığı: `GET /api/health`, kalp atışı, `ReportFailedJob`

## Ne kanıtlar

- Her kontrol **tek başına** düşürülür ve yanıt birebir `{"status":"fail","failed":["<ad>"]}` olur;
  böylece bir kontrolün silinmesi başka kontrolün 503'üyle gizlenmez (mutant: `DatabaseCheck` listeden
  çıkarılınca `readiness_fails_when_the_database_is_unreachable` 200 görür, kırmızı).
- Sınırlar iki yönlü: 9 dk'lık iş geçer, 11 dk'lık düşer; %89,9 geçer, %90 düşer.
- `schema` (Faz 20.1, K263): son göçün kaydı silinince ve `migrations` tablosu hiç yokken 503; dosyası olmayan
  fazla kayıt ise 200. Mutantlar: `array_diff` yönü ters → iki test kırmızı; tablo yokken `true` → bir test kırmızı.
  Veritabanına ulaşılamazken `schema` de düşer: beklenen yanıt `["database","schema"]`.
- Gecikmeli ve işçideki iş "bekliyor" sayılmaz.
- Fırlatan kontrol başarısızdır ve yol bilgisi yanıta sızmaz.
- Zamanlayıcıda `health-heartbeat` her dakika kayıtlı ve koşunca damgayı yazar.
- Başarısız iş `critical` ile, yalnızca beş güvenli alanla bildirilir (istisna metnindeki e-posta yok).

## Kurulum

- `RefreshDatabase` (`jobs` tablosu), `queue.default = database`, disk sahte (`DiskUsage` `instance()`),
  kalp atışı taze.
- Veritabanı düşüşü: varsayılan bağlantı adı **yalnızca istek boyunca** olmayan bir ada çevrilir
  (`withUnreachableDatabase`). Test sonunda geri alınmazsa `RefreshDatabase`'in geri alma adımı da
  aynı ada bağlanmaya çalışır ve test hatayla biter.

## Nasıl denenir

`php artisan test --compact --filter=ReadinessTest`
