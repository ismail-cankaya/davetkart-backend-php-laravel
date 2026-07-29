# `config/filesystems.php` — Kılavuz

Dosyaların nereye yazılacağını tanımlar. Laravel'in **disk** kavramı, farklı
depolama hedeflerini (yerel klasör, S3, FTP) aynı arayüzün arkasına koyar.

## Diskler

| Disk | Konum | Web'den erişilir mi |
|---|---|:---:|
| `local` | `storage/app/private/` | ❌ Hayır |
| `public` | `storage/app/public/` | ✅ Evet (symlink ile) |
| `s3` | Amazon S3 | ✅ Evet |

## Neden soyutlama? (Ports & Adapters)

Kod şunu yazar:

```php
Storage::disk(config('davetkart.media.disk'))->put($path, $contents);
```

Yerelden S3'e geçiş, `.env`'de tek satırdır. İş kodu **hangi depolamayı
kullandığını bilmez** — bu, Hexagonal Architecture'ın (Ports & Adapters) küçük
bir uygulamasıdır. Aynı prensibi `PaymentGateway` ve `AiProvider` arayüzlerinde
de kullanacağız.

## 🔴 `local` ve `public` farkı — güvenlik meselesi

- `local` diskteki dosyaya doğrudan URL ile ulaşılamaz. Erişim, yetki kontrolü
  yapan bir controller üzerinden verilir.
- `public` diskteki dosya, `php artisan storage:link` ile `public/storage`
  altına bağlanır ve herkese açıktır.

**Kural:** Davetiye galeri görselleri zaten misafirle paylaşılacak → `public`.
Ama yükleme klasörü **asla PHP çalıştırılabilir bir dizin olmamalı.**
`storage/` bu yüzden `public/` dışında durur; sadece symlink ile okunur.

## `storage:link` — unutulan komut

```powershell
php artisan storage:link
```

Bu çalıştırılmadan `public` diske yüklenen dosyalar 404 döner. Kurulum
adımlarında sık atlanan komuttur.

## DavetKart'ta dosya güvenliği (Adım 11)

| Önlem | Neden |
|---|---|
| MIME **içerikten** doğrulanır (`mimetypes:` kuralı) | `zararli.php` → `resim.jpg` yeniden adlandırması uzantı kontrolünü geçer |
| Dosya adı **rastgele** üretilir | Kullanıcı adı yol geçişi (`../../`) içerebilir |
| Boyut limiti disk başına ayrı | Kimliksiz (misafir) yüklemede limit daha düşük |
| Yükleme sonrası optimizasyon **kuyrukta** | 15 sn kuralı |

Limitler `config/davetkart.php` → `media` bölümünde.

## Dikkat

- `Storage::url()` her diskte doğru URL'i üretir; yol elle birleştirilmez.
- `FILESYSTEM_DISK` (`.env`) Laravel'in varsayılan diski; bizim medya diskimiz
  ayrı anahtardan (`DAVETKART_MEDIA_DISK`) okunur, karıştırılmamalı.
