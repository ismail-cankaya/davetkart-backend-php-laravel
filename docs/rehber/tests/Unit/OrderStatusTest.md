# `tests/Unit/OrderStatusTest.php`

> **Faz:** 10 — Sertleştirme, adım 10.3 (enum'la aynı commit)
> **Test edilen:** [`../../app/Enums/OrderStatus.md`](../../app/Enums/OrderStatus.md) §11
> **Kurallar:** **T6** (varlık ve yokluk birlikte) · **K39** (türetilen liste) · **K89**
> **Önce oku:** [`OrderScopeTest.md`](OrderScopeTest.md) — projenin ilk birim testi ve
> `Tests\TestCase` yerine PHPUnit'in `TestCase`'inin neden seçildiği

---

## 1. Neden bir birim testi daha?

Feature testleri durum makinesinin yalnızca **birkaç** geçişini dener:

| Feature testi | Denediği geçiş |
|---|---|
| `the_same_webhook_twice_does_not_move_paid_at` | `paid → paid` ✗ |
| `a_paid_order_cannot_be_moved_back_to_failed` | `paid → failed` ✗ |
| `a_refund_keeps_the_paid_at_stamp` | `paid → refunded` ✓ |
| 10.7: `a_paid_webhook_after_expiry_still_grants_the_order` | `expired → paid` ✓ |
| 10.7: `a_paid_webhook_after_a_provider_failure_is_rejected_loudly` | `failed → paid` ✗ |

Beş durum × beş durum = **25 çift**. Feature testleri bunun beşini görüyor.
Kalan yirmi çiftten biri yanlışlıkla açılsa (*"`expired → refunded` da olsun"*,
*"`pending` kolunu `true` yapalım"*) hiçbir feature testi kırılmayabilir.

Bu dosya tablonun **tamamını** sabitler. Laravel'i ayağa kaldırmaz, veritabanına
dokunmaz; beş test milisaniyeler içinde biter.

---

## 2. Tablo bir sabit, test bir döngü

```php
private const ALLOWED = [
    [OrderStatus::Pending, OrderStatus::Paid],
    [OrderStatus::Pending, OrderStatus::Failed],
    [OrderStatus::Pending, OrderStatus::Expired],
    [OrderStatus::Expired, OrderStatus::Paid],
    [OrderStatus::Paid, OrderStatus::Refunded],
];

foreach (OrderStatus::cases() as $from) {
    foreach (OrderStatus::cases() as $to) {
        $this->assertSame(
            in_array([$from, $to], self::ALLOWED, true),
            $from->canTransitionTo($to),
            sprintf('%s -> %s', $from->value, $to->value),
        );
    }
}
```

İki PHP ayrıntısı:

- **Sabitte enum case'i.** PHP 8.1'den beri enum case'leri sabit ifadelerinde
  kullanılabilir (`const X = [Status::A]`). Liste bu yüzden `private const`.
- **`in_array(..., true)` diziyi dizi ile kıyaslar.** Katı (`===`) kıyasta iki
  dizi, aynı anahtarlarda aynı (`===`) değerleri taşıyorsa eşittir. Enum
  case'leri **tekil nesnelerdir** (`OrderStatus::Paid === OrderStatus::Paid`),
  dolayısıyla çift karşılaştırması güvenli.

Üçüncü parametre (`'%s -> %s'`) bir **hata mesajıdır**: 25 iddiadan biri
kırılınca PHPUnit hangi çiftin kırıldığını söyler. Mesajsız bir döngü yalnızca
*"false yerine true geldi"* derdi.

> **T6:** izin **verilenler** kadar verilme**yenler** de sabitlenir.
> Yalnızca `assertTrue(Expired->canTransitionTo(Paid))` yazsaydık,
> `canTransitionTo()`'yu `return true;` yapan bir değişiklik testi geçerdi.

---

## 3. Beş test

| Test | Neyi korur |
|---|---|
| `it_exposes_exactly_five_raw_values` | CHECK kısıtının okuduğu liste (K39). Migration 10.4 bu listeden kurulur |
| `the_transition_table_is_exactly_the_documented_one` | 🔴 25 çiftin tamamı |
| `an_expired_order_can_still_become_paid_but_a_failed_one_cannot` | K89'un özü, okunur tek cümle |
| `an_expired_order_grants_nothing_and_holds_no_money` | `grantsPublishRight()` ve `hasBeenPaid()` **değişmedi**; `paidValues()` hâlâ `['paid','refunded']` |
| `only_states_without_an_exit_are_final` | `isFinal()`'in makineden türetildiği |

Üçüncü test ikinci testin bir alt kümesi — bilerek. Tablo testi kırıldığında
mesaj *"expired -> paid"* der; bu test ise **neden** önemli olduğunu adıyla
söyler. Bir test dosyası aynı zamanda bir belgedir.

---

## 4. Mutasyon tablosu (kum havuzunda koşturuldu)

| # | Mutasyon (`OrderStatus.php`) | Kırılan test(ler) |
|---|---|---|
| 1 | `self::Expired => $next === self::Paid` → `false` | `the_transition_table_…` · `an_expired_order_can_still_become_paid_…` · `only_states_without_an_exit_are_final` (`expired` artık çıkışsız) |
| 2 | `Pending` kolundan `self::Expired`'i çıkar | `the_transition_table_…` |
| 3 | `self::Expired => true` (her yere gidebilir) | `the_transition_table_…` |
| 4 | `grantsPublishRight()`'a `|| $this === self::Expired` | `an_expired_order_grants_nothing_…` |
| 5 | `isFinal()`'i eski gövdeye döndür (`!== Pending`) | `only_states_without_an_exit_are_final` |
| 6 | `case Expired`'ı sil | Sözdizimi: `self::Expired` bulunamaz — dosya yüklenmez, **tüm** testler kırılır |

---

## 5. Kendin dene

```powershell
php artisan test --testsuite=Unit
# 11 passed (OrderScopeTest 6 + OrderStatusTest 5)
```

Bir mutasyonu elle dene: `canTransitionTo()`'da `self::Expired` kolunu `false`
yap ve yeniden koştur. Kırmızı satır şöyle görünür:

```
expired -> paid
Failed asserting that false is identical to true.
```

---

## 6. Terim sözlüğü

| Terim | Anlamı |
|---|---|
| **Durum makinesi** | Geçerli durumları ve aralarındaki geçişleri tanımlayan model |
| **Geçiş tablosu** | Her (kaynak, hedef) çifti için "meşru mu" cevabının tamamı |
| **Tekil nesne (singleton)** | Her enum case'inin programda tek bir örneği vardır; `===` güvenlidir |
