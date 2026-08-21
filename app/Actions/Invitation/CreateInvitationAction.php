<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Yeni davetiye olusturur ve varsa program akisini yazar.
 *
 * Sahiplik iliskiden gelir: user_id hicbir zaman istemci verisinden okunmaz.
 * Ayrintili aciklama: docs/rehber/app/Actions/Invitation/CreateInvitationAction.md
 */
final class CreateInvitationAction
{
    public function __construct(
        private readonly SyncTimelineEventsAction $syncTimelineEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $timelineEvents
     */
    public function handle(User $user, array $attributes, ?array $timelineEvents): Invitation
    {
        // E4: iki tabloya yaziliyor. Yarim kalan davetiye programsiz kalirdi.
        return DB::transaction(function () use ($user, $attributes, $timelineEvents): Invitation {
            $invitation = $user->invitations()->create($attributes);

            if ($timelineEvents !== null) {
                $this->syncTimelineEvents->handle($invitation, $timelineEvents);
            }

            // Resource iliskinin YUKLU olmasini bekler (3.9); senkronizasyon
            // sonrasi bellekteki koleksiyon bayattir.
            return $invitation->load('timelineEvents');
        });
    }
}
