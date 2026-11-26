<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Hesabın başarısız giriş kovası dolu (SEC-16, K265) -> 429 RATE_LIMITED + retryAfter; IP kovalarıyla aynı yanıt.
 * Ayrıntılı açıklama: docs/rehber/app/Exceptions/AccountLoginThrottledException.md
 */
final class AccountLoginThrottledException extends RuntimeException implements HasErrorCode
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many failed login attempts for this account.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RateLimited;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return ['retryAfter' => max(1, $this->retryAfterSeconds)];
    }
}
