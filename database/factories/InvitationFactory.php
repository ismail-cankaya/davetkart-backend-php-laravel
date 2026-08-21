<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * Test ve seeder icin sahte davetiye uretir.
 *
 * 🔴 Rastgelelik yalnizca DAVRANISI ETKILEMEYEN alanlarda: durum, moduller ve
 * sahiplik sabittir, yoksa testler tesadufe baglanir.
 * Ayrintili aciklama: docs/rehber/database/factories/InvitationFactory.md
 *
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Bir davetiyenin varsayilan alanlari.
     *
     * Donus tipi BILEREK yazilmadi — ust siniftan devralinir (Faz 2, ders 19).
     */
    public function definition(): array
    {
        return [
            // Sahip verilmezse fabrika kendi kullanicisini uretir.
            'user_id' => User::factory(),
            'status' => InvitationStatus::default(),

            'category_id' => 'dugun',
            'preset_id' => 'moda-gece',
            'palette' => 'midnight',

            'title' => 'Hayatimizin En Anlamli Gunu',
            'subtitle' => fake()->sentence(),
            'names' => fake()->firstName().' & '.fake()->firstName(),
            'venue' => fake()->company(),
            'map_url' => null,
            'event_at' => now()->addMonths(3),

            // Hepsi KAPALI: modul acikligi paywall'in konusu, varsayilan olamaz.
            'show_envelope' => false,
            'show_timer' => false,
            'show_timeline' => false,
            'show_gallery' => false,
            'show_gift' => false,
            'show_rsvp' => false,

            'bank_name' => null,
            'account_holder' => null,
            'iban' => null,
            'gift_options' => null,

            'rsvp_deadline' => null,
            'ask_menu_preference' => false,

            'published_at' => null,
        ];
    }

    /** Yayinlanmis davetiye — status ve published_at birlikte degisir. */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvitationStatus::Published,
            'published_at' => now(),
        ]);
    }

    /** Program akisi olan davetiye; sira 0'dan baslar. */
    public function withTimeline(int $count = 3): static
    {
        return $this
            ->state(fn (array $attributes): array => ['show_timeline' => true])
            ->has(
                TimelineEvent::factory()
                    ->count($count)
                    ->sequence(fn (Sequence $sequence): array => ['sort_order' => $sequence->index]),
                'timelineEvents',
            );
    }
}
