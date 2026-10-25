<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionTier;
use App\Models\PriceCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test için kampanya. Varsayılan: şu an geçerli, bütün planlarda %20, süresiz.
 *
 * Alanlar sabit (rastgelelik yok): indirim testleri tesadüfe bağlanmasın
 * (OrderFactory'nin ilkesi).
 * Ayrıntılı açıklama: docs/rehber/app/Models/PriceCampaign.md §5
 *
 * @extends Factory<PriceCampaign>
 */
class PriceCampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'percent' => 20,
            'tier' => null,
            'starts_at' => now()->subHour(),
            'ends_at' => null,
            'stopped_at' => null,
            'label' => null,
        ];
    }

    public function percent(int $percent): static
    {
        return $this->state(fn (array $attributes): array => ['percent' => $percent]);
    }

    public function forTier(SubscriptionTier $tier): static
    {
        return $this->state(fn (array $attributes): array => ['tier' => $tier]);
    }

    /** Henüz başlamamış (yarın başlar). */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->addDay(),
            'ends_at' => null,
        ]);
    }

    /** Süresi dolmuş (dün bitti). */
    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
        ]);
    }

    /** Elle durdurulmuş. */
    public function stopped(): static
    {
        return $this->state(fn (array $attributes): array => ['stopped_at' => now()->subMinute()]);
    }
}
