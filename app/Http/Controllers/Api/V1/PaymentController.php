<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payment\CheckoutResult;
use App\Actions\Payment\StartCheckoutAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StoreCheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Odeme baslatma uclari — yalnizca YONLENDIRIR; is kurali Action'da (K3).
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PaymentController.md §A-E5-17
 */
final class PaymentController extends Controller
{
    /**
     * TEKIL alim: bu davetiye icin plan satin al.
     * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PaymentController.md §A-E5-31
     */
    public function forInvitation(
        StoreCheckoutRequest $request,
        Invitation $invitation,
        StartCheckoutAction $action,
    ): JsonResponse {
        Gate::authorize('publish', $invitation);

        /** @var User $user auth:sanctum burada null OLAMAYACAGINI garanti eder. */
        $user = $request->user();

        return $this->respond($action->handle($user, $invitation, $request->tier(), $request->giftCode(), $request->phone()));
    }

    /** PAKET alim: hesabin tamami icin plan satin al (K42). */
    public function forAccount(StoreCheckoutRequest $request, StartCheckoutAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($action->handle($user, null, $request->tier(), $request->giftCode(), $request->phone()));
    }

    /** 201: yeni bir siparis KAYNAGI olustu (odeme henuz tamamlanmadi). */
    private function respond(CheckoutResult $result): JsonResponse
    {
        return (new OrderResource($result->order))
            ->withCheckout($result->action)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
