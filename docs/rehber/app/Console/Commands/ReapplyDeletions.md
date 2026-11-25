# `app/Console/Commands/ReapplyDeletions.php` — `data:reapply-deletions`

> **Faz:** 19 ortak düzeltme (B-E1, 25 Kasım 2026) · **Karar:** K233
> **Kitap:** davetkart-docs `operations/deployment/YEDEK-VE-GERI-YUKLEME.md` §7.2 ve §9

## Ne zaman

Yalnızca **veritabanı yedekten geri yüklendikten sonra**, `artisan up`'tan önce:

```bash
php artisan data:reapply-deletions --since=2026-11-20T03:00:00Z --dry-run   # önce say
php artisan data:reapply-deletions --since=2026-11-20T03:00:00Z
```

`--since` = geri yüklenen dökümün alındığı an (manifestteki zaman). Zorunlu; yoksa ya da okunamazsa çıkış 1
(kapalı başarısızlık: yanlış bir varsayılan, yedekten önceki silmeleri de "yeniden" uygulardı).

## Ne yapar

1. [`DeletionLog::since()`](../../Support/DeletionLog.md) ile o andan beri yazılan kayıtları eskiden yeniye okur.
2. Her kaydı türüne göre **aynı Action'la** yeniden siler ([`DeletionSubject`](../../Enums/DeletionSubject.md)).
   Kayıt zaten yoksa "zaten yoktu" sayılır (yedekten önce silinmiş ya da ikinci koşu: komut idempotent).
3. Davetiyede çöp kutusu zamanı günlükteki ana yazılır: 30 günlük süre yeniden başlamaz.
4. Sonunda `data:purge`: kural tabanlı silmeler (misafir verisi, mesajlar, süresi dolan çöp kutusu) aynı kuralla gider.

## Bilinen sınırlar

- Davetiye silmesinde sipariş hakkının serbest kalıp kalmadığı (`DeleteInvitationAction`, yayından 3 gün)
  yeniden uygulama **anına** göre hesaplanır; asıl silme pencerenin içinde, yeniden uygulama dışındaysa hak
  yanmış sayılır. Nadir (yedek en çok birkaç gün eski olur); olursa sipariş elle serbest bırakılır.
- Yeniden uygulanan silmeler de günlüğe yazılır (aynı kimlik ikinci kez): zararsız.

## Nasıl denenir

`php artisan test --compact tests/Feature/DeletionLogTest.php` (geri yükleme benzetimi, dry-run, eski kayıt, galeri dizisi).
