<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Refund\RequestRefundAction;
use App\Actions\Refund\WithdrawRefundRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Refund\StoreRefundRequestRequest;
use App\Http\Resources\RefundRequestResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Hakkı iptal et / vazgeç (Faz 14, 14.5 · K138). Yalnızca yönlendirir (K3).
 *
 * Sahiplik: OrderPolicy::view — başkasının siparişi 404 (H7).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Refund/RequestRefundAction.md
 */
final class RefundRequestController extends Controller
{
    public function store(StoreRefundRequestRequest $request, Order $order, RequestRefundAction $action): JsonResponse
    {
        Gate::authorize('view', $order);

        return (new RefundRequestResource($action->handle($order, $request->reason())))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function destroy(Order $order, WithdrawRefundRequestAction $action): Response
    {
        Gate::authorize('view', $order);

        $action->handle($order);

        return response()->noContent();
    }
}
