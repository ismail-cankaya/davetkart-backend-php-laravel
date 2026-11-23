<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Support\EmailVerificationLink;

/**
 * Doğrulama postasını kuyruğa koyar (K191). Kayıt ve "yeniden gönder" ucu çağırır;
 * hesap zaten doğrulanmışsa hiçbir şey göndermez.
 */
final class SendEmailVerificationAction
{
    public function handle(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->notify(new VerifyEmailNotification(EmailVerificationLink::for($user)));
    }
}
