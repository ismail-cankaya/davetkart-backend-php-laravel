# `app/Contracts/HealthCheck.php`

> **Faz:** 19.7 (`OPS-03`, K255) · **Genel bakış:** [`app/Support/Health/ReadinessProbe.md`](../Support/Health/ReadinessProbe.md) ·
> **Test:** `tests/Feature/ReadinessTest.php`

## Ne

Hazır olma (readiness) kontrollerinden birinin sözleşmesi: `name()` + `passes()`. `ReadinessProbe` listeyi sırayla
koşar; `false` dönen **ya da fırlatan** kontrol başarısızdır (fail-closed).

## Neden arayüz

- Her kontrol kendi sınıfında kalır; yeni kontrol = yeni sınıf + `MonitoringServiceProvider`'daki listeye bir satır.
- Testte tek bir kontrol, gerçek sistemi bozmadan sahte bir uygulamayla değiştirilebilir.
- `Contracts/`'ta durur: dış bir servise değil, uygulamanın kendi durumuna bakan bir soru (`CLAUDE.md` §1).

## Kurallar

- `name()` yanıta giden **tek** bilgidir: kısa, sabit, küçük harf (`database`, `queue` …). Değer, yol, hata metni yok;
  uç herkese açık.
- `passes()` hızlı olmalı: dış izleyici dakikada bir çağırır, her çağrı bütün kontrolleri koşar.
- İstisnayı yutmaya gerek yok; probe yakalar ve kontrolü başarısız sayar.

## Sık hata

| Hata | Sonuç |
|---|---|
| `name()`'e ölçülen değeri eklemek (`disk-97`) | `the_health_response_leaks_no_details` kırılır; saldırgana bilgi |
| Kontrolün içinde `try/catch` ile `true` dönmek | Bozuk bileşen "sağlıklı" görünür |

Dene: `php artisan test --compact --filter=ReadinessTest`
