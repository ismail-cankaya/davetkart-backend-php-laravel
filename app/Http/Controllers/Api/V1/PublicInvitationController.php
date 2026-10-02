<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitation\ResolveBrandingAction;
use App\Actions\Invitation\ResolvePublicInvitationAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicInvitationResource;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

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
    public function show(
        Request $request,
        string $id,
        ResolvePublicInvitationAction $resolve,
        ResolveBrandingAction $branding,
    ): JsonResponse {
        // Cache'te DUZ DIZI durur, Eloquent modeli degil (4.2b'deki ->resolve()).
        // Tazelik TTL ile degil, yayin olayiyla saglanir (4.6); TTL yalnizca
        // olayin kacirildigi durumlar icin ust sinirdir.
        $payload = Cache::remember(
            Invitation::publicCacheKey($id),
            Config::integer('davetkart.cache.public_invitation_ttl'),
            function () use ($request, $id, $resolve, $branding): array {
                $invitation = $resolve->handle($id);

                // Faz 10 (10.66): imza kararı da önbelleğe giriyor; ödeme
                // bildirimi davetiyenin önbelleğini düşürür (K102).
                return PublicInvitationResource::make($invitation)
                    ->withBranding($branding->handle($invitation))
                    ->resolve($request);
            },
        );

        // K11: auth disindaki her yanit {data: ...} zarfiyla doner. Resource'u
        // dogrudan dondurmuyoruz cunku cache'ten gelen sey artik bir dizi.
        return response()->json(['data' => $payload]);
    }
}
