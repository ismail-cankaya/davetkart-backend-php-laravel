<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Contracts\PublishEntitlementResolver;
use App\Enums\SubscriptionTier;
use App\Models\Invitation;
use Illuminate\Support\Facades\Config;

/**
 * Misafir sayfası "DavetKart ile hazırlandı" imzasını gösterir mi? (Faz 10, 10.66 · K102)
 *
 * Fiyat kartı Elit'e "Logosuz özel yayın" vaat ediyor. Davetiyeye bağlı
 * ödenmiş sipariş o planı karşılıyorsa imza gösterilmez.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Invitation/ResolveBrandingAction.md
 */
final class ResolveBrandingAction
{
    public function __construct(
        private readonly PublishEntitlementResolver $entitlements,
    ) {}

    /** İmza gösterilecekse true. */
    public function handle(Invitation $invitation): bool
    {
        $whiteLabel = SubscriptionTier::from(Config::string('davetkart.branding.white_label_tier'));
        $owned = $this->entitlements->highestTierFor($invitation);

        return $owned === null || ! $owned->covers($whiteLabel);
    }
}
