# `ShowPricingAction` · `PricingOverview` — frontend'in fiyat kataloğu

> **Faz:** 13, adım 13.6 · **Karar:** K128, K134, K135
> **Uçlar:** `GET /api/public/plans` (misafir) · `GET /api/pricing` (oturumlu)
> **Test:** `tests/Feature/PricingTest.php`

---

## 1. Ne döner?

Üç planın fiyat dökümü (`QuotePriceAction::forTiers`), açık ödeme yöntemleri
(`PaymentMethods::enabled`), havale açıksa hesap bilgisi ve ilk yayın yüzdesi.

| Alan | Misafir | Oturumlu |
|---|---|---|
| Kampanya indirimi | ✔ | ✔ |
| İlk yayın indirimi (planlara uygulanmış) | ✘ | ✔ (uygunsa) |
| `firstPublishPercent` (yazı için: "ilk yayınına %60") | ✔ | ✔ |

## 2. Neden bir Action, neden bir DTO?

Controller yalnızca yönlendirir (K3). Kataloğu kurmak ise iki kaynağı birleştiren bir iş
(fiyat + yöntemler). Bu iş Resource'ta da yapılamaz: Resource yalnızca **biçim** değiştirir,
servis çağırmaz. Bu yüzden ortaya bir Action ve onun döndürdüğü saf veri (`PricingOverview`)
çıktı.

## 3. Havale hesabı neden yöntem açıkken?

`BANK_TRANSFER_*` dolu ama `PAYMENT_METHODS` içinde `bank_transfer` yoksa hesap **gitmez**.
Frontend "hesap geldiyse havale açıktır" diye düşünmek zorunda kalmasın. Tek doğru kaynak
`paymentMethods` listesi.
