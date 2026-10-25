# FAZ 13 — Fiyat API'si, indirimler, ödeme yöntemi anahtarı

> **Tarih:** 25 Ekim 2026 · **Dal:** `faz-13-payment-settings` (backend + frontend)
> **Önkoşul:** Faz 11 (hediye kodu), Faz 12 (Shopier) · **Plan:** `claude/FAZ-13-PLANI.md`
> **İşletme kılavuzu:** [`../FIYAT-VE-INDIRIM-YONETIMI.md`](../FIYAT-VE-INDIRIM-YONETIMI.md)
> **Elle doğrulama:** [FAZ-13-ELLE-DOGRULAMA.md](FAZ-13-ELLE-DOGRULAMA.md)

---

## 1. Fazın amacı

Fiyat yalnızca backend'de dursun. Frontend onu API'den okusun. İki tür indirim olsun
(ilk yayın %60, süreli kampanya). Ödeme yöntemleri `.env`'de tek satırla açılıp kapansın.

## 2. Merkezi fikir: "sayıyı hesaplayan, onu tahsil edendir"

| Önce | Sonra |
|---|---|
| Fiyat iki yerde: `config/davetkart.php` (tahsil) + `data.ts` (gösterim) + `/pricing` sayfasında üçüncü, uydurma bir kopya | Tek yer: `config` → `QuotePriceAction` → API |
| İndirim yok (yalnızca hediye kodu) | İlk yayın + kampanya + hediye kodu, tek dökümde |
| Kart her zaman açık, havale frontend `.env`'iyle | `PAYMENT_METHODS` satırı; backend reddeder, frontend gizler |

## 3. Yazılan dosyalar

### Backend (13.1–13.8)

| Adım | İçerik |
|---|---|
| 13.1 | `PaymentMethod` enum · `PaymentMethods` · `BankTransferAccount` · `PaymentMethodException` · `ErrorCode::PaymentMethodDisabled` · `config/payment.php` (`methods`, `bank_transfer`) · `config/davetkart.php` (`pricing`) · `.env.example` · `phpunit.xml` · `docs/08`, `docs/10` · `PaymentMethodsTest` |
| 13.2 | `PromotionKind` · migration `price_campaigns` · migration `add_promotion_to_orders` · `Order` cast'leri + `listPriceMinor()` · `OrderPromotionConstraintsTest` |
| 13.3 | `PriceCampaign` + fabrika · `PriceCampaignSpec` · `Start/StopPriceCampaignAction` · `pricing:campaign:start/list/stop` · `PriceCampaignCommandsTest` |
| 13.4 | `QuotePriceAction` · `PriceQuote` · `Promotion` · `GiftCode::discountFor($tier, $base)` · `Quote/ReserveGiftCodeAction` taban parametresi · `QuotePriceActionTest` |
| 13.5 | `StartCheckoutAction` (fiyat + yöntem kapısı) · `PaymentGatewayResolver` · `PreviewGiftCodeAction` · `PriceQuoteResource` · `GiftCodeQuoteResource` · `TestCase` · `FirstPublishCheckoutTest` · `PaymentMethodGateTest` |
| 13.6 | `ShowPricingAction` · `PricingOverview` · `PricingResource` · `PricingController` · rotalar · `PricingTest` |
| 13.7 | `OrderResource` tutar alanları · `OrderTest` |
| 13.8 | Bu dosya · elle doğrulama · işletme kılavuzu · `fazlar/README.md` |

### Frontend (13.9–13.13)

