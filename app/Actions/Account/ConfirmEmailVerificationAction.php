<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Exceptions\EmailVerificationFailedException;
use App\Models\User;
use App\Support\EmailChangeLink;
use Illuminate\Support\Facades\DB;

/**
 * İmzası doğrulanmış bağlantıyla adresi doğrular (K191). Tek kullanım: doğrulanmış hesapta
 * ya da adres değişmişse bağlantı reddedilir. Kilit: aynı bağlantının iki eşzamanlı tıklaması.
 */
final class ConfirmEmailVerificationAction
{
    /**
     * @param  array{user_id: int, address_fingerprint: string}  $link
     *
     * @throws EmailVerificationFailedException -> 422 EMAIL_VERIFICATION_INVALID
     */
    public function handle(array $link): void
    {
        DB::transaction(function () use ($link): void {
            $user = User::query()->whereKey($link['user_id'])->lockForUpdate()->first();

            if ($user === null) {
                throw EmailVerificationFailedException::because('user not found');
            }

            if (! hash_equals(EmailChangeLink::fingerprint($user->email), $link['address_fingerprint'])) {
                throw EmailVerificationFailedException::because('address changed');
            }

            if ($user->hasVerifiedEmail()) {
                throw EmailVerificationFailedException::because('already used');
            }

            $user->email_verified_at = now();
            $user->save();
        });
    }
}
