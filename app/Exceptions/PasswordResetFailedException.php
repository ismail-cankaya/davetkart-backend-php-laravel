<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Parola sifirlanamadi (Faz 10, 10.31). 🔴 SEBEBI ISTEMCIYE SOYLENMEZ (H6).
 *
 * Laravel'in parola araci (password broker) uc ayri sonuc dondurur: token
 * gecersiz, kullanici yok, (sifirlamada) throttle. Disariya giden tek sey
 * PASSWORD_RESET_INVALID'dir: "bu e-posta kayitli degil" demek sifirlama
 * formunu bir hesap tarayicisina cevirir. Mesaj yalnizca log'u ve yerel
 * `debug` blogunu besler. RegistrationFailedException'in kardesi.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/PasswordResetFailedException.md
 */
final class PasswordResetFailedException extends RuntimeException implements HasErrorCode
{
    /** @param  string  $brokerStatus  Password::* sabiti (ör. passwords.token) — yalnizca log icin */
    public static function rejected(string $brokerStatus): self
    {
        return new self("Password reset rejected by the broker: {$brokerStatus}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PasswordResetInvalid;
    }

    /**
     * 🔴 BOS ve oyle kalmali (H6).
     *
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return [];
    }
}
