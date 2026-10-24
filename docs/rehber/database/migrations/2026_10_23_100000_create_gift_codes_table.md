# `2026_10_23_100000_create_gift_codes_table.php` — hediye kodları tablosu

> **Faz:** 11, adım 11.1 · **Kararlar:** K109 (D3, özet), K110 (D4, sayaç yok), K112 (D6)
> **Test:** `GiftCodeModelTest::the_database_rejects_inconsistent_codes` (10 durum)

---

## 1. Kolonlar

| Kolon | Tip | Neden |
|---|---|---|
| `id` | ULID | Komutlarda (`gift-codes:disable <id>`) görünür. Artan sayı kaç kampanya yapıldığını ele verirdi |
| `code_hash` | `char(64)` UNIQUE | `sha256(normalize(kod))`. **Kodun kendisi yok** (K109) |
| `code_hint` | `varchar(8)` | Son 4 karakter; `gift-codes:list`'te hangi kod olduğunu tanımak için |
| `label` | `varchar(120)` null | Operatörün notu: "Instagram çekilişi" |
| `batch_id` | ULID null, index | Toplu üretimin kimliği. Elle girilen kodda NULL |
| `benefit` | `varchar(16)` | `free` / `percent` / `fixed` |
| `tier` | `varchar(16)` null | `free`'de zorunlu. İndirim kodlarında NULL = "bütün planlar" |
| `percent_off` | `smallint` null | 1–100 |
| `amount_off_minor` | `integer` null | Kuruş, > 0 |
| `max_redemptions` | `integer` | ≥ 1. Elle girilen kodda N, toplu üretimde 1 |
| `expires_at` | `timestamp` null | Günün sonu, İstanbul saatiyle hesaplanıp UTC saklanır |
| `disabled_at` | `timestamp` null | **Silme yerine kapatma** |

## 2. 🔴 Neden kodun kendisi saklanmıyor? (K109)

Kod **para değerinde**. Tek kullanımlık bir `free/elit` kodu 549 TL eder. Veritabanı bir gün
sızarsa (yedek dosyası, yanlış yapılandırılmış bir panel, SQL injection) düz kodlar doğrudan
kullanılabilirdi.

Özetten geri dönülemez: SHA-256 tek yönlüdür. Üretilen kodlar 30 sembollü alfabeden 12
karakter taşır, yani 30¹² ≈ 2^58,9 olasılık var. Bütün olasılıkları denemek pratikte imkânsız.

Elle girilen kodlar (`DUGUN2026`) zayıftır, sözlükle bulunabilir. Ama bu kodlar zaten
kamuya dağıtılan kodlar: Instagram'da paylaşılan bir kodun "sızması" diye bir şey yok. Onları
koruyan şey kişi başı limit (K111) ve `max_redemptions`.

> **Neden HMAC değil (`hash_hmac('sha256', $kod, APP_KEY)`)?** Daha güçlü olurdu ama
> `APP_KEY` döndürüldüğü gün bütün kodlar çalışmaz hale gelirdi. Ayrıca yüksek entropili
> kodlarda kazancı yok. `RsvpEditCode` de (K101) düz SHA-256 kullanıyor.

## 3. 🔴 Neden kullanım sayısı (`redemptions_count`) yok? (K110)

Sayaç kolonu artırması kolay, azaltması zor bir değerdir:

- Kullanıcı kodla ödemeyi başlattı, kart sayfasında vazgeçti → sayaç 1 arttı, **hiç düşmedi**.
- Sağlayıcı ödemeyi reddetti → sayacı düşürmek için `HandlePaymentCallbackAction`'a kod gerekir.
- `orders:expire` süresi dolan siparişi işaretledi → oraya da kod gerekir.

