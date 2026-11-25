# `app/Contracts/DiskUsage.php`

> **Faz:** 19.7 (`OPS-03`, K255) · **Uygulama:** [`LocalDiskUsage`](../Support/Health/LocalDiskUsage.md) ·
> **Kullanan:** [`DiskSpaceCheck`](../Support/Health/DiskSpaceCheck.md) · **Test:** `tests/Feature/ReadinessTest.php`

## Ne

Tek soru: diskin doluluk yüzdesi (`usedPercent(): float`, 0–100).

## Neden arayüz

Testin "disk %95 dolu" durumunu **gerçek diski doldurmadan** kurabilmesi için. `ReadinessTest`
`readiness_fails_when_the_disk_is_almost_full` container'a sabit yüzde dönen bir uygulama bağlar; üretimde
`MonitoringServiceProvider` `LocalDiskUsage`'ı bağlar.

## Kurallar

- Ölçülemiyorsa **fırlat** (sıfır dönme): `ReadinessProbe` fırlatan kontrolü başarısız sayar (fail-closed).
- Yüzde olarak dön, bayt değil: eşik (`config/health.php` → `disk_max_used_percent`) yüzde.

## Sık hata

| Hata | Sonuç |
|---|---|
| Ölçüm başarısızken `0.0` dönmek | Dolu disk "boş" görünür, alarm çalmaz |
| Testte gerçek `LocalDiskUsage`'ı kullanmak | Sonuç makinenin diskine bağlı, test kararsız |
