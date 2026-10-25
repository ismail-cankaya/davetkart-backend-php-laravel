<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\RequestEmailChangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RequestEmailChangeRequest;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * E-posta değişikliği — istek (Faz 14, 14.10). Yalnızca yönlendirir (K3).
 *
 * 202 = "istek alındı, sonuç başka bir kanaldan (mail) gelecek" — parola
 * sıfırlama isteğiyle aynı. Adres kullanımda olsa da AYNI yanıt.
 */
final class EmailChangeController extends Controller
{
    public function store(RequestEmailChangeRequest $request, RequestEmailChangeAction $action): Response
    {
        /** @var User $user auth:sanctum null olmadığını garanti eder */
        $user = $request->user();

        $action->handle($user, $request->newEmail());

        return response()->noContent(Response::HTTP_ACCEPTED);
    }
}
