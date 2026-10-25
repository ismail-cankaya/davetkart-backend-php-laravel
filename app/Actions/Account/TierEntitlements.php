<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\EntitlementState;
use App\Enums\SubscriptionTier;

/**
 * Tek bir planın kova sayıları (Faz 14, 14.3). Saf veri, değişmez.
 */
final readonly class TierEntitlements
{
    /**
     * @param  array<string, int>  $counts  EntitlementState değeri -> adet; her kova HER ZAMAN var
     */
    public function __construct(
        public SubscriptionTier $tier,
        private array $counts,
    ) {}

    public function count(EntitlementState $state): int
    {
        return $this->counts[$state->value] ?? 0;
    }
}
