# `config/health.php`

> **Faz:** 19.7 (`OPS-03`) · okuyan: `app/Support/Health/*Check.php` · kılavuz: [`../app/Support/Health/ReadinessProbe.md`](../app/Support/Health/ReadinessProbe.md)

| Anahtar | Değer | Anlamı |
|---|---|---|
| `queue_max_age_minutes` | 10 | Bekleyen en eski iş bundan yaşlıysa `queue` başarısız |
| `scheduler_max_silence_minutes` | 5 | Son kalp atışı bundan eskiyse `scheduler` başarısız (kalp atışı dakikada bir) |
| `disk_max_used_percent` | 90 | `storage/` bölümü bu yüzdeye ulaşınca `disk` başarısız |

## Neden `env()` yok

Eşik bir işletme kararıdır (OPS-03 §A-3 varsayılanı), sunucuya göre değişmez; `config/davetkart.php`'deki
iş kurallarıyla aynı yaklaşım. Değiştirmek kod değişikliğidir ve testten geçer.

## Neden ayrı dosya

`config/davetkart.php` Faz 19'da Hat A'nın dosyası; izleme eşikleri iş kuralı da değil.

## Sık hata

- Kuyruk eşiğini `retry_after`'dan (90 sn) küçük seçmek: yeniden denenen iş yanlış alarm verir.
- Zamanlayıcı eşiğini 1 dakika yapmak: dağıtımda ve dakika sınırında sahte alarm.
