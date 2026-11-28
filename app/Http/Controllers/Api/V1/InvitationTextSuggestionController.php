<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Assistant\SuggestInvitationTextsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\SuggestInvitationTextsRequest;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Metin önerisi ucu (Faz 25, K310). Zarf korunur; Resource yok (model yok, AssistantController ile aynı gerekçe). */
final class InvitationTextSuggestionController extends Controller
{
    public function __invoke(SuggestInvitationTextsRequest $request, Invitation $invitation, SuggestInvitationTextsAction $action): JsonResponse
    {
        Gate::authorize('update', $invitation);

        /** @var User $user auth:sanctum null olmadığını garanti eder (AccountController ile aynı kalıp) */
        $user = $request->user();

        $suggestions = $action->handle($user, $invitation, $request->key(), $request->current());

        return response()->json(['data' => ['suggestions' => $suggestions]]);
    }
}
