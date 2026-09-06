<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactSubject;
use App\Models\ContactMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test ve seeder icin sahte iletisim mesaji uretir.
 *
 * 🔴 Konu SABIT (General), rastgele degil: rastgele bir enum degeri, konu
 * bazli bir testi tesadufe baglardi (OrderFactory ve InvitationFactory'nin
 * ayni ilkesi — rastgelelik yalnizca davranisi etkilemeyen alanda).
 * Ayrintili aciklama: docs/rehber/database/factories/ContactMessageFactory.md
 *
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    /** Donus tipi BILEREK yazilmadi — ust siniftan devralinir (ders 19). */
    public function definition(): array
    {
        return [
            'name' => 'Deniz Yilmaz',
            'email' => 'deniz@example.test',
            'subject' => ContactSubject::General,
            'message' => 'Davetiye planlari hakkinda bilgi almak istiyorum.',

            // 64 karakterlik sahte ozet: kolonun genisligiyle ayni, ama
            // gercek bir IP'den turetilmemis (fabrika kisisel veri uretmez).
            'ip_hash' => str_repeat('a', 64),
        ];
    }
}
