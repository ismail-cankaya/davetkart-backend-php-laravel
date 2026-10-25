<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Actions\Pricing\PriceQuote;
use App\Actions\Pricing\QuotePriceAction;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionTier;
use App\Exceptions\GiftCodeException;
use App\Exceptions\PaymentMethodException;
use App\Models\GiftCode;
use App\Models\User;
use App\Services\Payment\PaymentMethods;
use App\Support\GiftCodes\GiftCodeFormat;

/**
 * Kodun bu plan için ne kazandıracağını söyler; hiçbir şey ayırmaz (Faz 11).
 *
 * Salt okunur: kilit yok, transaction yok, satır yazılmaz. Kurallar rezervasyonla
 * aynı yerden gelir (QuoteGiftCodeAction). Önizleme "geçerli" deyip checkout
 * "geçersiz" diyebilir (arada başkası son hakkı almış olabilir); karar checkout'ta.
 *
 * Faz 13: tam fiyat dökümü döner (PriceQuote) — kod, otomatik indirimden sonra
 * kalan fiyata uygulanır (K133). Kod yöntemi kapalıysa 403 (K134).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/PreviewGiftCodeAction.md
 */
final class PreviewGiftCodeAction
{
    public function __construct(
        private readonly QuoteGiftCodeAction $quote,
        private readonly QuotePriceAction $quotePrice,
        private readonly PaymentMethods $methods,
    ) {}

    /**
     * @throws GiftCodeException
     * @throws PaymentMethodException Hediye kodu yöntemi kapalı (403)
     */
    public function handle(User $user, string $code, SubscriptionTier $tier): PriceQuote
    {
        $this->methods->ensureEnabled(PaymentMethod::GiftCode);

        $price = $this->quotePrice->handle($user, $tier);

        $giftCode = GiftCode::query()
            ->where('code_hash', GiftCodeFormat::hash($code))
            ->first();

        return $price->withGiftCode(
            $this->quote->handle($giftCode, $user, $tier, $price->afterPromotionMinor()),
        );
    }
}
