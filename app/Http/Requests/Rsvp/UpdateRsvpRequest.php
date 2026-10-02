<?php

declare(strict_types=1);

namespace App\Http\Requests\Rsvp;

use App\Support\RsvpEditCode;

/**
 * PUT /api/public/invitations/{invitation}/rsvps/{rsvp} girdisini doğrular (Faz 10, 10.59 · K101).
 *
 * İlk gönderimle aynı kurallar, artı ilk yanıtta dönen düzenleme kodu.
 * Honeypot yok: güncelleme yalnızca kodu bilen misafire açık.
 * Ayrıntılı açıklama: docs/rehber/app/Http/Requests/Rsvp/UpdateRsvpRequest.md
 */
final class UpdateRsvpRequest extends RsvpRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'editCode' => ['required', 'string', 'size:'.RsvpEditCode::LENGTH],
        ];
    }

    /** İlk gönderimin yanıtında dönen kod. */
    public function editCode(): string
    {
        return $this->string('editCode')->toString();
    }
}
