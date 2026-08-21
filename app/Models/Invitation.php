<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationStatus;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kullanicinin olusturdugu davetiye.
 *
 * 🔴 `user_id`, `status` ve `published_at` BILEREK doldurulabilir DEGIL:
 * sahiplik iliski uzerinden, durum ise yayin akisi tarafindan belirlenir.
 * Ayrintili aciklama: docs/rehber/app/Models/Invitation.md
 */
#[Fillable([
    'category_id', 'preset_id', 'palette',
    'title', 'subtitle', 'names', 'venue', 'map_url', 'event_at',
    'show_envelope', 'show_timer', 'show_timeline',
    'show_gallery', 'show_gift', 'show_rsvp',
    'bank_name', 'account_holder', 'iban', 'gift_options',
    'rsvp_deadline', 'ask_menu_preference',
])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 🔴 InvitationPolicy kati karsilastirma yapar; HasUlids yuzunden
            // getIncrementing() false, yani anahtar cast'i otomatik gelmiyor.
            'user_id' => 'integer',

            'status' => InvitationStatus::class,

            // K23: degismez tarih. $d->addDay() orijinali bozmaz, kopya doner.
            'event_at' => 'immutable_datetime',
            'rsvp_deadline' => 'immutable_date',
            'published_at' => 'immutable_datetime',

            'gift_options' => 'array',

            'show_envelope' => 'boolean',
            'show_timer' => 'boolean',
            'show_timeline' => 'boolean',
            'show_gallery' => 'boolean',
            'show_gift' => 'boolean',
            'show_rsvp' => 'boolean',
            'ask_menu_preference' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Program akisi — HER ZAMAN kullanicinin sirasiyla doner.
     *
     * Siralama iliskinin icinde: cagiran yerde unutulursa sira rastgele olurdu.
     *
     * @return HasMany<TimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(TimelineEvent::class)->orderBy('sort_order');
    }
}
