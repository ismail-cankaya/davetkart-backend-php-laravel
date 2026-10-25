<?php

declare(strict_types=1);

namespace App\Actions\Refund;

use App\Enums\RefundBlocker;
use App\Enums\RefundRequestStatus;
use App\Exceptions\RefundNotAllowedException;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\Refund\RefundEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Kullanıcı kullanılmamış bir hakkı iptal eder ve iade ister (Faz 14, 14.5 · K138, K139).
 *
 * Para burada GERİ GÖNDERİLMEZ. Talep açılır, hak DONDURULUR (yayında
 * kullanılamaz); yönetici parayı Shopier'den gönderip `refunds:approve` ile
 * siparişi `refunded` yapar.
 *
 * 🔴 Sipariş satırı KİLİTLENİR. ClaimReleasedOrderAction da aynı satırı
 * kilitliyor: "iptal et" ile "yayınla" aynı anda gelirse biri diğerini bekler.
 * Kilitsiz: iptal "bağsız" okur, yayın aynı anda bağlar, kullanıcı hem yayın
 * hem iade alırdı.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Refund/RequestRefundAction.md
 */
final class RequestRefundAction
{
    public function __construct(private readonly RefundEligibility $eligibility) {}

    /**
     * @throws RefundNotAllowedException Hak iptal edilemez → 409
     */
    public function handle(Order $order, ?string $reason): RefundRequest
    {
        try {
            return DB::transaction(function () use ($order, $reason): RefundRequest {
                $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $fresh->load('openRefundRequest');

                $blocker = $this->eligibility->blockerFor($fresh);

                if ($blocker !== null) {
                    throw new RefundNotAllowedException($blocker);
                }

                // #[Fillable] boş: alanlar açıkça atanır (E7).
                $request = new RefundRequest;
                $request->order_id = $fresh->id;
                $request->user_id = $fresh->user_id;
                $request->status = RefundRequestStatus::Requested;
                $request->reason = $reason;
                $request->save();

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // Kilit aynı siparişi sıraya sokar; bu kol yalnızca savunma
            // (kısmi UNIQUE indeks son kalkan). Kullanıcıya anlamı aynı.
            throw new RefundNotAllowedException(RefundBlocker::AlreadyRequested);
        }
    }
}
