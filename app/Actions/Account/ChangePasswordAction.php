<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Oturum açıkken parola değiştirir (Faz 14, 14.8 · K142).
 *
 * 🔴 Bu istek dışındaki BÜTÜN oturumlar kapanır. Parolasını değiştiren
 * kullanıcı çoğu zaman bir şeyden şüpheleniyordur; eski parolayla alınmış
 * token'lar açık kalsaydı değişiklik koruma sağlamazdı (ResetPasswordAction
 * ile aynı gerekçe). Farkı: o akışta kimse oturumda değil, hepsi kapanır;
 * burada kullanıcı ekranın başında, onun oturumu kalır.
 *
 * Bekleyen bir sıfırlama bağlantısı da silinir: eski parola zamanında
 * istenmiş bir bağlantı, yeni parolayı ezmesin.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/ChangePasswordAction.md
 */
final class ChangePasswordAction
{
    /**
     * @param  int|null  $keepTokenId  Açık kalacak oturum (isteği yapan); null = hepsi kapanır
     */
    public function handle(User $user, #[SensitiveParameter] string $newPassword, ?int $keepTokenId): void
    {
        DB::transaction(function () use ($user, $newPassword, $keepTokenId): void {
            // 'hashed' cast'i düz parolayı hash'ler (K32).
            $user->forceFill(['password' => $newPassword])->save();

            $user->tokens()
                ->when($keepTokenId !== null, static fn ($query) => $query->whereKeyNot($keepTokenId))
                ->delete();

            Password::broker()->deleteToken($user);
        });

        // Commit'ten sonra, kuyruktan: "siz değilseniz" uyarısı.
        $user->notify(new PasswordChangedNotification);
    }
}
