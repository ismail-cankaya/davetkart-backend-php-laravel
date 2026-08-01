<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Uygulama kullanicisi (davetiye sahibi).
 *
 * Kolon adi `name`'dir; frontend'in bekledigi `fullName` donusumu
 * yalnizca UserResource icinde yapilir (CLAUDE.md §1).
 */
#[Fillable(['name', 'email', 'password'])]
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
     */
    protected function email(): Attribute
    {
        return Attribute::set(
            fn (string $value): string => mb_strtolower(trim($value))
        );
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
}
