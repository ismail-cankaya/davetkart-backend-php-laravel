<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Refund\ResolveRefundRequestAction;
use App\Console\Commands\Concerns\ResolvesRefundRequests;
use App\Enums\RefundRequestStatus;
use Illuminate\Console\Command;

/**
 * Bir iade talebini reddeder; hak kullanıcıya geri döner (Faz 14, 14.6).
 *
 *   php artisan refunds:reject 01J… --note="Gerekçe"
 *
 * Para hareketi yok, soru sorulmaz.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/RefundCommands.md
 */
final class RejectRefundRequest extends Command
{
    use ResolvesRefundRequests;

    protected $signature = 'refunds:reject
                            {id : Talep kimliği (refunds:list)}
                            {--note= : Ret gerekçesi (kullanıcıya mailde gider)}';

    protected $description = 'Iade talebini reddeder: hak kullaniciya geri doner';

    public function handle(ResolveRefundRequestAction $action): int
    {
        return $this->resolve($action, RefundRequestStatus::Rejected);
    }
}
