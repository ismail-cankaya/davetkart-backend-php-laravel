<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\EntitlementState;
use App\Enums\SubscriptionTier;

/**
 * "Planlarım" ekranının tamamı: plan başına sayılar + satır satır haklar (Faz 14, 14.3).
 */
final readonly class EntitlementSummary
{
    /**
     * @param  list<TierEntitlements>  $tiers  Üç plan da, rank sırasıyla, sıfır olsa bile
     * @param  list<EntitlementRight>  $rights  En yeni sipariş üstte
     */
    public function __construct(
        public array $tiers,
        public array $rights,
    ) {}

    public function forTier(SubscriptionTier $tier): TierEntitlements
    {
        foreach ($this->tiers as $entry) {
            if ($entry->tier === $tier) {
                return $entry;
            }
        }

        return new TierEntitlements($tier, []);
    }

    /** Bütün planlarda kullanılabilir hak sayısı — hesap silme uyarısı, panel rozeti. */
    public function totalAvailable(): int
    {
        return array_sum(array_map(
            static fn (TierEntitlements $tier): int => $tier->count(EntitlementState::Available),
            $this->tiers,
        ));
    }
}
