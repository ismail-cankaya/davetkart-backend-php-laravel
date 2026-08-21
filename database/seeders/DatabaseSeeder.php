<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Invitation;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Gelistirme veritabanini elle denenebilir bir baslangic durumuna getirir.
 *
 * Tekrar calistirilabilir: veri zaten varsa hicbir sey yapmaz.
 * Ayrintili aciklama: docs/rehber/database/seeders/DatabaseSeeder.md
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Elle denemede kullanilacak hesap; parola UserFactory::PASSWORD. */
    private const DEMO_EMAIL = 'test@ornek.test';

    public function run(): void
    {
        $user = User::query()->where('email', self::DEMO_EMAIL)->first()
            ?? User::factory()->create([
                'first_name' => 'Test',
                'last_name' => 'Kullanici',
                'email' => self::DEMO_EMAIL,
            ]);

        // Idempotans: ikinci calistirmada kopya davetiye uretme.
        if ($user->invitations()->exists()) {
            $this->command?->info('Tohumlama atlandi: demo veri zaten var.');

            return;
        }

        Invitation::factory()
            ->for($user)
            ->withTimeline()
            ->create(['title' => 'Taslak Davetiye']);

        Invitation::factory()
            ->for($user)
            ->published()
            ->withTimeline()
            ->create(['title' => 'Yayindaki Davetiye']);

        $this->command?->info(sprintf(
            'Demo hesap: %s / %s',
            self::DEMO_EMAIL,
            UserFactory::PASSWORD,
        ));
    }
}
