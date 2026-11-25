# `app/Support/Health/QueueBacklogCheck.php` — `queue`

> **Faz:** 19.7 (`OPS-03`, K255) · **Genel bakış:** [`ReadinessProbe.md`](ReadinessProbe.md) · **Test:** `tests/Feature/ReadinessTest.php`
> **Eşik:** `config/health.php` → `queue_max_age_minutes` (10)

## Ne ölçer

Varsayılan kuyruk bağlantısının tablosunda (`jobs`) **ayrılmamış** (`reserved_at` boş) ve **zamanı gelmiş**
(`available_at <= şimdi`) en eski işin yaşı. Yaş eşikten büyükse başarısız; bekleyen iş yoksa geçer.

## Hangi hataya karşı

İşçi takıldı ya da hiç başlatılmadı: parola sıfırlama, e-posta doğrulama, iade maili kuyrukta bekler, hata da yazılmaz.

## Kurallar

- Sürücü `database` değilse (testte `sync`) ölçülecek tablo yok: **geçer**. Redis/SQS'e geçilirse bu kontrol
  yeniden yazılmalı, yoksa sessizce hep yeşil kalır.
- Bağlantı ve tablo adı `queue.connections.<varsayılan>`'dan okunur; sabit `jobs` yalnızca yedek.

## Sık hata

| Hata | Sonuç |
|---|---|
| Gecikmeli işi (`available_at` gelecekte) bekliyor saymak | Planlı iş yanlış alarm verir |
| İşçinin elindeki işi (`reserved_at` dolu) bekliyor saymak | Uzun süren iş yanlış alarm verir |

## Nasıl denenir

`readiness_fails_when_the_oldest_job_is_too_old` (9 dk geçer, 11 dk kalır) ·
`a_delayed_or_reserved_job_does_not_count_as_waiting`.
