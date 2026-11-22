<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * Medya deposu bu serviste kullanılamıyor (yapılandırma hatası) -> 503
 * PROVIDER_UNAVAILABLE; PaymentProviderException::unavailable() ile aynı sözleşme (K123/K183).
 * Ayrintili aciklama: docs/rehber/app/Support/MediaDiskGuard.md
 */
final class MediaStorageUnavailableException extends RuntimeException implements HasErrorCode
{
    private const int RETRY_AFTER_SECONDS = 60;

    public static function wrongDisk(string $disk): self
    {
        return new self("Media disk '{$disk}' is not allowed in production.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ProviderUnavailable;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return ['retryAfter' => self::RETRY_AFTER_SECONDS];
    }
}
