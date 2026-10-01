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
 * { id, status, updatedAt, publishedAt, releasableUntil, invitation: {...} } doner.
 *
 * 🔴 YALNIZCA sahibin Resource'u. Public yanit (PublicInvitationResource)
 * bu iki tarihi TASIMAZ (C5): misafirin bir davetiyenin ne zaman yayinlandigini
 * ya da odemenin geri alinabilirligini bilmesi icin bir sebep yok.
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

            // Faz 10 (10.21): silme uyarisi (frontend F7.2) kesin tarih
            // verebilsin. `updatedAt` vekil OLAMAZ: yayindan sonraki tek bir
            // duzenleme onu tazeler ve kullaniciya yanlis guvence verirdi.
            'publishedAt' => $this->published_at?->toIso8601String(),

            // Bu andan ONCE silinirse tekil siparisin hakki serbest kalir,
            // sonra silinirse yanar (K82). Kural modelde, burada yalnizca
            // gosteriliyor; frontend "3 gun"u bilmek zorunda kalmiyor.
            'releasableUntil' => $this->releaseWindowEndsAt()?->toIso8601String(),

            'invitation' => new InvitationPayloadResource($this->resource),
        ];
    }
}
