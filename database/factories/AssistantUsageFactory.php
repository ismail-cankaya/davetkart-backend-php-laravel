<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssistantUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test icin sahte gunluk sayac uretir.
 *
 * 🔴 Rastgelelik YOK. Kota testleri "kac mesaj kalmisti" sorusuna bakiyor;
 * rastgele bir baslangic sayaci, testi tesadufe baglardi (OrderFactory'nin
 * ayni ilkesi).
 * Ayrintili aciklama: docs/rehber/database/factories/AssistantUsageFactory.md
 *
 * @extends Factory<AssistantUsage>
 */
class AssistantUsageFactory extends Factory
{
    /**
     * Varsayilan: bugun, sifir mesaj.
     *
     * Donus tipi BILEREK yazilmadi — ust siniftan devralinir (ders 19).
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'usage_date' => CarbonImmutable::now()->toDateString(),
            'message_count' => 0,
        ];
    }

    /** Belirli bir gunun sayaci — "dunku kota bugunu etkilemez" testi icin. */
    public function on(string $date): self
    {
        return $this->state(['usage_date' => $date]);
    }

    /** Sayaci belirli bir degere kurar. */
    public function spent(int $count): self
    {
        return $this->state(['message_count' => $count]);
    }
}
