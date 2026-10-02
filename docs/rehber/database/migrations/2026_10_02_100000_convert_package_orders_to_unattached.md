# `database/migrations/2026_10_02_100000_convert_package_orders_to_unattached.php`

> **Faz:** 10 — Dilim F, adım 10.58 · **Karar:** **K99** (paket tek davetiye)
> **Önce oku:** [`PublishEntitlementResolver.md`](../../app/Contracts/PublishEntitlementResolver.md) → *Faz 10*

---

## Ne yapıyor?

```sql
UPDATE orders SET scope = 'invitation' WHERE scope = 'account';
```

Faz 9'a kadar fiyat sayfasından alınan sipariş `'account'` kapsamıyla yazılıyor ve hesabın bütün
davetiyelerini açıyordu. K99'dan sonra her sipariş tek davetiyelik. Bu migration eski satırları yeni
biçime çeviriyor. Satırların davetiyesi zaten yok (CHECK kısıtı bunu garanti ediyor), yani çevrilen
satır **bağsız tekil sipariş** oluyor ve sahibinin bir sonraki yayınında bağlanıyor.

## Neden yalnızca veri, şema değil?

Kolon ve CHECK kısıtı kalıyor. `'account'` değeri hâlâ geçerli bir değer, yalnızca artık yazılmıyor.
Kısıtı kaldırmak, ileride biri `'account'` yazarsa hiçbir şeyin onu durdurmaması demekti.

## Geri alma

`down()` bilerek **boş**. Hangi satırın eskiden paket olduğu, çevrildikten sonra bilinemez. Geri
almak şemayı değiştirmediği için `migrate:rollback`'i engellemiyor; satırlar oldugu gibi kalır.

## Üretim

Üretimde `'account'` satırı **yok** (henüz satış yapılmadı). Migration geliştirme veritabanları için.

**Test:** `PaywallTest::the_migration_turns_old_packages_into_unclaimed_orders` eski biçimde bir satır
kuruyor, `up()`'ı çağırıyor ve satırın `'invitation'` + davetiyesiz olduğunu görüyor.
