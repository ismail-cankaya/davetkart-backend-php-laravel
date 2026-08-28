<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Kayit tamamlanamadi. 🔴 SEBEBI ISTEMCIYE SOYLENMEZ (H6).
 *
 * Mesaj yalnizca log'a ve yerel `debug` blogunu besler; disariya giden tek sey
 * REGISTRATION_FAILED kodudur. "Bu e-posta zaten kayitli" demek, kayit formunu
 * bir hesap tarayicisina cevirir (08 §3.1).
 * Ayrintili aciklama: docs/rehber/app/Exceptions/RegistrationFailedException.md
 */
final class RegistrationFailedException extends RuntimeException implements HasErrorCode
{
    /**
     * E-posta zaten kayitli.
     *
     * Adlandirilmis kurucu: sebep KODDA acik, YANITTA gizli.
     */
    public static function emailTaken(): self
    {
        return new self('Registration failed: email already exists.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RegistrationFailed;
    }

    /**
     * 🔴 BOS ve oyle kalmali (H6): sebep disari cikarsa kayit formu bir hesap
     * tarayicisina doner.
     *
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return [];
    }
}
