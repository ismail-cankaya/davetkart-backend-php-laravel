# `QuotePriceAction` · `PriceQuote` · `Promotion` — fiyatın tek hesabı

> **Faz:** 13, adım 13.4 · **Karar:** K128–K133
> **Test:** `tests/Feature/QuotePriceActionTest.php`

---

## 0. Bir dakikalık özet

```
liste fiyatı (config)                      39900  (399 ₺ Gold)
− otomatik indirim (en büyük aday)        −23940  (ilk yayın %60)
= indirimli fiyat                          15960
− hediye kodu (KALAN fiyata)               −7980  (%50 kod)
= ödenecek                                  7980  (79,80 ₺)
```

Bu dökümü üreten tek yer `QuotePriceAction` + `PriceQuote`. Checkout, hediye kodu
önizlemesi ve fiyat uçları aynı sınıfı çağırır. Frontend hiçbir sayı hesaplamaz,
gelen sayıyı gösterir.

## 1. Neden "tek yer"? (K128–K129)

Faz 12'ye kadar fiyat iki yerde yazılıydı: backend `config/davetkart.php` (tahsilat) ve
frontend `data.ts` (gösterim). İndirim eklendiği gün bu ikilik bir **hata fabrikasına**
dönüşürdü. Frontend %60'ı kendisi hesaplasaydı yuvarlama farkı ("159,60" ↔ "159,59"),
kampanyanın bitişi ya da "ilk yayın mı?" sorusu iki tarafta ayrı ayrı cevaplanırdı.
Ekranda bir sayı, kartta başka bir sayı görünürdü.

Kural: **sayıyı hesaplayan, onu tahsil edendir.** Frontend yalnızca gösterir.

## 2. Kurallar

| # | Kural | Nerede |
|---|---|---|
| 1 | Liste fiyatı config'ten, istekten asla | `$tier->price() * 100` |
| 2 | Kampanya: şu an geçerli ve plana uygun olanların **en büyüğü** | `bestCampaignFor()` |
| 3 | İlk yayın: kullanıcının `paid_at` damgalı **hiç** siparişi yoksa | `firstPublishPromotion()` |
| 4 | İkisi toplanmaz: büyük olan; **eşitlikte ilk yayın** | `best()` |
| 5 | Hediye kodu kalan fiyata uygulanır | `PriceQuote::withGiftCode()` |
| 6 | Yuvarlama aşağı (kuruş farkı işletmenin lehine) | `Promotion::discountFor()` |

### 2.1 "İlk yayın" kimi kapsar? (K131)

`whereNotNull('paid_at')` şu siparişleri **sayar**: `paid`, `refunded` (bir zamanlar
ödenmişti), hediye koduyla bedava açılmış sipariş, başka davetiyeye bırakılmış sipariş.
Senin kararın şuydu: "herhangi bir yayın sayılır."

**Saymadıkları:** `pending`, `failed`, `expired`. Ödeme sayfasına gidip vazgeçen kullanıcı
geri döndüğünde indirimini kaybetmemeli.

⚠️ **Bilinen ve kabul edilen risk:** kullanıcı iki ayrı sekmede iki ödeme sayfası açıp
**ikisinde de** parayı öderse ikisi de %60 alır. Bunu engellemek için ödenmemiş siparişleri
saymak gerekirdi, bu da yukarıdaki "vazgeçip dönen" kullanıcıyı cezalandırırdı. Gerçek
para ödemesi gerektirdiği için kötüye kullanım değeri düşük.

### 2.2 Eşitlikte neden ilk yayın?

Yeni üyenin indirimi kampanyadan bağımsız ve kalıcı olsun. %60'lık bir kampanya açıp sonra
durdurduğunda yeni üyenin ekranında hiçbir şey değişmemeli (yüzde aynı, yalnızca kaynak
farklı olurdu).

### 2.3 Kampanya eşitliğinde neden "en geç biten"?

İki %30'luk kampanyadan biri 3 gün, diğeri 10 gün sonra bitiyorsa müşteriye "10 güne kadar"
demek doğru bilgi. 3 gün sonra o kampanya biterse diğeri devam ediyor olacak.

## 3. `Promotion` ve `PriceQuote` neden ayrı nesneler?

- `Promotion` = **kural** (kaynak + yüzde + varsa kampanya satırı). Bir fiyata uygulanabilir.
- `PriceQuote` = **sonuç** (bu plan, bu kullanıcı, bu an). Saf veri, yan etkisiz.

`PriceQuote` `readonly`: hesaplandıktan sonra değişemez. `withGiftCode()` yeni bir kopya
döndürür (immutable value object). Böylece checkout'un ortasında bir yerin dökümü sessizce
değiştirmesi imkânsız olur.

### 3.1 `withGiftCode()` bir sözleşmeyi zorlar

```php
if ($giftCode->priceMinor !== $this->afterPromotionMinor()) {
    throw new LogicException(...);
}
```

Hediye kodu **indirimli** fiyat üzerinden hesaplanmalı. Biri yanlışlıkla liste fiyatı
üzerinden hesaplasaydı (399 ₺'nin %100'ü = 399 ₺) toplam `159,60 − 399 = −239,40` olurdu.
Hata sessiz bir eksi tutar yerine gürültülü bir istisna olarak görünür.

## 4. Performans: üç plan, iki sorgu

`forTiers()` üç planı birlikte hesaplar. Kampanyaları **bir kez** çeker ve PHP'de süzer,
"ödenmiş siparişi var mı?" sorusunu da **bir kez** sorar (`exists()`, tek satır bile
okumaz). Test bunu sorgu sayısını sayarak kanıtlıyor (`three_tiers_cost_two_queries`).
Fiyat sayfası her ziyarette bu ucu çağırdığı için önemli.

## 5. Bu Action neden "salt okunur"?

Kilit yok, transaction yok, yazma yok. Fiyatı **göstermek** için de çağrılıyor ve gösterim
bir söz değil. Sipariş anında `StartCheckoutAction` hesabı **yeniden** yapar ve sonucu
siparişe dondurur (`promotion`, `promotion_discount_minor`, `price_campaign_id`).
Ekranı açık bırakıp kampanya bittikten sonra ödemeye basan kullanıcı bitmiş fiyattan
değil, güncel fiyattan sipariş açar.

## 6. Kendin dene

```powershell
php artisan tinker
>>> $u = App\Models\User::first();
>>> $q = app(App\Actions\Pricing\QuotePriceAction::class)->handle($u, App\Enums\SubscriptionTier::Gold);
>>> [$q->listMinor, $q->promotion?->kind, $q->totalMinor()]
```
