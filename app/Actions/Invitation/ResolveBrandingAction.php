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
        return $this->forMany([$invitation])[$invitation->getKey()];
    }

    /**
     * Aynı karar, sahibin listesindeki bütün davetiyeler için tek sorguda (10.66b).
     *
     * @param  iterable<Invitation>  $invitations
     *
     * @return array<string, bool> davetiye kimliği => imza gösterilsin mi
     */
    public function forMany(iterable $invitations): array
    {
        $whiteLabel = SubscriptionTier::from(Config::string('davetkart.branding.white_label_tier'));

        return array_map(
            static fn (?SubscriptionTier $owned): bool => $owned === null || ! $owned->covers($whiteLabel),
            $this->entitlements->highestTiersFor($invitations),
        );
    }
}
