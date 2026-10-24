# `app/Models/GiftCodeRedemption.php` — kod kullanımı modeli

> **Faz:** 11, adım 11.4 · **Kararlar:** K110 (D4), K114 (D8), K116 (D10)
> **Test:** `GiftCodeModelTest::only_pending_paid_and_refunded_orders_hold_a_redemption`

---

## 1. Ne tutar?

Bir siparişte bir kodun kullanımı. Kolonlar için:
`docs/rehber/database/migrations/2026_10_23_100100_create_gift_code_redemptions_table.md`.

## 2. 🔴 "Aktif" tanımı burada, tek yerde

```php
public static function releasingStatuses(): array
{
    return [OrderStatus::Failed, OrderStatus::Expired];
}

public function scopeActive(Builder $query): void
{
    $query->whereHas('order', fn ($o) => $o->whereNotIn('status', ['failed', 'expired']));
}
```

Bu scope'u üç yer kullanıyor: kişi başı kontrol, tükenme kontrolü (`QuoteGiftCodeAction`)
ve liste sayımı (`GiftCode::scopeWithActiveRedemptionCount`). Tanım bir yerde değişirse üçü
birlikte değişir (C3).

### Neden "iade eden durumlar" listesi, "aktif durumlar" değil?

İki yazım bugün aynı sonucu verir. Fark, yarın `OrderStatus`'a yeni bir durum (örneğin
`partially_refunded`) eklendiğinde ortaya çıkar:

| Liste | Yeni durum | Sonuç |
|---|---|---|
| "aktif olanlar: pending, paid, refunded" | listede yok → **iade eder** | Kod fazladan kullanılır, yani para kaybı |
| "iade edenler: failed, expired" | listede yok → **tutar** | Kod belki erken tükenir, para kaybı yok |

Belirsizlikte hata **işletmenin lehine** yöne düşmeli. `ExpireStaleOrders`'ın `whereNotNull`
koşuluyla aynı refleks.

> Neden `OrderStatus` enum'unda bir metot değil (`releasesGiftCode()`)? K50 deseni ("kural
> enum'da") orada daha doğru olurdu. Ama `OrderStatus.php` bu fazın dosya listesinde yoktu ve
> soru hediye koduna ait. Kural kod tarafında, tek bir statik metotta toplandı.
> `GiftCodeModelTest::every_order_status_is_classified...` listeyi sabitliyor.

## 3. Neden `UPDATED_AT = null`?

Satır yazıldıktan sonra değişmez. Kullanımın durumu siparişte değişir, satırda değil. Laravel
varsayılan olarak `updated_at` yazmaya çalışır. Sabit `null` yapılınca kolon beklenmez.

## 4. Bilinen sınır: geç ödeme (K116)

Sipariş `expired` → kullanım iade oldu → kodu başka biri kullandı (max'a ulaştı) → eski
siparişin parası geldi (`expired → paid`, K89). Kullanım yeniden **aktif** olur ve kod
`max_redemptions`'ı 1 aşar.

Engellemek, parası ödenmiş bir siparişi reddetmek demekti. Nadir bir durum. Kod yazılmadı,
`GiftCodeCheckoutTest::a_late_payment_reactivates_the_redemption_beyond_the_maximum` davranışı
belgeliyor. `HandlePaymentCallbackAction` bu geçişte zaten `Log::warning` bırakıyor.

## 5. Deneme

```powershell
php artisan tinker
>>> App\Models\GiftCodeRedemption::query()->active()->count()
>>> App\Models\GiftCodeRedemption::query()->with('order')->latest('created_at')->first()
```
