<?php

declare(strict_types=1);

namespace App\Http\Requests\Invitation;

/**
 * PUT /api/invitations/{invitation} girdisini dogrular.
 *
 * Katalog anahtarlari 'sometimes' + 'required': gonderilmeyebilir, ama
 * gonderilirse bos olamaz — NOT NULL kolonu null'a cekilemesin diye.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Invitation/UpdateInvitationRequest.md
 */
final class UpdateInvitationRequest extends InvitationRequest
{
    /** @return list<string> */
    protected function catalogPresence(): array
    {
        return ['sometimes', 'required'];
    }
}
