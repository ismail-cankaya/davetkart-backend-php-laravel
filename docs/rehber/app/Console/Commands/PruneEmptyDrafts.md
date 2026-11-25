# `app/Console/Commands/PruneEmptyDrafts.php` — `invitations:prune-empty-drafts`

> **Faz:** 19 ortak düzeltme — Hat A `E2` · **Karar:** K261 (K192'nin "boş taslak temizliği yok" maddesini tek seferlik komutla kapatır)
> **Test:** `tests/Feature/PruneEmptyDraftsTest.php`

## Neden

19.26'ya (FE-50, K192) kadar sihirbaz her ziyarette sunucuda boş bir taslak ("Düğün", isimsiz) açıyordu. 19.26 bunu durdurdu ve
50'lik taslak tavanını getirdi, ama eskiden açılmış boş taslaklar silinmedi; tavana sayılıyorlar. Çok gezinmiş eski bir kullanıcı
tavana bu boş satırlarla yaklaşabilir. İsmail'in kararı (25 Kasım 2026): tek seferlik temizlik komutu.

## Ne siler

Hepsi aynı anda doğru olan taslaklar:

| Koşul | Neden |
|---|---|
| `status != published`, `published_at IS NULL` | Hiç yayınlanmamış: hak ve misafir verisi yok |
| `names`, `venue` boş/boşluk, `event_at IS NULL` | K192'nin "gerçek içerik" kuralının tersi (frontend `hasDraftContent` ile aynı üç alan) |
| `updated_at` `--older-than` günden (varsayılan 7) eski | "Davetiyeni Oluştur" içerik boşken de taslak açar (K192); şu an düzenlenen taslağa dokunulmaz |
| Siparişi yok (`orders`) | Serbest/bağlı hak taşıyan satır `DeleteInvitationAction`'ın kuralına tabidir; bu komut ona girmez |
| Medyası yok (`media`) | Galeri dosyası olan taslak boş değildir |

Silme **soft delete**'tir (`deleted_at`): satır tavandan hemen düşer (`CreateInvitationAction` silinmişleri saymaz), kalıcı silme
`data:purge`'ün 30 günlük kuralıyla olur (K98). Koşullar tek bir `UPDATE … WHERE` içinde silme anında yeniden sınanır: sayım ile
silme arasında içerik yazılan taslak elenir. Model olayı tetiklenmez; yayınlanmamış taslağın misafir önbelleği yoktur.

## Kullanım

```powershell
php artisan invitations:prune-empty-drafts --dry-run      # kaç taslak silinecek
php artisan invitations:prune-empty-drafts                # sil (7 günden eski)
php artisan invitations:prune-empty-drafts --older-than=30
```

Zamanlayıcıda **yok** (K84: bakım komutu önce elle, `--dry-run` ile). 19.26'dan sonra yeni boş taslak yalnızca açık istekle doğar;
tekrar gerekirse aynı komut idempotenttir. `--older-than` 1'den küçükse ya da sayı değilse `INVALID` döner, hiçbir şey silmez.

## Nasıl denenir

`php artisan test --filter=PruneEmptyDraftsTest` (boş/boşluklu silinir; isimli, tarihli, yeni, yayınlanmış, siparişli, medyalı kalır;
`--dry-run` silmez; `--older-than=0` reddedilir).
