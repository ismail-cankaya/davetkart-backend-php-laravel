<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Media\StoreUploadedMediaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Davetiye SAHIBININ galeri yuklemesi — auth:sanctum arkasinda.
 *
 * 🔴 Yetki 'view' degil 'update' soruluyor: bir davetiyeye dosya eklemek onu
 * DEGISTIRMEKTIR. Bugun ikisi ayni sonucu veriyor (InvitationPolicy::owns),
 * ama Faz 7'de "yayinlanmis davetiye kilitlenir" (INVITATION_LOCKED) kurali
 * geldigi gun ayrisacaklar — ve o gun bu satirin hangi soruyu sordugu onem
 * kazanacak. Faz 5'te RsvpPolicy::delete() ile ayni gerekce.
 *
 * Rota parametresi MODEL: sahibin ucunda gorunurluk sorusu yok, sahiplik
 * sorusu var ve onu Policy cevapliyor. (Misafirin ucu 6.12'de string alacak —
 * orada yayin durumu bir SORGU KAPSAMIDIR, PublicRsvpController ile ayni.)
 *
 * Controller'da hicbir `if` yok (CLAUDE.md §1): bicim StoreMediaRequest'te,
 * kota ve MIME StoreUploadedMediaAction'da, hata -> HTTP esleme
 * ApiExceptionRenderer'da.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/MediaController.md
 */
final class MediaController extends Controller
{
    public function store(
        StoreMediaRequest $request,
        Invitation $invitation,
        StoreUploadedMediaAction $action,
    ): JsonResponse {
        Gate::authorize('update', $invitation);

        $media = $action->handle(
            $invitation,
            $request->kind(),
            $request->uploadedFile(),
        );

        // 201 Created: yeni bir kaynak dogdu ve kimligi yanitta.
        return (new MediaResource($media))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
