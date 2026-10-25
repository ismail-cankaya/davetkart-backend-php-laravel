# Parola değiştirme: `PUT /api/auth/password`

> **Faz:** 14, adım 14.8 · **Karar:** K142
> **Dosyalar:** `ChangePasswordRequest`, `ChangePasswordAction`, `PasswordChangedNotification`, `PasswordController`
> **Test:** `tests/Feature/PasswordChangeTest.php`

## 1. Neden "şifremi unuttum" yetmiyordu?

Faz 10'dan beri hesap sayfasındaki "şifremi değiştir" bağlantısı sıfırlama mailine
gidiyordu. Üç sorunu vardı: mail sunucusuna bağımlı (canlıda yoksa hiç çalışmaz), parolasını
**bilen** kullanıcıyı gelen kutusuna gönderiyor ve sıfırlama bütün oturumları kapattığı için
kullanıcıyı kendi ekranından da atıyor.

## 2. Gövde ve kurallar

```json
{ "currentPassword": "…", "password": "…" }
```

| Kural | Neden |
|---|---|
| `currentPassword` → `current_password:sanctum` | 🔴 Token yetmez: açık kalmış oturumu bulan biri parolayı değiştirip sahibini dışarıda bırakamasın |
| `password` → `min:8 · max:255` | Kayıtla **aynı** |
| `different:currentPassword` | Aynı parolayı "değiştirmek" hiçbir şeyi korumaz |
| `throttle:auth` | Mevcut parola bir tahmin hedefi (hesap silme ucuyla aynı kova) |

Onay alanı (`password_confirmation`) backend'de **yok**: kayıt ve sıfırlamada da yok, ikinci
kez yazdırmak frontend'in işi. Sözleşme üç uçta aynı kaldı.

## 3. 🔴 Hangi oturumlar kapanır? (K142)

| Akış | Kapanan |
|---|---|
| Sıfırlama (`ResetPasswordAction`, Faz 10) | **Hepsi** — kimse oturumda değil |
| Değiştirme (bu Action) | İsteği yapan **hariç** hepsi |

Kullanıcı ekranın başında; onun oturumunu kapatmak "değişti ama sizi de attık" demek olurdu.
Diğer cihazlardaki token'lar eski parolayla alınmıştı; açık kalsalardı değişiklik koruma
sağlamazdı.

`PersonalAccessToken` değilse (çerezli SPA oturumu, `TransientToken`) korunacak satır yok:
controller'daki `instanceof` bir iş kuralı değil, yönlendirme.

## 4. Yan etkiler

- Bekleyen sıfırlama bağlantısı silinir: eski parola zamanında istenmiş bağlantı yeni
  parolayı ezmesin.
- Kuyruktan *"parolanız değiştirildi, siz değilseniz…"* maili. Bağlantı token taşımaz,
  yalnızca "şifremi unuttum" sayfasına gider.

## 5. Testler

| Test | Kanıtladığı |
|---|---|
| `the_password_changes_and_only_this_session_survives` | 🔴 Diğer cihaz 401, bu cihaz 200, mail gitti |
| `a_wrong_current_password_changes_nothing` | 422 `current_password`, mail yok |
| `the_new_password_follows_the_registration_rules` | `min:8` |
| `the_same_password_is_refused` | `different` |
| `a_pending_reset_link_is_invalidated` | Bekleyen sıfırlama satırı gider |
