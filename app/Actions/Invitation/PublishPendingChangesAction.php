<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Exceptions\PaywallViolationException;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * "Değişiklikleri yayınla" (Faz 27.3, K323): taslağı `UpdateInvitationAction` üzerinden canlıya uygular. Paket kapısı
 * orada (402 aynı kodlarla); yazılan alanlar taslaktan düştüğü için başarıda taslak boşalır, 402'de olduğu gibi kalır.
 * Ayrıntı: docs/rehber/app/Actions/Invitation/PublishPendingChangesAction.md
 */
final class PublishPendingChangesAction
{
    public function __construct(
        private readonly UpdateInvitationAction $update,
    ) {}

    /**
     * @throws ModelNotFoundException Davetiye kilit anında silinmiş -> 404
     * @throws PaywallViolationException Taslak sahip olunan paketin üstünde -> 402
     */
    public function handle(Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($invitation): Invitation {
            $fresh = Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            $pending = PendingChanges::of($fresh);

            if ($pending->isEmpty()) {
                return $fresh->load(['timelineEvents', 'galleryMedia']);
            }

            return $this->update->handle($fresh, $pending->attributes, $pending->timelineEvents);
        });
    }
}
