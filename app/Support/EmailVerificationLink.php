<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Kayıt sonrası e-posta doğrulama bağlantısı (K191, SEC-09) — EmailChangeLink deseni:
 * imzalı, süreli, veritabanında hiçbir şey saklanmaz; adresin parmak izi bağlantıyı adrese bağlar.
 * Ayrıntılı açıklama: docs/rehber/app/Support/EmailVerificationLink.md
 */
final class EmailVerificationLink
{
    public const ROUTE = 'public.email-verification.confirm';

    public static function for(User $user): string
    {
        $signed = URL::temporarySignedRoute(
            self::ROUTE,
            now()->addMinutes(Config::integer('davetkart.auth.email_verification_expire_minutes')),
            [
                'user' => $user->id,
                'address' => EmailChangeLink::fingerprint($user->email),
            ],
            absolute: false,
        );

        $query = (string) parse_url($signed, PHP_URL_QUERY);

        return Config::string('davetkart.frontend.url')
            .Config::string('davetkart.frontend.email_verification_path')
            .'?'.$query;
    }
}
