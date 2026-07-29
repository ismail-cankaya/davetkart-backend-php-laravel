# `config/auth.php` — Kılavuz

"Kullanıcı kim?" sorusunun nasıl cevaplanacağını tanımlar.

## İki temel kavram

| Kavram | Sorusu | Bizdeki değeri |
|---|---|---|
| **Guard** | Kullanıcı **nasıl** tanınıyor? | `web` → oturum çerezi · `sanctum` → Bearer token |
| **Provider** | Kullanıcı **nereden** okunuyor? | `users` tablosu, `App\Models\User` üzerinden |

Guard "kimlik kartını nasıl kontrol ederim", provider "kayıt defteri nerede"
sorusunu cevaplar. İkisinin ayrılması, aynı kullanıcı tablosunu farklı giriş
yöntemleriyle kullanabilmeyi sağlar.

## DavetKart'ta hangisi kullanılıyor?

**Sanctum guard.** Frontend `Authorization: Bearer <token>` gönderiyor;
rotalarımız `auth:sanctum` middleware'i ile korunacak. Oturum çerezi (`web` guard)
API tarafında kullanılmıyor.

```php
Route::middleware('auth:sanctum')->group(function () {
    // korumalı rotalar
});
```

## Şifre hash'leme — bcrypt yerine Argon2id

Laravel varsayılanı `bcrypt` (`config/hashing.php`, `BCRYPT_ROUNDS=12`).
Planımız **Argon2id**'ye geçmek.

Fark: bcrypt yalnızca CPU maliyeti üretir. Argon2id ayrıca **bellek maliyeti**
üretir — saldırganın binlerce GPU çekirdeğini paralel çalıştırması, her çekirdek
için ayrı RAM gerektiği için pahalılaşır. Aynı hız bütçesiyle daha yüksek direnç.

Bu geçiş Adım 6'da (Auth modülü) yapılacak; `config/hashing.php` yayımlanıp
`driver` değiştirilecek.

## `password_timeout`

Hassas bir işlemden önce şifrenin yeniden sorulması için geçmesi gereken süre
(saniye). Bizde şu an kullanılmıyor; ileride "hesap silme" gibi bir işlem
eklenirse devreye girer.

## Dikkat

- Token'ı `Bearer` öneki olmadan göndermek 401 üretir — frontend'in `api.ts`
  interceptor'ı 401'de oturumu düşürür. Bu yüzden **yetki hatalarında 403**
  döndürmek zorunludur; 403 yerine 401 dönersek kullanıcı sistemden atılır.
- Guard adı `sanctum`, provider adı `users`; karıştırılırsa "Auth guard [x] is
  not defined" hatası alınır.
