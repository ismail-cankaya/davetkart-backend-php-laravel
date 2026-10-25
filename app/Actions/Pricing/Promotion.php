<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\PromotionKind;
use App\Models\PriceCampaign;
use Carbon\CarbonImmutable;

/**
 * Bir plana uygulanan otomatik indirim (Faz 13, K133). Saf veri.
 *
 * `campaign` yalnızca kind = Campaign iken dolu; `endsAt` müşteriye "şu
 * tarihe kadar" demek için (ilk yayın indiriminin bitişi yok).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/QuotePriceAction.md §3
 */
final readonly class Promotion
{
    private function __construct(
        public PromotionKind $kind,
        public int $percent,
        public ?PriceCampaign $campaign,
    ) {}

    public static function firstPublish(int $percent): self
    {
        return new self(PromotionKind::FirstPublish, $percent, null);
    }

    public static function campaign(PriceCampaign $campaign): self
    {
        return new self(PromotionKind::Campaign, $campaign->percent, $campaign);
    }

    public function endsAt(): ?CarbonImmutable
    {
        return $this->campaign?->ends_at;
    }

    /**
     * Bu fiyattan düşülecek tutar (kuruş).
     *
     * 🔴 Tam sayı aritmetiği, aşağı yuvarlama: kuruş farkı işletmenin lehine
     * (GiftCode::discountFor ile aynı kural). Float yok.
     */
    public function discountFor(int $priceMinor): int
    {
        return intdiv($priceMinor * $this->percent, 100);
    }
}
