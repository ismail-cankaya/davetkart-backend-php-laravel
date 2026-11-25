# `config/deletion_log.php` — Kılavuz

> **Faz:** 19 ortak düzeltme (B-E1, 25 Kasım 2026) · **Karar:** K233

| Anahtar | Değer | Neden |
|---|---|---|
| `disk` | `DAVETKART_DELETION_LOG_DISK`, varsayılan `local` | Günlük **veritabanının dışında** durmalı: döküm geri yüklenince her tablo yedek anına döner; günlük de dönseydi yedekten sonraki silmeler kaybolurdu. Üretimde `s3`: Lightsail disk görüntüsüne dönülse bile kalır. `local` (özel disk, web kökü dışında) yalnızca döküm geri yüklemesine dayanır. |
| `days` | 40 | En eski yedek (S3 dökümü 30 + 7 gün eski sürüm) bundan genç. Gizlilik metni: "yedeklerden en geç 40 gün içinde". `data:purge` bundan eski gün klasörlerini siler. |

Neden `config/davetkart.php` değil: o dosyaya dokunmak K18 cırcırını bütün dosyanın eski uzun yorumlarına açar.

Ayrıntı: [`app/Support/DeletionLog.md`](../app/Support/DeletionLog.md) · kitap: davetkart-docs
`operations/deployment/YEDEK-VE-GERI-YUKLEME.md` §7.3.
