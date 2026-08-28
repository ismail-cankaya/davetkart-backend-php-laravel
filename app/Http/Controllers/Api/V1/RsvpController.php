<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RsvpResource;
use App\Models\Invitation;
use App\Models\Rsvp;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Sahibin LCV panelinin uc noktalari — auth:sanctum arkasinda.
 *
 * Liste ucu 15 saniyede bir cagrilir (config: poll_interval_seconds), yani
 * sistemin en sik istenen AUTH'LU ucudur. Bu yuzden rotasina SetEtag
 * middleware'i takili (K46): veri degismediyse govde hic gonderilmez.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/RsvpController.md
 */
final class RsvpController extends Controller
{
    /**
     * Bir davetiyenin LCV yanitlari; en yeni ustte.
     *
     * Sahiplik iki kez korunuyor: Gate karari verir, sorgu KAPSAMI zorlar.
     * Ikincisi olmasa Gate'i unutmak tum yanitlari acardi (P3).
     */
    public function index(Invitation $invitation): AnonymousResourceCollection
    {
        Gate::authorize('view', $invitation);

        return RsvpResource::collection(
            $invitation->rsvps()->latest()->get(),
        );
    }

    /**
     * Sahip moderasyonu: bir yaniti siler.
     *
     * loadMissing() ACIK bir yuklemedir; yasak olan ortuk (lazy) olanidir.
     * Policy iliskiye bakacak ve preventLazyLoading yerelde exception firlatirdi.
     */
    public function destroy(Rsvp $rsvp): Response
    {
        Gate::authorize('delete', $rsvp->loadMissing('invitation'));

        $rsvp->delete();

        return response()->noContent();
    }
}
