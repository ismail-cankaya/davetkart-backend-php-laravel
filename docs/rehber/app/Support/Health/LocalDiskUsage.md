# `app/Support/Health/LocalDiskUsage.php`

> **Faz:** 19.7 (`OPS-03`, K255) · **Arayüz:** [`DiskUsage`](../../Contracts/DiskUsage.md) · **Kullanan:**
> [`DiskSpaceCheck`](DiskSpaceCheck.md) · **Bağlama:** `MonitoringServiceProvider`

## Ne

`DiskUsage`'ın üretim uygulaması: `storage_path()`'in bulunduğu bölümde `disk_total_space` ve `disk_free_space` ile
`(toplam - boş) / toplam * 100`.

## Neden `storage/`

Büyüyen her şey orada: yerel yüklemeler, loglar, dosya cache'i, oturumlar. Kök bölüm ayrıysa bile dolduğunda uygulamayı
durduran bu bölümdür.

## Kurallar

- PHP fonksiyonları `false` dönebilir (izin, ağ diski). O zaman **fırlatır** (`Disk space could not be measured.`):
  probe kontrolü başarısız sayar. Sıfır dönmek dolu diski gizlerdi.
- Medya S3'e taşındığında (K183) yüklemeler bu bölümde büyümez; loglar ve cache yine burada.

## Nasıl denenir

Testler sahte `DiskUsage` bağlar; bu sınıf yalnızca yerelde/sunucuda: `php artisan tinker` →
`app(App\Contracts\DiskUsage::class)->usedPercent()`.
