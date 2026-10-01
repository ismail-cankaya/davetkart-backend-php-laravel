<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Support\Facades\Password;

/**
 * Parola sifirlama baglantisi gonderir — hesap VARSA (Faz 10, 10.32).
 *
 * 🔴 Sonuc cagirana BILEREK donmez (void). Laravel'in parola araci uc sonuc
 * uretir: gonderildi, kullanici yok, cok sik istendi. Controller hangisi
 * olursa olsun AYNI 202'yi doner; ayrimi bilseydi bir gun kullanmak
 * isteyebilirdi ve sifirlama formu bir hesap tarayicisina donerdi (08 §3.1).
 *
 * 🔴 Zaman farki: mail KUYRUGA gider (ResetPasswordNotification
 * ShouldQueue). Kayitli e-postada yalnizca bir token satiri ve bir kuyruk
 * kaydi yazilir; SMTP'nin yavasligi yanit suresine yansimaz. Senkron
 * gonderim, "yanit 2 sn surdu = bu e-posta kayitli" sizintisi olurdu.
 *
 * Ayni e-postaya 60 sn icinde ikinci istek araci gecemez (auth.passwords
 * .users.throttle): yeni mail gitmez, yanit yine 202.
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/SendPasswordResetLinkAction.md
 */
final class SendPasswordResetLinkAction
{
    public function handle(string $email): void
    {
        Password::broker()->sendResetLink(['email' => $email]);
    }
}
