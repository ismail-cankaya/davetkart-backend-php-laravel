<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Pricing\PriceQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * Hediye kodu önizlemesinin istemciye açık hali (Faz 11).
 *
 * 🔴 Beyaz liste (C1): kodun kimliği, ipucu, kalan kullanım sayısı, bitiş
 * tarihi GİTMEZ. Kalan sayı kampanyanın büyüklüğünü ele verirdi.
 * Para alanları kuruş (int), adları `Minor` ile biter (amount_minor kuralı).
 *
 * Faz 13: PriceQuoteResource'un alanları + kodun kendi alanları. Faz 11'in
 * sözleşmesi korunur: `priceMinor − discountMinor = totalMinor` (artık
 * discountMinor TOPLAM indirim: otomatik + kod). Kodun payı `giftDiscountMinor`.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Resources/GiftCodeQuoteResource.md
 *
 * @property PriceQuote $resource
 */
final class GiftCodeQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        $giftCode = $quote->giftCode ?? throw new LogicException('Gift code preview needs a gift code quote.');

        return [
            'benefit' => $giftCode->giftCode->benefit->value,
            ...(new PriceQuoteResource($quote))->toArray($request),
            'giftDiscountMinor' => $quote->giftDiscountMinor(),
            'currency' => Config::string('davetkart.currency'),
        ];
    }
}
