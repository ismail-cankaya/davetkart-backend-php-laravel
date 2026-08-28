<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RsvpStatus;
use Database\Factories\RsvpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir misafirin davetiyeye verdigi LCV yaniti.
 *
 * 🔴 `invitation_id` ve `ip_hash` BILEREK doldurulabilir DEGIL:
 *   - aidiyet iliski uzerinden kurulur ($invitation->rsvps()->create(...)),
 *   - ip_hash'i sunucu hesaplar; istemciden gelen bir "IP" veri degil YALANDIR.
 * Bu tablo auth'suz yazma yolunda oldugu icin beyaz liste bir konfor degil,
 * savunmanin kendisidir.
 * Ayrintili aciklama: docs/rehber/app/Models/Rsvp.md
 */
#[Fillable(['guest_name', 'guest_count', 'status', 'menu_preference', 'message'])]
class Rsvp extends Model
{
    /** @use HasFactory<RsvpFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RsvpStatus::class,

            // PostgreSQL surucusu smallint'i duruma gore string dondurebilir.
            // Kota SUM'i ve frontend'in sayisal toplami buna guvenemez (P4:
            // guvenlik/hesap karsilastirmasinda iki tarafin tipi garanti olmali).
            'guest_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
