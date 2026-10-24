<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Enums\SubscriptionTier;
use App\Exceptions\GiftCodeException;
use App\Models\GiftCode;
use App\Models\User;
use App\Support\GiftCodes\GiftCodeFormat;

/**
 * Kodun bu plan için ne kazandıracağını söyler; hiçbir şey ayırmaz (Faz 11).
 *
 * Salt okunur: kilit yok, transaction yok, satır yazılmaz. Kurallar rezervasyonla
 * aynı yerden gelir (QuoteGiftCodeAction). Önizleme "geçerli" deyip checkout
 * "geçersiz" diyebilir (arada başkası son hakkı almış olabilir); karar checkout'ta.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/PreviewGiftCodeAction.md
 */
final class PreviewGiftCodeAction
{
    public function __construct(
        private readonly QuoteGiftCodeAction $quote,
    ) {}

    /** @throws GiftCodeException */
    public function handle(User $user, string $code, SubscriptionTier $tier): GiftCodeQuote
    {
        $giftCode = GiftCode::query()
            ->where('code_hash', GiftCodeFormat::hash($code))
            ->first();

        return $this->quote->handle($giftCode, $user, $tier);
    }
}
