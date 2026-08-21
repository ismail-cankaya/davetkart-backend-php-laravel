<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\TimelineEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test ve seeder icin sahte program adimi uretir.
 *
 * `sort_order` sabit 0'dir; siralama testi yapan cagiran onu sequence() ile
 * kendisi verir (bkz. InvitationFactory::withTimeline).
 * Ayrintili aciklama: docs/rehber/database/factories/TimelineEventFactory.md
 *
 * @extends Factory<TimelineEvent>
 */
class TimelineEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Ust kayit verilmezse fabrika kendi davetiyesini uretir.
            'invitation_id' => Invitation::factory(),

            'time' => '19:00',
            'title' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'sort_order' => 0,
        ];
    }
}
