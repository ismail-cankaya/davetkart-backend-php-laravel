<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;

/**
 * Profil (ad/soyad) güncelleme (Faz 14, 14.7). Yalnızca yönlendirir (K3).
 * Yanıt `GET /auth/me` ile aynı: `{data: user}` (K11).
 */
final class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): UserResource
    {
        /** @var User $user auth:sanctum null olmadığını garanti eder */
        $user = $request->user();

        return new UserResource($action->handle($user, $request->profile()));
    }
}
