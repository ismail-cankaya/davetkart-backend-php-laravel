<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Stüdyonun kaydı (Faz 27.3, K323): yayındaki davetiyede taslağa, taslak davetiyede doğrudan yazar. Paket kontrolü yok.
 * Ayrıntı: docs/rehber/app/Actions/Invitation/SavePendingChangesAction.md
 */
final class SavePendingChangesAction
{
    public function __construct(
        private readonly UpdateInvitationAction $update,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  kolon adlarıyla (InvitationRequest::invitationAttributes)
     * @param  list<array<string, mixed>>|null  $timelineEvents  null = programa dokunma
     *
     * @throws ModelNotFoundException Davetiye kilit anında silinmiş -> 404
     */
    public function handle(Invitation $invitation, array $attributes, ?array $timelineEvents): Invitation
    {
        return DB::transaction(function () use ($invitation, $attributes, $timelineEvents): Invitation {
            $fresh = Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== InvitationStatus::Published) {
                return $this->update->handle($fresh, $attributes, $timelineEvents);
            }

            $current = PendingChanges::of($fresh);
            $merged = array_merge($current->attributes, $attributes);

            // Canlıya eşit gelen alan taslakta durmaz: rozet ("yayınlanmamış değişiklik") yalnızca gerçek farkta yanar.
            $probe = clone $fresh;
            $probe->fill($merged);
            $changed = array_filter(
                $merged,
                fn (string $column): bool => $this->differs($probe->getAttribute($column), $fresh->getAttribute($column)),
                ARRAY_FILTER_USE_KEY,
            );

            $timeline = $timelineEvents ?? $current->timelineEvents;
            if ($timeline !== null && $this->sameTimeline($fresh, $timeline)) {
                $timeline = null;
            }

            $pending = new PendingChanges($changed, $timeline);

            // Olaysız ve zaman damgasız yazım: misafir önbelleği (InvitationChanged) ve "son güncelleme" değişmez.
            Invitation::query()->whereKey($fresh->getKey())->toBase()->update([
                'pending_changes' => $pending->toColumn() === null ? null : json_encode($pending->toColumn(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

            return $fresh->refresh()->load(['timelineEvents', 'galleryMedia']);
        });
    }

    private function differs(mixed $next, mixed $live): bool
    {
        if ($next instanceof DateTimeInterface && $live instanceof DateTimeInterface) {
            return $next->format(DateTimeInterface::ATOM) !== $live->format(DateTimeInterface::ATOM);
        }

        if (is_array($next) || is_array($live)) {
            return json_encode($this->sorted($next)) !== json_encode($this->sorted($live));
        }

        return $next !== $live;
    }

    /** jsonb anahtar sırasını korumaz: karşılaştırma sıralı biçimde. */
    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->sorted($item), $value);
    }

    /** @param  list<array<string, mixed>>  $events */
    private function sameTimeline(Invitation $invitation, array $events): bool
    {
        $live = $invitation->timelineEvents()->orderBy('sort_order')->get()
            ->map(fn ($event): array => [(string) $event->id, $event->time ?? '', $event->title ?? '', $event->description ?? ''])
            ->all();
        $next = array_map(fn (array $event): array => [
            (string) ($event['id'] ?? ''),
            (string) ($event['time'] ?? ''),
            (string) ($event['title'] ?? ''),
            (string) ($event['description'] ?? ''),
        ], $events);

        return $live === $next;
    }
}
