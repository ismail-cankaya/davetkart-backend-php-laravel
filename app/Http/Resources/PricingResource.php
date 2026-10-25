<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Pricing\PriceQuote;
use App\Actions\Pricing\PricingOverview;
use App\Enums\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;

/**
 * Fiyat kataloğunun istemciye açık hâli — types.ts → PricingCatalog (Faz 13).
 *
 * ```json
 * { "currency": "TRY", "personalized": true, "firstPublishPercent": 60,
 *   "plans": [ PriceQuoteResource, … ],
 *   "paymentMethods": ["gift_code"],
 *   "bankTransfer": null }
 * ```
 *
 * `bankTransfer` HER ZAMAN var; havale kapalıyken null (C7: her katalogun
 * alanı). IBAN bir sır değil — ödeme yapan her müşteriye zaten gösteriliyor —
 * ama yalnızca yöntem açıkken gider (K135).
 * Ayrıntılı açıklama: docs/rehber/app/Http/Resources/PricingResource.md
 *
 * @property PricingOverview $resource
 */
final class PricingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overview = $this->resource;
        $account = $overview->bankTransfer;

        return [
            'currency' => Config::string('davetkart.currency'),
            'personalized' => $overview->personalized,
            'firstPublishPercent' => $overview->firstPublishPercent,
            'plans' => array_map(
                static fn (PriceQuote $quote): array => (new PriceQuoteResource($quote))->toArray($request),
                $overview->plans,
            ),
            'paymentMethods' => array_map(
                static fn (PaymentMethod $method): string => $method->value,
                $overview->paymentMethods,
            ),
            'bankTransfer' => $account === null ? null : [
                'bankName' => $account->bankName,
                'accountHolder' => $account->accountHolder,
                'iban' => $account->iban,
            ],
        ];
    }
}
