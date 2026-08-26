<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Program akisindaki bir adimin MISAFIRE acik surumu.
 *
 * Sahibin surumunden (TimelineEventResource) tek farki: `id` YOK. Misafir
 * duzenleme yapmaz; React anahtarini frontend kendi uretir (types.ts -> localKey).
 * Artan bigint kimligi disari vermek, K40'in ULID ile kapattigi sayim
 * sizintisini arka kapidan geri getirirdi (C5).
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/PublicTimelineEventResource.md
 *
 * @mixin TimelineEvent
 */
final class PublicTimelineEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Frontend bu alanlari zorunlu string bekliyor; null bos metne doner.
            'time' => $this->time ?? '',
            'title' => $this->title ?? '',
            'description' => $this->description ?? '',
        ];
    }
}
