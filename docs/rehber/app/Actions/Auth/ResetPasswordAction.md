# `app/Actions/Auth/ResetPasswordAction.php`

> **Faz:** 10 — Dilim D, adım 10.33
> **Çağıran:** `AuthController::resetPassword` → **204** · hata: 422 `PASSWORD_RESET_INVALID`
> **İstisna:** [`PasswordResetFailedException.md`](../../Exceptions/PasswordResetFailedException.md)

## 1. Akış

```php
$status = Password::broker()->reset($credentials, function (User $user, string $password) {
    DB::transaction(function () use ($user, $password) {
        $user->forceFill(['password' => $password])->save();   // 'hashed' cast → Argon2id (K32)
        $user->tokens()->delete();                              // 🔴 TÜM oturumlar
    });
    event(new PasswordReset($user));
});

if ($status !== Password::PASSWORD_RESET) {
    throw PasswordResetFailedException::rejected($status);
}
```

Aracın bizim yerimize denetledikleri: token **o e-postanın** satırında mı, 60
dakikadan eski mi, hash'i tutuyor mu. Başarıda token satırını **kendisi siler**,
yani bağlantı tek kullanımlık.

## 2. 🔴 Neden TÜM token'lar iptal?

Parolasını sıfırlayan kullanıcı çoğu zaman bir şeyden şüpheleniyordur: çalınmış bir
oturum, unutulmuş bir cihaz, ele geçirilmiş bir parola. Eski parolayla alınmış
token'lar açık kalsaydı, saldırganın oturumu sıfırlamadan sonra da çalışırdı ve
sıfırlama bir koruma sağlamamış olurdu.

Bedeli: kullanıcı kendi cihazlarında da yeniden giriş yapar. Kabul edilebilir,
çünkü sıfırlama sık yapılan bir işlem değil.

**Neden yeni bir oturum açılmıyor?** Sıfırlama yanıtı token döndürmüyor (204) ve
frontend kullanıcıyı giriş sayfasına yönlendiriyor. Sebep: sıfırlama bağlantısı bir
**e-posta**ya gidiyor. Bağlantıya tıklayıp anında oturum açmak, e-posta hesabını ele
geçiren birine DavetKart oturumunu da tek tıkla vermek olurdu. Yeni parolayı bir
kez daha yazmak bu adımı bilinçli kılıyor.

## 3. Neden transaction?

Parola güncellendi ama token iptali başarısız olsaydı eski oturumlar açık kalırdı.
Tersi olsaydı kullanıcı eski parolasıyla, oturumsuz kalırdı. İkisi tek birim.
`PasswordReset` olayı transaction'ın **dışında**: dinleyicisi (bugün yok) dış bir
şey yapacaksa kesinleşmiş veriyi görmeli.

## 4. Hatalar

Araç `PASSWORD_RESET` dışında ne döndürürse döndürsün (token yanlış, kullanıcı yok,
çok sık) dışarı **tek kod** gider: `PASSWORD_RESET_INVALID` (H6). Test, yanlış token
ile kayıtsız e-postanın **ham gövdesinin** birebir aynı olduğunu iddia ediyor.
