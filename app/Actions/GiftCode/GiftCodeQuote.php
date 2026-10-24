<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Enums\SubscriptionTier;
use App\Models\GiftCode;

/**
 * Bir kodun bir plan için hesaplanmış karşılığı (Faz 11). Saf veri, yan etkisiz.
 *
 * Tutarlar kuruş (int). `total = price − discount` ve hiçbir zaman eksi değil (D6).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/ReserveGiftCodeAction.md §5
 */
final readonly class GiftCodeQuote
{
    public function __construct(
        public GiftCode $giftCode,
        public SubscriptionTier $tier,
        public int $priceMinor,
        public int $discountMinor,
    ) {}

    /** Ödenecek tutar; 0 ise sipariş hediye yolundan gider (D1). */
    public function totalMinor(): int
    {
        return $this->priceMinor - $this->discountMinor;
    }

    public function coversEverything(): bool
    {
        return $this->totalMinor() === 0;
    }
}
