<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\RevokeTokenAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    /** Yalnizca istegi tasiyan token'i iptal eder; govde dondurmez (204). */
    public function logout(Request $request, RevokeTokenAction $action): Response
    {
        /** @var User $user auth:sanctum burada null OLAMAYACAGINI garanti eder. */
        $user = $request->user();

        $action->handle($user);

        return response()->noContent();
    }

    /**
     * Faz 10 (10.35): sifirlama baglantisi iste. Hesap var olsa da olmasa da
     * AYNI 202, govdesiz (enumeration yok, 08 §3.1). 202 = "istegin alindi,
     * sonuc baska bir kanaldan (mail) gelecek".
     */
    public function forgotPassword(ForgotPasswordRequest $request, SendPasswordResetLinkAction $action): Response
    {
        $action->handle($request->normalizedEmail());

        return response()->noContent(Response::HTTP_ACCEPTED);
    }

    /** Faz 10 (10.35): yeni parolayi kaydet. Basarida 204; yeni oturum ACILMAZ. */
    public function resetPassword(ResetPasswordRequest $request, ResetPasswordAction $action): Response
    {
        $credentials = $request->credentials();

        $action->handle($credentials['email'], $credentials['token'], $credentials['password']);

        return response()->noContent();
    }

    /** Token dogrulama. Zarfsiz DEGIL: {data: ...} varsayilani gecerli (K11). */
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
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
