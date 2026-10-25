# FAZ 12 — Shopier ile kartla ödeme

> **Tarih:** 24 Ekim 2026 · **Dal:** `faz-12-shopier` (backend + frontend)
> **Önkoşul:** Faz 11 (hediye kodu) · **Prompt:** `claude/PROMPT-FAZ-12.md`
> **Elle doğrulama:** [FAZ-12-ELLE-DOGRULAMA.md](FAZ-12-ELLE-DOGRULAMA.md) (🔴 canlıda, gerçek kartla)

---

## 0. 🔴 ÖNCE BUNU OKU

1. **§A (K94) boş geldi.** Shopier panelinden toplanacak sekiz bilginin hiçbiri yoktu.
   Bütün formüller topluluk kaynaklarından (`erkineren/shopier`, OSB örnekleri) ve
   prompt'un güvenli varsayılanlarıyla yazıldı. **Resmi dokümanla çelişirse doküman
   kazanır**; değişecek yerler `ShopierSignature` ve `ShopierGateway::matchOsbToOrder`.
2. **Varsayılan mod `osb` (K117):** tarayıcı callback'i hiçbir siparişi `paid` yapmaz.
   Panelde OSB kurulmazsa **canlıda bütün ödemeler `pending` kalır** (para çekilir, hak
   açılmaz). Elle doğrulamanın 2. ve 7. adımları bunun için var.
3. 🔴 **OSB → sipariş eşlemesi bir tahmin.** OSB'nin bizim `platform_order_id`'mizi
   taşıdığı doğrulanmadı. Taşımıyorsa her OSB `critical` log yazar ve hiçbir sipariş
   ödenmez. Elle doğrulama adım 7 bunu kesinleştirir.

## 1. Fazın amacı

Kart bilgisi Shopier'in sayfasında yazılsın, ödeme sitemizde onaylansın. Kart verisi
hiçbir bileşenimize, API'mize ya da log'umuza girmesin.

## 2. Merkezi fikir: "imza neyi kanıtlar?"

Fazın bütün kararları tek bir soruya dayanıyor. İmzalı her mesaj **yalnızca imzanın
kapsadığı alanlar** için bir kanıttır.

| Mesaj | İmzanın kapsadığı | Kanıtlamadığı | Sonuç |
|---|---|---|---|
| Ödeme formu | sipariş, tutar, para birimi | — | Tutar tarayıcıda değiştirilemez |
| Tarayıcı callback'i | `random_nr + platform_order_id` | **`status`** | `osb` modunda durum işlenmez (K117) |
| OSB | bütün gövde | "bu ödeme BU siparişin bedeli" | Tutar ayrıca karşılaştırılır (K126) |

## 3. Yazılan dosyalar

### Backend (12.1–12.9)

| Adım | Dosyalar |
|---|---|
| 12.1 | `Services/Payment/{CheckoutAction,RedirectAction,FormPostAction}.php` · `tests/Unit/CheckoutActionTest.php` |
| 12.2 | `CheckoutSession` · `PaymentGateway` · `FakeGateway` · `CheckoutResult` · `StartCheckoutAction` · `PaymentController` · `PublicPaymentWebhookController` · `OrderResource` · test yardımcıları (`PaywallTest`, `GiftCodeCheckoutTest`) |
| 12.3 | `config/payment.php` · `.env.example` · `docs/10` |
| 12.4 | migration `…_add_provider_payment_id_to_orders` · `Order` · `PaymentNotification` · `HandlePaymentCallbackAction` · `tests/Feature/PaymentNotificationAmountTest.php` |
| 12.5 | `Shopier/{ShopierSignature,ShopierAmount}.php` · `tests/Unit/ShopierSignatureTest.php` |
| 12.6 | `ShopierGateway.php` · `Shopier/{ShopierConfirmation,ShopierCallback}.php` · `tests/Feature/ShopierGatewayTest.php` |
| 12.7 | `ShopierCallbackController` · `ShopierOsbController` · `routes/api.php` · `ShopierCallbackTest` · `ShopierOsbTest` |
| 12.8 | `AppServiceProvider` · `tests/Feature/PaymentDriverResolutionTest.php` |
| 12.9 | Bu dosya · `FAZ-12-ELLE-DOGRULAMA.md` · `fazlar/README.md` |

### Frontend (12.10–12.14)

