<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Yeni bir oturum (Sanctum token'ı) açar ve hangi cihazdan açıldığını yazar
 * (Faz 14, 14.9 · K143).
 *
 * Giriş ve kayıt AYNI yerden token alır: etiket ve cihaz bilgisi iki yerde
 * ayrı ayrı yazılsaydı biri unutulur, oturum listesinde "bilinmeyen cihaz"
 * satırları birikirdi (C3).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Auth/IssueApiTokenAction.md
 */
final class IssueApiTokenAction
{
    /** Login ve Register'ın eski TOKEN_NAME'i; token kaynağı ayırt edilmez. */
    private const TOKEN_NAME = 'api';

    /** Kolon 255; uzun ve anlamsız başlık (bot, saldırı) kırpılır. */
    private const MAX_USER_AGENT = 255;

    public function handle(User $user, ?string $userAgent): string
    {
        $token = $user->createToken(self::TOKEN_NAME);

        $agent = $userAgent === null ? null : mb_substr(trim($userAgent), 0, self::MAX_USER_AGENT);

        if ($agent !== null && $agent !== '') {
            $token->accessToken->forceFill(['user_agent' => $agent])->save();
        }

        return $token->plainTextToken;
    }
}
