<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AssistantUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir kullanicinin BIR GUNDEKI asistan mesaj sayaci.
 *
 * 🔴 Bu tablo bir SAYACDIR, bir GUNLUK degil. Kullanicinin ne yazdigi
 * hicbir yere kaydedilmiyor:
 *   - KVKK veri minimizasyonu: saklanmayan veri sizamaz. Bir sohbet
 *     gecmisi tablosu, sistemdeki en hassas metin deposu olurdu.
 *   - Kotanin sorusu "kac mesaj", "hangi mesaj" degil. Bir sinir icin
 *     gereken en az veri saklanir (E1 ailesi).
 *
 * 🔴 Neden `throttle` kovasi degil de TABLO? Cunku bu bir HIZ SINIRI degil
 * bir PARA KONTROLUDUR (config/davetkart.php: "AI cagrisi ucretli;
 * kotasiz birakmak finansal risktir"). RateLimiter kovalari cache'te durur;
 * `cache:clear`, bir deploy ya da Redis'in yeniden baslamasi butun
 * kotalari sifirlar. Bir hiz siniri icin bu kabul edilebilir, bir fatura
 * kontrolu icin degil. Hiz siniri AYRICA var (throttle:assistant) — L3:
 * ikisi birbirinin yerine gecmez.
 *
 * 🔴 #[Fillable] listesi BOS: bu satirin hicbir alani istemcinin mali
 * degil. Zaten toplu atamayla da yazilmiyor — AskAssistantAction sayaci
 * TEK BIR SQL deyimiyle artiriyor (kilit gerektirmeyen kosullu UPDATE).
 * Ayrintili aciklama: docs/rehber/app/Models/AssistantUsage.md
 */
#[Fillable([])]
class AssistantUsage extends Model
{
    /** @use HasFactory<AssistantUsageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',

            // K23: degismez tarih. `immutable_date` saat tasimaz — kolonun
            // kendisi de tasimiyor (E8: tarih ile zaman damgasi karistirilmaz).
            'usage_date' => 'immutable_date',

            // PostgreSQL surucusu integer'i duruma gore string dondurebilir;
            // kota karsilastirmasi buna guvenemez (P4).
            'message_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
