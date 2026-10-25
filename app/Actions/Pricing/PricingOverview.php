<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Enums\PaymentMethod;
use App\Services\Payment\BankTransferAccount;

/**
 * Fiyat uçlarının yanıtı: planlar + açık ödeme yöntemleri (Faz 13). Saf veri.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Pricing/ShowPricingAction.md
 */
final readonly class PricingOverview
{
    /**
     * @param  list<PriceQuote>  $plans  rank sırasında
     * @param  list<PaymentMethod>  $paymentMethods
     * @param  bool  $personalized  true = kullanıcıya özel (ilk yayın indirimi hesaba katıldı)
     */
    public function __construct(
        public array $plans,
        public array $paymentMethods,
        public ?BankTransferAccount $bankTransfer,
        public int $firstPublishPercent,
        public bool $personalized,
    ) {}
}
