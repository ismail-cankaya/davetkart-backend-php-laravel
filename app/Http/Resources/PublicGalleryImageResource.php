<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Galeri fotografinin MISAFIRE acik yuzu — yalnizca `url`.
 *
 * Sahibin surumunden (MediaResource) tek farki: `id` YOK. Misafir galeriyi
 * duzenlemez, kimlik ona hicbir is gormez; disari vermek gereksiz bir sozlesme
 * olurdu (C5 — PublicTimelineEventResource ile ayni karar).
 *
 * Nesne olarak doner, duz metin olarak degil: yarin bir `alt` ya da boyut
 * bilgisi eklendiginde sozlesme KIRILMADAN genisler.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/PublicGalleryImageResource.md
 *
 * @mixin Media
 */
final class PublicGalleryImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Metot cagrisi: URL disk + yoldan TURETILIR (E1, MediaResource).
            'url' => $this->url(),
        ];
    }
}
