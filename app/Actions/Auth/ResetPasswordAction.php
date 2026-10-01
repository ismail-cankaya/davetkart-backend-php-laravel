<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\PasswordResetFailedException;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Maildeki baglantiyla parolayi degistirir (Faz 10, 10.33).
 *
 * 🔴 Basarida kullanicinin TUM token'lari iptal edilir. Parolasini
 * sifirlayan kullanici cogu zaman bir seyden supheleniyordur: calinmis bir
 * oturum, unutulmus bir cihaz. Eski parolayla alinmis token'lar acik
 * kalsaydi sifirlama koruma saglamazdi. Kullanici yeniden giris yapar;
 * sifirlama yeni bir oturum ACMAZ (frontend giris sayfasina yonlendirir).
 *
 * Token'in tek kullanimlik olmasini, 60 dk omrunu ve e-postayla eslesmesini
 * Laravel'in parola araci denetler; basarida token satirini kendisi siler.
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/ResetPasswordAction.md
 */
final class ResetPasswordAction
{
    /**
     * @throws PasswordResetFailedException Token yanlis/suresi dolmus ya da e-posta kayitli degil -> 422
     */
    public function handle(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                // Parola ve token iptali TEK transaction: biri olup digeri
                // olmasaydi ya eski oturumlar acik kalirdi ya da kullanici
                // eski parolasiyla, oturumsuz kalirdi.
                DB::transaction(function () use ($user, $password): void {
                    // 'password' => 'hashed' cast'i duz parolayi hash'ler (K32).
                    $user->forceFill(['password' => $password])->save();
                    $user->tokens()->delete();
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw PasswordResetFailedException::rejected($status);
        }
    }
}
