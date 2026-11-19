<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Refund\ResolveRefundRequestAction;
use App\Console\Commands\Concerns\ResolvesRefundRequests;
use App\Enums\OrderStatus;
use App\Enums\RefundBlocker;
use App\Enums\RefundRequestStatus;
use App\Models\RefundRequest;
use App\Services\Refund\RefundEligibility;
use Illuminate\Console\Command;

/**
 * Bir iade talebini onaylar: sipariş `refunded` olur (Faz 14, 14.6 · K141).
 *
 *   php artisan refunds:approve 01J… [--note="..."]
 *
 * 🔴 Onay, PARANIN GÖNDERİLDİĞİNİ varsayar: komut önce sorar. Etkileşimsiz
 * çalıştırmada (--no-interaction) soru "hayır" sayılır; bir betikten
 * yanlışlıkla onay geçmesin.
 *
 * Faz 18.4 (K167): hak kullanılmışsa komut soruyu SORMADAN durur.
 * Ayrıntılı açıklama: docs/rehber/app/Console/Commands/RefundCommands.md
 */
final class ApproveRefundRequest extends Command
{
    use ResolvesRefundRequests;

    protected $signature = 'refunds:approve
                            {id : Talep kimliği (refunds:list)}
                            {--note= : Kullanıcıya mailde gidecek not}';

    protected $description = 'Iade talebini onaylar: siparis refunded olur (once Shopier panelinden iade edin)';

    public function handle(ResolveRefundRequestAction $action, RefundEligibility $eligibility): int
    {
        // 🔴 Faz 18.4 (SEC-02 · K167): para sorusundan ÖNCE. Kullanılmış hakkın
        // parası gönderilmeden durulsun. Bu bir ön denetim; kesin karar Action'da,
        // kilit altında yeniden verilir.
        $blocker = $this->usedRightBlocker($eligibility);

        if ($blocker !== null) {
            $this->error("Bu hak kullanılmış ({$blocker->value}): talep onaylanamaz. Parayı GÖNDERMEYİN; refunds:reject ile kapatın.");

            return self::FAILURE;
        }

        if (! $this->confirm('Parayı Shopier panelinden geri gönderdiniz mi?', false)) {
            $this->warn('Vazgeçildi. Önce Shopier panelinden iade edin.');

            return self::FAILURE;
        }

        return $this->resolve($action, RefundRequestStatus::Approved);
    }

    /**
     * Açık talebin hakkı kullanılmış mı? null = engel yok ya da karar Action'ın.
     *
     * Talep yoksa, kapalıysa ya da sipariş zaten `refunded` ise burada durulmaz:
     * o yolların mesajını ve davranışını Action belirler.
     */
    private function usedRightBlocker(RefundEligibility $eligibility): ?RefundBlocker
    {
        $id = $this->argument('id');
        $request = is_string($id) ? RefundRequest::query()->with('order')->find($id) : null;
        $order = $request?->order;

        if ($request === null || $order === null || ! $request->status->isOpen() || $order->status === OrderStatus::Refunded) {
            return null;
        }

        return $eligibility->usageBlockerFor($order);
    }
}
