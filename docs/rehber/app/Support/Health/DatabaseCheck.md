# `app/Support/Health/DatabaseCheck.php` — `database`

> **Faz:** 19.7 (`OPS-03`, K255) · **Genel bakış:** [`ReadinessProbe.md`](ReadinessProbe.md) · **Test:** `tests/Feature/ReadinessTest.php`

## Ne ölçer

Varsayılan veritabanı bağlantısında `select 1`. Sorgu dönerse geçer; bağlantı kurulamaz ya da sorgu hata verirse
istisna fırlar ve `ReadinessProbe` kontrolü başarısız sayar. Eşik yok.

## Hangi hataya karşı

PostgreSQL durmuş, parola değişmiş, bağlantı sınırı dolmuş: `/api/ping` yine 200 der, ama hiçbir istek iş yapamaz.

## Neden yalnızca `select 1`

Tablo sorgusu göç (migration) durumuna bağlanırdı; amaç "bağlantı çalışıyor mu". Her çağrı ucuz olmalı: uç dakikada
bir vurulur ve `throttle:60,1` ile sınırlı.

## Sık hata

- `false` dönmek için `try/catch` eklemek: gerek yok, fırlatmak zaten başarısızlık (ve hata metni yanıta sızmaz).

## Nasıl denenir

`readiness_fails_when_the_database_is_unreachable` bağlantı adını istek süresince bozar ve `failed: ["database"]` bekler.
Yerelde: PostgreSQL'i durdur → `curl -i http://localhost:8030/api/health` → 503.
