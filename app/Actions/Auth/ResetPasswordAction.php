<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\PasswordResetFailedException;
use App\Models\User;
use App\Support\AccountLoginThrottle;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Maildeki baglantiyla parolayi degistirir (Faz 10, 10.33): TUM token'lar iptal, yeni oturum ACILMAZ.
 * Basarida hesabin basarisiz giris kovasi bosalir (SEC-16, K265).
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/ResetPasswordAction.md
 */
final class ResetPasswordAction
{
    public function __construct(private readonly AccountLoginThrottle $throttle) {}

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

        $this->throttle->clear($email);
    }
}
