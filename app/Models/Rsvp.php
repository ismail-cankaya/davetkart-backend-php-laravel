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

    /**
     * Misafirin ektigi fotograf — YOKSA null.
     *
     * 🔴 photo_media_id #[Fillable] listesinde YOK ve olmayacak. Kimlik
     * istemciden geliyor, yani "bu medya bu davetiyeye mi ait?" sorusu
     * cevaplanmadan atanamaz. Toplu atama o soruyu ATLAR — SubmitRsvpAction
     * once dogrular, sonra acikca yazar (N1 + E7 ailesi).
     *
     * @return BelongsTo<Media, $this>
     */
    public function photoMedia(): BelongsTo
    {
        // Kolon adi konvansiyondan (photo_media_id) turetilemez cunku iliski
        // adi 'photoMedia'; Laravel 'photo_media_id' tahmin ederdi ve dogru
        // olurdu — yine de ACIKCA yaziliyor: iki iliski ayni tabloya bakiyor
        // ve hangisinin hangi kolonu kullandigi okundugunda gorulmeli.
        return $this->belongsTo(Media::class, 'photo_media_id');
    }

    /**
     * Misafirin ektigi video — YOKSA null.
     *
     * @return BelongsTo<Media, $this>
     */
    public function videoMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'video_media_id');
    }
}
