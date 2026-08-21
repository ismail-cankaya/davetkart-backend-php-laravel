<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;
use Illuminate\Support\Facades\DB;

/**
 * Mevcut davetiyeyi gunceller; program listesi gonderildiyse senkronize eder.
 *
 * Yetki kontrolu BURADA DEGIL: Policy controller'da calisir (3.7).
 * Ayrintili aciklama: docs/rehber/app/Actions/Invitation/UpdateInvitationAction.md
 */
final class UpdateInvitationAction
{
    public function __construct(
        private readonly SyncTimelineEventsAction $syncTimelineEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $timelineEvents  null = programa dokunma
     */
    public function handle(Invitation $invitation, array $attributes, ?array $timelineEvents): Invitation
    {
        return DB::transaction(function () use ($invitation, $attributes, $timelineEvents): Invitation {
            $invitation->fill($attributes)->save();

            $timelineChanged = $timelineEvents !== null
                && $this->syncTimelineEvents->handle($invitation, $timelineEvents);

            // Yalnizca program degistiyse kaydin kendisi "kirli" olmaz ve
            // updated_at bayat kalirdi — frontend onu "son kaydetme" diye gosteriyor.
            if ($timelineChanged && ! $invitation->wasChanged()) {
                $invitation->touch();
            }

            return $invitation->load('timelineEvents');
        });
    }
}
