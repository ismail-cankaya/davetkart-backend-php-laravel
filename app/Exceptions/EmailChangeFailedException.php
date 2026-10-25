<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * E-posta değişikliği onaylanamadı (Faz 14, K144) → 422 `EMAIL_CHANGE_INVALID`.
 *
 * 🔴 SEBEBİ İSTEMCİYE SÖYLENMEZ (H6): "adres başka hesapta" ile "süresi
 * dolmuş" aynı yanıtı alır. Mesaj yalnızca log için.
 * PasswordResetFailedException'ın kardeşi.
 */
final class EmailChangeFailedException extends RuntimeException implements HasErrorCode
{
    public static function because(string $reason): self
    {
        return new self("Email change rejected: {$reason}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::EmailChangeInvalid;
    }

    /**
     * 🔴 BOŞ ve öyle kalmalı (H6).
     *
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return [];
    }
}
