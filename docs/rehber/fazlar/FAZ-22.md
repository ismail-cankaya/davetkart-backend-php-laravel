# Faz 22 — Yayın öncesi son faz: eksikler ve düzenlemeler

> **Başlangıç:** 27 Kasım 2026 · **Entegrasyon dalı:** `faz-22-release-prep` (`faz-21-backlog` @ `1191a95`'ten; frontend `e28b599`'dan)
> **Takip tablosu:** docs deposu `phases/faz-22/README.md` §1 · **Kararlar:** `decisions/addenda/PHP-LARAVEL-SETUP-EK-FAZ-22.md`
> **Önceki:** [`FAZ-21.md`](FAZ-21.md)

## Fazın amacı

Release'den önceki son faz: eksiklerin ve düzenlemelerin yapılacağı yer (İsmail, 27 Kasım 2026). Madde listesi açık;
yeni madde geldikçe buraya bölüm eklenir.

## 22.1 — E-posta doğrulaması satın almayı engellemez (K276)

### İstek

İsmail: doğrulama olmadan satın alma olmuyordu, bu kural değişecek. Panelde hatırlatma olsun, satın almaya engel olmasın;
uyarı "Hesap erişiminizi kaybetmemek için e-posta adresinizi doğrulayın".

### Karar (K276)

- Checkout ve yayın doğrulamayı **beklemez**. Yayın da kalkmak zorunda: kalsaydı ödeme dönüşündeki otomatik yayın (K267) 403 alırdı.
- İlk yayın indirimi doğrulama istemeye **devam eder** (İsmail oturumda seçti): doğrulanmamış hesap liste fiyatından öder.
- Kayıtta posta, yeniden gönderme (saatte 3), 24 saatlik bağlantı aynı (K191).

### Değişen dosyalar

| Dosya | Ne | Kılavuz |
|---|---|---|
| `app/Support/VerifiedEmailGuard.php` | silindi | [`VerifiedEmailGuard.md`](../app/Support/VerifiedEmailGuard.md) (başına silindi bandı) |
| `app/Exceptions/EmailNotVerifiedException.php` | silindi | — |
| `app/Actions/Payment/StartCheckoutAction.php` | guard bağımlılığı ve ilk satır çıktı | [`StartCheckoutAction.md`](../app/Actions/Payment/StartCheckoutAction.md) "Faz 22" |
| `app/Actions/Invitation/PublishInvitationAction.php` | guard bağımlılığı ve çağrısı çıktı | [`PublishInvitationAction.md`](../app/Actions/Invitation/PublishInvitationAction.md) "Faz 22" |
| `app/Enums/ErrorCode.php`, `contracts/error-codes.json` | `EMAIL_NOT_VERIFIED` silindi (K270; 28 → 27) | [`ErrorCode.md`](../app/Enums/ErrorCode.md) "Faz 22", `docs/08` §4 |
| `app/Services/Pricing/FirstPublishEligibility.php` | yalnızca yorum (kural korundu) | [`FirstPublishEligibility.md`](../app/Services/Pricing/FirstPublishEligibility.md) "Faz 22" |
| `app/Actions/Auth/RegisterUserAction.php`, `app/Http/Resources/UserResource.php` | yalnızca yorum | [`RegisterUserAction.md`](../app/Actions/Auth/RegisterUserAction.md) "Faz 22" |
| `tests/Feature/EmailVerificationTest.php` | `cannot_start_a_checkout` → `can_start_a_checkout_at_list_price` (sipariş 39900, `promotion` boş); `cannot_publish` → `can_publish_a_paid_invitation`; indirim testi aynı | — |
| `tests/Feature/InvitationTest.php` | yalnızca test yorumu | — |

Frontend (`faz-22-release-prep`): bant metni tr/en, doğrulama sayfası `doneBody`, fiyat sayfası indirim bandı ve SSS,
`EMAIL_NOT_VERIFIED` sözleşme kopyasından ve `errors.json`'dan silindi. Ayrıntı: frontend
`docs/rehber/src/components/account/EmailVerificationBanner.md` "Faz 22".

### Kapılar

| Kapı | Sonuç |
|---|---|
| `composer check` | **koşmadı**: bulut çalışma alanında PHP 8.3 (proje `^8.5`), `composer install` GitHub'dan paket indiremedi ("Could not authenticate against github.com"). Değişen PHP dosyalarında yalnızca `php -l` koştu: sözdizimi hatası yok |
| `npm run check` (frontend) | çıkış 0 · `verify:errors` "27 kod, iki dil" · `verify:i18n` 780 parça, 65 dosya. Node 22.23.5 ile (proje 24) |
| `npm run e2e` | **koşmadı** (backend ayağa kalkmadı) |

### Elle doğrulama (İsmail)

1. `composer check`: `EmailVerificationTest` iki yeni testi ve `ErrorCodeUsageTest` yeşil olmalı.
2. Yeni hesap aç, adresi doğrulama: panelde amber bant "Hesap erişiminizi kaybetmemek için…" görünür; fiyat sayfasından
   paket al: ödeme sayfası açılır, tutar liste fiyatı.
3. Ödemeden dönünce davetiye yayınlanır (403 yok).
4. Bağlantıyı aç: bant kaybolur; sonraki ilk siparişte (daha önce ödenmiş sipariş yoksa) %60 indirim görünür.
