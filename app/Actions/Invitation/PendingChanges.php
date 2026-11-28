<?php

declare(strict_types=1);

namespace App\Actions\Invitation;

use App\Models\Invitation;

/**
 * Yayındaki davetiyenin yayınlanmamış değişiklikleri (Faz 27.3, K323): `invitations.pending_changes` kolonunun tipli
 * okuması. Yalnızca canlıdan farklı kolonlar (`attributes`, istekteki kolon adlarıyla) ve program adımları tutulur.
 * Ayrıntı: docs/rehber/app/Actions/Invitation/PendingChanges.md
 */
final readonly class PendingChanges
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $timelineEvents  null = program değişmedi
     */
    public function __construct(
        public array $attributes = [],
        public ?array $timelineEvents = null,
    ) {}

    public static function of(Invitation $invitation): self
    {
        /** @var array{attributes?: array<string, mixed>, timelineEvents?: list<array<string, mixed>>}|null $raw */
        $raw = $invitation->pending_changes;

        return new self($raw['attributes'] ?? [], $raw['timelineEvents'] ?? null);
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [] && $this->timelineEvents === null;
    }

    /**
     * Canlıya yazılan alanlar taslaktan düşer (K324).
     *
     * @param  list<string>  $columns
     */
    public function without(array $columns, bool $timeline): self
    {
        return new self(
            array_diff_key($this->attributes, array_flip($columns)),
            $timeline ? null : $this->timelineEvents,
        );
    }

    /** @return array{attributes: array<string, mixed>, timelineEvents?: list<array<string, mixed>>}|null */
    public function toColumn(): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        $column = ['attributes' => $this->attributes];
        if ($this->timelineEvents !== null) {
            $column['timelineEvents'] = $this->timelineEvents;
        }

        return $column;
    }
}
