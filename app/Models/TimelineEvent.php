<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Davetiyenin program akisindaki tek bir adim (17:00 Karsilama, 19:00 Nikah...).
 *
 * 🔴 `invitation_id` BILEREK doldurulabilir DEGIL: aidiyet iliski uzerinden
 * kurulur — $invitation->timelineEvents()->create(...).
 * `HasFactory` 3.6'da fabrikayla birlikte eklenecek.
 * Ayrintili aciklama: docs/rehber/app/Models/TimelineEvent.md
 */
#[Fillable(['time', 'title', 'description', 'sort_order'])]
class TimelineEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
