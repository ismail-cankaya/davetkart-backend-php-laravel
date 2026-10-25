<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\SummarizeEntitlementsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntitlementSummaryResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Hesap ayarlarının okuma uçları (Faz 14, 14.4) — yalnızca yönlendirir (K3).
 *
 * Policy YOK: uç parametre almıyor, yalnızca istek sahibinin kendi verisini
 * okuyor. Sahiplik Action'ın sorgusunda (`$user->orders()`), IDOR'a açılacak
 * bir kimlik yok.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Controllers/Api/V1/AccountController.md
 */
final class AccountController extends Controller
{
    public function entitlements(Request $request, SummarizeEntitlementsAction $action): EntitlementSummaryResource
    {
        /** @var User $user auth:sanctum null olmadığını garanti eder */
        $user = $request->user();

        return new EntitlementSummaryResource($action->handle($user));
    }
}
