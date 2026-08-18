<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\RegistrationFailedException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Yeni kullanici olusturur ve ilk API token'ini uretir.
 *
 * Gelen veri RegisterRequest'ten gecmistir; burada TEKRAR DOGRULANMAZ.
 * Benzersizlik, veritabani UNIQUE kisitiyla korunur — `unique` kurali degil (H6).
 * Ayrintili aciklama: docs/rehber/app/Actions/Auth/RegisterUserAction.md
 */
final class RegisterUserAction
{
    /** Sanctum token etiketi; ileride cihaz bazli iptal icin ayrisabilir. */
    private const TOKEN_NAME = 'api';

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $attributes
     *
     * @return array{user: User, token: string}
     *
     * @throws RegistrationFailedException E-posta zaten kayitliysa.
     */
    public function handle(array $attributes): array
    {
        try {
            return DB::transaction(function () use ($attributes): array {
                $user = User::create($attributes);

                return [
                    'user' => $user,
                    'token' => $user->createToken(self::TOKEN_NAME)->plainTextToken,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // H6: hangi alanin catistigi ISTEMCIYE soylenmez.
            throw RegistrationFailedException::emailTaken();
        }
    }
}
