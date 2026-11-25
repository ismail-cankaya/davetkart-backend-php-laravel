# `app/Support/Health/SchedulerHeartbeatCheck.php` — `scheduler`

> **Faz:** 19.7 (`OPS-03`, K255) · **Genel bakış:** [`ReadinessProbe.md`](ReadinessProbe.md) · **Damgayı yazan:**
> [`RecordSchedulerHeartbeat`](RecordSchedulerHeartbeat.md) · **Eşik:** `config/health.php` → `scheduler_max_silence_minutes` (5)

## Ne ölçer

Cache'teki `health:scheduler-heartbeat` damgası (Unix zamanı) eşikten taze mi. Damga yoksa ya da tam sayı değilse
başarısız.

## Hangi hataya karşı

Cron satırı silindi ya da sunucu yeniden kurulurken eklenmedi: `data:purge`, `media:prune-orphans`, süresi dolan
siparişler sessizce durur.

## Sık hata

- Dağıtımda `cache:clear` damgayı siler: ilk `schedule:run`'a (≤1 dk) kadar `scheduler` başarısız görünür. Dış
  izleyicide alarm "2 ardışık hata" ile kurulur (`IZLEME.md`).
- Cache sürücüsü `array` ise damga istekler arasında yaşamaz: üretimde kalıcı sürücü (`database`/`redis`) gerekir.

## Nasıl denenir

`readiness_fails_when_the_scheduler_is_silent` · yerelde cron yoksa `/api/health` → `failed: ["scheduler"]`;
`php artisan schedule:run` sonra yeniden.
