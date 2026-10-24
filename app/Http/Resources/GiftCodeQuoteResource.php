<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\GiftCode\GiftCodeQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Config;

/**
 * Hediye kodu önizlemesinin istemciye açık hali (Faz 11).
 *
 * 🔴 Beyaz liste (C1): kodun kimliği, ipucu, kalan kullanım sayısı, bitiş
 * tarihi GİTMEZ. Kalan sayı kampanyanın büyüklüğünü ele verirdi.
 * Para alanları kuruş (int), adları `Minor` ile biter (amount_minor kuralı).
 * Ayrıntılı açıklama: docs/rehber/app/Http/Resources/GiftCodeQuoteResource.md
 *
 * @property GiftCodeQuote $resource
 */
final class GiftCodeQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            'benefit' => $quote->giftCode->benefit->value,
            'tier' => $quote->tier->value,
            'priceMinor' => $quote->priceMinor,
            'discountMinor' => $quote->discountMinor,
            'totalMinor' => $quote->totalMinor(),
            'currency' => Config::string('davetkart.currency'),
        ];
    }
}
