<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Program akisindaki tek bir adimi frontend sozlesmesine cevirir
 * (types.ts -> TimelineEvent).
 *
 * Beyaz liste: burada adi gecmeyen hicbir alan disari cikmaz (C1).
 * `sort_order` DISARI CIKMAZ — sira, dizinin kendi sirasiyla ifade edilir.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/TimelineEventResource.md
 *
 * @mixin TimelineEvent
 */
final class TimelineEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Sozlesme: tum id'ler metindir. Kolon bigint (3.3 §6).
            'id' => (string) $this->id,

            // Frontend bu alanlari zorunlu string bekliyor; null bos metne doner.
            'time' => $this->time ?? '',
            'title' => $this->title ?? '',
            'description' => $this->description ?? '',
        ];
    }
}
