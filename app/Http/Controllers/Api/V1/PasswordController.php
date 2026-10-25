<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\ChangePasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Oturum açıkken parola değiştirme (Faz 14, 14.8). Yalnızca yönlendirir (K3).
 *
 * `instanceof` bir iş kuralı değil, YÖNLENDİRME: Sanctum'un token'ı bir
 * TransientToken da olabilir (çerezli SPA oturumu); onun kimliği yoktur,
 * o zaman korunacak bir satır da yoktur.
 */
final class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request, ChangePasswordAction $action): Response
    {
        /** @var User $user auth:sanctum null olmadığını garanti eder */
        $user = $request->user();
        $token = $user->currentAccessToken();

        $action->handle($user, $request->newPassword(), $token instanceof PersonalAccessToken ? $token->id : null);

        return response()->noContent();
    }
}
