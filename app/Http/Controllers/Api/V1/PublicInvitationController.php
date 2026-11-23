<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitation\PublicInvitationPayloadAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Misafirin davetiyeyi okudugu tek uc — auth YOK, cache VAR.
 *
 * Sistemin en yuksek trafikli noktasi: link gruba duser, yuzlerce kisi
 * dakikalar icinde acar, veri neredeyse hic degismez.
 *
 * 🔴 Rota parametresi model DEGIL string: route-model binding middleware'den
 * once calisip her istekte SELECT acardi ve cache'i anlamsizlastirirdi.
 * Ayrintili aciklama: docs/rehber/app/Http/Controllers/Api/V1/PublicInvitationController.md
 */
final class PublicInvitationController extends Controller
{
    public function show(Request $request, string $id, PublicInvitationPayloadAction $payload): JsonResponse
    {
        // Onbellekli yuk (4.2b, K7) FE-04'te eyleme tasindi: bot onizlemesi ayni anahtari paylasir.
        $data = $payload->handle($request, $id);

        // K11: auth disindaki her yanit {data: ...} zarfiyla doner. Resource'u
        // dogrudan dondurmuyoruz cunku cache'ten gelen sey artik bir dizi.
        return response()->json(['data' => $data]);
    }
}