Her başarısızlık yolu sayacı hatırlamak zorunda kalırdı ve biri unutulduğunda kod sessizce
tükenirdi. Bunun yerine **tek doğruluk kaynağı siparişin durumu**: kullanım satırı bir
siparişe bağlı ve sipariş `failed` ya da `expired` olunca kullanım kendiliğinden iade olur.
Sayım her seferinde `gift_code_redemptions` + `orders` üzerinden yapılır (ders 30: türetilebilen
veri saklanmaz).

## 4. CHECK kısıtları (E2, E11)

```sql
gift_codes_benefit_check        benefit IN ('free','percent','fixed')     -- enum'dan
gift_codes_tier_check           tier IN ('standart','gold','elit')        -- NULL geçer
gift_codes_free_has_tier_check  benefit <> 'free' OR tier IS NOT NULL
gift_codes_percent_check        (benefit = 'percent') = (percent_off IS NOT NULL)
                                AND (percent_off IS NULL OR percent_off BETWEEN 1 AND 100)
gift_codes_amount_off_check     (benefit = 'fixed') = (amount_off_minor IS NOT NULL)
                                AND (amount_off_minor IS NULL OR amount_off_minor > 0)
gift_codes_max_redemptions_check max_redemptions >= 1
```

**`(A) = (B)` kalıbı iki yönlü bir "ancak ve ancak"tır.** `orders_paid_at_check` (Faz 7) ile
aynı fikir: "yüzde kodunda `percent_off` dolu olmalı" **ve** "yüzde olmayan kodda boş olmalı".
Tek yönlü yazılsaydı (`benefit <> 'percent' OR percent_off IS NOT NULL`) bir `fixed` kodun
yanlışlıkla `percent_off = 50` taşıması mümkün olurdu.

**Üç değerli mantık tuzağı:** `tier IN (...)` kısıtı `tier = NULL` satırı kabul eder, çünkü
`NULL IN (...)` sonucu `NULL`'dur ve CHECK yalnızca `FALSE`'u reddeder. Burada istenen bu:
NULL "bütün planlar" demek. Zorunluluğu `free` için ayrı kısıt (`free_has_tier`) kuruyor.

**PostgreSQL'de UNSIGNED yok.** `unsignedInteger` düz `integer`'a düşer ve `-100` kabul eder
(Faz 5, Faz 6). `> 0` ve `>= 1` kısıtları bu yüzden açıkça yazıldı.

## 5. Neden silme yerine `disabled_at`?

Kullanım satırları koda `restrictOnDelete` ile bağlı. Kullanılmış bir kod silinemez, çünkü
silinseydi "bu sipariş hangi kodla bedava oldu" sorusu cevapsız kalırdı (muhasebe izi,
K82 ailesi). Kullanılmamış bir kod silinebilirdi ama iki farklı yol (sil / kapat) yerine tek
yol daha az hata üretir.

## 6. Geri alma

`down()` tabloyu düşürür. `gift_code_redemptions` bu tabloya bağlı olduğu için önce o tablonun
migration'ı geri alınmalı. `migrate:rollback` sırayı zaten ters çevirir.

## 7. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Kodun kendisini bir `code` kolonuna yazmak | Veritabanı sızıntısı = para sızıntısı |
| `redemptions_count` kolonu eklemek | Terk edilen ödemelerde sayaç düşmez, kod erken tükenir |
| CHECK'i tek yönlü yazmak | Tutarsız satır (fixed + percent_off) mümkün olur |
| `unsignedInteger`'a güvenmek | PostgreSQL negatif değeri kabul eder |

## 8. Deneme

```powershell
php artisan migrate
psql -U postgres davetkart -c "\d gift_codes"
# Kısıtı tetikle:
psql -U postgres davetkart -c "INSERT INTO gift_codes (id, code_hash, code_hint, benefit, max_redemptions) VALUES ('01aaaaaaaaaaaaaaaaaaaaaaaa', repeat('a',64), 'AAAA', 'free', 1);"
# ERROR: ... violates check constraint "gift_codes_free_has_tier_check"
```
