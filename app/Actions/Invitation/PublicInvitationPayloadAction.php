<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Http\Resources\PublicInvitationResource;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Misafirin okudugu davetiye yuku, onbellekli (4.2b, K7). API (`PublicInvitationController`)
 * ve bot onizlemesi (`SharePreviewController`, FE-04) AYNI anahtari paylasir: yayin/guncelleme
 * olayi (`ClearInvitationCache`) ikisini birden tazeler.
 * Kilavuz: docs/rehber/app/Actions/Invitation/PublicInvitationPayloadAction.md
 */
final class PublicInvitationPayloadAction
{
    public function __construct(
        private readonly ResolvePublicInvitationAction $resolve,
        private readonly ResolveBrandingAction $branding,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException Bulunamadi VEYA yayinda degil (onbellege yazilmaz)
     */
    public function handle(Request $request, string $id): array
    {
        // Cache'te DUZ DIZI durur, Eloquent modeli degil (4.2b'deki ->resolve()).
        // Tazelik TTL ile degil, yayin olayiyla saglanir (4.6); TTL yalnizca
        // olayin kacirildigi durumlar icin ust sinirdir.
        return Cache::remember(
            Invitation::publicCacheKey($id),
            Config::integer('davetkart.cache.public_invitation_ttl'),
            function () use ($request, $id): array {
                $invitation = $this->resolve->handle($id);

                // Faz 10 (10.66): imza kararı da önbelleğe giriyor; ödeme
                // bildirimi davetiyenin önbelleğini düşürür (K102).
                return PublicInvitationResource::make($invitation)
                    ->withBranding($this->branding->handle($invitation))
                    ->resolve($request);
            },
        );
    }
}
