# `database/migrations/2026_09_11_100000_make_orders_scope_not_null.php`

> **Faz:** 9 — Üretim hazırlığı, dosya A2.4 (**daralt** adımı)
> **Öncekiler:** [`2026_09_10_100000_add_scope_to_orders_table.md`](2026_09_10_100000_add_scope_to_orders_table.md)
> (genişlet) · A2.3 (taşı — `Order` · `OrderFactory` · `StartCheckoutAction`)
> **Kurallar:** **E2** (zorunluluk `if` ile değil kısıtla) · **E7**

---

## 1. Tek satırlık migration, üç adımlık desen

```sql
ALTER TABLE orders ALTER COLUMN scope SET NOT NULL;
```

Bu satırın tek başına bir anlamı yok; anlamı **sırasında**:

| Deploy | Ne gider | Şema | Kod | Çalışır mı |
|---|---|---|---|---|
| 1 | A2.2 | `scope` nullable | **eski** (scope yazmaz) | ✅ |
| 2 | A2.3 | değişmez | **yeni** (scope yazar) | ✅ |
| 3 | **A2.4** | `scope NOT NULL` | yeni | ✅ |

🔴 Üçü aynı deploy'a sıkıştırılırsa desen bir **tören** olur: nullable kolon
hiçbir şey korumaz, çünkü koruduğu pencere hiç açılmaz. Yerelde bu fark
görünmez — `php artisan migrate` üçünü ardışık koşar ve her şey yolunda
görünür. Fark yalnızca **canlı bir sunucuda**, eski süreç hâlâ istek işlerken
ortaya çıkar.

> Faz 9'un tekrar eden dersi: bu fazın ürettiği hataların çoğu yerelde
> görünmez. `composer check` bu üç dosyanın **sırasını** doğrulayamaz —
> yalnızca hepsinin koştuğunu görür.

---

## 2. 🔴 Güvenlik ağını elle yazmadım

Cazip olan şuydu:

```php
// ❌ yazılmadı
if (Order::query()->whereNull('scope')->exists()) {
    throw new RuntimeException('Scope backfill incomplete.');
}
```

Üç sebeple reddedildi:

1. **Veritabanı zaten reddediyor.** Tek bir `NULL` kalmışsa PostgreSQL deyimi
   çalıştırmaz:
   ```
   ERROR: column "scope" of relation "orders" contains null values
   ```
   Uygulama katmanında aynı garantiyi tekrarlamak **E2**'nin yasakladığı şey:
   *benzersizlik/zorunluluk `if` ile değil kısıtla kurulur.*
2. **`if` bir yarış penceresi açar.** Kontrol ile `ALTER TABLE` arasında yeni
   bir sipariş yazılabilir. Kısıtta böyle bir aralık yoktur.
3. **Hata mesajı zaten daha iyi.** PostgreSQL hangi tabloda hangi kolonun
   sorunlu olduğunu söylüyor; elle yazılan mesaj bundan azını taşırdı.

**Migration'ın burada patlaması istenen davranıştır.** Deploy durur, veri
bozulmaz, sebep ekranda yazar. Sessizce geçmesi, `scope`'u `NULL` olan bir
siparişin yayın hakkı sorgusundan kaçması demek olurdu — ve bu, tam olarak
kapatmaya çalıştığımız hatanın bir başka yüzü.

---

## 3. Neden `->change()` değil, ham SQL?

```php
$table->string('scope', 16)->nullable(false)->change();   // ❌ yazmadık
DB::statement('ALTER TABLE orders ALTER COLUMN scope SET NOT NULL');  // ✅
```

Laravel'in `->change()` metodu kolonu **yeniden tanımlar** — tip, uzunluk,
varsayılan, hepsi. Yazmadığın her modifier **sessizce düşer**. Burada tek bir
özelliği değiştiriyoruz; kolonun tamamını yeniden beyan etmek, dokunmadığın
şeyleri de riske atmaktır.

Proje zaten PostgreSQL'e bağlı (**K9'/K19**) ve `orders` tablosunun tüm CHECK
kısıtları da ham `ALTER TABLE` ile yazıldı — bu dosya o çizgiyi sürdürüyor.

