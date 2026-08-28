<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaKind;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Yuklenmis bir dosyanin KAYDI (dosyanin kendisi diskte durur).
 *
 * 🔴 #[Fillable] listesi BOS — ve bu bir eksiklik degil, bu tablonun en dogru
 * ifadesi: burada istemcinin sahip oldugu TEK BIR ALAN YOK.
 *   - disk / path / mime_type / size_bytes  -> sunucu, dosyayi inceleyerek belirler
 *   - invitation_id                          -> iliskiden gelir
 *   - kind                                   -> dogrulamadan gecse de KARARI Action verir
 * Alanlarin tamami acikca atanir (E7 ailesi).
 * Ayrintili aciklama: docs/rehber/app/Models/Media.md
 */
#[Fillable([])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasUlids;

    /**
     * Laravel 'Media' -> 'medias' diye cogullardi; tablo adi 'media'.
     * ('media' zaten Latince 'medium'un cogulu.)
     */
    protected $table = 'media';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,

            // PostgreSQL surucusu integer'i duruma gore string dondurebilir;
            // SUM/karsilastirma buna guvenemez (P4, Faz 3 ders 29).
            'size_bytes' => 'integer',

            // K23: degismez tarih.
            'optimized_at' => 'immutable_datetime',
        ];
    }

    /**
     * Dosyanin herkese acik URL'i.
     *
     * 🔴 ACCESSOR DEGIL, METOT. Sebep: accessor yazsaydik `$media->url` bir
     * KOLON gibi gorunur ve toArray()/JSON ciktisina sizabilirdi. Metot olmasi,
     * bunun saklanan bir deger degil TURETILEN bir deger oldugunu cagri
     * yerinde gorunur kilar (E1).
     *
     * Disk satirdan okunuyor, config'ten DEGIL: dosya o gun hangi diske
     * yazildiysa URL'i de oradan cozulur (6.2 §4).
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** Kuyruktaki optimizasyon bu dosyayi zaten isledi mi? */
    public function isOptimized(): bool
    {
        return $this->optimized_at !== null;
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
