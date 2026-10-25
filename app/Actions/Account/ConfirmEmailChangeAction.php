<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Exceptions\EmailChangeFailedException;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Support\EmailChangeLink;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * İmzalı bağlantıyla e-posta adresini değiştirir (Faz 14, 14.10 · K144).
 *
 * Başarıda:
 *   - adres değişir, `email_verified_at` damgalanır (yeni adres kanıtlandı)
 *   - BÜTÜN oturumlar kapanır: hesabın kimliği değişti, kullanıcı yeni
 *     adresle yeniden giriş yapar (parola sıfırlamayla aynı kural)
 *   - eski adrese bekleyen sıfırlama bağlantısı silinir
 *   - ESKİ adrese "adresiniz değişti" maili gider: hesap ele geçirildiyse
 *     asıl sahibin haberi olsun
 *
 * 🔴 Yeni adres başka bir hesapta kayıtlıysa veritabanının UNIQUE kısıtı
 * yakalar ve AYNI EMAIL_CHANGE_INVALID döner (H6) — "bu adres kullanımda"
 * denmez. Önceden `exists()` diye sorulsaydı iki istek arasında yarış olurdu (H6).
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Account/RequestEmailChangeAction.md §4
 */
final class ConfirmEmailChangeAction
{
    /**
     * @param  array{user_id: int, email: string, old_fingerprint: string}  $change
     *
     * @throws EmailChangeFailedException
     */
    public function handle(array $change): void
    {
        try {
            $oldEmail = DB::transaction(function () use ($change): string {
                $user = User::query()->whereKey($change['user_id'])->lockForUpdate()->first();

                if ($user === null) {
                    throw EmailChangeFailedException::because('user not found');
                }

                // Tek kullanımlık: adres bir kez değişince parmak izi tutmaz.
                if (! hash_equals(EmailChangeLink::fingerprint($user->email), $change['old_fingerprint'])) {
                    throw EmailChangeFailedException::because('stale link');
                }

                $oldEmail = $user->email;

                // Bekleyen sıfırlama ESKİ adresle anahtarlı; adres değişmeden sil.
                Password::broker()->deleteToken($user);

                $user->email = $change['email'];   // mutator normalize eder
                $user->email_verified_at = now();
                $user->save();

                $user->tokens()->delete();

                return $oldEmail;
            });
        } catch (UniqueConstraintViolationException) {
            throw EmailChangeFailedException::because('address taken');
        }

        Notification::route('mail', $oldEmail)
            ->notify(new EmailChangedNotification($change['email']));
    }
}
