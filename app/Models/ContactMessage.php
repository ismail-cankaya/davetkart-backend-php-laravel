<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactSubject;
use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Iletisim formundan gelen bir mesaj.
 *
 * 🔴 `ip_hash` #[Fillable] listesinde YOK. Rsvp modelindeki ayni gerekce:
 * onu sunucu hesaplar; istemciden gelen bir "IP" veri degil YALANDIR.
 * Beyaz liste burada bir konfor degil savunmanin kendisidir — bu tablo
 * auth'suz yazma yolunun ucundadir.
 *
 * Diger dort alan (name, email, subject, message) toplu atanabilir cunku
 * dordu de kullanicinin BEYANIDIR ve FormRequest bicimlerini dogrulamistir.
 * Ayrintili aciklama: docs/rehber/app/Models/ContactMessage.md
 */
#[Fillable(['name', 'email', 'subject', 'message'])]
class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject' => ContactSubject::class,
        ];
    }
}
