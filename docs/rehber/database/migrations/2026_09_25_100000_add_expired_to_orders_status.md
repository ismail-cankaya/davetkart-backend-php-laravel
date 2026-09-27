# `database/migrations/2026_09_25_100000_add_expired_to_orders_status.php`

> **Faz:** 10 — Sertleştirme, adım 10.4 · **Karar:** **K89**
> **Önce oku:** [`../../app/Enums/OrderStatus.md`](../../app/Enums/OrderStatus.md) §11 ·
> [`2026_09_03_100000_create_orders_table.md`](2026_09_03_100000_create_orders_table.md) (kısıtların ilk hâli)
> **Kurallar:** **K39** (liste enum'dan) · **E2** (kural kısıtta) · genişlet/daralt (Faz 9, A2.2–A2.4)

---

## 1. Ne yapıyor?

`orders.status` kolonunun CHECK kısıtına `expired` değerini ekler:

```
önce:  status IN ('pending', 'paid', 'failed', 'refunded')
sonra: status IN ('pending', 'paid', 'failed', 'expired', 'refunded')
```

Enum 10.3'te değişti; ama veritabanı enum'u **tanımaz**. `orders:expire` komutu
(10.5) `expired` yazmaya başladığında kısıt eski listeyi taşıyorsa PostgreSQL
yazmayı reddeder:

```
ERROR: new row for relation "orders" violates check constraint "orders_status_check"
```

Bu migration o reddi kaldırır. Hepsi bu — ama nasıl yaptığı öğretici.

---

## 2. 🔴 Neden "düşür → yeniden yaz"?

PostgreSQL'de bir CHECK kısıtının **ifadesi değiştirilemez**. `ALTER TABLE …
ALTER CONSTRAINT` yalnızca ertelenebilirlik (*deferrable*) gibi özellikleri
değiştirir, koşulu değil. Tek yol:

```sql
ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check;
ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN (…));
```

İki deyimin arasında tablo bir an **kısıtsız** kalır. Bu an dışarıdan görünür
mü? Hayır: Laravel PostgreSQL'de her migration'ı **bir transaction içinde**
koşar (PostgreSQL DDL'i transaction'a alabilen nadir veritabanlarından biridir;
MySQL alamaz). Başka bir bağlantı ya eski kısıtı görür ya yenisini — arasını
asla.

`IF EXISTS`: kısıt bir şekilde yoksa (elle düşürülmüş bir geliştirme veritabanı)
migration patlamaz, doğru kısıtı kurar. İdempotan bir DDL, tekrar koşulabilen
bir DDL'dir.

---

## 3. K39: dosyada `'expired'` kelimesi hiç geçmiyor

```php
public function up(): void
{
    $this->rebuildStatusCheck(OrderStatus::values());
}
```

Liste enum'dan okunur, elle yazılmaz. `create_orders_table` de aynı şeyi
yapıyordu ve bunun ince bir sonucu var:

| Veritabanı | `create_orders_table` hangi listeyi kurar? | Bu migration ne yapar? |
|---|---|---|
| **Üretim** (Faz 7'de kuruldu) | Eski liste (4 değer) — o gün enum öyleydi | Eskisini düşürür, 5 değerle kurar ✅ |
| **Test** (`RefreshDatabase`, her koşu sıfırdan) | Yeni liste (5 değer) — enum bugün böyle | Aynısını düşürür, aynısını kurar (zararsız) |

Yani testlerde bu migration **hiçbir şeyi değiştirmiyor** — testler zaten 10.3
commit'inden beri yeşil. Onu gerçekten sınayan tek ortam, Faz 7'den beri
yaşayan bir veritabanı. Bu yüzden §9'daki elle deneme şart.

> **Ders:** enum'dan beslenen bir migration, koştuğu **günün** enum'unu
> okur. `create_orders_table` bugün koşsa 5 değer yazar; Eylül başında koşmuş
> olan 4 yazdı. Aynı dosya iki farklı şema üretebiliyor — bu yüzden şemayı
> değiştiren her enum değişikliği **yeni** bir migration ister; eskisini
> düzenlemek yetmez.

---

## 4. 🔴 `down()`: önce veri, sonra kısıt

```php
public function down(): void
{
    DB::table('orders')
        ->where('status', OrderStatus::Expired->value)
        ->update(['status' => OrderStatus::Failed->value, 'updated_at' => now()]);

    $this->rebuildStatusCheck(array_values(array_diff(
        OrderStatus::values(),
        [OrderStatus::Expired->value],
    )));
}
```

İki karar:

**(1) Sıra.** `ADD CONSTRAINT … CHECK` tabloyu baştan sona **doğrular**. Tek
bir `expired` satırı kalmışsa geri alma patlar. Önce veri eski dünyaya
taşınır.

**(2) Hedef neden `failed`?** Çünkü Faz 9'un dünyasında süresi dolmuş siparişin
karşılığı buydu — `orders:expire` tam olarak onu yazıyordu. Geri alma, dünyayı
**o günkü anlamıyla** geri kurar.

⚠️ **Bu dönüşüm kayıplıdır.** Geri alıp yeniden `migrate` edersen o satırlar
`expired`'a **dönmez**; artık kesin bir red gibi görünürler ve geç gelen
ödemeleri yine yutulur. Geri alma bir kurtarma aracıdır, gidip gelinen bir
anahtar değil.

`down()` listeyi yine enum'dan türetiyor (`array_diff`), elle yazmıyor: yarın
enum'a bir durum daha eklenirse geri alma onu **korur**, yalnızca `expired`'ı
çıkarır.

`updated_at` elle yazılıyor: Query Builder'ın toplu `update()`'i zaman
damgalarını kendisi doldurmaz (`ExpireStaleOrders` §2'nin aynı notu). Satırın
değiştiği görünür kalmalı.

---

## 5. 🔴 Deploy sırası

Değer **eklemek** bir genişletme (*expand*) adımıdır — Faz 9'un `scope`
göçündeki ilk adımın aynısı:

| Sıra | Ne olur | Sonuç |
|---|---|---|
| ✅ migration → kod | Kısıt `expired`'ı kabul eder; eski kod onu hiç yazmaz; sonra yeni kod yazmaya başlar | Sorunsuz |
| ⚠️ kod → migration | Arada `orders:expire` koşarsa `UPDATE … 'expired'` CHECK'e takılır | Komut hata verir, **veri bozulmaz**: satırlar `pending` kalır, bir saat sonra yeniden denenir |

İkinci sıra bile güvenli bir hataya düşüyor — kısıtın işi tam olarak bu (E2).

**Geri alırken sıra tersine döner:** önce `migrate:rollback` (satırlar `failed`'a
döner), **sonra** eski kod. Eski kod önce gelirse, `expired` bir satırı okuyan
her yol (`OrderStatus::from('expired')`) `ValueError` fırlatır — örneğin o
siparişe gelen bir webhook 500 alır.

---

## 6. `orders_paid_at_check`'e neden dokunulmadı?

```sql
CHECK ((status IN ('paid', 'refunded')) = (paid_at IS NOT NULL))
```

Liste `OrderStatus::paidValues()`'tan, o da `hasBeenPaid()`'ten türetiliyor.
`expired` için `hasBeenPaid()` `false` (para gelmedi), dolayısıyla liste
**değişmedi** ve kısıtı yeniden kurmak gereksiz. `expired` bir satır `paid_at =
NULL` taşır — `pending` iken de öyleydi.

Plan bunu açıkça yazmıştı: *"`paid_at` CHECK'i etkilenmez."* Etkilenseydi bu,
`hasBeenPaid()`'in yanlış cevap verdiğinin işareti olurdu.

---

## 7. Büyük tabloda ne değişirdi?

`ADD CONSTRAINT` doğrulama boyunca tabloyu **kilitler** (yazmalara kapalı).
`orders` küçük bir tablo; bugün milisaniyeler. Milyonlarca satırlık bir tabloda
kilit saniyelerce sürer ve o sürede ödeme webhook'ları bekler. Bilinen çözüm iki
adım:

```sql
ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (…) NOT VALID;  -- anında, yeni satırlara uygulanır
ALTER TABLE orders VALIDATE CONSTRAINT orders_status_check;               -- eski satırları kilitsiz doğrular
```

Burada **yazılmadı**: ölçeğin gerektirmediği bir karmaşıklık, okuyana yalnızca
soru bırakır. Ama tablo büyüdüğü gün hatırlanmalı.

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `create_orders_table`'ı düzenleyip yeni migration yazmamak | Test yeşil, **üretim** eski kısıtla kalır; `orders:expire` her saat patlar |
| 2 | Listeyi elle yazmak (`'pending','paid',…`) | Enum bir daha değişince kısıt sessizce eskir (K39) |
| 3 | `down()`'da önce kısıtı kurmak | `expired` satırlar yüzünden geri alma patlar |
| 4 | `down()`'da `expired` satırları silmek | Muhasebe kaydı yok olur; ödeme denemesinin izi kaybolur |
| 5 | Eski kodu migration'dan önce geri almak | `expired` satırları okuyan her yol `ValueError` (§5) |
| 6 | `paid_at` kısıtını da "garanti olsun" diye yeniden kurmak | Gereksiz tam tablo doğrulaması; değişmeyen bir şeyi değişmiş gibi gösterir |

---

## 9. Kendin dene

```powershell
php artisan migrate
```

```sql
-- pgAdmin 4
SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'orders_status_check';
-- Beklenen: … 'pending', 'paid', 'failed', 'expired', 'refunded' …
```

Geri almayı **veriyle** dene — asıl öğretici kısım bu:

```powershell
php artisan tinker --execute "App\Models\Order::factory()->create(['status' => App\Enums\OrderStatus::Expired]);"
php artisan migrate:rollback --step=1
```

```sql
-- pgAdmin 4
SELECT status, count(*) FROM orders GROUP BY status;
-- Beklenen: failed | 1          ← expired satır failed'a döndü

