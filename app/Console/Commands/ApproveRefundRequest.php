<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Refund\ResolveRefundRequestAction;
use App\Console\Commands\Concerns\ResolvesRefundRequests;
use App\Enums\RefundRequestStatus;
use Illuminate\Console\Command;

/**
 * Bir iade talebini onaylar: sipariş `refunded` olur (Faz 14, 14.6 · K141).
 *
 *   php artisan refunds:approve 01J… [--note="..."]
 *
 * 🔴 Onay, PARANIN GÖNDERİLDİĞİNİ varsayar: komut önce sorar. Etkileşimsiz
 * çalıştırmada (--no-interaction) soru "hayır" sayılır; bir betikten
 * yanlışlıkla onay geçmesin.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/RefundCommands.md
 */
final class ApproveRefundRequest extends Command
{
    use ResolvesRefundRequests;

    protected $signature = 'refunds:approve
                            {id : Talep kimliği (refunds:list)}
                            {--note= : Kullanıcıya mailde gidecek not}';

    protected $description = 'Iade talebini onaylar: siparis refunded olur (once Shopier panelinden iade edin)';

    public function handle(ResolveRefundRequestAction $action): int
    {
        if (! $this->confirm('Parayı Shopier panelinden geri gönderdiniz mi?', false)) {
            $this->warn('Vazgeçildi. Önce Shopier panelinden iade edin.');

            return self::FAILURE;
        }

        return $this->resolve($action, RefundRequestStatus::Approved);
    }
}
