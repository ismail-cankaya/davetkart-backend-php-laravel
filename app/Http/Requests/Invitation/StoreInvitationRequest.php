<?php

declare(strict_types=1);

namespace App\Http\Requests\Invitation;

/**
 * POST /api/invitations girdisini dogrular.
 *
 * Katalog anahtarlari ZORUNLU: kolonlari NOT NULL ve sihirbaz her zaman
 * doldurur. Eksik gelirse 422 doner — veritabani hatasindan (500) iyidir.
 * Ayrintili aciklama: docs/rehber/app/Http/Requests/Invitation/StoreInvitationRequest.md
 */
final class StoreInvitationRequest extends InvitationRequest
{
    /** @return list<string> */
    protected function catalogPresence(): array
    {
        return ['required'];
    }
}
