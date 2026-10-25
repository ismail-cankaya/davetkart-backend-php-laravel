# `app/Actions/GiftCode/ReserveGiftCodeAction.php` — kodu siparişe ayırmak

> **Faz:** 11, adım 11.6 · **Kararlar:** K110 (D4), K111 (D5), K113 (D7)
> Aynı adımın dosyaları: `QuoteGiftCodeAction.php`, `GiftCodeQuote.php`
> **Test:** `GiftCodeCheckoutTest` (dolaylı) + elle yarış denemesi (§2.3)

---

## 1. Üç sınıf, üç görev

| Sınıf | Görev | Yan etki |
|---|---|---|
| `ReserveGiftCodeAction` | Kodu **kilitleyerek** bulur, kuralları uygulatır | Satır kilidi |
| `QuoteGiftCodeAction` | Bulunmuş bir kodun kurallarını uygular, indirimi hesaplar | Yok (yalnızca okur) |
| `GiftCodeQuote` | Sonuç: kod, plan, fiyat, indirim, toplam | Saf veri |

`PreviewGiftCodeAction` (11.8) de `QuoteGiftCodeAction`'ı kullanır ama kilitsiz bulur.
Kurallar **tek yerde** (C3): önizleme "geçerli" deyip checkout başka bir kurala göre
"geçersiz" diyemez.

> Prompt bu adım için iki dosya listelemişti (`Reserve` + `GiftCodeQuote`). Kuralların
> önizlemeyle paylaşılması için üçüncü bir dosya (`QuoteGiftCodeAction`) eklendi. Alternatifi,
> Reserve'ün içinde public bir "kilitsiz" metot açmaktı. O da tek sınıfa iki iş yüklerdi.

## 2. 🔴 Yarış koşulu ve kilit (E9)

### 2.1 Sorun: check-then-act

Kodun son bir hakkı kaldı (`max = 1`, aktif = 0). İki kullanıcı aynı milisaniyede checkout'a
basıyor:

```
İstek A: aktif sayım = 0 < 1  ✅        İstek B: aktif sayım = 0 < 1  ✅
İstek A: kullanım satırı yaz            İstek B: kullanım satırı yaz
→ Kod 2 kez kullanıldı.
```

Kontrol (`count`) ile eylem (`insert`) arasında bir boşluk var ve ikinci istek o boşluğa
giriyor. UNIQUE kısıt burada yardım etmez: iki satır **farklı** siparişlere ait, ikisi de
geçerli.

### 2.2 Çözüm: kod satırını kilitlemek

```php
GiftCode::query()
    ->where('code_hash', GiftCodeFormat::hash($code))
    ->lockForUpdate()     // SELECT ... FOR UPDATE
    ->first();
```

`FOR UPDATE`, transaction bitene kadar bu satırı başka bir `FOR UPDATE`'e kapatır. İstek B
`first()` satırında **bekler**. A kullanım satırını yazıp commit edince B kilidi alır ve
sayımı **yeniden** yapar: 1 ≥ 1 → `GIFT_CODE_INVALID`.

Kilit **kod satırında**: aynı kodu kullanan herkes sıraya girer, farklı kodlar birbirini
beklemez. Kişi başı kontrol de aynı kilidin altında, yani aynı kullanıcının iki sekmeden
yaptığı eşzamanlı denemeler de sıraya girer (K111). Bu yüzden kişi başı limit için ayrı bir
kısıt ya da partial index gerekmedi.

### 2.3 Kanıt (elle, 23 Ekim 2026, bulut çalışma alanında PHP 8.5.12 + PostgreSQL 16)

`pcntl_fork` ile 6 süreç aynı anda `max = 1` olan bir koda checkout yaptı. Pencereyi
büyütmek için rezervasyondan sonra 300 ms bekleme eklendi:

| Durum | Sonuç |
|---|---|
| `lockForUpdate()` **var** | 1 başarılı, 5 `GiftCodeException` · `redemptions = 1` |
| `lockForUpdate()` **silindi** (2 deneme) | `redemptions = 6`, `orders = 6` |

PHPUnit testleri tek süreçte ve tek bağlantıda koştuğu için bu yarışı yakalayamaz. Kilidi
koruyan otomatik bir test **yok**; satır silinirse testler yeşil kalır. Bu bilinen bir boşluk.

### 2.4 🔴 Neden transaction zorunlu?

Transaction yoksa her sorgu kendi otomatik transaction'ında koşar ve `FOR UPDATE` kilidi
**sorgu biter bitmez** bırakılır. Kilit hiçbir şeyi korumaz. Bu yüzden Action en başta
denetler:

```php
if (DB::transactionLevel() === 0) {
    throw new LogicException('ReserveGiftCodeAction must run inside a database transaction.');
}
```

Bu bir **programlama hatası** savunmasıdır, kullanıcı hatası değil (500 döner). Rezervasyon
ile kullanım satırının **aynı** transaction'da yazılması çağıranın (`StartCheckoutAction`)
sorumluluğu.

