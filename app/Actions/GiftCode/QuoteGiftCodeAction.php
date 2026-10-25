<?php

declare(strict_types=1);

namespace App\Actions\GiftCode;

use App\Enums\SubscriptionTier;
use App\Exceptions\GiftCodeException;
use App\Models\GiftCode;
use App\Models\GiftCodeRedemption;
use App\Models\User;

/**
 * Bulunmuş bir kodun bu kullanıcı ve bu plan için kurallarını uygular (Faz 11).
 *
 * Kuralların TEK yeri: rezervasyon (kilitli satırla) da önizleme (kilitsiz)
 * de buradan geçer; iki kopya ayrışamaz (C3). Satırı bulmak ve kilitlemek
 * çağıranın işi.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/GiftCode/ReserveGiftCodeAction.md §3
 */
final class QuoteGiftCodeAction
{
    /**
     * @param  GiftCode|null  $giftCode  null = bu özetle kod yok
     * @param  int|null  $priceMinor  Kodun uygulanacağı taban (Faz 13: otomatik
     *                                indirimden sonraki fiyat); null = liste fiyatı
     *
     * @throws GiftCodeException
     */
    public function handle(?GiftCode $giftCode, User $user, SubscriptionTier $tier, ?int $priceMinor = null): GiftCodeQuote
    {
        // 1. Yok, kapalı, süresi dolmuş: hepsi aynı yanıt (D7).
        if ($giftCode === null || $giftCode->isDisabled() || $giftCode->isExpired()) {
            throw GiftCodeException::invalid();
        }

        // 2. Kişi başı (D5). Tükenmeden ÖNCE: kodu kendisi kullanmış kişiye
        //    "geçersiz" demek yanlış olurdu; o kodun varlığını zaten biliyor.
        $usedByUser = GiftCodeRedemption::query()
            ->active()
            ->where('gift_code_id', $giftCode->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($usedByUser) {
            throw GiftCodeException::alreadyUsed();
        }

        // 3. Tükenmiş mi (D4: sayım sipariş durumundan türetilir).
        $active = GiftCodeRedemption::query()
            ->active()
            ->where('gift_code_id', $giftCode->id)
            ->count();

        if ($active >= $giftCode->max_redemptions) {
            throw GiftCodeException::invalid();
        }

        // 4. Plan. En sonda: tükenmiş bir kodun planını ele vermesin.
        if (! $giftCode->isApplicableTo($tier)) {
            /** @var SubscriptionTier $codeTier isApplicableTo() false ise plan doludur */
            $codeTier = $giftCode->tier;

            throw GiftCodeException::notApplicable($codeTier);
        }

        $base = $priceMinor ?? $tier->price() * 100;

        return new GiftCodeQuote(
            giftCode: $giftCode,
            tier: $tier,
            priceMinor: $base,
            discountMinor: $giftCode->discountFor($tier, $base),
        );
    }
}
