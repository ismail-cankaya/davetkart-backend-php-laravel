<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Invitation;
use App\Models\Rsvp;
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

        // LCV modulu ACIK ve son tarihi ILERIDE: Faz 5'in elle dogrulama
        // betigi bu davetiye uzerinden kosuyor (FAZ-5-ELLE-DOGRULAMA.md).
        $yayinda = Invitation::factory()
            ->for($user)
            ->published()
            ->withTimeline()
            ->create([
                'title' => 'Yayindaki Davetiye',
                'show_rsvp' => true,
                'rsvp_deadline' => now()->addMonth()->toDateString(),
                'ask_menu_preference' => true,
            ]);

        // Panelin bos gorunmemesi icin uc yanit. Toplamlar bilerek farkli:
        // katilan 3 + kararsiz 1 = 4 kotadan yer tutar, gelmeyen 2 tutmaz (K50).
        Rsvp::factory()->for($yayinda)->guests(3)->create(['guest_name' => 'Can Dogan']);
        Rsvp::factory()->for($yayinda)->pending()->create(['guest_name' => 'Elif Yilmaz']);
        Rsvp::factory()->for($yayinda)->declined()->guests(2)->create(['guest_name' => 'Mert Kaya']);

        $this->command?->info(sprintf(
            'Demo hesap: %s / %s',
            self::DEMO_EMAIL,
            UserFactory::PASSWORD,
        ));
    }
}
