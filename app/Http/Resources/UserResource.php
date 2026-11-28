<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User modelini frontend sozlesmesine cevirir (types.ts -> AuthUser).
 * snake_case -> camelCase donusumunun yapildigi TEK yer (CLAUDE.md §1).
 * Ayrıntı: docs/rehber/app/Http/Resources/UserResource.md §K18-1
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

            // K35: ad ve soyad ayri olacak, fronted kısmında gerekirse birlestirilir
            'firstName' => trim($this->first_name),
            'lastName' => trim($this->last_name),

            'email' => $this->email,

            // K191, K276: arayüz "adresini doğrula" hatırlatmasını buna göre gösterir (engel değil).
            'emailVerified' => $this->email_verified_at !== null,
        ];
    }
}
