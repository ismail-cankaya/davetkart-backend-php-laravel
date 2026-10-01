# `database/migrations/2026_10_01_100000_make_orders_user_id_nullable.php`

> **Faz:** 10 — Dilim D, adım 10.38 · **Karar:** **K97** (H-1: hesap silinince anonimleştirme)
> **Önce oku:** `2026_09_03_100000_create_orders_table` (Faz 7) · [`DeleteAccountAction.md`](../../app/Actions/Auth/DeleteAccountAction.md) (10.39)

## 1. Ne değişti?

| | Faz 7 | Faz 10 |
|---|---|---|
| `orders.user_id` | `NOT NULL` | `NULL` olabilir |
| Kullanıcı silinince | `ON DELETE CASCADE`: sipariş de silinir | `ON DELETE SET NULL`: sipariş kalır, sahibi boşalır |

## 2. Neden? (K97)

İsmail'in kararı (1 Ekim 2026): hesap silinince **anonimleştirme**. Faz 7'nin
`cascadeOnDelete`'i iki şeyle çelişiyordu:

1. **K82:** muhasebe kaydı kullanıcının bir tıkıyla yok olamaz. Aynı migration'ın
   `invitation_id`'si bu yüzden `nullOnDelete` yazılmıştı. Davetiye silinince kalan
   kayıt, kullanıcı silinince kayboluyordu.
2. **Ödenmiş bir paranın izi:** sağlayıcının tarafında bir ödeme var
   (`provider_ref`). Bizim tarafta karşılığı kaybolursa iade, itiraz ya da vergi
   kaydı soruları cevapsız kalır.

**Anonim kalan satırda kişisel veri var mı?** Hayır. Ad ve e-posta `users`
tablosunda; sipariş yalnızca plan, tutar, para birimi, durum, zamanlar ve
sağlayıcı referansını taşıyor. *"Bu ödemeyi kim yaptı?"* sorusunun cevabı
kaybolur, *"bu ödeme yapıldı mı?"* sorusununki kalır.

## 3. PostgreSQL'de neden üç adım?

```php
$table->dropForeign(['user_id']);
$table->foreignId('user_id')->nullable()->change();
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
```

Bir yabancı anahtarın `ON DELETE` davranışı yerinde değiştirilemez: kısıt düşürülür,
kolon `NULL` kabul eder hâle gelir, kısıt yeniden kurulur. Laravel PostgreSQL
migration'larını tek bir transaction'da koşar, yani *"kısıtsız tablo"* anı dışarıdan
hiç görünmez.

## 4. 🔴 `down()` neden reddedebilir?

Anonim bir sipariş varsa (`user_id IS NULL`) `NOT NULL` kısıtı onu kabul etmez. Geri
almanın tek yolu o satırları silmek olurdu, yani bu migration'ın korumaya çalıştığı
muhasebe kaydını yok etmek. `down()` bu durumda kaç satır olduğunu söyleyen bir
istisna fırlatır ve kararı bir insana bırakır. Boş tabloda (ya da hiç hesap
silinmemişken) sorunsuz geri alınır.

## 5. Doğrulama (1 Ekim 2026, `davetkart_test`, PostgreSQL 18.4)

```
migrate            → user_id nullable: YES · ON DELETE: n (SET NULL)
migrate:rollback   → DONE (anonim satır yokken)
migrate            → DONE
```

Davranışın kendisini `AccountDeletionTest` sınıyor: hesap silinir, sipariş satırı
`user_id = NULL` ile kalır (10.41).

## 6. Kodda neyi değiştirmedi?

Hiçbir sorgu `user_id`'nin dolu olduğunu varsaymıyor. Siparişler her yerde
`where('user_id', $user->id)` ile soruluyor (yayın hakkı, sipariş listesi, serbest
bırakılmış siparişi bağlama); `NULL` satırlar bu sorgulara doğal olarak girmiyor.
`OrderPolicy::owns()` `===` ile karşılaştırıyor; `null` hiçbir kullanıcının
kimliğine eşit değil.