---

## 4. ⚠️ Büyük tablolarda bu satır masum değildir

`SET NOT NULL`, PostgreSQL'de **ACCESS EXCLUSIVE** kilidi alır ve tabloyu
baştan sona tarar. `orders` bugün küçük, dolayısıyla milisaniyeler sürer. Ama
milyonlarca satırda aynı satır tabloyu dakikalarca kilitler — o süre boyunca
hiçbir okuma veya yazma geçmez.

Büyük tablolarda doğru yol iki adımdır (PostgreSQL 12+):

```sql
ALTER TABLE orders ADD CONSTRAINT orders_scope_not_null
    CHECK (scope IS NOT NULL) NOT VALID;          -- anında, kilitsiz
ALTER TABLE orders VALIDATE CONSTRAINT orders_scope_not_null;  -- tarar, kilitlemez
ALTER TABLE orders ALTER COLUMN scope SET NOT NULL;  -- doğrulanmış CHECK varsa TARAMAZ
```

Bugün buna gerek yok ve **gereksiz karmaşıklık yazmıyoruz** (K15). Buraya
yazılma sebebi B6: *bir çözümün hangi ölçekte geçerli olduğu da bilgidir.*

---

## 5. Bu migration'dan sonra dört kombinasyonun son hâli

| `scope` | `invitation_id` | Anlamı | Mümkün mü |
|---|---|---|---|
| `account` | `NULL` | Paket alımı | ✅ |
| `invitation` | dolu | Tekil, bağlı | ✅ |
| `invitation` | `NULL` | Serbest bırakılmış tekil | ✅ |
| `account` | dolu | Anlamsız | ❌ CHECK |
| `NULL` | herhangi | — | ❌ **artık NOT NULL** |

Şema tarafı bitti. Bundan sonrası davranış: resolver'ın sorgusu (A2.5), serbest
bırakma kuralı (A2.6) ve yeniden bağlama (A2.7).

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Üç adımı tek deploy'a sıkıştırmak | Desen törene döner; koruduğu pencere hiç açılmaz (§1) |
| 2 | Elle `whereNull` kontrolü eklemek | Yarış penceresi + **E2** ihlali (§2) |
| 3 | `->change()` kullanmak | Yazılmayan modifier'lar sessizce düşer (§3) |
| 4 | Migration patlayınca `NULL`'ları silmek | Ödeme kaydı silinir; doğru çözüm geri doldurmayı tamamlamak |
| 5 | Büyük tabloda düz `SET NOT NULL` | Tablo dakikalarca kilitlenir (§4) |

---

## 7. Kendin dene

```powershell
php artisan migrate
```

🔴 Zorunluluğun gerçekten kurulduğunu gör — A2.2'nin kılavuzunda **geçen** aynı
sorgu artık **geçmemeli**:

```sql
UPDATE orders SET scope = NULL WHERE id = (SELECT id FROM orders LIMIT 1);
-- ERROR: null value in column "scope" of relation "orders" violates not-null constraint
```

A2.2'de bu sorgu `UPDATE 1` diyordu. Aradaki fark, CHECK ile `NOT NULL`
arasındaki farkın kendisi.

Geri alma da sınanır (**B5**):

```powershell
php artisan migrate:rollback --step=1
php artisan migrate
```

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **ACCESS EXCLUSIVE** | PostgreSQL'in en güçlü kilidi; okuma dâhil her şeyi bekletir |
| **`NOT VALID`** | Kısıtı yeni satırlara uygulayıp mevcut satırları taramayan mod |
| **Modifier** | Kolon tanımına eklenen ikincil özellik (`nullable`, `default`) |
| **Daralt (contract)** | Şema geçişinin, eski hâle izin veren esnekliği kaldıran son adımı |

---

## 9. Sırada ne var?

**A2.5 — `OrderEntitlementResolver`.** `whereNull('invitation_id')` yerine
kapsam yüklemi: `scope IN (grantsAcrossAccount olanlar) OR invitation_id = :id`.
Tek `where` değişiyor ama deliği fiilen kapatan satır bu — şemayı hazırladık,
şimdi onu **okuyan** kodu düzeltiyoruz.
