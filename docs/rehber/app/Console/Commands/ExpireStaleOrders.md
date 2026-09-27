# `app/Console/Commands/ExpireStaleOrders.php`

> **Faz:** 9 — Üretim hazırlığı, dosya 9.9 · 🆕 **Faz 10**, adım 10.5 (§9: `failed` → `expired`)
> **Komut:** `php artisan orders:expire`
> **İlgili:** [`../../Enums/OrderStatus.md`](../../Enums/OrderStatus.md) ·
> [`../../Models/Order.md`](../../Models/Order.md) · [`../../../config/payment.md`](../../../config/payment.md)
> **Kurallar:** **E2** (kural sorgunun kapsamında) · **N4** · ders 26 · Faz 10: **K89** · **E12**

---

## 1. Üç fazdır yazılan, hiç okunmayan bir kolon

`StartCheckoutAction` Faz 7'den beri her siparişe bir son kullanma damgası
basıyor:

```php
$order->expires_at = now()->addMinutes(
    Config::integer('payment.order_expires_after_minutes'),   // 30
);
```

Ve bu damgayı bugüne kadar **hiçbir kod sormadı**. `Order::isExpired()` bile
yazıldı ama çağıranı yoktu.

> **Ders 26'nın en somut hâli:** yazılıp okunmayan bir kolon, *doğru olduğu
> varsayılan* bir kolondur. Bu faza kadar ne doğruluğu ne yanlışlığı
> görülebilirdi — `expires_at` yanlış hesaplansaydı hiçbir test, hiçbir
> kullanıcı fark etmezdi.

### Okunmamasının somut bedeli

| Sonuç | Neden |
|---|---|
| "Siparişlerim" ekranı (ileride) yıllar öncesinden *"ödeme bekliyor"* satırları gösterir | `pending` hiç bitmez |
| Terk edilmiş ödeme oranı ölçülemez | Başarısız ile yarım kalan ayırt edilemez |
| `provider_ref` UNIQUE alanı hiç sonuçlanmayacak satırlarla dolar | Temizleyen yok |

---

## 2. 🔴 Tek deyimde `UPDATE`, satır satır `save()` değil

```php
$affected = $query->update([
    'status' => OrderStatus::Expired->value,   // Faz 9'da: Failed (§9)
    'updated_at' => now(),
]);
```

İki sebep ve ikincisi asıl sebep:

**(1) Performans.** Binlerce satırda N sorgu yerine 1 sorgu.

**(2) 🔴 Yarış koşulu.** Döngüyle yazsaydık:

```php
foreach ($stale as $order) {          // ← okundu: status = pending
    $order->status = OrderStatus::Expired;
    $order->save();                   // ← arada webhook geldi, satır 'paid' oldu
}                                     //   save() onu 'expired' yapar: ÖDENMİŞ SİPARİŞ HAKKINI KAYBETTİ
```

Toplu `UPDATE`'te `where status = 'pending'` koşulu **deyimin kendi içinde**
durur; PostgreSQL onu yazma anında doğrular. Bu adımda `paid` olmuş bir satır
hiçbir şekilde `expired` olamaz.

> **E2**'nin yeni bir yüzü: benzersizlik `if` ile değil kısıtla kurulur —
> ve **seçim** de `if` ile değil **sorgunun kapsamıyla** yapılır. Aynı fikir
> Faz 3'ün **P3**'ünde (koleksiyon uçlarında sahiplik sorguyla korunur) ve
> Faz 8'in koşullu `UPDATE`'inde (kota kontrolü + yazma tek deyimde) vardı.

`updated_at` elle yazılıyor çünkü Query Builder'ın toplu `update()`'i model
olaylarını ve otomatik zaman damgalarını atlar — satırın değiştiği görünür
kalmalı.

---

## 3. `expires_at IS NULL` neden dışarıda?

```php
->whereNotNull('expires_at')
->where('expires_at', '<', now())
```

**N4**: *`null` ile bir değer farklı bilgilerdir.* `NULL` burada "süre
sınırsız" demek — sağlayıcı penceresi olmayan akışlar için. `Order::isExpired()`
zaten aynı ayrımı yapıyor ve `??` ile birleştirmeyi bilerek reddediyor.