| Adım | Dosyalar |
|---|---|
| 12.10 | `types.ts` (`CheckoutAction`) · `services/payments.ts` (`isCheckoutAction`, `checkoutActionOf`) |
| 12.11 | `utils/submitProviderForm.ts` · `stores/useCheckoutStore.ts` (`goToProvider`) · `scripts/verify-payment.ts` |
| 12.12 | `CardPaymentForm.tsx` (kart alanları kalktı) |
| 12.13 | `PaymentReturnPage.tsx` · `utils/paymentReturn.ts` (60 sn yoklama, "doğrulanıyor" metni). Frontend 11.6'nın otomatik yayını (`useAutoPublish`) aynen korundu |
| 12.14 | `locales/*/translation.json` (10 dil: `providerCheckout.*`, `paymentReturn.*`) |

Her kod dosyasının kılavuzu `docs/rehber/<aynı yol>.md` altında, kendi adımında.

## 4. ⚠️ Plandan sapmalar

| Sapma | Neden |
|---|---|
| Callback ayrı bir metot (`parseCallback`), arayüzün `parseNotification`'ı Shopier'de **OSB** (K127) | `PaymentNotification`'ın sözü "işlenebilir bildirim"; `osb` modundaki callback bu sözü tutamaz |
| Sır kontrolü `AppServiceProvider`'da değil `ShopierGateway` kurucusunda (K123) | Controller'lar sürücüyü doğrudan istiyor; tek kapı kurucu |
| Callback adresi `route()` değil `ShopierGateway::CALLBACK_PATH` sabiti | Rota 12.7'de; her adım kendi başına yeşil kalsın. `ShopierCallbackTest` ikisini kilitliyor |
| Ek dosyalar: `CheckoutActionTest` (12.1), `PaymentNotificationAmountTest` (12.4), `ShopierConfirmation`/`ShopierCallback` (12.6), `submitProviderForm` testleri `verify-payment.ts`'te (12.11), `paymentReturn.ts` (12.13) | Listede yoktu; her biri kendi adımının kodunu sınıyor ya da tamamlıyor |
| `claude/PHP-LARAVEL-SETUP.md`'ye **dokunulmadı** | Faz 11'de olduğu gibi: ana dosyada commit'lenmemiş bir değişiklik ve işlenmemiş `EK-FAZ-11` var. Faz 12 kayıtları `PHP-LARAVEL-SETUP-EK-FAZ-12.md`'de |
| `SubscriptionTier::label()` artık kullanılıyor (`product_name`) | Faz 7 açık kararı #4 ("silinsin mi?") kendiliğinden kapandı |
| 12.13, frontend'e Faz 12 sırasında gelen **11.6** commit'inin (otomatik yayın) üstüne yeniden kuruldu | Prompt K67'yi ("ödeme yayınlamaz") varsayıyordu; 11.6 dönüş sayfasında otomatik yayını getirdi. Backend'de K67 hâlâ geçerli (ödeme bildirimi yayınlamaz; yayın ayrı `POST …/publish`). "Davetiyeni yayınla" düğmesi yazılmadı |

## 5. Alınan kararlar (K94, K117–K127)

| # | Karar |
|---|---|
| **K94** | §A boş. Varsayılanlar: klasik `api_pay4.php`, callback imzası `random_nr + platform_order_id`, `website_index=1`, alıcı yer tutucuları, OSB `res`+`hash`, yanıt `success`. Elle doğrulamadan sonra güncellenecek |
| **K117** | Onay kanalı `SHOPIER_CONFIRMATION`, varsayılan **`osb`**. `callback` ancak `status`'ün imzalı olduğu ya da başarısız ödemede callback gelmediği **gösterilirse** |
| **K118** | `CheckoutSession` bir `CheckoutAction` taşır (`RedirectAction` \| `FormPostAction`); `parseNotification(Request)` |
| **K119** | `OrderResource.checkout`; `redirectUrl` `type=redirect` olduğu sürece korunur |
| **K120** | Paket yok, kendi sürücümüz (`erkineren/shopier` HTML basıp `die` ediyor) |
| **K121** | `platform_order_id = orders.id = provider_ref`; Shopier'in kimliği `orders.provider_payment_id` (UNIQUE değil) |
| **K122** | Callback yanıtı 303, hedef config'ten; geçersiz imza → `/odeme/hata` (K69'dan bilinçli sapma: karşıda insan var). OSB K69'a uyar |
| **K123** | Sır, mod ya da (`osb` modunda) OSB kimliği eksikse sürücü kurulmaz → 503 |
| **K124** | Tutar metni tamsayı aritmetiğiyle; imzaya giren ile forma yazılan aynı değişken |
| **K125** | Ad/soyad/e-posta kullanıcıdan; TCKN, telefon, adres config'teki yer tutucular |
| **K126** | Bildirim tutar taşıyorsa siparişinkiyle eşleşmeli; uymazsa geçiş yok + `critical`. `istest=1` üretimde reddedilir |
| **K127** | Tarayıcı callback'i ayrı metot ve ayrı sonuç tipi (`ShopierCallback`); `osb` modunda bildirim nesnesi hiç üretilmez |

