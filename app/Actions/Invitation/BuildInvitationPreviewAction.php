<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;
use App\Models\TimelineEvent;

/**
 * İmzalı önizlemenin modeli (Faz 29.4, K328): canlı davetiyenin üstüne yayınlanmamış taslak, KAYDEDİLMEDEN.
 * Ayrıntı: docs/rehber/app/Actions/Invitation/BuildInvitationPreviewAction.md
 */
final class BuildInvitationPreviewAction
{
    public function handle(Invitation $invitation): Invitation
    {
        $invitation->load(['timelineEvents', 'galleryMedia']);
        $pending = PendingChanges::of($invitation);
        if ($pending->isEmpty()) {
            return $invitation;
        }

        $preview = clone $invitation;
        $preview->fill($pending->attributes);

        if ($pending->timelineEvents !== null) {
            $events = collect($pending->timelineEvents)->values()->map(fn (array $event, int $index): TimelineEvent => new TimelineEvent([
                'time' => $event['time'] ?? null,
                'title' => $event['title'] ?? null,
                'description' => $event['description'] ?? null,
                'sort_order' => $index,
            ]));
            $preview->setRelation('timelineEvents', $events);
        }

        return $preview;
    }
}
