<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionTier;
use RuntimeException;

/**
 * Hediye kodu kullanılamadı (Faz 11). Üç kod, hepsi 422 (D7).
 *
 * 🔴 invalid() ARGÜMAN ALMAZ: yok/kapalı/süresi dolmuş/tükenmiş için yanıt
 * (debug mesajı dahil) bayt bayt aynı olsun diye. Sebep bir parametreyle
 * taşınsaydı yerel `debug` bloğu bile hangisi olduğunu ele verirdi.
 * Ayrıntılı açıklama: docs/rehber/app/Exceptions/GiftCodeException.md
 */
final class GiftCodeException extends RuntimeException implements HasErrorCode
{
    private function __construct(
        private readonly ErrorCode $errorCode,
        private readonly ?SubscriptionTier $tier,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Kod yok, kapalı, süresi dolmuş ya da tükenmiş: dışarıdan ayırt edilemez. */
    public static function invalid(): self
    {
        return new self(ErrorCode::GiftCodeInvalid, null, 'Gift code is not valid.');
    }

    /** Bu kullanıcının bu kodla aktif bir kullanımı var (D5). */
    public static function alreadyUsed(): self
    {
        return new self(ErrorCode::GiftCodeAlreadyUsed, null, 'Gift code was already used by this user.');
    }

    /** Kod başka bir plan için. */
    public static function notApplicable(SubscriptionTier $codeTier): self
    {
        return new self(
            ErrorCode::GiftCodeNotApplicable,
            $codeTier,
            "Gift code applies only to the '{$codeTier->value}' tier.",
        );
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * Beyaz listeden geçer (H9); yalnızca NOT_APPLICABLE 'tier' taşır.
     *
     * @return array<string, mixed>
     */
    public function errorParams(): array
    {
        return $this->tier === null ? [] : ['tier' => $this->tier->value];
    }
}
