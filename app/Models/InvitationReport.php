<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationReportReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Misafirin bir davetiyeyi bildirmesi (Faz 25, K313). `invitation_id` ve `ip_hash` doldurulabilir DEĞİL:
 * Action ilişkiden ve isteğin IP'sinden yazar. Ayrıntı: docs/rehber/app/Models/InvitationReport.md
 */
#[Fillable(['reason', 'details'])]
class InvitationReport extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reason' => InvitationReportReason::class];
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
