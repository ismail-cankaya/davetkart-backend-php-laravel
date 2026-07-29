# `config/queue.php` — Kılavuz

Kuyruk = "bu işi şimdi değil, arka planda yap." Kullanıcı cevabını hemen alır,
uzun iş sonra çalışır.

## Sürücüler

| Sürücü | Davranış | Ne zaman |
|---|---|---|
| `sync` | Kuyruğa almaz, **anında** çalıştırır | Hata ayıklarken |
| `database` | `jobs` tablosuna yazar | Tek sunucu, ek altyapı yok |
| `redis` | Redis listesine yazar | Üretim, yüksek hacim |

Bizde `.env` → `QUEUE_CONNECTION=database`. `jobs` tablosu kurulu.

## 🔴 15 saniye kuralı

Frontend `api.ts` timeout'u **15 saniye**. Bundan uzun sürebilecek her iş kuyruğa
gider; endpoint sadece "kabul edildi" der.

| İş | Neden kuyrukta |
|---|---|
| `OptimizeUploadedImage` | 8 MB fotoğrafın yeniden boyutlandırılması saniyeler sürer |
| `SendRsvpNotification` | SMTP sunucusu yavaş cevap verebilir; kullanıcı beklememeli |

## Nasıl çalışır?

1. Kod `OptimizeUploadedImage::dispatch($media)` der.
2. Laravel işi serileştirip `jobs` tablosuna yazar. HTTP isteği **biter.**
3. Ayrı bir süreç (`php artisan queue:work`) tabloyu dinler, işi alır, çalıştırır.

**Kritik:** Worker çalışmıyorsa iş tabloda bekler, hiç yapılmaz. Yerelde ayrı bir
terminalde çalıştırman gerekir:

```powershell
php artisan queue:work
```

## Önemli anahtarlar

| Anahtar | Ne işe yarar |
|---|---|
| `retry_after` | Worker çökerse iş kaç saniye sonra tekrar alınsın |
| `failed` | Başarısız işlerin kaydedileceği yer (`failed_jobs` tablosu) |
| `block_for` | Worker'ın boş kuyrukta bekleme süresi |

## Dikkat

- `retry_after`, işin gerçek süresinden **uzun** olmalı. Kısa olursa aynı iş iki
  kez çalışır — mail iki kez gider.
- Job'lara **model değil id** göndermek daha güvenlidir; Laravel `SerializesModels`
  ile modeli id'den yeniden yükler, bayat veriyle çalışılmaz.
- Kod değiştirdikten sonra `queue:restart` gerekir — worker eski kodu hafızada
  tutar.
