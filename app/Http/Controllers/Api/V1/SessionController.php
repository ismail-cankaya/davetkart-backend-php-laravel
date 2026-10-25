<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RevokeOtherSessionsAction;
use App\Actions\Auth\RevokeSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Aktif oturumlar (Faz 14, 14.9 · K143). Yalnızca yönlendirir (K3).
 *
 * `index` Action'sız (K15, OrderController::index ile aynı): tek sorgu + Resource.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Auth/IssueApiTokenAction.md §5
 */
final class SessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user auth:sanctum null olmadığını garanti eder */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $current = $token instanceof PersonalAccessToken ? $token->id : null;

        // Süresi dolmuş (K90: 30 gün, mutlak) token tabloda kalabilir ama
        // artık bir oturum değildir; listede görünmez.
        $sessions = $user->tokens()
            ->where('created_at', '>', now()->subMinutes(Config::integer('sanctum.expiration')))
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();

        return SessionResource::collection(
            $sessions->map(static fn (PersonalAccessToken $token): SessionResource => (new SessionResource($token))->markCurrent($current)),
        );
    }

    public function destroy(Request $request, int $session, RevokeSessionAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user, $session);

        return response()->noContent();
    }

    public function destroyOthers(Request $request, RevokeOtherSessionsAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();

        // TransientToken (çerezli SPA) bir satır değildir; korunacak kimliği yok.
        $token = $user->currentAccessToken();

        $action->handle($user, $token instanceof PersonalAccessToken ? $token->id : null);

        return response()->noContent();
    }
}
