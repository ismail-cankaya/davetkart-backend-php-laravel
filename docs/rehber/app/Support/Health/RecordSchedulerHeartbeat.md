# `app/Support/Health/RecordSchedulerHeartbeat.php`

> **Faz:** 19.7 (`OPS-03`, K255) · **Kayıt:** [`MonitoringServiceProvider`](../../Providers/MonitoringServiceProvider.md) ·
> **Okuyan:** [`SchedulerHeartbeatCheck`](SchedulerHeartbeatCheck.md) · **Test:** `tests/Feature/ReadinessTest.php`

## Ne

Zamanlayıcıya dakikada bir kayıtlı çağrılabilir sınıf: cache'e `health:scheduler-heartbeat` = şimdiki Unix zamanı
yazar (`Cache::forever`). Anahtar `CACHE_KEY` sabitinde; yazan ve okuyan aynı sabiti kullanır.

## Neden cache, neden `forever`

- Veritabanı tablosu gerekmez; damga tek bir sayı.
- Süre (`ttl`) verilseydi damga kendiliğinden silinir ve kontrol "sustu" ile "hiç yazılmadı"yı ayıramazdı.
  Tazeliği `SchedulerHeartbeatCheck` eşikle ölçer.

## Sık hata

- Anahtarı kodda ikinci kez dize olarak yazmak: biri değişince kontrol hep kırmızı kalır. `CACHE_KEY` kullan.

## Nasıl denenir

`the_scheduler_records_a_heartbeat_every_minute` zamanlanmış olayı çalıştırır ve damganın `now()` olduğunu doğrular.
