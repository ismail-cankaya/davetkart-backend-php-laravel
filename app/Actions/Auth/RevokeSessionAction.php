<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Tek bir oturumu kapatır — "bu cihazdan çık" (Faz 14, 14.9 · K143).
 *
 * 🔴 Sorgu kullanıcının KENDİ token'larıyla sınırlı (`$user->tokens()`).
 * Başkasının oturum kimliği verilirse satır bulunmaz → 404 (H7): kimlik
 * sıralı bir tamsayı ve tahmin edilebilir, bu yüzden sahiplik sorguda.
 */
final class RevokeSessionAction
{
    /**
     * @throws ModelNotFoundException Bu kullanıcının böyle bir oturumu yok → 404
     */
    public function handle(User $user, int $sessionId): void
    {
        $user->tokens()->whereKey($sessionId)->firstOrFail()->delete();
    }
}