> ⚠️ Testlerde `RefreshDatabase` her testi bir transaction'a sardığı için seviye hep ≥ 1.
> Bu savunma otomatik testle sınanmadı.

## 3. Kural sırası (`QuoteGiftCodeAction`)

| # | Kontrol | Hata |
|---|---|---|
| 1 | Kod yok · `disabled_at` dolu · `expires_at` geçmiş | `INVALID` |
| 2 | Bu kullanıcının bu kodla **aktif** kullanımı var | `ALREADY_USED` |
| 3 | Aktif kullanım sayısı ≥ `max_redemptions` | `INVALID` |
| 4 | Kod bu plan için değil | `NOT_APPLICABLE` (`params.tier`) |

**Neden 2, 3'ten önce?** Tek kullanımlık kodu kullanmış biri tekrar denediğinde
"geçersiz" görmesi yanlış olurdu: kodun var olduğunu zaten biliyor. Ona doğru eylemi söyleyen
"zaten kullandın"dır.

**Neden 4 en sonda?** `NOT_APPLICABLE` kodun var olduğunu ve planını ele verir. Tükenmiş bir
kod bu bilgiyi vermemeli. Bu yüzden önce tükenme (`INVALID`) bakılır.

"Aktif" tanımı `GiftCodeRedemption::scopeActive()`'te (K110). Sayım her seferinde sipariş
durumundan türetilir, saklanmaz.

## 4. Akış

```
StartCheckoutAction::handle()
└─ DB::transaction(
     ├─ ReserveGiftCodeAction::handle()   ← kilit burada alınır
     │   └─ QuoteGiftCodeAction::handle() ← kurallar
     ├─ sipariş satırı (pending ya da gift)
     └─ kullanım satırı                    ← kilit commit'e kadar sürer
   )
└─ sağlayıcı çağrısı (transaction DIŞINDA, F3)
```

## 5. `GiftCodeQuote`

```php
final readonly class GiftCodeQuote {
    public GiftCode $giftCode; public SubscriptionTier $tier;
    public int $priceMinor; public int $discountMinor;
    public function totalMinor(): int        // price − discount
    public function coversEverything(): bool // total === 0 → hediye yolu (K107)
}
```

`readonly`: bir kez kurulur, değişmez. `StartCheckoutAction` bu nesneyi transaction içinde
taşır. Biri arada `discountMinor`'u değiştiremez.

## 6. Bilinen sınırlar

- **Zamanlama yan kanalı:** bilinmeyen kodda tek sorgu atılır, var olan kodda üç-dört.
  Yanıt süresi ölçülerek "bu kod var" bilgisi teorik olarak çıkarılabilir. Hız sınırı
  (saatte 30) bu ölçümü pratikte işe yaramaz kılıyor. Sabit süreli bir yol yazılmadı.
- **Bekleyen sipariş kodu tutar:** kullanıcı kodla checkout başlatıp kart sayfasını
  kapatırsa sipariş 30 dakika (`order_expires_after_minutes`) `pending` kalır. Bu sürede
  aynı kodu tekrar denerse `ALREADY_USED` görür. Sipariş `orders:expire` ile `expired`
  olunca kod geri gelir.

## 7. Sık yapılan hatalar

| Hata | Sonuç |
|---|---|
| Önce `first()`, sonra ayrı bir `lockForUpdate()` | Arada boşluk kalır (E9) |
| Rezervasyonu ve kullanım satırını ayrı transaction'larda yazmak | Kilit erken bırakılır, iki istek aynı hakkı alır |
| Sağlayıcı çağrısını transaction içine almak | Kod satırı sağlayıcı yanıt verene kadar kilitli kalır (saniyeler). Sağlayıcı çağrısı geri de alınamaz (F3) |
| Kuralları önizlemeye kopyalamak | İki kopya ayrışır |

## 8. Deneme

```powershell
php artisan tinker
>>> $u = App\Models\User::first();
>>> app(App\Actions\GiftCode\ReserveGiftCodeAction::class)->handle($u, 'DUGUN2026', App\Enums\SubscriptionTier::Gold)
# LogicException: must run inside a database transaction
>>> DB::transaction(fn () => app(App\Actions\GiftCode\ReserveGiftCodeAction::class)->handle($u, 'DUGUN2026', App\Enums\SubscriptionTier::Gold))
```

---

## 🆕 Faz 13 (13.4): taban fiyat parametresi

`ReserveGiftCodeAction::handle(..., ?int $priceMinor)` ve `QuoteGiftCodeAction::handle(..., ?int $priceMinor)`
kodu verilen **taban** üzerinden hesaplar. Checkout ve önizleme tabanı
`PriceQuote::afterPromotionMinor()`'dan verir (K133: kod kalan fiyata uygulanır).
`GiftCodeQuote::$priceMinor` artık "kodun uygulandığı taban" demek. Otomatik indirim yoksa
bu yine liste fiyatıdır.
