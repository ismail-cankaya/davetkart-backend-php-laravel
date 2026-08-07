<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Cikis: YALNIZCA istegi tasiyan token'i iptal eder.
 *
 * Kullanicinin diger cihazlardaki oturumlari ETKILENMEZ — telefondan cikmak
 * bilgisayardaki oturumu dusurmemeli.
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/RevokeTokenAction.md
 */
final class RevokeTokenAction
{
    public function handle(User $user): void
    {
        $token = $user->currentAccessToken();

        // TransientToken (cerez tabanli SPA kipi) veritabaninda kayitli DEGILDIR
        // ve delete() metodu YOKTUR; kontrolsuz cagri olumcul hata verir.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
