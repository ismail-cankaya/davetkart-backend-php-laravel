<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitation\BuildInvitationPreviewAction;
use App\Actions\Invitation\CreatePreviewLinkAction;
use App\Actions\Invitation\ResolveBrandingAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\PreviewLinkResource;
use App\Http\Resources\PublicInvitationResource;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Telefonda aç" (Faz 29.4, K328): sahibine imzalı bağlantı; imzayı taşıyan herkese taslaklı önizleme.
 * Ayrıntı: docs/rehber/app/Http/Controllers/Api/V1/InvitationPreviewController.md
 */
final class InvitationPreviewController extends Controller
{
    public function store(Invitation $invitation, CreatePreviewLinkAction $action): PreviewLinkResource
    {
        Gate::authorize('update', $invitation);

        return PreviewLinkResource::make($action->handle($invitation));
    }

    public function show(Request $request, Invitation $invitation, BuildInvitationPreviewAction $action, ResolveBrandingAction $branding): JsonResponse
    {
        $preview = $action->handle($invitation);
        $data = PublicInvitationResource::make($preview)->withBranding($branding->handle($invitation))->resolve($request);

        // Önizleme önbelleğe alınmaz ve arama motoruna girmez.
        return response()->json(['data' => $data])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
