<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RsvpStatus;
use App\Models\Invitation;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test ve seeder icin sahte LCV yaniti uretir.
 *
 * 🔴 Rastgelelik yalnizca DAVRANISI ETKILEMEYEN alanlarda. `status` ve
 * `guest_count` sabit: ikisi de kota hesabini belirliyor, rastgele olsalardi
 * kota testleri tesadufe baglanirdi (InvitationFactory ile ayni ilke).
 * Ayrintili aciklama: docs/rehber/database/factories/RsvpFactory.md
 *
 * @extends Factory<Rsvp>
 */
class RsvpFactory extends Factory
{
    /**
     * Bir LCV yanitinin varsayilan alanlari.
     *
     * Donus tipi BILEREK yazilmadi — ust siniftan devralinir (Faz 2, ders 19).
     */
    public function definition(): array
    {
        return [
            // Ust kayit verilmezse fabrika kendi davetiyesini uretir.
            'invitation_id' => Invitation::factory(),

            'guest_name' => fake()->name(),
            'guest_count' => 1,
            'status' => RsvpStatus::Attending,

            'menu_preference' => null,
            'message' => null,

            // Gercek Action APP_KEY ile hash'ler; fabrika icin yalnizca
            // 64 karakterlik gecerli bir deger yeterli. Ham IP burada da
            // saklanmaz — test verisi bile olsa aliskanlik bozulmaz.
            'ip_hash' => hash('sha256', fake()->ipv4()),
        ];
    }

    /** Gelmiyorum diyen misafir — kotadan yer TUTMAZ (K50). */
    public function declined(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RsvpStatus::Declined,
        ]);
    }

    /** Kararsiz misafir — kotadan yer TUTAR (K50). */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RsvpStatus::Pending,
        ]);
    }

    /**
     * Kac kisilik yanit?
     *
     * Kota testlerinin okunur olmasi icin: `Rsvp::factory()->guests(4)` yazmak,
     * `->create(['guest_count' => 4])` yazmaktan daha net soyler ki bu sayi
     * TESTIN KONUSU.
     */
    public function guests(int $count): static
    {
        return $this->state(fn (array $attributes): array => [
            'guest_count' => $count,
        ]);
    }
}
