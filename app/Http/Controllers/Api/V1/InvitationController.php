<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitation\CreateInvitationAction;
use App\Actions\Invitation\DeleteInvitationAction;
use App\Actions\Invitation\PublishInvitationAction;
use App\Actions\Invitation\ResolveBrandingAction;
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
    public function index(Request $request, ResolveBrandingAction $branding): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Invitation::class);

        /** @var User $user auth:sanctum burada null OLAMAYACAGINI garanti eder. */
        $user = $request->user();

        // 3.9: Resource iliskinin YUKLU olmasini bekler. with() olmadan
        // her davetiye icin ayri sorgu acilirdi (N+1).
        $invitations = $user->invitations()
            ->with(['timelineEvents', 'galleryMedia'])
            ->latest('updated_at')
            ->get();

        // 10.66b: imza kararı bütün liste için tek sorguda (N+1 yok).
        $shown = $branding->forMany($invitations);

        return InvitationResource::collection($invitations->map(
            fn (Invitation $invitation): InvitationResource => InvitationResource::make($invitation)
                ->withBranding($shown[$invitation->getKey()]),
        ));
    }

    public function store(
        StoreInvitationRequest $request,
        CreateInvitationAction $action,
        ResolveBrandingAction $branding,
    ): JsonResponse {
        Gate::authorize('create', Invitation::class);

        /** @var User $user */
        $user = $request->user();

        $invitation = $action->handle(
            $user,
            $request->invitationAttributes(),
            $request->timelineEvents(),
        );

        return $this->present($invitation, $branding)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /** Sahibin duzenleme icin okumasi. Baskasinin kaydinda 404 doner (H7). */
    public function show(Invitation $invitation, ResolveBrandingAction $branding): InvitationResource
    {
        Gate::authorize('view', $invitation);

        return $this->present($invitation->load(['timelineEvents', 'galleryMedia']), $branding);
    }

    public function update(
        UpdateInvitationRequest $request,
        Invitation $invitation,
        UpdateInvitationAction $action,
        ResolveBrandingAction $branding,
    ): InvitationResource {
        Gate::authorize('update', $invitation);

        return $this->present($action->handle(
            $invitation,
            $request->invitationAttributes(),
            $request->timelineEvents(),
        ), $branding);
    }

    /**
     * Davetiyeyi yayina alir — PAYWALL KAPISI (Faz 7).
     *
     * 🔴 Yetenek 'publish', 'update' DEGIL: sahiplik ayni olsa da niyet ayri.
     * Bir gun "yayinlanmis davetiye duzenlenemez" kurali gelirse (docs/08'in
     * INVITATION_LOCKED kodu tam olarak bunun icin duruyor) update ile publish
     * ayni yetenegi paylasiyor olsaydi ikisi birlikte kilitlenirdi.
     *
     * Yanit 200 ve tam kayit doner: frontend'in editoru ayni Resource'u
     * okuyup durumu 'published' olarak gosterebilsin (ayri bir "yayinlandi"
     * zarfi ikinci bir sozlesme olurdu).
     */
    public function publish(
        Invitation $invitation,
        PublishInvitationAction $action,
        ResolveBrandingAction $branding,
    ): InvitationResource {
        Gate::authorize('publish', $invitation);

        // 🔴 load() ZORUNLU: Action kilitli bir YENIDEN OKUMA yapiyor ve o
        // ornek iliskileri tasimiyor. Kati kip yerelde LazyLoadingViolation
        // firlatir (3.9); uretimde ise sessiz bir N+1 olurdu.
        // 10.66b: yayın bağsız bir paketi bağlayabilir (K99); imza kararı
        // yayından SONRA sorulur.
        return $this->present(
            $action->handle($invitation)->load(['timelineEvents', 'galleryMedia']),
            $branding,
        );
    }

    /**
     * Davetiyeyi siler (soft delete) ve odenmis hakkin akibetine karar verir.
     *
     * Faz 9'a kadar govde tek satirdi: `$invitation->delete()`. Silmenin bir
     * IS KURALI dogmasi (uc gunluk geri alma penceresi) onu bir Action'a
     * tasidi — K3: is kurali controller'da durmaz.
     */
    public function destroy(Invitation $invitation, DeleteInvitationAction $action): Response
    {
        Gate::authorize('delete', $invitation);

        $action->handle($invitation);

        return response()->noContent();
    }

    /**
     * Sahibin kaydı, imza kararıyla birlikte (Faz 10, 10.66b · K102).
     * Editör önizlemesi misafirin göreceği imzayı göstersin.
     */
    private function present(Invitation $invitation, ResolveBrandingAction $branding): InvitationResource
    {
        return InvitationResource::make($invitation)->withBranding($branding->handle($invitation));
    }
}
