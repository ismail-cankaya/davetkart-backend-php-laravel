<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GiftCodeBenefit;
use App\Enums\SubscriptionTier;
use App\Models\GiftCode;
use App\Support\GiftCodes\GiftCodeFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test için sahte hediye kodu.
 *
 * 🔴 Fabrika düz kodu SAKLAMAZ (üretim koduyla aynı, D3). Testin kodu bilmesi
 * gerekiyorsa withCode('DUGUN2026') ile kendisi verir.
 * Ayrıntılı açıklama: docs/rehber/database/factories/GiftCodeFactory.md
 *
 * @extends Factory<GiftCode>
 */
class GiftCodeFactory extends Factory
{
    /** Varsayılan: bütün planlarda %20, 10 kullanımlık, süresiz, açık. */
    public function definition(): array
    {
        $code = GiftCodeFormat::generate();

        return [
            'code_hash' => GiftCodeFormat::hash($code),
            'code_hint' => GiftCodeFormat::hint($code),
            'label' => null,
            'batch_id' => null,
            'benefit' => GiftCodeBenefit::Percent,
            'tier' => null,
            'percent_off' => 20,
            'amount_off_minor' => null,
            'max_redemptions' => 10,
            'expires_at' => null,
            'disabled_at' => null,
        ];
    }

    /** Testin bildiği düz kod; özet ve ipucu ondan türetilir. */
    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes): array => [
            'code_hash' => GiftCodeFormat::hash($code),
            'code_hint' => GiftCodeFormat::hint($code),
        ]);
    }

    /** Planı bedava açar. Alanlar birlikte değişir: CHECK kısıtları fabrikayı da bağlar. */
    public function free(SubscriptionTier $tier): static
    {
        return $this->state(fn (array $attributes): array => [
            'benefit' => GiftCodeBenefit::Free,
            'tier' => $tier,
            'percent_off' => null,
            'amount_off_minor' => null,
        ]);
    }

    public function percent(int $percent, ?SubscriptionTier $tier = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'benefit' => GiftCodeBenefit::Percent,
            'tier' => $tier,
            'percent_off' => $percent,
            'amount_off_minor' => null,
        ]);
    }

    public function fixed(int $amountMinor, ?SubscriptionTier $tier = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'benefit' => GiftCodeBenefit::Fixed,
            'tier' => $tier,
            'percent_off' => null,
            'amount_off_minor' => $amountMinor,
        ]);
    }

    public function maxUses(int $max): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_redemptions' => $max,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'disabled_at' => now()->subMinute(),
        ]);
    }
}
