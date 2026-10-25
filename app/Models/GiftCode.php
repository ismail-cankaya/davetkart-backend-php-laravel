<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GiftCodeBenefit;
use App\Enums\SubscriptionTier;
use Database\Factories\GiftCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hediye kodu (Faz 11). Kodun kendisi değil özeti saklanır (D3).
 *
 * 🔴 #[Fillable] boş (E7): kodu yalnızca komutların Action'ları yazar.
 * İndirim kuralı (D6) burada, tek yerde: discountFor() + isApplicableTo().
 * Ayrıntılı açıklama: docs/rehber/app/Models/GiftCode.md
 *
 * @property int|null $active_redemptions_count withActiveRedemptionCount() ile gelir
 */
#[Fillable([])]
class GiftCode extends Model
{
    /** @use HasFactory<GiftCodeFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'benefit' => GiftCodeBenefit::class,
            'tier' => SubscriptionTier::class,
            'percent_off' => 'integer',
            'amount_off_minor' => 'integer',
            'max_redemptions' => 'integer',
            'expires_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /** Kod bu plan için kullanılabilir mi? Plansız indirim kodu bütün planlarda geçer (D6). */
    public function isApplicableTo(SubscriptionTier $tier): bool
    {
        return $this->tier === null || $this->tier === $tier;
    }

    /**
     * Bu planın fiyatından düşülecek tutar (kuruş). Plan uymuyorsa 0.
     *
     * 🔴 Tam sayı aritmetiği: yüzde aşağı yuvarlanır (kuruş farkı işletmenin
     * lehine), sabit indirim fiyatı aşamaz. Float yok.
     *
     * Faz 13 (K133): `$priceMinor` kodun uygulanacağı TABAN — otomatik
     * indirimden (kampanya / ilk yayın) sonra kalan fiyat. Verilmezse liste
     * fiyatı (Faz 11 davranışı). Ücretsiz kod tabanın tamamını düşer.
     */
    public function discountFor(SubscriptionTier $tier, ?int $priceMinor = null): int
    {
        if (! $this->isApplicableTo($tier)) {
            return 0;
        }

        $price = $priceMinor ?? $tier->price() * 100;

        return match ($this->benefit) {
            GiftCodeBenefit::Free => $price,
            GiftCodeBenefit::Percent => intdiv($price * (int) $this->percent_off, 100),
            GiftCodeBenefit::Fixed => min((int) $this->amount_off_minor, $price),
        };
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** `expires_at` NULL = süresiz (N4: iki durum ayrı bilgi). */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * `active_redemptions_count` sütununu tek alt sorguyla ekler (N+1 yok).
     *
     * @param  Builder<GiftCode>  $query
     */
    public function scopeWithActiveRedemptionCount(Builder $query): void
    {
        $query->withCount([
            'redemptions as active_redemptions_count' => static function (Builder $redemptions): void {
                /** @var Builder<GiftCodeRedemption> $redemptions */
                $redemptions->active();
            },
        ]);
    }

    /** @return HasMany<GiftCodeRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(GiftCodeRedemption::class);
    }
}
