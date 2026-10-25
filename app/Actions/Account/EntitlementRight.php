<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\EntitlementState;
use App\Enums\RefundBlocker;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * Hak listesinin bir satırı: sipariş + nerede olduğu + iade edilebilirliği (Faz 14, 14.3).
 *
 * `order` ilişkileri (invitation withTrashed, openRefundRequest) YÜKLÜ gelir.
 */
final readonly class EntitlementRight
{
    public function __construct(
        public Order $order,
        public EntitlementState $state,
        public ?RefundBlocker $refundBlocker,
        public ?CarbonImmutable $refundWindowEndsAt,
    ) {}

    public function isRefundable(): bool
    {
        return $this->refundBlocker === null;
    }
}
