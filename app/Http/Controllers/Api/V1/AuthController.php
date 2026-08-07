<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * Kimlik uc noktalari. Yalnizca YONLENDIRIR; is kurali Action'larda (K3).
 *
 * 🔴 Auth yanitlari ZARFSIZ doner: {user, token} — {data: ...} YOK (K11).
 * Frontend services/auth.ts dogrudan `data.user` okuyor.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/AuthController.md
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        $result = $action->handle($request->userAttributes());

        return response()->json([
            'user' => (new UserResource($result['user']))->resolve(),
            'token' => $result['token'],
        ], JsonResponse::HTTP_CREATED);
    }
}
