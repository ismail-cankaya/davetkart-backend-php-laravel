# FAZ 11 — Hediye Kodu (indirim + ücretsiz plan)

> **Tarih:** 23 Ekim 2026 · **Dal:** `faz-11-hediye-kodu` (`release-2026-10-20`'den)
> **Önceki:** Faz 10 + yayın · **Sonraki:** Faz 12 — Shopier
> **Kapsam:** backend (11.1–11.11) + frontend (11.12–11.16, `davetkart-frontent`
> `faz-11-hediye-kodu` dalı, `main`'den). Frontend kaydı §10'da.

---

## 0. 🔴 ÖNCE BUNU OKU

- `composer check` **koştu ve yeşil**: 684 test, pint ve phpstan temiz, katalog güncel.
  Ama **İsmail'in Windows makinesinde değil**, bulut çalışma alanında koştu: PHP 8.5.12 (CLI) +
  PostgreSQL 16, projenin kendi `vendor/` klasörüyle. Windows + Herd + PostgreSQL 18'de koşmadı.
  Kapanış ölçütü: [`FAZ-11-ELLE-DOGRULAMA.md`](FAZ-11-ELLE-DOGRULAMA.md) Adım 0.
- **Eşzamanlılık otomatik testle sınanmıyor.** Kod satırı kilidi (`lockForUpdate`) elle, 6
  süreçlik bir `pcntl_fork` denemesiyle kanıtlandı (`ReserveGiftCodeAction.md` §2.3).
  Satır silinirse testler yeşil kalır.
- Mevcut `PaywallTest` ve `OrderTest` **değiştirilmedi** ve yeşil.

---

## 1. Fazın amacı

Kullanıcıya bir kodla indirim ya da bedava plan vermek: kampanyalar (sabit, N kişilik kod) ve
hediyeler (toplu, tek kullanımlık kodlar). Faz 12'den (Shopier) **önce**, çünkü indirim
siparişin tutarını değiştiriyor ve Shopier imzalı tutarı doğrudan `amount_minor`'dan alacak.

## 2. 🔴 Fazın merkezi fikri: hediye = sipariş (K107)

Kod planın tamamını karşılıyorsa sağlayıcıya gidilmez, ama ayrı bir "hediye hakkı" tablosu
da yazılmaz. `provider = 'gift'`, `status = 'paid'`, `amount_minor = 0` olan **normal bir
sipariş** yazılır.

Bu tek karar fazın en büyük kazancı: yayın hakkı (`OrderEntitlementResolver`), bağsız siparişin
bağlanması (`ClaimReleasedOrderAction`), paywall'ın 402 kontrolü, iade sonrası yayından
kaldırma, "siparişlerim" ucu: **hiçbiri değişmedi**. Hepsi hediyeyi ödenmiş bir sipariş olarak
tanıyor. Ayrı bir hak kaynağı yazılsaydı bu beş yerin her biri iki tabloya bakmak zorunda
kalırdı.

İkinci merkezi fikir: **kullanım sayısı saklanmaz** (K110). Bir kullanım, bağlı siparişi
`pending`, `paid` ya da `refunded` ise aktiftir; `failed` ya da `expired` olunca kod
kendiliğinden geri gelir. Sağlayıcı hatasında, süresi dolan ödemede, reddedilen kartta tek
satır telafi kodu yazılmadı.

## 3. Yazılan dosyalar

| Adım | Dosyalar | Kılavuz |
|---|---|---|
| **11.1** | `app/Enums/GiftCodeBenefit.php` · `..._create_gift_codes_table.php` | [enum](../app/Enums/GiftCodeBenefit.md) · [migration](../database/migrations/2026_10_23_100000_create_gift_codes_table.md) |
| **11.2** | `..._create_gift_code_redemptions_table.php` · `..._add_discount_and_gift_rules_to_orders.php` | [kullanımlar](../database/migrations/2026_10_23_100100_create_gift_code_redemptions_table.md) · [orders](../database/migrations/2026_10_23_100200_add_discount_and_gift_rules_to_orders.md) |
| **11.3** ↕ | `app/Support/GiftCodes/GiftCodeFormat.php` · `tests/Unit/GiftCodeFormatTest.php` | [biçim](../app/Support/GiftCodes/GiftCodeFormat.md) |
| **11.4** ↕ | `GiftCode` · `GiftCodeRedemption` · iki factory · `Order` (+ sabit, cast, ilişki) · `tests/Feature/GiftCodeModelTest.php` | [GiftCode](../app/Models/GiftCode.md) · [Redemption](../app/Models/GiftCodeRedemption.md) · [factory](../database/factories/GiftCodeFactory.md) · [Order §Faz 11](../app/Models/Order.md) |
| **11.5** | `ErrorCode` (+3) · `GiftCodeException` · `docs/08` · `contracts/error-codes.json` | [ErrorCode §Faz 11](../app/Enums/ErrorCode.md) · [exception](../app/Exceptions/GiftCodeException.md) |
| **11.6** | `ReserveGiftCodeAction` · `QuoteGiftCodeAction` · `GiftCodeQuote` | [rezervasyon](../app/Actions/GiftCode/ReserveGiftCodeAction.md) |
| **11.7** | `StartCheckoutAction` · `StoreCheckoutRequest` · `PaymentController` · `OrderResource` · `GiftCodeCheckoutTest` | [akış](../app/Actions/Payment/StartCheckoutAction.md) · [test](../tests/Feature/GiftCodeCheckoutTest.md) |
| **11.8** | `PreviewGiftCodeAction` · `PreviewGiftCodeRequest` · `GiftCodeController` · `GiftCodeQuoteResource` · `routes/api.php` · `AppServiceProvider` · `config/davetkart.php` · `GiftCodePreviewTest` | [önizleme](../app/Actions/GiftCode/PreviewGiftCodeAction.md) · [yanıt](../app/Http/Resources/GiftCodeQuoteResource.md) · [limiter](../app/Providers/AppServiceProvider.md) |
| **11.9** | `GiftCodeSpec` · `CreateGiftCodeAction` · `GenerateGiftCodeBatchAction` · `CreateGiftCode` · `GenerateGiftCodes` · `GiftCodeCreateCommandsTest` | [spec](../app/Actions/GiftCode/GiftCodeSpec.md) · [create](../app/Console/Commands/CreateGiftCode.md) · [generate](../app/Console/Commands/GenerateGiftCodes.md) |
| **11.10** | `ListGiftCodes` · `DisableGiftCode` · `DisableGiftCodeAction` · `GiftCodeAdminCommandsTest` | [list](../app/Console/Commands/ListGiftCodes.md) · [disable](../app/Console/Commands/DisableGiftCode.md) |
| **11.11** | bu dosya · elle doğrulama · `fazlar/README.md` · `README.md` | — |

## 4. ⚠️ Plandan sapmalar

| # | Plan | Yapılan | Gerekçe |
|---|---|---|---|
| S1 | 11.3 modeller, 11.4 `GiftCodeFormat` | **Yer değiştirdi** (↕) | Fabrika `GiftCodeFormat::hash()`'e bağlı. Sıra korunsaydı 11.3 commit'i tek başına derlenmezdi |
| S2 | 11.6'da iki dosya | + `QuoteGiftCodeAction` | Kurallar önizlemeyle paylaşılsın (C3). Alternatifi Reserve'de "kilitsiz" bir public metot açmaktı |
| S3 | 11.8 rota `/api/v1/gift-codes/preview` | `/api/gift-codes/preview` | Sürüm URL'de değil namespace'te (CLAUDE.md §2) |
| S4 | Önizleme `{discount, total}` | `{priceMinor, discountMinor, totalMinor}` | API'de izlenecek bir para biçimi yoktu. Frontend kataloğu TL; `discount: 9975` TL sanılırsa "9.975 ₺" yazar. `Minor` eki `amount_minor` kuralı |
| S5 | `OrderResource` indirimi gösterir | Yalnızca `discountMinor`, yalnızca indirim > 0 iken | Üç mevcut test tutar alanlarını ve tam alan listesini kilitliyor. C7 kalıbıyla eklenince indirimsiz yanıt bayt bayt aynı kaldı (`OrderResource.md` §Faz 11) |
| S6 | Limiter iki checkout rotasına uygulanır | Uygulandı, ama **kod gönderilmezse saymaz** | Kodsuz ödemeyi yavaşlatmak için sebep yok |
| S7 | Orders için iki kısıt | Tek eşdeğer kısıt: `(provider='gift') = (amount_minor=0)` | `orders_paid_at_check` kalıbı. Mantıksal olarak aynı |
| S8 | — | `gift_code_redemptions.discount_minor > 0` | Her fayda en az 1 kuruş düşürür; 0 ancak bir hatayla oluşur |
| S9 | — | Kural sırası: kişi başı, tükenmeden **önce** | Tek kullanımlık kodu kendisi kullanan kişi "geçersiz" değil "zaten kullandın" görmeli |
| S10 | — | `gift-codes:list --limit` (varsayılan 50) | `contact:list` ile aynı |
| S11 | — | `config/davetkart.php` (11.8), `contracts/error-codes.json` (11.5) ve iki ek test dosyası (`GiftCodeModelTest`, `GiftCodeFormatTest` zaten planlıydı) | Limiter değerleri config'te (diğer limiter'lar gibi). Katalog `errors:export --check` için. Model testi §7'deki "DB seviyesinde reddediliyor" kanıtı için |
| S12 | `bootstrap/app.php`'de eşleme | **Değişmedi** | `HasErrorCode` arayüzü eşlemeyi gereksiz kılıyor |

## 5. Kurulan kurallar (Faz 11 · 2 kural)

| # | Kural | Gerekçe |
|---|---|---|
| **E13** | **Geri alınması gereken bir sayaç saklanmaz; sayılan olayların durumundan türetilir** | `redemptions_count` terk edilen her ödemede yukarıda kalırdı. Düşürmek için her başarısızlık yolu (sağlayıcı hatası, `orders:expire`, reddedilen kart) sayacı hatırlamak zorundaydı. Durumdan türetilen sayım bu yolların **hiçbirine** kod istemedi. E12'nin kardeşi: bir değerin anlamı, ona yazan yolların toplamıdır, ve yol sayısı arttıkça saklanan bir türev yalan söylemeye başlar |
| **H14** | **Para değerindeki bir sır veritabanına, log'a ve konsola düz yazılmaz** | Kod yalnızca özet + son 4 karakter olarak saklanır, log'a yalnızca ipucu girer, toplu üretim kodları konsola basmaz, CSV bir kez yazılır. `RsvpEditCode` (K101) aynı ilkenin ilk örneğiydi; bu faz onu genelleştiriyor |

> Kural sayıları: … · FAZ-9 (3) · FAZ-10 (bkz. `FAZ-10-PLANI.md`) · **FAZ-11 (2)**

## 6. Alınan kararlar (K107–K116)

| # | Prompt | Karar | Gerekçe |
|---|---|---|---|
| **K107** | D1 | **Hediye = sipariş.** Tutar 0 → `provider='gift'`, `paid`, `amount_minor=0`, sağlayıcıya gidilmez | §2. Bütün paywall noktaları değişmeden tanıyor |
| **K108** | D2 | Tutar > 0 → normal akış; `amount_minor = fiyat − indirim`, `discount_minor` ayrı | Shopier tutarı `amount_minor`'dan alacak; indirim sağlayıcıya şeffaf |
| **K109** | D3 | Kod değil `sha256(normalize(kod))` saklanır; listeleme için son 4 karakter | Kod para değerinde. Tek kullanımlık kodlar ~59 bit, özetten geri bulunamaz (**H14**) |
| **K110** | D4 | Kullanım sayısı **saklanmaz**; aktif = sipariş `pending`/`paid`/`refunded` | **E13**. Yarış kod satırındaki `lockForUpdate` ile kapanıyor |
| **K111** | D5 | Kişi başı 1 aktif kullanım, kod satırı kilitliyken denetlenir | Kilit aynı kodun bütün kullanımlarını sıraya koyuyor; partial unique index gerekmedi (kurulamazdı da: aktiflik başka tabloda) |
| **K112** | D6 | Yüzde `intdiv` ile aşağı; sabit `min(tutar, fiyat)`; `free` yalnızca kendi planında; indirim kodunda `tier=null` = bütün planlar | Float yok; kuruş farkı işletmenin lehine |
| **K113** | D7 | Yok/kapalı/süresi dolmuş/tükenmiş → tek kod `GIFT_CODE_INVALID`; ayrıca `ALREADY_USED`, `NOT_APPLICABLE`; hepsi 422 | Kod tahmin edene hangi kodların var olduğunu sızdırmaz. Dört yanıt bayt bayt aynı (testli) |
| **K114** | D8 | İade edilen sipariş kullanımı **geri vermez** | Öde, iade et, tekrar kullan döngüsü olmasın |
| **K115** | D9 | Tek kullanımlık kodlar bir kez gösterilir: yalnızca `storage/app/private/gift-codes/<batch>.csv` | K109'un sonucu. Kodlar konsola basılmaz |
| **K116** | D10 | Süresi dolup kullanımı iade olmuş siparişe geç ödeme gelirse kullanım yeniden aktifleşir, kod max'ı aşabilir. **Bilinen ve kabul edilen sınır**, kod yazılmadı | Engellemek parası alınmış siparişi reddetmek olurdu. Test davranışı belgeliyor |

## 7. 🔴 Doğrulama durumu (dürüst liste)

### ✅ Koşan ve geçen (bulut çalışma alanı, 23 Ekim 2026)

- `composer check`: pint ✅ · phpstan 0 hata ✅ · `errors:export --check` "Katalog guncel" ✅ ·
  **684/684 test** ✅ (başlangıç 551; +133)
- Migration'lar: `migrate:fresh` → `migrate:rollback --step=3` → `migrate` hatasız
- Komutlar elle: `create`, `generate` (CSV içeriği gözle), `list`, hatalı seçenek mesajları
- Kilit yarışı: kilitli 1/6, kilitsiz 6/6 (§0)
- Mutasyon: 14 mutasyonun 12'si kırmızı. Kalan ikisi kılavuzlarda yazılı
  (`GiftCodeException.md` §2, `GenerateGiftCodeBatchAction.md` §3)

### ⬜ Koşmayan

- İsmail'in makinesinde (Windows, Herd, PostgreSQL 18) **hiçbir şey koşmadı**
- `migrate` canlıda koşmadı
- Frontend (11.12–11.16): yazılmadı
- `ReserveGiftCodeAction`'ın transaction savunması (`LogicException`): `RefreshDatabase`
  yüzünden testle sınanamadı

## 8. Öğrenilen dersler (65–68)

| # | Ders |
|---|---|
| **65** | **PostgreSQL'de bir hata bütün transaction'ı zehirler.** UNIQUE ihlalini yakalayıp devam etmek isteyen kod, `save()`'i iç bir `DB::transaction` ile (savepoint) sarmalı. Yoksa dışarıda bir transaction olduğunda (testlerde `RefreshDatabase`, yarın bir toplu işlem) sonraki her sorgu `25P02 current transaction is aborted` alır |
| **66** | **PHPUnit yarış koşulu göremez.** Tek süreç, tek bağlantı, tek transaction. `lockForUpdate` silinse bütün testler yeşil kalır. Kilidin kanıtı ancak birden çok süreçle (`pcntl_fork` + ayrı bağlantılar) ve yapay bir bekleme penceresiyle alınır |
| **67** | **`expectsOutputToContain` sıralı ve tüketicidir.** Her beklenti bir çıktı satırını tüketir. Aynı tablo satırındaki iki hücre için iki beklenti yazılırsa ikincisi "yok" der. Bir satırda birden çok şey sınanacaksa `Artisan::call()` + `Artisan::output()` |
| **68** | **Bir "açık karar"ı kilitleyen test bir sözleşmedir.** `OrderTest` gövdede `amount` kelimesini arıyor. Yeni bir alan bu kararı delmek yerine C7 kalıbıyla (yalnızca varken) eklendi. Kararı değiştirmek gerekirse önce test bilinçli olarak güncellenir |

## 9. 🔴 Açık kararlar ve borçlar

| # | Konu | Öneri |
|---|---|---|
| A1 | `OrderResource`'ta `amountMinor` açılsın mı? Ödeme dönüş sayfası "ödenen: 199,50 ₺" göstermek isterse gerekir | Frontend adımında (11.15) ihtiyaç doğarsa `OrderTest` taramasıyla birlikte bilinçli olarak değiştir |
| A2 | Bekleyen sipariş kodu 30 dk tutar: kullanıcı kart sayfasını kapatıp aynı kodu tekrar denerse `ALREADY_USED` görür | Kabul edildi (K110'un doğal sonucu). Frontend mesajı "Bu kodla başlatılmış bir ödemen var; birkaç dakika sonra tekrar dene" olabilir |
| A3 | Toplu kapatma (`gift-codes:disable --batch=`) yok | CSV kaybolursa 1000 komut gerekir. İhtiyaç doğarsa tek seçenek |
| A4 | Zamanlama yan kanalı: bilinmeyen kodda 1 sorgu, var olan kodda 3-4 | Hız sınırı ölçümü pratikte işe yaramaz kılıyor. Sabit süreli yol yazılmadı |
| A5 | Kilit için otomatik test yok | Ders 66. Bir "concurrency" test grubu (ayrı bağlantılarla, `RefreshDatabase`'siz) ileride kurulabilir |
| A6 | `GiftCodeRedemption::releasingStatuses()` modelde; K50 deseni enum'u öneriyor | `OrderStatus` bu fazın dosya listesinde yoktu. Bir sonraki `OrderStatus` değişikliğinde oraya taşınabilir |

## 10. Frontend (11.12–11.16) — ✅ yazıldı

`npm run check` her adım commit'inde ayrı ayrı yeşil (bulut çalışma alanı, Node 22). Gerçek
backend + Chromium ile elle denendi (yanlış kod, %50, ücretsiz, onaysız basış, başarı sayfası,
390 px). Ayrıntı: frontend `docs/rehber/src/pages/CheckoutPage.md` → "Faz 11".

Frontend kararları: hediye kodu **yalnızca kartla** geçerli (havalede sipariş açılmıyor, kod
ayrılamaz); toplam 0 iken kart/havale yerine "Ücretsiz etkinleştir"; önizlemesi olmayan kod
checkout'a gitmez; yeni `npm run verify:gift` (25 kontrol).

Backend tarafından frontend'e düşenlerin listesi (hepsi karşılandı):

- `src/contracts/error-codes.json`: backend'in `contracts/error-codes.json`'u kopyalanır (25 kod)
- 3 yeni hata kodunun `tr`/`en` metni; `GIFT_CODE_NOT_APPLICABLE` `params.tier` alır
- `POST /api/gift-codes/preview {code, tier}` → `{benefit, tier, priceMinor, discountMinor, totalMinor, currency}`
- Checkout gövdesine isteğe bağlı `giftCode`
- Yanıtta `status: "paid"` gelirse kart/havale yok; `redirectUrl` başarı sayfası
- `OrderRecord`'a isteğe bağlı `discountMinor?: number`
- Önizleme "geçerli" deyip checkout 422 dönebilir (`PreviewGiftCodeAction.md` §3): ikisinin
  hatası da aynı i18n metninden çizilmeli
- 429 `RATE_LIMITED`: önizleme ile kodlu checkout aynı kovada

## 11. Kapanış listesi

1. [`FAZ-11-ELLE-DOGRULAMA.md`](FAZ-11-ELLE-DOGRULAMA.md) Adım 0: `composer check` **kendi makinende**
2. Aynı dosyanın 1–14. adımları; frontend'de `npm run check` ve `/odeme?tier=gold` üzerinden hediye kodu
3. `claude/PHP-LARAVEL-SETUP-EK-FAZ-11.md` (11.17) ana dosyaya işlenir

## 12. Bir cümlelik özet

Hediye kodu ayrı bir hak sistemi olarak değil sıfır tutarlı bir **sipariş** olarak kuruldu ve
kullanım sayısı saklanmayıp sipariş durumundan türetildi. Bu iki karar sayesinde ödeme ve
paywall katmanının tek satırı değişmedi, her başarısızlık yolu kodu kendiliğinden geri verdi.
