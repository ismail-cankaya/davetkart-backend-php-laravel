<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\EmailNotVerifiedException;
use App\Models\User;

/**
 * K191 (SEC-09): ödeme ve yayın kapısının TEK yeri. Çağıranlar: StartCheckoutAction,
 * PublishInvitationAction. Ayrıntılı açıklama: docs/rehber/app/Support/VerifiedEmailGuard.md
 */
final readonly class VerifiedEmailGuard
{
    /** @throws EmailNotVerifiedException -> 403 EMAIL_NOT_VERIFIED */
    public function assertVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException;
        }
    }
}
