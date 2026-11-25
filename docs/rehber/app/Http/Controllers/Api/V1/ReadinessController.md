# `app/Http/Controllers/Api/V1/ReadinessController.php` — `GET /api/health`

> **Faz:** 19.7 (`OPS-03`, K255) · **Rota:** `health.ready`, `throttle:60,1` · **Genel bakış:**
> [`app/Support/Health/ReadinessProbe.md`](../../../../Support/Health/ReadinessProbe.md) · **Test:** `tests/Feature/ReadinessTest.php`

## Ne yapar

`ReadinessProbe::failures()`'ı çağırır ve sonucu yanıta çevirir; iş mantığı yok:

| Durum | Kod | Gövde |
|---|---|---|
| Bütün kontroller geçti | 200 | `{"status":"ok"}` |
| En az biri kaldı | 503 | `{"status":"fail","failed":["queue"]}` |

Her iki yanıtta `Cache-Control: no-store`: önbellekteki eski bir "ok" alarmı susturmasın.

## `/api/ping` ile farkı

`/api/ping` (`HealthController`) canlılıktır: PHP ayakta mı; veritabanı yokken de 200 döner, dağıtım betiği onu bekler.
`/api/health` hazır olmadır: iş yapabiliyor mu. Dış izleyici (UptimeRobot) bunu çağırır (`IZLEME.md` §4).

## Neden `{ data: … }` zarfı yok

İzleme araçları gövdede düz anahtar arar (`status`); yanıt bir kaynak (Resource) değil, durum sinyali.

## Sık hata

- Yanıta hata metni ya da ölçüm değeri eklemek: uç herkese açık (`the_health_response_leaks_no_details`).
- 503 yerine 200 + `fail` dönmek: izleyicilerin çoğu yalnızca durum koduna bakar.

Dene: `curl -i http://localhost:8030/api/health`
