# `app/Models/GiftCode.php` — hediye kodu modeli

> **Faz:** 11, adım 11.4 · **Kararlar:** K109 (D3), K110 (D4), K112 (D6)
> **Test:** `tests/Feature/GiftCodeModelTest.php`

---

## 1. Görevleri

| Üye | Soru |
|---|---|
| `isApplicableTo(tier)` | Bu kod bu plan için geçer mi? |
| `discountFor(tier)` | Bu planın fiyatından kaç kuruş düşer? |
| `isDisabled()` / `isExpired()` | Kapatılmış mı? Süresi geçmiş mi? |
| `scopeWithActiveRedemptionCount()` | Listede `active_redemptions_count` sütununu tek sorguda ekler |
| `redemptions()` | `HasMany<GiftCodeRedemption>` |

## 2. 🔴 `#[Fillable([])]`: toplu atama kapalı (E7)

Bu tablodaki hiçbir alan istemciden gelmez. Kodları yalnızca komutların Action'ları yazar
ve her alanı **açıkça** atar (`CreateGiftCodeAction::fill()`). `Order` ve `Media` modelleriyle
aynı karar. Bir gün bir admin paneli yazılsa bile `GiftCode::create($request->all())` çalışmaz:
`max_redemptions = 999999` gönderilemez.

## 3. İndirim kuralı (K112)

```php
$price = $tier->price() * 100;      // TL → kuruş, int

return match ($this->benefit) {
    GiftCodeBenefit::Free    => $price,                                   // hepsi
    GiftCodeBenefit::Percent => intdiv($price * $percent, 100),           // aşağı yuvarla
    GiftCodeBenefit::Fixed   => min($amountOffMinor, $price),             // fiyatı aşamaz
};
```

**Neden `intdiv`?** Para hiçbir yerde `float` değildir (CLAUDE.md §3). `0.1 + 0.2` gibi
hatalar tam sayı aritmetiğinde yapısal olarak imkânsızdır. `intdiv` bölümün tam kısmını alır,
yani yüzde **aşağı** yuvarlanır. Kuruş farkı işletmenin lehine.

> Bugünkü fiyatlar tam TL (249, 399, 549) olduğu için `fiyat_kuruş × yüzde` her zaman 100'e
> tam bölünür, yani yuvarlama hiç devreye girmez. `intdiv` fiyatlar bir gün kuruşlu
> olursa (`249,90`) doğru davranışı şimdiden garanti ediyor.

**Neden `min()`?** 1000 TL'lik bir `fixed` kod 249 TL'lik plana uygulanırsa indirim 249 TL
olur, -751 değil. Ödenecek tutar asla eksi olamaz. Eksi olsaydı `orders_amount_minor_check`
(`>= 0`) satırı reddederdi ama hatayı veritabanına bırakmak yerine doğru değeri hesaplamak
gerekir.

**Plan uymuyorsa 0 döner.** Hata fırlatmak `QuoteGiftCodeAction`'ın işi (hangi hata kodunun
döneceğine o karar veriyor). Model yalnızca hesaplar.

## 4. Plan kuralı

| Kod | `tier` | Geçerli olduğu planlar |
|---|---|---|
| `free` | zorunlu, ör. `gold` | yalnızca `gold` |
| `percent` / `fixed` | `null` | hepsi |
| `percent` / `fixed` | `elit` | yalnızca `elit` |

`free/gold` kodu `elit` planına uygulanamaz, kısmi indirim de vermez. "Gold'u bedava açar"
demek "Elit'ten 399 TL düşer" demek değil; ikincisi bir ürün kararı olurdu ve verilmedi.

## 5. Aktif kullanım sayımı (K110) ve N+1

```php
GiftCode::query()->withActiveRedemptionCount()->get();
// SELECT gift_codes.*, (SELECT count(*) FROM gift_code_redemptions
//    WHERE gift_code_id = gift_codes.id AND EXISTS (SELECT * FROM orders
//    WHERE orders.id = order_id AND status NOT IN ('failed','expired'))) AS active_redemptions_count
```

Laravel'in `withCount`'u sayımı **alt sorgu olarak** aynı SELECT'e koyar. 50 kodluk liste
51 değil **1** sorgudur (`GiftCodeAdminCommandsTest::counting_runs_in_a_single_query...`).
"Aktif" tanımı `GiftCodeRedemption::scopeActive()`'te durur ve burada yeniden yazılmaz.

## 6. Süre (N4)

`expires_at` NULL ise kod süresizdir. `isExpired()` iki durumu `??` ile birleştirmez:
"süresiz" ve "süresi dolmuş" farklı bilgiler (`Order::isExpired()` ile aynı).

## 7. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| `$price * $percent / 100` | `/` PHP'de float döndürebilir (`7.5`), kuruş kaybolur |
| `round()` ile yuvarlamak | Yarım kuruşta kullanıcı lehine de yuvarlar |
| Liste döngüsünde `$code->redemptions()->count()` | N+1: her kod için ayrı sorgu |
| `$code->tier === 'gold'` | Cast sonrası enum, string değil → hep `false` |

## 8. Deneme

```powershell
php artisan tinker
>>> $c = App\Models\GiftCode::factory()->percent(33)->make();
>>> $c->discountFor(App\Enums\SubscriptionTier::Gold)   // 39900 × 33 / 100
=> 13167
>>> App\Models\GiftCode::factory()->fixed(1_000_000)->make()->discountFor(App\Enums\SubscriptionTier::Standart)
=> 24900
```

---

## 🆕 Faz 13 (13.4 · K133) — `discountFor($tier, $priceMinor)`

İkinci parametre, kodun uygulanacağı **taban**: otomatik indirimden (kampanya / ilk yayın)
sonra kalan fiyat. Verilmezse liste fiyatı kullanılır (Faz 11 davranışı, eski testler
değişmedi).

| Kod | Liste 399 ₺, ilk yayın %60 → taban 159,60 ₺ | İndirim |
|---|---|---|
| `free` | tabanın tamamı | 159,60 ₺ |
| `percent 50` | tabanın %50'si | 79,80 ₺ |
| `fixed 500 ₺` | `min(500, 159,60)` | 159,60 ₺ |

Sonuç: kod hiçbir zaman toplamı eksiye düşüremez. Ücretsiz kod her durumda tam olarak
sıfıra indirir, yani sipariş hediye siparişi olarak açılır.
