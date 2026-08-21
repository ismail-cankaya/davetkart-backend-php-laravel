<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitation\CreateInvitationAction;
use App\Actions\Invitation\UpdateInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\StoreInvitationRequest;
use App\Http\Requests\Invitation\UpdateInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Davetiye CRUD uc noktalari. Yalnizca YONLENDIRIR; is kurali Action'larda (K3).
 *
 * Yetki her metotta Gate::authorize ile sorulur; kural tek yerde,
 * InvitationPolicy'de (3.7). authorizeResource KULLANILAMIYOR — gerekcesi
 * kilavuz §3'te.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/InvitationController.md
 */
final class InvitationController extends Controller
{
    /** Kullanicinin davetiyeleri; en son duzenlenen ustte. */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Invitation::class);

        /** @var User $user auth:sanctum burada null OLAMAYACAGINI garanti eder. */
        $user = $request->user();

        // 3.9: Resource iliskinin YUKLU olmasini bekler. with() olmadan
        // her davetiye icin ayri sorgu acilirdi (N+1).
        $invitations = $user->invitations()
            ->with('timelineEvents')
            ->latest('updated_at')
            ->get();

        return InvitationResource::collection($invitations);
    }

    public function store(StoreInvitationRequest $request, CreateInvitationAction $action): JsonResponse
    {
        Gate::authorize('create', Invitation::class);

        /** @var User $user */
        $user = $request->user();

        $invitation = $action->handle(
            $user,
            $request->invitationAttributes(),
            $request->timelineEvents(),
        );

        return (new InvitationResource($invitation))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /** Sahibin duzenleme icin okumasi. Baskasinin kaydinda 404 doner (H7). */
    public function show(Invitation $invitation): InvitationResource
    {
        Gate::authorize('view', $invitation);

        return new InvitationResource($invitation->load('timelineEvents'));
    }

    public function update(
        UpdateInvitationRequest $request,
        Invitation $invitation,
        UpdateInvitationAction $action,
    ): InvitationResource {
        Gate::authorize('update', $invitation);

        return new InvitationResource($action->handle(
            $invitation,
            $request->invitationAttributes(),
            $request->timelineEvents(),
        ));
    }

    /** Soft delete: satir kalir, deleted_at damgalanir (3.2). */
    public function destroy(Invitation $invitation): Response
    {
        Gate::authorize('delete', $invitation);

        $invitation->delete();

        return response()->noContent();
    }
}
