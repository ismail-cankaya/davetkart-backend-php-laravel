# `app/Console/Commands/MoveMediaDisk.php` — `media:move-disk`

> **Faz:** 19 ortak düzeltme — Hat A `E6` · **İlgili karar:** K183 (üretimde medya yalnızca `s3`) · **Test:** `tests/Feature/MoveMediaDiskTest.php`

## Neden

K183 (19.5, ARC-04) üretimde yüklemeyi `s3` dışındaki diske kapattı ama **eski** dosyaları taşımadı: `public` diskte kalan
medya satırı `disk = public` taşıdığı için okunmaya devam eder (`Media::url()` diski satırdan okur), yeni yüklemeler ise `s3`'e
gider. Medya iki yerde yaşar; yedek, temizlik ve sunucu taşıması ikisini de hatırlamak zorunda kalır.

## Ne yapar

`disk = --from` (varsayılan `public`) olan her satır için, `chunkById(100)` ile:

1. Dosyayı hedefe **akışla** kopyalar (`readStream` → `writeStream`; büyük video belleğe alınmaz). Hedefte aynı boyutta dosya
   varsa yeniden yazmaz: yarıda kalan bir koşu tekrar başlatılabilir.
2. Hedefteki boyut kaynağınkine eşit değilse satıra **dokunmaz**.
3. Satırı koşullu çevirir: `UPDATE media SET disk = <hedef> WHERE id = ? AND disk = <kaynak>`.
4. Kaynak dosya yalnızca `--delete-source` ile, satır çevrildikten sonra silinir.
5. Etkilenen her davetiye için `InvitationChanged` → `ClearInvitationCache` (K48): misafir önbelleği eski URL'leri taşıyordu.

Kaynak dosyası olmayan satırlar listelenir, değişmez ve komut `FAILURE` ile biter (elle karar). Aynı disk ya da tanımsız disk
→ `INVALID`, hiçbir şey yapılmaz.

## Kullanım (sunucuda, İsmail)

```bash
php artisan media:move-disk --dry-run                     # public → davetkart.media.disk (üretimde s3)
php artisan media:move-disk                               # kopyala + satırları çevir, kaynak kalır
php artisan media:move-disk --delete-source               # doğrulandıktan sonra ikinci koşu: kaynağı da sil
```

Zamanlayıcıda **yok** (K84). Kuyruktaki görsel küçültme işi satırı yeniden okur (`SerializesModels`); taşınmış satırda yeni diskte
çalışır.
