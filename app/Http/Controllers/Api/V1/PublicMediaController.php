<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\StoreGuestMediaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StorePublicMediaRequest;
use App\Http\Resources\MediaResource;
use Illuminate\Http\JsonResponse;

/**
 * MISAFIRIN LCV foto/videosunu yukledigi uc — auth YOK.
 *
 * 🔴 MediaController (6.11) ile karsilastir: her karar TERSINE dondu.
 *
 * | | MediaController | bu dosya |
 * |---|---|---|
 * | Auth        | auth:sanctum        | yok (K12 grubu) |
 * | Parametre   | Invitation (binding)| string |
 * | Yetki       | Gate::authorize     | yok — P5 |
 * | Tur         | gallery             | rsvp_photo \| rsvp_video |
 *
 * 🔴 Rota parametresi MODEL DEGIL STRING: route-model binding calissaydi
 * YAYINLANMAMIS bir davetiye de coz(ul)urdu ve gorunurluk karari Action'in
 * disina kacardi (Faz 5, PublicRsvpController ile ayni gerekce).
 *
 * 🔴 Gate::authorize YOK ve bu bir eksiklik DEGIL: yuklenen medyanin SAHIBI
 * YOK. Yetki P5 ile ust kaynaga devredildi — "bu davetiyeye misafir yazabilir
 * mi?" sorusunu ResolveOpenRsvpInvitationAction cevapliyor.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PublicMediaController.md
 */
final class PublicMediaController extends Controller
{
    public function store(
        StorePublicMediaRequest $request,
        string $invitation,
        StoreGuestMediaAction $action,
    ): JsonResponse {
        $media = $action->handle(
            $invitation,
            $request->kind(),
            $request->uploadedFile(),
        );

        return (new MediaResource($media))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
