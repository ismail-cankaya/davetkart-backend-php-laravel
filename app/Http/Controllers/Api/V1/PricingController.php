<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Pricing\ShowPricingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\ShowPricingRequest;
use App\Http\Resources\PricingResource;
use App\Models\User;

/**
 * Fiyat kataloğu uçları (Faz 13, K128) — yalnızca yönlendirir (K3).
 *
 *   publicCatalog() -> GET /api/public/plans  misafir: liste + kampanya
 *   forUser()       -> GET /api/pricing       oturumlu: + ilk yayın indirimi (hedef için)
 *
 * İki uç, çünkü iki farklı soru: misafirin yanıtı herkes için aynıdır ve
 * ETag'le önbelleklenir; kullanıcınınki kişiye özeldir ve auth ister. Tek uçta
 * "token varsa kişisel" demek, /public/ önekinin "kimlik bilinmez" sözünü
 * bozardı (K12).
 * Ayrıntılı açıklama: docs/rehber/app/Http/Controllers/Api/V1/PricingController.md
 */
final class PricingController extends Controller
{
    public function publicCatalog(ShowPricingAction $action): PricingResource
    {
        return new PricingResource($action->handle(null));
    }

    /** Faz 18.3 (K163): `?invitation=` fiyatı sorulan hedef; yoksa davetiyesiz (paket). */
    public function forUser(ShowPricingRequest $request, ShowPricingAction $action): PricingResource
    {
        /** @var User $user auth:sanctum null olmadığını garanti eder */
        $user = $request->user();

        return new PricingResource($action->handle($user, $request->invitationId()));
    }
}
