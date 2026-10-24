# `2026_10_23_100100_create_gift_code_redemptions_table.php` — kod kullanımları

> **Faz:** 11, adım 11.2 · **Kararlar:** K110 (D4), K111 (D5), K114 (D8)
> **Test:** `GiftCodeModelTest` (aktif sayım, sipariş başına tek kod)

---

## 1. Satır neyi anlatır?

"Şu kod, şu kullanıcı tarafından, şu siparişte, şu kadar indirimle **denendi**."

Satır kendi başına "kullanıldı" demez. Kullanımın **aktif** olup olmadığını bağlı siparişin
durumu söyler (K110):

| Sipariş durumu | Kullanım | Neden |
|---|---|---|
| `pending` | aktif | Ödeme sürüyor; kod bu sipariş için ayrılmış |
| `paid` | aktif | Kullanıldı |
| `refunded` | **aktif** | K114: iade kullanımı geri vermez (yoksa "öde, iade et, tekrar kullan" döngüsü) |
| `failed` | iade | Sağlayıcı reddetti ya da hiç başlatılamadı |
| `expired` | iade | Ödeme penceresi doldu (`orders:expire`) |

## 2. Kolonlar ve yabancı anahtarlar

| Kolon | Kural | Neden |
|---|---|---|
| `gift_code_id` | FK, **restrictOnDelete** | Kullanılmış kod silinemez; kapatılır |
| `user_id` | FK null, **nullOnDelete** | Hesap silinince satır kalır, sahibi anonimleşir. Kodun toplam sayımı düşmez. `orders.user_id` ile aynı karar (K97) |
| `order_id` | FK **UNIQUE**, restrictOnDelete | Bir siparişte yalnızca bir kod. "Birden fazla kodu birleştirme" kapsam dışı ve bu kısıtla **yapısal olarak** imkânsız |
| `discount_minor` | > 0 | O anki indirim. Kod ya da fiyat sonra değişse de geçmiş sabit (F4 ailesi) |
| `created_at` | — | `updated_at` yok: satır değişmez |

Index `(gift_code_id, user_id)` kişi başı kontrolün sorgusunu karşılar:
"bu kodun bu kullanıcıya ait aktif kullanımı var mı?"

## 3. 🔴 Kişi başı limit neden UNIQUE kısıt değil? (K111)

İlk akla gelen `UNIQUE (gift_code_id, user_id)`. Ama bu, siparişi `expired` olan bir
kullanıcının kodu **bir daha hiç** kullanamaması demek: satır duruyor, kısıt ikinci satırı reddediyor.

Partial unique index (`WHERE order active`) de kurulamaz: aktiflik **başka tablodaki**
(orders) bir kolona bağlı, PostgreSQL index koşulunda başka tabloya bakamaz.

Bu yüzden kontrol uygulamada, **kod satırı kilitliyken** yapılıyor (`ReserveGiftCodeAction`).
Kilit aynı kodun bütün kullanımlarını sıraya koyduğu için iki istek aynı anda "kullanmamış"
cevabını alamaz. Ayrıntı: `ReserveGiftCodeAction.md` §2.

## 4. Neden `discount_minor > 0`?

Her fayda türü en az 1 kuruş düşürür. `percent` en az %1, `fixed` en az 1 kuruş, `free` ise
planın tamamını düşürür. Sıfır indirimli bir kullanım ancak bir hata sonucu oluşabilir
(örneğin plan uyuşmazlığının atlanması). Kısıt bu hatayı veritabanında yakalar.

## 5. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| `UNIQUE (gift_code_id, user_id)` | Süresi dolan ödemeden sonra kod bir daha kullanılamaz |
| `cascadeOnDelete` (kod silinince kullanımlar gider) | Bedava siparişin izi kaybolur |
| `user_id` cascade | Hesap silinince kodun sayımı düşer, kod yeniden kullanılabilir hale gelir |

## 6. Deneme

```powershell
php artisan tinker
>>> $o = App\Models\Order::factory()->create();
>>> App\Models\GiftCodeRedemption::factory()->forOrder($o)->create();
>>> App\Models\GiftCodeRedemption::factory()->forOrder($o)->create();
# UniqueConstraintViolationException: gift_code_redemptions_order_id_unique
```
