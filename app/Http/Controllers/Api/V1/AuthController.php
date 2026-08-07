<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
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

        return $this->session($result['user'], $result['token'], JsonResponse::HTTP_CREATED);
    }

    public function login(LoginRequest $request, LoginUserAction $action): JsonResponse
    {
        $result = $action->handle($request->credentials());

        return $this->session($result['user'], $result['token']);
    }

    /**
     * Frontend'in AuthSession sozlesmesi. Iki uc noktanin bicimi BIREBIR ayni
     * kalmali; bu yuzden tek yerden uretiliyor.
     */
    private function session(User $user, string $token, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return response()->json([
            'user' => (new UserResource($user))->resolve(),
            'token' => $token,
        ], $status);
    }
}
