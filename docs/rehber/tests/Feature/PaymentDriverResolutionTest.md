# `tests/Feature/PaymentDriverResolutionTest.php`

> **Faz:** 12 — adım 12.8 · **Sınadığı:** `AppServiceProvider::resolvePaymentGateway` + `ShopierGateway` kurucusu (K70, K123)

## Sorusu

*"Yapılandırma yanlışsa ne olur?"* Cevap her zaman aynı olmalı: **gürültü**. Ödeme uçları
503 `PROVIDER_UNAVAILABLE`, `orders` tablosuna satır yazılmaz, sistemin geri kalanı
(davetiye okuma, LCV) çalışır.

## Testler

| Test | Kanıtladığı |
|---|---|
| `the_configured_drivers_resolve` | `shopier` → `ShopierGateway`, `fake` → `FakeGateway` |
| `a_shopier_checkout_returns_a_signed_form` | **Uçtan uca**: HTTP checkout → `checkout.type=form`, `redirectUrl` YOK, sipariş `provider=shopier` ve `provider_ref=id`, imza tutuyor, sır yanıtta yok |
| `a_misconfigured_provider_is_503_and_writes_no_order` (4 veri) | Bilinmeyen sürücü, boş sır, anahtar yok, `osb` modunda OSB yok |
| `callback_mode_resolves_without_osb_credentials` | `callback` modunda OSB isteğe bağlı |

## 🔴 "Sipariş yazılmaz" neden önemli?

`StartCheckoutAction` sürücüyü kurucusunda alıyor (autowiring). Sürücü kurulamazsa
Action hiç kurulmuyor, yani sipariş satırı hiç yazılmıyor. Kontrol çağrı anında
(`startCheckout` içinde) olsaydı sipariş yazılır, sonra `failed` işaretlenirdi: her
yapılandırma hatası veritabanında bir çöp satır bırakırdı. `assertDatabaseCount('orders', 0)`
bu sıralamayı kilitliyor.
