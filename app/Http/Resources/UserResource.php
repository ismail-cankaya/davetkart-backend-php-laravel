<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User modelini frontend sozlesmesine cevirir (types.ts -> AuthUser).
 *
 * snake_case -> camelCase donusumunun yapildigi TEK yer (CLAUDE.md §1).
 * Beyaz liste: burada adi gecmeyen hicbir alan disari cikmaz.
 * Ayrintili aciklama: docs/rehber/app/Http/Resources/UserResource.md
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Frontend `id: string` bekliyor; users.id ise bigint.
            'id' => (string) $this->id,

            // K35: ad ve soyad ayri kolon, birlestirme YALNIZCA burada.
            'fullName' => trim($this->first_name.' '.$this->last_name),

            'email' => $this->email,
        ];
    }
}
