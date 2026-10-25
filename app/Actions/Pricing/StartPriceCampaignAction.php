<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\PriceCampaign;
use Illuminate\Support\Facades\Log;

/**
 * Bir fiyat kampanyası açar (Faz 13, K132): `pricing:campaign:start 20 ...`.
 *
 * Çakışma kontrolü YOK, bilerek: aynı plana birden çok kampanya uygunsa
 * QuotePriceAction büyük olanı seçer. "Gold'a %30, hepsine %10" birlikte
 * yaşayabilir.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/StartPriceCampaignAction.md
 */
final class StartPriceCampaignAction
{
    public function handle(PriceCampaignSpec $spec): PriceCampaign
    {
        // Alanlar açıkça atanır (#[Fillable] boş, E7).
        $campaign = new PriceCampaign;
        $campaign->percent = $spec->percent;
        $campaign->tier = $spec->tier;
        $campaign->starts_at = $spec->startsAt;
        $campaign->ends_at = $spec->endsAt;
        $campaign->label = $spec->label;
        $campaign->save();

        // Fiyat değişikliği bir iş olayıdır: kim ne zaman ne açtı, log'da dursun.
        Log::info('Price campaign started', [
            'price_campaign_id' => $campaign->id,
            'percent' => $campaign->percent,
            'tier' => $campaign->tier?->value,
            'starts_at' => $campaign->starts_at->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
        ]);

        return $campaign;
    }
}
