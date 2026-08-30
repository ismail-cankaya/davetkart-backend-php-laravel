<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Rsvp\SubmitRsvpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rsvp\StoreRsvpRequest;
use App\Http\Resources\RsvpResource;
use Illuminate\Http\JsonResponse;

/**
 * Misafirin LCV yanitini gonderdigi tek uc — auth YOK.
 *
 * 🔴 Rota parametresi model DEGIL string: route-model binding calissaydi
 * YAYINLANMAMIS bir davetiye de coz(ul)urdu ve gorunurluk karari Action'in
 * disina kacardi. Kimin gorunur oldugunu SubmitRsvpAction soyler.
 *
 * Controller hicbir `if` icermez (CLAUDE.md §1): honeypot karari, gorunurluk,
 * son tarih ve kota Action'da; hata -> HTTP esleme ApiExceptionRenderer'da.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PublicRsvpController.md
 */
final class PublicRsvpController extends Controller
{
    public function store(
        StoreRsvpRequest $request,
        string $invitation,
        SubmitRsvpAction $action,
    ): JsonResponse {
        $rsvp = $action->handle(
            $invitation,
            $request->rsvpAttributes(),
            (string) $request->ip(),
            $request->isHoneypotTripped(),
            // 🔴 AYRI parametre, rsvpAttributes()'in icinde DEGIL: o dizi
            // toplu atamaya gidiyor ve toplu atama sahiplik kontrolunu atlar.
            $request->mediaIds(),
        );

        // 201 hem gercek kayitta hem honeypot yutmasinda doner — ayirt
        // edilebilir olsaydi savunma bir kez kullanilip olurdu (5.7).
        return (new RsvpResource($rsvp))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
