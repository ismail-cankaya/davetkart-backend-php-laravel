<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Yuklenmis bir dosyanin SOZLESME yuzu.
 *
 * 🔴 C1 beyaz listesi burada ozellikle dar — iki alan. `disk` ve `path`
 * DISARI CIKMAZ:
 *   - `path` ic dizin duzenini ve ad uretim desenini ele verir,
 *   - `disk` yarin S3'e gecildiginde sozlesmeyi kirar.
 * Ikisi de DEPOLAMA DETAYIDIR; sozlesme "dosya nerede duruyor"u degil
 * "ona nasil ulasilir"i soyler.
 *
 * `url` bir kolon DEGIL, turetilen bir degerdir (E1): Media::url() satirin
 * KENDI diskinden cozer. Bu yuzden depolama degistigi gun sozlesme hic
 * degismez — 6.2'de `disk` kolonunu saklamanin karsiligi tam olarak burasi.
 *
 * types.ts'te karsiligi yok: frontend yalnizca `url` okuyor
 * (services/media.ts -> toHostedUrl). `id`'nin gerekcesi kilavuz §3.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/MediaResource.md
 *
 * @mixin Media
 */
final class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Bir yazma ucunun yaniti, YARATTIGI kaynagin kimligini tasir.
            // Olmazsa istemci kendi olusturdugu satira hicbir zaman referans
            // veremez (Faz 3'te K44 ile ayni karar).
            'id' => $this->id,

            // 🔴 Metot cagrisi, ozellik erisimi DEGIL. JsonResource bilinmeyen
            // cagrilari DelegatesToResource ile modele iletiyor; url() orada
            // Storage::disk($this->disk)->url($this->path) calistiriyor.
            'url' => $this->url(),
        ];
    }
}
