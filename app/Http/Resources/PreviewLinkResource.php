<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Invitation\PreviewLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * "Telefonda aç" bağlantısı (Faz 29.4, K328).
 *
 * @mixin PreviewLink
 */
final class PreviewLinkResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->url,
            'expiresAt' => $this->expiresAt->toIso8601String(),
        ];
    }
}
