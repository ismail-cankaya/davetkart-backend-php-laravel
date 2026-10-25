<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Enums\RefundBlocker;
use RuntimeException;

/**
 * Hak iptal edilip iade istenemez (Faz 14, K139) → 409 `REFUND_NOT_ALLOWED`.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Refund/RequestRefundAction.md
 */
final class RefundNotAllowedException extends RuntimeException implements HasErrorCode
{
    public function __construct(public readonly RefundBlocker $blocker)
    {
        parent::__construct("Refund not allowed: {$blocker->value}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RefundNotAllowed;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return ['reason' => $this->blocker->value];
    }
}
