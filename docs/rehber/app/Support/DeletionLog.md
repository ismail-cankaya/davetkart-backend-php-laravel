# `app/Support/DeletionLog.php` — Kılavuz

> **Faz:** 19 ortak düzeltme (B-E1, 25 Kasım 2026) · **Karar:** K233
> **Yazan:** [`RecordDeletion`](../Observers/RecordDeletion.md) · **Okuyan:** `data:reapply-deletions`, `data:purge`

## Neden

Gizlilik metni (K231) silinen verinin yedeklerden de en geç 40 gün içinde gittiğini söylüyor. Ama bir döküm
geri yüklenirse, yedekten **sonra** silinen hesap/davetiye/yanıt canlıya geri gelir. Günlük o silmeleri
hatırlar; geri yüklemeden sonra yeniden uygulanır.

## Desen

- Silme başına **bir dosya**: `deletion-log/<YYYY-MM-DD>/<HHMMSS.mikro>-<tür>-<kimlik>.json`, içinde yalnızca
  `{"subject","id","at"}` (UTC). Kişisel veri yok: ad, e-posta, içerik yazılmaz.
- Ekleme (`append`) yok: S3'te append oku-yaz demektir, eşzamanlı iki silme birbirini ezer. Ayrı dosya yarışmaz.
- `since($an)`: o günden itibaren klasörleri okur, `at >= $an` olanları eskiden yeniye döner; bozuk dosya atlanır.
- `prune($sınır)`: günü sınırdan eski klasörleri siler (`data:purge` → 40 gün).

## Sık hata

- Diski veritabanıyla birlikte geri yüklenen bir yere koymak (ör. bir tablo): günlük de yedek anına döner.
- Test: `TestCase` günlüğü sahte bir diske (`deletion-log-test`) yönlendirir; yoksa her silme testi gerçek diske yazardı.

## Nasıl denenir

`php artisan test --compact tests/Feature/DeletionLogTest.php`
