<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;

/**
 * "Vazgeç" (Faz 27.3, K323): yayınlanmamış değişiklikleri atar; canlı davetiye değişmez.
 * Ayrıntı: docs/rehber/app/Actions/Invitation/DiscardPendingChangesAction.md
 */
final class DiscardPendingChangesAction
{
    public function handle(Invitation $invitation): Invitation
    {
        // Olaysız ve zaman damgasız: misafirin gördüğü hiçbir şey değişmedi.
        Invitation::query()->whereKey($invitation->getKey())->toBase()->update(['pending_changes' => null]);

        return $invitation->refresh()->load(['timelineEvents', 'galleryMedia']);
    }
}
