<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// Fiillable: Sadece bu alanlar değiştirilebilir.
// Hidden: Bu alanlar JSON cevirmede gizlenir.
#[Fillable(['first_name', 'last_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * E-postayi her zaman kucuk harfe indirger.
     *
     * PostgreSQL'de UNIQUE karsilastirmasi harf duyarlidir; normalize
     * edilmezse ayni adres iki hesap acabilir.
     *
     * Klasik mutator sozdizimi BILEREK secildi; Attribute sinifi Larastan'da
     * generic bildirimi ister. Gerekcesi: docs/rehber/app/Models/User.md §3.6
     */
    protected function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Atama aninda varsayilan hash surucusuyle (Argon2id, K32) hash'lenir.
            'password' => 'hashed',
        ];
    }

    /**
     * Kullanicinin satin alma kayitlari (Faz 7).
     *
     * Siralama BILEREK yok: yayin hakki sorgusu hic siralamaz, bir
     * "siparislerim" ekrani en yeniyi ustte ister. Karar cagirana ait
     * (invitations() ile ayni gerekce).
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Kullanicinin davetiyeleri — sahipligi kuran tek dogru yol.
     *
     * Siralama BILEREK yok: davetiye sirasi bir sunum tercihidir, cagiran
     * belirler. (timelineEvents'te sira anlamin parcasi oldugu icin oradadir.)
     *
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
