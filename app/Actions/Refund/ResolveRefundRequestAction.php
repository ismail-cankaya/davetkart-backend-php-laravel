<?php

declare(strict_types=1);

namespace App\Actions\Refund;

use App\Enums\OrderStatus;
use App\Enums\RefundRequestStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Notifications\RefundRequestResolvedNotification;
use App\Services\Refund\RefundEligibility;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Yönetici iade talebini kapatır: onay ya da ret (Faz 14, 14.6 · K141).
 *
 * Onay: talep `approved`, sipariş `paid -> refunded` (durum makinesi izin
 * verirse). Para BU KODDA gönderilmez: yönetici önce Shopier panelinden iade
 * eder, sonra bu komutu çalıştırır. Sıra ters olursa kayıt "iade edildi" der
 * ama para gitmemiş olur.
 *
 * İki satır da KİLİTLENİR: kullanıcı aynı anda "Vazgeç" derse ya da Shopier'in
 * iade bildirimi aynı anda gelirse, biri diğerini bekler.
 *
 * Faz 18.4 (SEC-02 · K167): onay, hakkın HÂLÂ kullanılmamış olduğunu kilit
 * altında yeniden sınar; kullanılmışsa onaylamaz ve sebebini söyler.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Refund/ResolveRefundRequestAction.md
 */
final class ResolveRefundRequestAction
{
    public function __construct(private readonly RefundEligibility $eligibility) {}

    /**
     * @throws RuntimeException Talep yok, zaten kapanmış ya da hak kullanılmış (yalnızca onayda)
     */
    public function handle(string $requestId, RefundRequestStatus $decision, ?string $note): RefundRequest
    {
        if (! in_array($decision, [RefundRequestStatus::Approved, RefundRequestStatus::Rejected], true)) {
            throw new InvalidArgumentException("A refund request is resolved as approved or rejected, not {$decision->value}.");
        }

        [$resolved, $order] = DB::transaction(function () use ($requestId, $decision, $note): array {
            $request = RefundRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null) {
                throw new RuntimeException("Refund request {$requestId} not found.");
            }

            if (! $request->status->canTransitionTo($decision)) {
                throw new RuntimeException("Refund request {$requestId} is already {$request->status->value}.");
            }

            // Sipariş her iki kararda da kilitlenir: onayda değişir, retde
            // mailin tutarı ondan okunur.
            $order = Order::query()->whereKey($request->order_id)->lockForUpdate()->firstOrFail();

            if ($decision === RefundRequestStatus::Approved) {
                $this->ensureRightIsStillUnused($request, $order);
                $this->refundOrder($order);
            }

            $request->status = $decision;
            $request->admin_note = $note;
            $request->resolved_at = now();
            $request->save();

            return [$request, $order];
        });

        // Commit'ten SONRA: geri alınan bir kararın maili gitmesin.
        $resolved->load('user');
        $resolved->user?->notify(new RefundRequestResolvedNotification(
            $decision,
            $order->tier,
            $order->amount_minor,
            $note,
        ));

        return $resolved;
    }

    /**
     * 🔴 Faz 18.4 (SEC-02 · K167): "hak kullanıldı mı" ONAY ANINDA, sipariş satırı
     * kilitliyken yeniden sorulur (TOCTOU). Talep anındaki cevap onaya kadar bayatlar.
     *
     * @throws RuntimeException Hak kullanılmış: onay yok, talep açık kalır
     */
    private function ensureRightIsStillUnused(RefundRequest $request, Order $order): void
    {
        // Para zaten geri gitmiş (Shopier bildirimi önce geldi): korunacak bir şey yok.
        if ($order->status === OrderStatus::Refunded) {
            return;
        }

        $blocker = $this->eligibility->usageBlockerFor($order);

        if ($blocker !== null) {
            throw new RuntimeException(
                "Refund request {$request->id} cannot be approved: the right has been used ({$blocker->value}). "
                .'Do not send the money; close the request with refunds:reject.',
            );
        }
    }

    private function refundOrder(Order $order): void
    {
        // Shopier'in iade bildirimi önce gelmiş olabilir: sipariş zaten refunded.
        if ($order->status === OrderStatus::Refunded) {
            return;
        }

        if (! $order->status->canTransitionTo(OrderStatus::Refunded)) {
            throw new LogicException("Order {$order->id} cannot be refunded from {$order->status->value}.");
        }

        $order->status = OrderStatus::Refunded;
        $order->save();
    }
}
