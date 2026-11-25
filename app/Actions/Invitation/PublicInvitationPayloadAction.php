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
 * Misafirin okudugu davetiye yuku, onbellekli (4.2b, K7); API ve bot onizlemesi ayni anahtari paylasir.
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
        $payload = Cache::remember(
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

        return $this->withLiveGuestUploadFlag($payload);
    }

    /**
     * A-E3: bayrak ortam ayarı, davetiye verisi değil; önbellekten değil her istekte okunur
     * (bayrak değişince `cache:clear` gerekmez). Alan yalnızca LCV açıkken vardır.
     *
     * @param  array<string, mixed>  $payload
     *
     * @return array<string, mixed>
     */
    private function withLiveGuestUploadFlag(array $payload): array
    {
        $design = $payload['invitation'] ?? null;

        if (is_array($design) && array_key_exists('guestUploads', $design)) {
            $design['guestUploads'] = Config::boolean('davetkart.guest_uploads.enabled');
            $payload['invitation'] = $design;
        }

        return $payload;
    }
}