SQL'de `NULL < now()` zaten `NULL` (yani false gibi davranır), dolayısıyla
`whereNotNull` teknik olarak gereksiz. **Yazıldı** çünkü niyeti görünür
kılıyor: bir okuyucu "peki NULL olanlar?" diye sormasın diye. Sessiz doğruluk,
okunabilir doğruluktan zayıftır.

Karşılaştırma bir **an** üzerinde (`<`), bir takvim günü üzerinde
(`whereDate`) değil — `DeleteInvitationAction`'ın üç günlük penceresiyle aynı
ayrım.

---

## 4. `--dry-run` neden var?

Bu komut **veri değiştiriyor** ve ilk kez üretimde, kimsenin bakmadığı bir
saatte koşacak. `--dry-run`, zamanlayıcıya bağlamadan önce *"kaç satırı
vuracak"* sorusunu **yazmadan** cevaplıyor.

`FAZ-9-ELLE-DOGRULAMA.md`'nin adımı da bu: önce `--dry-run`, sayıyı gör,
sonra gerçeğini koş. Bir bakım komutunu ilk kez zamanlayıcıdan görmek, onu
hiç görmemektir.

---

## 5. Bu komutun YAPMADIKLARI (B6)

| Yapmaz | Neden |
|---|---|
| Satır silmek | Muhasebe kaydı silinmez; `expired` bir **durum**, yokluk değil |
| Sağlayıcıya iptal bildirmek | Sağlayıcı kendi penceresini kendi yönetir |
| `paid_at` yazmak | `orders_paid_at_check` kısıtı buna izin vermez ve vermemeli |
| Serbest bırakma / hak iadesi | Ödenmemiş siparişin hakkı zaten yoktu |
| Kendini zamanlamak | Zamanlama `routes/console.php`'de (9.11) |

---

## 6. Sık yapılan hatalar

| # | Hata | Ne olur |
|---|---|---|
| 1 | `foreach` + `save()` | Arada gelen webhook'un ödediği sipariş `failed` olur (§2) |
| 2 | `status` koşulunu PHP'de yazmak | Aynı yarış, farklı kılık |
| 3 | Satırı silmek | Ödeme denemesinin izi kaybolur |
| 4 | `whereNotNull` yerine `??` ile birleştirmek | "Sınırsız" ile "dolmuş" eşitlenir (**N4**) |
| 5 | `updated_at` yazmamak | Satır sessizce değişir, hiçbir yerde görünmez |
| 6 | `--dry-run`sız zamanlayıcıya bağlamak | İlk gerçek koşu üretimde ve gözlemsiz olur |

---

## 7. Kendin dene

```powershell
# Süresi dolmuş bir sipariş üret
php artisan tinker
>>> App\Models\Order::factory()->create(['expires_at' => now()->subDay()]);

php artisan orders:expire --dry-run    # "1 siparis suresi dolmus gorunuyor (yazilmadi)."
php artisan orders:expire              # "1 siparis expired isaretlendi."
php artisan orders:expire              # "0 siparis expired isaretlendi."  ← idempotent
```

Üçüncü koşu **0** demeli: komut aynı satırı ikinci kez yakalamaz, çünkü artık
`pending` değil. Bir bakım komutunun idempotan olması, zamanlayıcıdan güvenle
koşmasının önkoşuludur.

**Mutasyon denemesi (T16):** `->where('status', Pending)` satırını sil.
`it_never_touches_a_paid_order` **kırmızıya dönmeli**. (Faz 10'da kum havuzunda
yeniden koşturuldu: kırıldı.)

---

## 8. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Toplu update** | Tek SQL deyimiyle birçok satırı değiştirme |
| **İdempotan** | Birden çok kez çalıştırıldığında sonucu değişmeyen işlem |
| **`--dry-run`** | Yazmadan, ne yapacağını raporlayan çalışma kipi |
| **Terk edilmiş ödeme** | Başlatılıp tamamlanmayan checkout |

