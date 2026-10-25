<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionTier;
use Carbon\CarbonInterface;
use Database\Factories\PriceCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Manuel fiyat kampanyası (Faz 13, K132): "Yılbaşına kadar her şey %20".
 *
 * 🔴 #[Fillable] boş (E7): satırı yalnızca komutların Action'ları yazar.
 * "Şu an geçerli mi?" kuralı TEK yerde: scopeActiveAt() + isActiveAt().
 * Ayrıntılı açıklama: docs/rehber/app/Models/PriceCampaign.md
 */
#[Fillable([])]
class PriceCampaign extends Model
{
    /** @use HasFactory<PriceCampaignFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'tier' => SubscriptionTier::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
        ];
    }

    /**
     * Verilen anda geçerli kampanyalar: başlamış, bitmemiş, durdurulmamış.
     *
     * `ends_at` hariç (`>`): 23:59:59.999'da biten kampanya o saniyede bitmiştir.
     *
     * @param  Builder<PriceCampaign>  $query
     */
    public function scopeActiveAt(Builder $query, CarbonInterface $at): void
    {
        $query->whereNull('stopped_at')
            ->where('starts_at', '<=', $at)
            ->where(static fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /**
     * Bu plana uygulanabilenler: plansız (hepsi) ya da bu plana özel.
     *
     * @param  Builder<PriceCampaign>  $query
     */
    public function scopeApplicableTo(Builder $query, SubscriptionTier $tier): void
    {
        $query->where(static fn (Builder $q) => $q->whereNull('tier')->orWhere('tier', $tier->value));
    }

    /** scopeActiveAt()'in PHP ikizi — listeleme ve testler için. */
    public function isActiveAt(CarbonInterface $at): bool
    {
        return $this->stopped_at === null
            && $this->starts_at <= $at
            && ($this->ends_at === null || $this->ends_at > $at);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
