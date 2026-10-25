<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\ConfirmEmailChangeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmEmailChangeRequest;
use Illuminate\Http\Response;

/**
 * E-posta değişikliği — onay (Faz 14, 14.10 · K144). Kimliksiz; yetki imzada.
 * Başarıda 204: bütün oturumlar kapandı, frontend giriş sayfasına yönlendirir.
 */
final class PublicEmailChangeController extends Controller
{
    public function __invoke(ConfirmEmailChangeRequest $request, ConfirmEmailChangeAction $action): Response
    {
        $action->handle($request->change());

        return response()->noContent();
    }
}
