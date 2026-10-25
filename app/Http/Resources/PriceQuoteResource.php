<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Pricing\PriceQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bir planın fiyat dökümü — types.ts → PlanPrice (Faz 13, K128).
 *
 *   priceMinor     liste fiyatı (kuruş)
 *   promotion      {kind, percent, discountMinor, endsAt} | null
 *   discountMinor  toplam indirim
 *   totalMinor     ödenecek
 *
 * 🔴 Frontend HİÇBİR sayıyı hesaplamaz: üstü çizili fiyat `priceMinor`, büyük
 * yazılan fiyat `totalMinor`. İkisi de buradan, aynı hesaptan (QuotePriceAction).
 * `promotion` HER ZAMAN var (null olabilir): her fiyatın bir alanı, bağlama
 * göre var olan bir alan değil (C7'nin ayrımı, OrderResource'taki paidAt gibi).
 * Kampanya kimliği ve notu GİTMEZ (C1): iç bilgi.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Resources/PriceQuoteResource.md
 *
 * @property PriceQuote $resource
 */
final class PriceQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        $promotion = $quote->promotion;

        return [
            'tier' => $quote->tier->value,
            'priceMinor' => $quote->listMinor,
            'promotion' => $promotion === null ? null : [
                'kind' => $promotion->kind->value,
                'percent' => $promotion->percent,
                'discountMinor' => $quote->promotionDiscountMinor(),
                'endsAt' => $promotion->endsAt()?->toIso8601String(),
            ],
            'discountMinor' => $quote->discountMinor(),
            'totalMinor' => $quote->totalMinor(),
        ];
    }
}
