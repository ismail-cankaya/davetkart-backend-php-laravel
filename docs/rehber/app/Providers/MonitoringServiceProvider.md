# `app/Providers/MonitoringServiceProvider.php`

> **Faz:** 19.7 (`OPS-03`) · kayıt `bootstrap/providers.php` · **Test:** `tests/Feature/ReadinessTest.php`

## Neden

Hazır olma kontrollerinin kaydı. `AppServiceProvider` bu fazda Hat A'nın dosyasıydı (paralel çalışma); ayrıca
izleme kendi başına bir konu.

## Desen

- `register()`: `DiskUsage` → `LocalDiskUsage`; `ReadinessProbe` dört kontrolle kurulur (`bind`, singleton
  değil: test `DiskUsage`'ı `instance()` ile değiştirir ve sonraki istek yenisini görür).
- Kalp atışı burada **değil**, `routes/console.php`'de (öteki dört işin yanında): `RecordSchedulerHeartbeat`
  her dakika, adı `health-heartbeat`, `withoutOverlapping()` + `onOneServer()` + `sentryMonitor()` (`MaintenanceTest`
  her zamanlanmış işte üçünü şart koşar, K103). İlk sürüm `callAfterResolving(Schedule::class)` ile buradaydı;
  Larastan `app(Schedule::class)`'ı çözerken geri çağrıyı tetikleyip analizi çökertti ("Class mixed was not found").
- Kuyruğa gitmez (`Schedule::call`, `Schedule::job` değil): kuyruk takılınca kalp atışı da dursaydı iki
  arıza tek belirtiye karışırdı.

## Hangi hataya karşı

Sunucuda cron satırı silinir ya da `schedule:run` hata verir: hiçbir zamanlanmış iş koşmaz ve kimse bilmez.
Damga 5 dakikadan eskiyse `/api/health` 503 (`scheduler`).

## Sık hata

- Kalp atışını ikinci kez (provider'da) kaydetmek: iki kayıt, iki koşu.
- `onOneServer()` için ad vermemek: Laravel adı olmayan callback'te kilit kuramaz.

## Nasıl denenir

`php artisan schedule:list` → `health-heartbeat` her dakika · `php artisan schedule:run` → cache'te
`health:scheduler-heartbeat`.
