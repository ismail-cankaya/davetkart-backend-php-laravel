# `database/migrations/2026_09_10_100000_add_scope_to_orders_table.php`

> **Faz:** 9 — Üretim hazırlığı, dosya A2.2
> **İlgili:** [`../../app/Enums/OrderScope.md`](../../app/Enums/OrderScope.md) ·
> [`2026_09_03_100000_create_orders_table.md`](2026_09_03_100000_create_orders_table.md)
> **Kurallar:** **N4** · **E2** (kural `if` ile değil kısıtla) · **E7** · **E11**
> (çok kolonlu değişmez CHECK'e) · **K39** (değerler enum'dan) · **K70**

---

## 1. Neyi kapatıyor

`orders.invitation_id` iki farklı gerçeği tek biçimde saklıyordu. Ayrıntı ve
saldırı zinciri `OrderScope.md` §1'de; burada yalnızca şemaya düşen kısım var.

Migration'dan sonra dört kombinasyonun anlamı:

| `scope` | `invitation_id` | Anlamı | Kısıt |
|---|---|---|---|
| `account` | `NULL` | Paket alımı | ✅ |
| `invitation` | dolu | Tekil, bir davetiyeye bağlı | ✅ |
| `invitation` | `NULL` | 🔴 Serbest bırakılmış tekil | ✅ **bu dilimin amacı** |
| `account` | dolu | Anlamsız | ❌ `orders_account_scope_has_no_invitation_check` |

---

## 2. 🔴 Genişlet / Daralt (expand & contract)

Kolon burada **nullable** ekleniyor ve `NOT NULL` A2.4'e bırakılıyor. Sebep
yerelde görünmez, üretimde belirleyicidir.

Bir deploy anlıktır ama **atomik değildir**:

```
t0  eski kod çalışıyor, migration henüz koşmadı
t1  migration koşuyor          ← eski kod HÂLÂ çalışıyor ve scope YAZMIYOR
t2  yeni kod devreye giriyor
```

`t1` ile `t2` arasında kolon `NOT NULL` olsaydı, o aralıkta gelen **her ödeme
isteği** 500 verirdi — çünkü hâlâ ayakta olan eski `StartCheckoutAction` o
kolonu bilmiyor. Üstelik `t1`'de rollback yapmak da migration'ı geri almayı
gerektirir. Doğru sıra üçe bölünür:

| Adım | Ne yapar | Nerede |
|---|---|---|
| **1. Genişlet** | Nullable kolon + geri doldurma + kısıtlar | **bu dosya** |
| **2. Taşı** | Bütün yazıcılar `scope` yazar | A2.3 |
| **3. Daralt** | `SET NOT NULL` | A2.4 (ayrı migration) |

Her adım tek başına dağıtılabilir ve her adımdan sonra sistem çalışır. Bu, aynı
zamanda `composer check`'i her adımda yeşil tutan şey: bugün `OrderFactory`
`scope` yazmıyor, kolon nullable olduğu için 198 test etkilenmiyor.

> **Bu bir Faz 9 dersi.** Faz 3–8'de migration'lar hep yeni tablo yarattı; yeni
> bir tabloya kimse yazmıyordu, dolayısıyla `NOT NULL` bedavaydı. **Var olan ve
> yazılmakta olan** bir tabloya zorunlu kolon eklemek başka bir problemdir.

---

## 3. 🔴 CHECK, NULL'u reddetmez

İki kısıt da eklendi ama ikisi de şu an `NULL`'a izin veriyor. Bu bir eksiklik
değil, SQL'in **üç değerli mantığının** doğrudan sonucu:

```sql
NULL IN ('invitation', 'account')   →  NULL     -- TRUE değil, FALSE de değil
```

Ve CHECK kısıtının kuralı şudur:

> **Bir CHECK, satırı yalnızca sonuç `FALSE` olduğunda reddeder.** `TRUE` ve
> `NULL` (bilinmiyor) geçer.

İkinci kısıtta da aynısı olur:

```sql
scope <> 'account' OR invitation_id IS NULL
NULL   <> 'account' OR FALSE   →  NULL OR FALSE  →  NULL  → geçer
```

Bu, sık düşülen bir tuzaktır: bir CHECK yazıp "artık zorunlu" sanmak. Kolonu
gerçekten zorunlu kılan tek şey **`NOT NULL`**'dır. Faz 2'nin **A4**'ü
(*güvenlik kodunda kısa devre yasak*) ile aynı aile: bir kontrolün "çalışıyor
görünmesi", çalıştığı anlamına gelmez.

---

## 4. Geri doldurma ve bir zaman bağımlılığı

```sql
UPDATE orders SET scope = CASE WHEN invitation_id IS NULL THEN ? ELSE ? END
```

Bu sorgu **bugün** doğru, çünkü bugün `invitation_id`'nin `NULL` olmasının tek
sebebi paket alımı: kod tabanında `forceDelete()` çağıran tek bir satır yok, yani
`nullOnDelete` hiç ateşlenmemiş.

🔴 **Aynı sorgu A2.5'ten sonra yanlış cevap verirdi.** Kalıcı silme geldiğinde
`invitation_id IS NULL` iki sebepten olabilecek ve geri doldurma silinmiş tekil
siparişleri "paket" diye işaretleyip tam da kapattığımız deliği geri açacaktı.

> **Ders:** bir göç, koştuğu **anın** dünyasını varsayar. Migration dosyaları
> kalıcıdır ama içindeki gerekçe tarihlidir — bu yüzden gerekçe dosyaya yazılır,
> kafada tutulmaz.

Değerler `?` bağlamalarıyla geçiliyor (enum'dan geldikleri için string
birleştirme de güvenli olurdu, ama bağlama okunurluğu artırıyor). CHECK
kısıtlarında bağlama **kullanılamaz** — `ALTER TABLE` hazırlanmış deyim kabul
etmez; orada K39'un derleme-zamanı sabiti gerekçesi geçerli.

