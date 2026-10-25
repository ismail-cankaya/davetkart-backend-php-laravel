<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\PriceCampaign;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Bir kampanyayı hemen durdurur (Faz 13). Satır SİLİNMEZ (muhasebe izi).
 *
 * İdempotent: zaten durmuş bir kampanyayı tekrar durdurmak ilk durdurma
 * anını değiştirmez (DisableGiftCodeAction ile aynı kalıp).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/StartPriceCampaignAction.md §4
 */
final class StopPriceCampaignAction
{
    /** @throws InvalidArgumentException Kimlik yok */
    public function handle(string $id): PriceCampaign
    {
        $campaign = PriceCampaign::query()->find(strtolower($id))
            ?? throw new InvalidArgumentException('Bu kimlikle bir kampanya yok (pricing:campaign:list).');

        if ($campaign->stopped_at === null) {
            $campaign->stopped_at = now()->toImmutable();
            $campaign->save();

            Log::info('Price campaign stopped', ['price_campaign_id' => $campaign->id]);
        }

        return $campaign;
    }
}
