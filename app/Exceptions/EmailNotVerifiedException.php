<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/** Doğrulanmamış hesap ödeme başlatamaz ve yayınlayamaz (K191) -> 403 EMAIL_NOT_VERIFIED. */
final class EmailNotVerifiedException extends RuntimeException implements HasErrorCode
{
    public function __construct()
    {
        parent::__construct('The account email address is not verified.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EmailNotVerified;
    }

    /** @return array<string, mixed> */
    public function errorParams(): array
    {
        return [];
    }
}
