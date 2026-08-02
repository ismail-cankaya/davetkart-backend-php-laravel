<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test ve seeder icin sahte kullanici uretir.
 *
 * Ayrintili aciklama: docs/rehber/database/factories/UserFactory.md
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Uretilen kullanicilarin duz metin parolasi — testler bunu kullanir. */
    public const PASSWORD = 'password';

    /**
     * Hash bir kez uretilir, tum ornekler paylasir. Parola hash'lemesi
     * kasitli olarak yavastir; kullanici basina tekrarlamak test suresini uzatir.
     */
    protected static ?string $passwordHash = null;

    /**
     * Bir kullanicinin varsayilan alanlari.
     *
     * Donus tipi BILEREK yazilmadi: ust sinif Factory::definition() anahtarlari
     * "User'in gercek kolon adlari" olarak tanimlar. Buraya daha genis bir tip
     * yazmak kovaryansi bozar; bos birakilinca ust siniftan devralinir.
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
            'remember_token' => Str::random(10),
        ];
    }

    /** E-postasi dogrulanmamis kullanici. */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
