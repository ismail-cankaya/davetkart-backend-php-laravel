<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\SubscriptionTier;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir plan satin alma kaydi — projenin ticari cekirdegi.
 *
 * 🔴 #[Fillable] listesi BOS. Media modelindeki (Faz 6) ayni gerekce, daha
 * agir sonuclusu: bu tablodaki hicbir alan istemcinin mali degildir.
 *   - tier            -> istemci "gold" der ama KARARI StartCheckoutAction verir
 *   - amount_minor    -> fiyat SUNUCUDAKI config'ten okunur (asla govdeden)
 *   - status/paid_at  -> yalnizca odeme saglayicisinin bildirimi degistirir
 *   - provider_ref    -> saglayici uretir
 *
 * Toplu atama acik olsaydi `{"tier":"elit","status":"paid"}` govdesi bir
 * odemeyi bedava yapardi. Alanlarin tamami acikca atanir (E7 ailesi).
 * Ayrintili aciklama: docs/rehber/app/Models/Order.md
 */
#[Fillable([])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 🔴 Policy/kaynak karsilastirmalari kati (===) yapiyor; HasUlids
            // yuzunden getIncrementing() false, yani anahtar cast'i otomatik
            // gelmiyor (Faz 3, ders 29). Invitation'daki ayni satir.
            'user_id' => 'integer',

            'tier' => SubscriptionTier::class,
            'status' => OrderStatus::class,

            // PostgreSQL surucusu integer'i duruma gore string dondurebilir;
            // karsilastirma buna guvenemez (P4).
            'amount_minor' => 'integer',

            // K23: degismez tarih.
            'paid_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Yayin hakki VEREN siparisler.
     *
     * 🔴 Kural sorguda degil enum'da: hangi durumun hak verdigini
     * OrderStatus::grantsPublishRight() soyler, bu kapsam yalnizca onu
     * SQL'e cevirir. `where('status', 'paid')` yazsaydik kural uc dosyaya
     * dagilirdi (K50'nin Faz 5'te kurdugu desen).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeGrantingPublishRight(Builder $query): void
    {
        $granting = array_values(array_filter(
            OrderStatus::cases(),
            static fn (OrderStatus $status): bool => $status->grantsPublishRight(),
        ));

        $query->whereIn('status', array_column($granting, 'value'));
    }

    /**
     * Siparis, odeme penceresi dolmus mu?
     *
     * `expires_at` NULL ise sure sinirsizdir (saglayici penceresi olmayan
     * akislar icin). null-safe karsilastirma bilerek yok: iki durum farkli
     * bilgidir, `??` ile birlestirmek onlari sessizce esitlerdi (N4).
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tekil alimda davetiye; PAKET aliminda null (K42).
     *
     * @return BelongsTo<Invitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
