<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Doğrulama bağlantısı geçersiz (K191): tek kod, çok sebep (imza, süre, kullanılmış,
 * adres değişmiş, hesap yok). Sebep yalnızca loga gider (EmailChangeFailedException deseni, H6).
 */
final class EmailVerificationFailedException extends RuntimeException implements HasErrorCode
{
    public static function because(string $reason): self
    {
        return new self("Email verification rejected: {$reason}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EmailVerificationInvalid;
    }

    /** @return array<string, mixed> */
    public function errorParams(): array
    {
        return [];
    }
}