---

## 9. 🆕 Faz 10 — `failed` değil `expired` (10.5 · K89)

### 9.1 Bu komut bir hata üretiyordu

Faz 9'daki hâli süresi dolan siparişi `failed` yapıyordu. `failed` ise
`OrderStatus` makinesinde **final**: oradan hiçbir yere gidilemez. Sonuç
(denetim K-4, kum havuzunda yeniden üretildi):

```
12:29  kullanıcı öder, webhook ağda kaybolur
13:00  orders:expire → failed
13:05  sağlayıcı yeniden dener: paid
       failed → paid yasak → 204, sipariş failed, paid_at NULL, log YOK
```

Komut kendi işini **doğru** yapıyordu; yanlış olan, yazdığı değerin anlamıydı.
`failed` artık iki şeyi birden söylüyordu: *"sağlayıcı reddetti"* (kesin) ve
*"biz beklemekten vazgeçtik"* (tahmin). **E12:** *bir kolonun anlamı, ona yazan
tüm yolların toplamıdır.*

### 9.2 Değişen tek satır, değişen tek şey

```php
'status' => OrderStatus::Expired->value,
```

`expired` → `paid` geçişi meşru (10.3). Geç gelen ödeme artık hakkı açar ve
iz bırakır (10.6'nın `Log::warning`'i). Komutun geri kalanı — toplu `UPDATE`,
`where status = pending`, `--dry-run`, `updated_at` — **aynen kaldı**.

### 9.3 🔴 İki sıranın ikisi de güvenli

| Önce ne olur | Sonra | Sonuç |
|---|---|---|
| Webhook satırı kilitler, `paid` yazar | Komutun `UPDATE`'i satırı bekler, kilit düşünce `WHERE status = 'pending'`'i **yeniden değerlendirir** → eşleşmez | `paid` ✅ |
| Komut `expired` yazar | Webhook satırı kilitler, `expired` okur, `expired → paid` meşru | `paid` ✅ |

İlk satırdaki davranış PostgreSQL'in **READ COMMITTED** seviyesinin bir
özelliğidir: bekleyen bir `UPDATE`, kilidi aldığında satırın **son** hâline
göre koşulunu yeniden sınar. Faz 9'da da vardı; Faz 10'da ikinci satır eklendi.
Faz 9'da ikinci satırın sonucu **`failed`** idi — asıl hata oradaydı.

### 9.4 🔴 Test beklendiği gibi kırmızıya döndü (Faz 10 tuzağı #3)

Plan uyarmıştı: *"`ExpireStaleOrders`'ın mevcut testleri `failed` bekliyor.
Yeşil kalıyorsa test etkiyi değil yanıtı doğruluyordur."*

Kodu değiştirip **testlere dokunmadan** koşturduk:

```
MaintenanceTest::it_fails_a_pending_order_whose_window_has_closed
Failed asserting that two variables reference the same object.
-App\Enums\OrderStatus Enum (Failed, 'failed')
+App\Enums\OrderStatus Enum (Expired, 'expired')
```

Kırmızı **iyi haberdi**: test kolonun kendisine bakıyormuş (T14). Adı ve
beklentisi güncellendi: `it_expires_a_pending_order_whose_window_has_closed`,
artık `paid_at`'in `NULL` kaldığını da doğruluyor. Kılavuzu:
[`../../../tests/Feature/MaintenanceTest.md`](../../../tests/Feature/MaintenanceTest.md).

### 9.5 Aynı commit'te düzeltilen yorum

`OrderFactory::failed()`'in açıklaması *"Sağlayıcı reddetti ya da süre doldu"*
diyordu. İkinci yarısı bu adımla yanlış oldu ve düzeltildi (**B4**).

### 9.6 Belge borcu (Z adımına)

`docs/11` (§komutlar ve §zamanlayıcı tabloları) ile `docs/03` / `docs/05`'in
dosya ağaçları hâlâ *"`pending` → `failed`"* ve dört durumlu enum diyor. Faz
kapanışında (10.84) güncellenecek; o güne kadar bu kılavuz doğru olanıdır.
