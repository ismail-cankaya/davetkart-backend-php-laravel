<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;

/**
 * Ad ve soyadı günceller (Faz 14, 14.7).
 *
 * Alanlar AÇIKÇA atanır, `fill()` değil: User'ın #[Fillable] listesinde
 * `email` ve `password` da var. Bu Action'a yarın bir dizi geçilse bile
 * yalnızca iki kolon yazılır (E7'nin kendi modelimizdeki hali).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/UpdateProfileAction.md
 */
final class UpdateProfileAction
{
    /**
     * @param  array{first_name: string, last_name: string}  $profile
     */
    public function handle(User $user, array $profile): User
    {
        $user->first_name = $profile['first_name'];
        $user->last_name = $profile['last_name'];
        $user->save();

        return $user;
    }
}
