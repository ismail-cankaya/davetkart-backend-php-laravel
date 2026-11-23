<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Kullanıcı taslak tavanında (K192, FE-50) -> 403 DRAFT_LIMIT_REACHED. `limit` kullanıcının
 * KENDİ sınırı (ürün sabiti, FILE_TOO_LARGE'in `max`'ı gibi); sızıntı değil.
 */
final class DraftLimitReachedException extends RuntimeException implements HasErrorCode
{
    public function __construct(private readonly int $limit)
    {
        parent::__construct("The user already has {$limit} unpublished invitations.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DraftLimitReached;
    }

    /** @return array<string, mixed> */
    public function errorParams(): array
    {
        return ['limit' => $this->limit];
    }
}
