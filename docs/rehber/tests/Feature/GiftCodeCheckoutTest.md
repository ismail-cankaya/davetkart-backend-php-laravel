# `tests/Feature/GiftCodeCheckoutTest.php` — hediye koduyla ödeme başlatma

> **Faz:** 11, adım 11.7 · 23 test

---

## 1. İlke: yanıta değil etkiye bak (T14)

Bir checkout testinin asıl kanıtı yanıt değil **satırlar**:

- `orders.amount_minor` ve `orders.discount_minor`: indirim sunucuda mı hesaplandı?
- `gift_code_redemptions`: kullanım yazıldı mı, aktif mi?
- Sağlayıcı çağrıldı mı? Hediye testlerinde **patlayan** bir sağlayıcı bağlanır. Test
  201 alıyorsa sağlayıcıya hiç gidilmemiştir.

Fiyatlar `setUp()`'ta sabitlenir (249 / 399 / 549 TL). Beklenen kuruşlar testte elle
okunabilir: `%50 × 39900 = 19950`.

## 2. Kapsam

| Grup | Testler |
|---|---|
| Kısmi indirim (K108) | yüzde · sabit · yazım farkı (küçük harf, boşluk, tire) · boş kod = kodsuz |
| Tam karşılayan (K107) | `free` · `%100` · fiyatı aşan `fixed` (DataProvider) → paid gift, sağlayıcısız |
| Davetiye | gift siparişi davetiyeye bağlanır + `InvitationChanged` · gift ile **yayınlanabilir** (402 → 200) · bağsız gift siparişi ilk yayında bağlanır (`ClaimReleasedOrderAction`) |
| Hatalar (K113) | yanlış plan → `NOT_APPLICABLE` + `params.tier` · 🔴 dört geçersiz sebep **bayt bayt aynı** · süresi henüz dolmamış kod çalışır |
| Sayım (K110, K111, K114) | aynı kişi iki kez → `ALREADY_USED` · `expired` sonrası yeniden kullanım · sağlayıcı hatası kodu serbest bırakır · max 1 kodu ikinci kişi → `INVALID` · iade kullanımı geri vermez |
| Bilinen sınır (K116) | geç ödeme kullanımı yeniden aktifleştirir, sayım max'ı aşar |
| Güvenlik | gövdedeki `amount`/`discount`/`price` yok sayılır · 402 koddan önce · 65 karakter → `VALIDATION_FAILED` · 🔴 düz kod veritabanında ve log'da yok |

## 3. Önemli testler

### `every_invalid_reason_gets_the_same_bytes`

Dört kod hazırlanır: hiç olmayan, kapatılmış, süresi dolmuş, tükenmiş. Dördünün yanıt gövdesi
`array_unique` ile karşılaştırılır; tek bir değer kalmalı. Alan bazında karşılaştırma
(`assertJsonPath('error.code', ...)`) yetmezdi: bir gün zarfa sebep taşıyan bir alan eklense
yakalanmazdı.

### `a_failing_provider_marks_the_order_failed_and_frees_the_code`

Sağlayıcı patlar → 502 → sipariş `failed` → aktif kullanım 0. Sonra gerçek `FakeGateway`
geri bağlanır ve **aynı kullanıcı** aynı kodu kullanabilir. K110'un (sayaç yerine sipariş
durumu) somut kazancı: telafi yolunda tek satır kod yazılmadı.

### `the_plain_code_is_stored_and_logged_nowhere`

`Log::spy()` ile log çağrıları yakalanır. Kullanım log'u **var** ama yalnızca `code_hint`
taşıyor, düz kod yok. Sonra üç tablonun (`gift_codes`, `gift_code_redemptions`, `orders`)
tamamı JSON'a dökülüp düz kod aranır.

## 4. Testin göremediği

**Eşzamanlılık.** PHPUnit tek süreçte, tek bağlantıda ve `RefreshDatabase`'in açtığı tek
transaction'da koşar. `lockForUpdate()` silinse bu dosya yeşil kalır. Kilit elle,
`pcntl_fork` ile 6 süreçlik bir denemeyle sınandı (`ReserveGiftCodeAction.md` §2.3).

## 5. Mutasyon kanıtı (23 Ekim 2026, `--filter=GiftCode`)

| Mutasyon | Kırılan test sayısı |
|---|---|
| Süre kontrolü kaldırıldı | 1 |
| Tükenme `>=` → `>` | 3 |
| Kişi başı kontrol kaldırıldı | 3 |
| İade (`refunded`) kullanımı geri verir | 3 |
| `expired` kullanımı tutar | 5 |
| Hediye yolu kapatıldı (her şey sağlayıcıya) | 6 |
| Sabit indirim fiyatı aşabilir | 3 |
| Plan kontrolü kaldırıldı | 4 |
| `gift-code` limiter kapatıldı | 3 |
| Önizlemede `totalMinor` = fiyat | 3 |
| Kullanım satırı yazılmıyor | 8 |
| `INVALID` mesajı her seferinde farklı | **0**: üretim kipinde mesaj yanıta girmiyor (`GiftCodeException.md` §2) |
