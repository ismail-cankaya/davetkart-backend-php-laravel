# `app/Support/EmailVerificationLink.php`

> **Faz 19 (19.14 · K191, SEC-09)** · Kardeş: `EmailChangeLink` (Faz 14, K144) · Çağıran: `SendEmailVerificationAction`

## Ne yapar

Kayıt sonrası doğrulama postasındaki bağlantıyı üretir. Bağlantı frontend'in `/eposta-dogrula` sayfasına gider
(`davetkart.frontend.email_verification_path`); sorgu dizgisi backend'in göreli imzasını taşır. Sayfa sorguyu olduğu
gibi `POST /api/public/email-verification/confirm`'a iletir.

| Sorgu alanı | Değer |
|---|---|
| `user` | kullanıcı kimliği |
| `address` | `hash_hmac('sha256', email, app.key)` (EmailChangeLink'in `fingerprint()`'i) |
| `expires`, `signature` | Laravel `temporarySignedRoute`, ömür `auth.email_verification_expire_minutes` (24 saat) |

## Neden veritabanında token yok (plandan sapma)

Prompt "rastgele token, veritabanında yalnızca karması" diyordu ve `EmailChangeLink`'i örnek gösteriyordu. Oysa
`EmailChangeLink` hiçbir şey saklamaz: imzalı, süreli bir bağlantıdır ve tek kullanımını **durumdan** alır (eski
adresin parmak izi değişince bağlantı bayatlar). Burada da aynısı yapıldı (K191):

- **Sır saklanmıyor:** veritabanı sızsa bile geçerli bağlantı üretilemez; imzayı `APP_KEY` atar.
- **Tek kullanım:** `ConfirmEmailVerificationAction` doğrulanmış hesapta bağlantıyı reddeder.
- **Adrese bağlı:** adres değişirse (`ConfirmEmailChangeAction`) parmak izi tutmaz, bağlantı reddedilir.
- **Ham adres bağlantıda yok:** yalnızca HMAC'i; posta ya da tarayıcı geçmişi adresi göstermez.

Bedeli: bağlantı tek tek iptal edilemez (süre dolana ya da hesap doğrulanana kadar geçerli). Doğrulanınca zaten
işe yaramadığı için kayıp değil.

Laravel'in `MustVerifyEmail` + `verification.verify` yolu seçilmedi: o bir **web** rotası ve tarayıcıyı backend'e
yönlendirir; bu projede posta bağlantıları SPA'ya gider (parola sıfırlama, e-posta değişikliği). Ayrıca arayüz
`Registered` olayında Laravel'in kendi İngilizce postasını gönderirdi.

Testler: `EmailVerificationTest::a_verification_link_works_once_and_expires` ·
`the_link_stores_no_secret_and_carries_no_raw_address` · `a_link_for_a_changed_address_or_a_tampered_link_is_refused`.
