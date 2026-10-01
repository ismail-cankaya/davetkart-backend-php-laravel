<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Support\EmailNormalizer;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;

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
     * 🔴 Faz 10 (10.13): FormRequest'lerle AYNI fonksiyon. Bu mutator son
     * savunma hattidir: seeder, tinker, factory ve ileride parola sifirlama
     * (Dilim D) istek katmanindan gecmeden yazar.
     *
     * Klasik mutator sozdizimi BILEREK secildi; Attribute sinifi Larastan'da
     * generic bildirimi ister. Gerekcesi: docs/rehber/app/Models/User.md §3.6
     */
    protected function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = EmailNormalizer::normalize($value);
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
     * Laravel'in parola araci sifirlama token'ini uretince bunu cagirir.
     * Faz 10 (10.34): Laravel'in Ingilizce, `route('password.reset')` arayan
     * bildirimi yerine bizimki: Turkce, kuyruktan, frontend'e giden bagla.
     *
     * Parametre tipsiz: CanResetPassword sozlesmesi tipsiz tanimliyor ve
     * uygulayan sinif onu DARALTAMAZ (PHP bunu derleme aninda reddeder).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
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