---

## 5. `after()` neden yok?

```php
$table->string('scope', 16)->nullable();      // ✅
$table->string('scope', 16)->after('invitation_id');   // ❌ yazmadık
```

`after()` **MySQL'e özgü** bir modifier'dır. PostgreSQL grameri onu tanımaz ve
**sessizce yok sayar** — yani kolon yine sona eklenir. Sessizce hiçbir şey
yapan bir çağrı, yapmadığı şeyi yaptığını sandırır (ders 35'in ailesi: bir
ayarın açık olması, işini yaptığı anlamına gelmez).

PostgreSQL'de kolon sırası zaten anlamsız: `SELECT *` kullanmıyoruz, Eloquent
kolonları ada göre okuyor.

---

## 6. Yeni indeks neden yok?

Resolver'ın sorgusu `scope IN (...) OR invitation_id = ?` hâline gelecek. Yine de
`scope`'a indeks eklemedim:

- Kolonun **iki** farklı değeri var (düşük kardinalite). Satırların ~yarısını
  eşleyen bir indeksi planlayıcı zaten kullanmaz, sıralı tarama seçer.
- Sorgunun seçici kısmı `user_id`; onu mevcut `['user_id', 'status']` indeksi
  zaten karşılıyor. `scope` o kümenin içinde bir **filtre**, arama anahtarı değil.

Ölçmeden indeks eklemek, ölçmeden optimize etmektir: her indeks yazma
maliyetidir ve `orders` bir yazma tablosudur.

---

## 7. Bu migration'ın YAPMADIKLARI (B6)

| Yapmaz | Nerede |
|---|---|
| Kolonu zorunlu kılmak | A2.4 (`SET NOT NULL`) |
| Yazıcıları güncellemek | A2.3 (`Order` · `OrderFactory` · `StartCheckoutAction`) |
| Resolver'ın sorgusunu değiştirmek | A2.5 |
| Serbest bırakma/yeniden bağlama kuralı | A2.6 · A2.7 |
| Paketin kaç yayın açtığını sınırlamak | 🔴 K43 hâlâ açık |

---

## 8. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | Kolonu doğrudan `NOT NULL` eklemek | Deploy penceresinde her ödeme 500 verir (§2) |
| 2 | CHECK yazıp "zorunlu oldu" sanmak | `NULL` geçer; kolon hâlâ boş bırakılabilir (§3) |
| 3 | Kolona DB varsayılanı vermek | Yazmayı unutan yol sessizce yanlış hak üretir (E7/K70) |
| 4 | Geri doldurmayı kalıcı silmeden **sonra** koşmak | Silinmiş tekil siparişler "paket" olur — delik geri gelir (§4) |
| 5 | `after('invitation_id')` yazmak | PostgreSQL sessizce yok sayar (§5) |
| 6 | CHECK değerlerini elle yazmak | Enum değişince kısıt sessizce eskir (**K39**) |
| 7 | `down()`'da kısıtları düşürmeden kolonu silmek | PostgreSQL bağımlı CHECK yüzünden reddeder |

---

## 9. Kendin dene

```powershell
php artisan migrate
```

```sql
-- pgAdmin 4
SELECT scope, invitation_id IS NULL AS invitation_bos, count(*)
FROM orders GROUP BY 1, 2;
-- Beklenen: yalnızca ('account', true) ve ('invitation', false) satırları
```

Kısıtların gerçekten kurulduğunu gör (🔴 ikisi de hata vermeli):

```sql
-- 1) Geçersiz kapsam
UPDATE orders SET scope = 'paket' WHERE id = (SELECT id FROM orders LIMIT 1);
-- ERROR: new row ... violates check constraint "orders_scope_check"

-- 2) Paket + davetiye birlikte
UPDATE orders SET scope = 'account'
WHERE invitation_id IS NOT NULL AND id = (SELECT id FROM orders WHERE invitation_id IS NOT NULL LIMIT 1);
-- ERROR: ... "orders_account_scope_has_no_invitation_check"

-- 3) 🔴 Ama NULL GEÇER — §3'ün kanıtı
UPDATE orders SET scope = NULL WHERE id = (SELECT id FROM orders LIMIT 1);
-- UPDATE 1   ← hata YOK. A2.4'e kadar durum bu.
```

> 3. denemeyi yaptıysan geri al: `UPDATE orders SET scope = 'account' WHERE scope IS NULL AND invitation_id IS NULL;`

Geri alma da sınanmalı (**B5**):

```powershell
php artisan migrate:rollback --step=1
php artisan migrate
```

---

## 10. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Expand & contract** | Şema değişimini genişlet → taşı → daralt diye üçe bölen dağıtım deseni |
| **Backfill (geri doldurma)** | Yeni kolonu var olan satırlar için doldurma |
| **Üç değerli mantık** | SQL'de `TRUE` / `FALSE` / `NULL` (bilinmiyor) |
| **Kardinalite** | Bir kolondaki farklı değer sayısı; indeks kararını belirler |
| **Modifier** | Kolon tanımına eklenen ikincil özellik (`nullable`, `default`, `after`) |

---

## 11. Sırada ne var?

**A2.3 — yazıcılar.** `Order` modeline `scope` cast'i, `OrderFactory`'ye kapsam
(ve `forInvitation()` state'inin `invitation` yazması), `StartCheckoutAction`'da
K64'ün iki kolunun kendi kapsamını **açıkça** ataması (E7). Bu adımdan sonra
sistemde `scope`'suz yeni satır üretilemez — A2.4'ün `NOT NULL`'ı ancak o zaman
güvenli olur.
