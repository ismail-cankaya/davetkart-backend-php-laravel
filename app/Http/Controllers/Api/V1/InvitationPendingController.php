<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitation\DiscardPendingChangesAction;
use App\Actions\Invitation\PublishPendingChangesAction;
use App\Actions\Invitation\ResolveBrandingAction;
use App\Actions\Invitation\SavePendingChangesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\UpdateInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use Illuminate\Support\Facades\Gate;

/**
 * Yayındaki davetiyenin taslak katmanı (Faz 27.3, K323): kaydet, yayınla, vazgeç.
 * Ayrıntı: docs/rehber/app/Http/Controllers/Api/V1/InvitationPendingController.md
 */
final class InvitationPendingController extends Controller
{
    public function update(
        UpdateInvitationRequest $request,
        Invitation $invitation,
        SavePendingChangesAction $action,
        ResolveBrandingAction $branding,
    ): InvitationResource {
        Gate::authorize('update', $invitation);

        return $this->present($action->handle($invitation, $request->invitationAttributes(), $request->timelineEvents()), $branding);
    }

    public function publish(Invitation $invitation, PublishPendingChangesAction $action, ResolveBrandingAction $branding): InvitationResource
    {
        Gate::authorize('update', $invitation);

        return $this->present($action->handle($invitation), $branding);
    }

    public function destroy(Invitation $invitation, DiscardPendingChangesAction $action, ResolveBrandingAction $branding): InvitationResource
    {
        Gate::authorize('update', $invitation);

        return $this->present($action->handle($invitation), $branding);
    }

    private function present(Invitation $invitation, ResolveBrandingAction $branding): InvitationResource
    {
        return InvitationResource::make($invitation)->withBranding($branding->handle($invitation));
    }
}