## 6. 🔴 Doğrulama durumu (dürüst liste)

### ✅ Koşan ve geçen (bulut çalışma alanı, 24 Ekim 2026)

- **Backend `composer check`: 781/781**, pint ✅, phpstan (level 8) 0 hata, `errors:export --check` güncel.
  Ortam: PHP 8.5.12 (conda-forge) + elle derlenmiş GD + PostgreSQL 16. Başlangıç: 684/684.
- **Her backend adımı ayrı ayrı yeşil:** 12.1 694 · 12.2 694 · 12.3 694 · 12.4 699 · 12.5 728 ·
  12.6 751 · 12.7 774 · 12.8 781.
- **Frontend `npm run check`** (tsc + build + 10 doğrulama betiği) yeşil; 12.10–12.14'ün her biri ayrı ayrı da yeşil.
- Mutasyonlar (elle, geri alındı): `istest` kontrolü kapatılınca, tutar kontrolü kapatılınca,
  callback bildirimi her modda üretilince ilgili testler kırmızıya döndü.

### ⬜ Koşmayan

- **İsmail'in makinesinde** (Windows + Herd + PostgreSQL 18) hiçbir şey koşmadı.
- **Gerçek Shopier'e hiçbir istek atılmadı.** İmza formülü, form alanları, callback ve OSB
  biçimi gerçek sisteme karşı **hiç** sınanmadı. Elle doğrulama betiği bunun için.

## 7. Öğrenilen dersler (69–71)

**69. İmza, kapsadığı alanlardan fazlasını kanıtlamaz.** "İmzalı" bir istek her alanıyla
güvenilir değildir. Önce "imzaya hangi baytlar giriyor?" sorulur; dışında kalan her alan,
imzasız bir girdi kadar güvenilirdir. (Callback'te `status`.)

**70. İmza "kimden" der, "neyin bedeli" demez.** Doğru imzalı bir bildirim yanlış siparişle
eşleşebilir. Tutar karşılaştırması, kimlik doğrulamasının yerine geçmez, onu tamamlar.

**71. Bir değeri onu üreten fonksiyonla sınamak, onu kendisiyle karşılaştırmaktır.** Kripto
test vektörleri bağımsız bir araçla (Python `hmac`) hesaplandı.

## 8. 🔴 Açık kararlar ve borçlar

1. **§A'nın sekiz satırı** (Shopier paneli/dokümanı) → K94 güncellenir.
2. **OSB eşlemesi** (`matchOsbToOrder`) → elle doğrulama adım 7.
3. **İade bildirimi** yok: Shopier panelinden iade yapılırsa bize haber gelmiyor (bilinen).
   Gelmiyorsa `orders:refund` komutu gerekir (K100'ün yayından kaldırma kuralı ancak
   `refunded` durumuyla tetiklenir).
4. **Yer tutucu TCKN** Shopier'de reddedilirse kullanıcıdan TCKN istemek gerekir (KVKK kararı).
5. **Eski kart arayüzü** (`CardPreview`, `CardBrandMark`, `utils/paymentCard.ts`) artık
   çağrılmıyor: silinsin mi?
6. **`PaymentReturnPage`'in eski metinleri** hâlâ Türkçe sabit; yalnızca yeni ikisi çeviride.
7. **Fake'in `X-Signature` webhook ucu** üretimde de açık (sürücü `shopier` iken imza
   `FakeGateway` ile değil `ShopierGateway::parseNotification` = OSB ile doğrulanır). Kapatılsın mı?

## 9. Kapanış listesi

- [ ] Commit'ler (raporun komut blokları), iki depoda `git push -u origin faz-12-shopier`
- [ ] Bu makinede `php artisan migrate` + `composer check` (beklenen 781)
- [ ] Frontend `npm run check`
- [ ] §A doldur → K94
- [ ] Elle doğrulama 1–9

## 10. Bir cümlelik özet

Kart artık yalnızca Shopier'de yazılıyor; sitemiz imzalı bir form üretiyor, `paid` kararını
yalnızca imzası bütün gövdeyi kapsayan sunucu bildirimine ve tutar eşleşmesine bırakıyor.
