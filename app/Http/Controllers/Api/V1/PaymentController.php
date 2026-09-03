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
 *
 * Iki metot, K42'nin iki kolu:
 *   forInvitation() -> POST /api/invitations/{invitation}/checkout  (tekil)
 *   forAccount()    -> POST /api/payments/checkout                  (paket)
 *
 * Ayni Action'i cagiriyorlar; tek fark davetiyenin varligi. Iki AYRI Action
 * yazmak, aralarindaki ortak dort katmani (yeterlilik, fiyat, siparis,
 * telafi) kopyalamak olurdu (C3).
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PaymentController.md
 */
final class PaymentController extends Controller
{
    /**
     * TEKIL alim: bu davetiye icin plan satin al.
     *
     * Aidiyet URL'nin yapisinda (N1) ve rota baglamasi kaydi cozemezse zaten
     * 404 doner. Gate ise "senin mi" sorusunu soruyor; reddi de 404'e
     * cevriliyor (H7) — iki farkli sebep, AYNI yanit.
     */
    public function forInvitation(
        StoreCheckoutRequest $request,
        Invitation $invitation,
        StartCheckoutAction $action,
    ): JsonResponse {
        Gate::authorize('publish', $invitation);

        /** @var User $user auth:sanctum burada null OLAMAYACAGINI garanti eder. */
        $user = $request->user();

        return $this->respond($action->handle($user, $invitation, $request->tier()));
    }

    /** PAKET alim: hesabin tamami icin plan satin al (K42). */
    public function forAccount(StoreCheckoutRequest $request, StartCheckoutAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond($action->handle($user, null, $request->tier()));
    }

    /** 201: yeni bir siparis KAYNAGI olustu (odeme henuz tamamlanmadi). */
    private function respond(CheckoutResult $result): JsonResponse
    {
        return (new OrderResource($result->order))
            ->withRedirectUrl($result->redirectUrl)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
