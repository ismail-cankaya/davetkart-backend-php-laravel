# `app/Support/Health/` — hazır olma (readiness) kontrolleri

> **Kod dosyaları:** `ReadinessProbe.php` · `DatabaseCheck.php` · `QueueBacklogCheck.php` · `SchedulerHeartbeatCheck.php` ·
> `DiskSpaceCheck.php` · `LocalDiskUsage.php` · `RecordSchedulerHeartbeat.php` · `app/Contracts/HealthCheck.php` ·
> `app/Contracts/DiskUsage.php` · `app/Http/Controllers/Api/V1/ReadinessController.php` · rota `health.ready`
> **Faz:** 19.7 (`OPS-03`) · **Test:** `tests/Feature/ReadinessTest.php` · **Eşikler:** `config/health.php`

## Neden

`GET /api/ping` yalnızca "PHP ayakta" der (liveness). PostgreSQL durduğunda, kuyruk işçisi takıldığında,
cron sustuğunda ya da disk dolduğunda da 200 döner; sorunu ilk fark eden müşteri olur. `GET /api/health`
ikinci soruyu sorar: **iş yapabiliyor mu** (readiness). Dış izleyici dakikada bir buna vurur. `/ping`
dağıtım betiği için aynen kalır (veritabanı yokken de cevap vermeli).

## Desen

- Her kontrol kendi sınıfı (`HealthCheck`: `name()` + `passes()`); `ReadinessProbe` hepsini sırayla koşar
  (Composite). Denetleyici yalnızca sonucu yanıta çevirir.
- **Fırlatan kontrol başarısızdır** (fail-closed): probe `Throwable` yakalar. Kontroller bu yüzden kısa.
- Kayıt `MonitoringServiceProvider`'da; yeni kontrol = yeni sınıf + listeye bir satır.
- Yanıt: `{"status":"ok"}` 200 ya da `{"status":"fail","failed":["queue"]}` 503, `Cache-Control: no-store`.
  **Değer, yol, hata metni yok**: uç herkese açık; "disk %97, /var/www…" saldırgana harita olur.
- Hız sınırı `throttle:60,1` (IP başına dakikada 60): her istek bir sorgu çalıştırır.

| Ad | Ne ölçer | Eşik |
|---|---|---|
| `database` | `select 1` varsayılan bağlantıda | fırlatırsa |
| `queue` | `jobs`'ta ayrılmamış, zamanı gelmiş en eski işin yaşı (sürücü `database` değilse geçer) | 10 dk |
| `scheduler` | `RecordSchedulerHeartbeat`'in cache damgası | 5 dk |
| `disk` | `storage/` bölümünün doluluğu (`DiskUsage` arayüzü) | %90 |

## Hangi hataya karşı

Sessiz bozulma: işçi takılır (parola sıfırlama maili gitmez, hata da yok), cron silinir (zamanlanmış
temizlik ve kalp atışları durur), disk dolar (yükleme ve log yazımı patlar).

## Sık hata

- Gecikmeli (`available_at` gelecekte) ya da işçinin elindeki (`reserved_at` dolu) işi "bekliyor" saymak.
- Dağıtımda `cache:clear` kalp atışını siler: ilk `schedule:run`'a (≤1 dk) kadar `scheduler` başarısız
  görünür. Dış izleyicide alarmı "2 ardışık hata" ile kur.
- Kontrol eklerken yanıta ayrıntı koymak: test `the_health_response_leaks_no_details` kırılır.

## Nasıl denenir

`php artisan test --filter=ReadinessTest` · yerelde `curl -i http://localhost:8030/api/health`
(cron çalışmıyorsa `scheduler` başarısız; `php artisan schedule:run` sonra tekrar).
