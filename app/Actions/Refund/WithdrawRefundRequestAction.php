<?php

declare(strict_types=1);

namespace App\Actions\Refund;

use App\Enums\RefundRequestStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Kullanıcı açık iade talebinden vazgeçer; hak yeniden kullanılabilir olur (Faz 14, 14.5).
 *
 * Talep satırı KİLİTLENİR: yönetici aynı anda `refunds:approve` çalıştırırsa
 * ikisinden biri, diğerinin kapattığı talebi "açık" sanmaz.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Refund/RequestRefundAction.md §5
 */
final class WithdrawRefundRequestAction
{
    /**
     * @throws ModelNotFoundException Açık talep yok → 404
     */
    public function handle(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $request = RefundRequest::query()
                ->where('order_id', $order->getKey())
                ->open()
                ->lockForUpdate()
                ->firstOrFail();

            $request->status = RefundRequestStatus::Withdrawn;
            $request->resolved_at = now();
            $request->save();
        });
    }
}
