<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Davetiye kaydi — types.ts -> InvitationRecord.
 *
 * Sunucu ustverisi burada, kullanicinin tasarimi `invitation` altinda.
 * Ayrim istek govdesiyle simetrik: { invitation: {...} } gonderilir,
 * { id, status, updatedAt, invitation: {...} } doner.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/InvitationResource.md
 *
 * @mixin Invitation
 */
final class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'invitation' => new InvitationPayloadResource($this->resource),
        ];
    }
}
