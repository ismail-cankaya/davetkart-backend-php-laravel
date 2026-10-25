<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;

/**
 * "Diğer bütün cihazlardan çık" (Faz 14, 14.9 · K143). İsteği yapan oturum kalır.
 */
final class RevokeOtherSessionsAction
{
    /** @return int Kapatılan oturum sayısı */
    public function handle(User $user, ?int $keepSessionId): int
    {
        return $user->tokens()
            ->when($keepSessionId !== null, static fn ($query) => $query->whereKeyNot($keepSessionId))
            ->delete();
    }
}