| Adım | İçerik |
|---|---|
| 13.9 | `types.ts` (fiyat tipleri) · `services/pricing.ts` · `stores/usePricingStore.ts` · `hooks/usePricing.ts` · `utils/paymentMethods.ts` · önizleme doğrulaması · hata sözleşmesi + `PAYMENT_METHOD_DISABLED` (tr/en) · `GiftCodeField` (kodun kendi payı) |
| 13.10 | `pricing.*` çevirileri, 10 dil |
| 13.11 | `data.ts`'ten fiyat silindi · `PlanPriceTag` · `PaywallModal` · `OrderSummary` · `CheckoutPage` · `PaymentMethodSelector` · `PaymentReturnPage` · havale hesabı backend'e (`VITE_BANK_*` kalktı) |
| 13.12 | `PricingPage` gerçek üç pakete bağlandı · ana sayfa / kayıt sayfası "%60" yazıları backend'den |
| 13.13 | `scripts/verify-pricing.ts` (src/'de fiyat kalmadığının kanıtı) |

## 4. Kararlar (K128–K136)

| # | Karar |
|---|---|
| K128 | Fiyatın tek kaynağı backend. Frontend gösterir, hesaplamaz. `OrderResource`'un "tutar gönderilmez" kararı (Faz 7, C1 notu) geri alındı |
| K129 | Bütün hesap `QuotePriceAction` → `PriceQuote`; checkout, önizleme ve fiyat uçları aynı sınıfı çağırır |
| K130 | İlk yayın %60, `davetkart.pricing.first_publish_percent`, `env()` yok (ticari söz), 0–90 |
| K131 | "İlk yayın" = `paid_at` damgalı hiç sipariş yok. Hediye (bedava) ve iade edilmiş sipariş de sayılır; bekleyen sayılmaz |
| K132 | Kampanya: `price_campaigns` tablosu + artisan; deploy yok, bitiş kendiliğinden |
| K133 | Otomatik indirimler toplanmaz: büyük olan (eşitlikte ilk yayın). Hediye kodu kalan fiyata |
| K134 | `PAYMENT_METHODS`; backend reddeder (403 `PAYMENT_METHOD_DISABLED`), fiyat ucu bildirir; tanımsız kelime 503 |
| K135 | Havale hesabı backend `.env`'inde (`BANK_TRANSFER_*`), IBAN backend'de doğrulanır |
| K136 | Sağlayıcı yalnızca ödenecek tutar varken kurulur (`PaymentGatewayResolver`): kart kapalıyken boş Shopier sırları bedava siparişi bozmaz |

**Plandan sapmalar:**

- **Yarış önlemi kaldırıldı.** Planda "kullanıcı satırını kilitle, ödenmemiş ilk yayın
  siparişi varken ikincisi tam fiyattan açılsın" vardı. Bu, ödeme sayfasından dönen
  kullanıcının indirimini kaybetmesi demekti. Bekleyen sipariş sayılmıyor. Risk: iki
  sekmede iki kez **ödeyen** iki kez %60 alır. Para ödemesi gerektirdiği için kabul edildi.
- **`orders.list_price_minor` kolonu yok.** Liste fiyatı `amount + discount`'tan türetiliyor
  (E1). Saklansaydı bir gün ayrışabilirdi.
- **Uç adları:** `GET /api/public/plans` (misafir) + `GET /api/pricing` (oturumlu).
  `quote?tier=` yerine üç planı birden dönen tek uç. Fiyat sayfası da paywall da üç planı
  aynı anda gösteriyor.

## 5. Kurulan kurallar

1. **Frontend'e fiyat yazılmaz.** `npm run verify:pricing` `src/`'yi tarar.
2. **Fiyat dökümü siparişe dondurulur.** Kampanya bitse de sipariş tutarı değişmez.
3. **Yapılandırma hatası gürültülüdür.** Tanımsız yöntem 503; %100 ilk yayın 500.
4. **Kapalı yöntem iki katmanda kapalıdır:** frontend gizler, backend reddeder.

## 6. Sayılar

- Backend: `pint` ✅ · `phpstan` 0 hata ✅ · `errors:export --check` ✅ · **865 test** ✅.
  Bulut çalışma alanında PHP 8.5 + PostgreSQL 16 ile koştu, bu makinede **değil**.
  Her commit ayrı ayrı sınandı.
- Frontend: `npm run check` ✅ (tsc + build + 11 doğrulama betiği).
- Ekran görüntüleriyle bakıldı: fiyat sayfası (misafir / yeni üye), ödeme sayfası (yalnızca
  hediye kodu / kart + kısmi kod).

## 7. Devir

- 🔴 **Elle doğrulama bekliyor:** [FAZ-13-ELLE-DOGRULAMA.md](FAZ-13-ELLE-DOGRULAMA.md).
- **Bugün `PAYMENT_METHODS=gift_code`:** %60 ekranda görünür ama tahsil edilemez. Kart
  açılınca (Faz 12 elle doğrulaması sonrası) gerçek satışa döner.
- **Fiyat sayfası SSS'si** (yayın süresi, iade) eski sayfadan aynen kaldı; ticari olarak
  doğru mu, kontrol edilmeli.
- **Havale siparişi ucu** hâlâ yok (Faz 10'dan beri). Havale tutarı ilk yayın indirimini
  içeriyor ama eşleştirme hâlâ elle yapılıyor.
