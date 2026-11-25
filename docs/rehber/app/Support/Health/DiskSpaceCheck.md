# `app/Support/Health/DiskSpaceCheck.php` — `disk`

> **Faz:** 19.7 (`OPS-03`, K255) · **Genel bakış:** [`ReadinessProbe.md`](ReadinessProbe.md) · **Ölçüm:**
> [`DiskUsage`](../../Contracts/DiskUsage.md) → [`LocalDiskUsage`](LocalDiskUsage.md) · **Eşik:** `config/health.php` →
> `disk_max_used_percent` (90)

## Ne ölçer

`storage/` bölümünün doluluk yüzdesi eşiğin **altında** mı. Eşiğe eşitse başarısız (`<`).

## Hangi hataya karşı

Disk dolunca yükleme, log ve oturum/cache yazımı patlar; hata çoğu zaman başka bir yerde, anlamsız bir mesajla görünür.
%90'da uyarmak, dolmadan önce müdahale payı bırakır.

## Neden arayüzle

Sınıf ölçümü kendisi yapmaz, `DiskUsage`'a sorar. Böylece test sahte bir yüzde bağlayıp sınırı dener
(`readiness_fails_when_the_disk_is_almost_full`); gerçek diski doldurmak gerekmez.

## Sık hata

- Yüzdeyi yanıta koymak ("disk 97") — uç herkese açık; yalnızca ad (`disk`) döner.
- Eşiği `<=` yapmak: %90 "geçer" olur, sınır testi kırılır.
