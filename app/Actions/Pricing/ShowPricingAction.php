<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\PaymentMethod;
use App\Models\User;
use App\Services\Payment\PaymentMethods;

/**
 * Frontend'in fiyat kataloğu (Faz 13, K128): üç plan + açık yöntemler.
 *
 * 🔴 Frontend'de fiyat YOK: fiyat kartları, paywall, ödeme sayfası ve havale
 * paneli sayıları buradan okur. Misafir (`$user = null`) kampanya fiyatını
 * görür; oturum açmış kullanıcı ayrıca ilk yayın indirimini (K131).
 *
 * Havale hesabı yalnızca yöntem AÇIKKEN verilir (K135).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/ShowPricingAction.md
 */
final class ShowPricingAction
{
    public function __construct(
        private readonly QuotePriceAction $quotePrice,
        private readonly PaymentMethods $methods,
    ) {}

    public function handle(?User $user): PricingOverview
    {
        $methods = $this->methods->enabled();

        return new PricingOverview(
            plans: array_values($this->quotePrice->forTiers($user)),
            paymentMethods: $methods,
            bankTransfer: in_array(PaymentMethod::BankTransfer, $methods, true) ? $this->methods->bankAccount() : null,
            firstPublishPercent: QuotePriceAction::firstPublishPercent(),
            personalized: $user !== null,
        );
    }
}