UPDATE orders SET status = 'expired';
-- Beklenen: ERROR … violates check constraint "orders_status_check"   ← eski kısıt geri geldi
```

```powershell
php artisan migrate      # kısıt yeniden 5 değerli
```

Kum havuzunda (PHP 8.4 + PostgreSQL 16) bu sıra birebir koşturuldu ve
beklenenlerle aynı çıktıyı verdi. 🔴 Senin makinende (PHP 8.5 + PostgreSQL 18)
de koş — **B7**: başka bir makinede görülmüş sonuç, bu makinenin kaydı değildir.

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **CHECK kısıtı** | Kolonun alabileceği değerleri veritabanı seviyesinde sınırlayan kural |
| **Transactional DDL** | Şema değişikliklerinin de geri alınabilir bir transaction içinde koşması (PostgreSQL ✅, MySQL ❌) |
| **Genişlet/daralt** | Şemayı önce eski ve yeni kodun ikisinin de çalışacağı hâle genişletip, sonra daraltmak |
| **Kayıplı geri alma** | Geri almanın bilgi yok etmesi: yeniden ileri alınca eski hâl dönmez |
| **`NOT VALID`** | Kısıtı yalnızca yeni satırlara uygulayıp eski satırların doğrulamasını sonraya bırakmak |
