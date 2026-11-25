<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * K233 (B-E1): silme gunlugune yazilan kayit turleri; geri yuklemeden sonra yeniden silinirler.
 * Ayrintili aciklama: docs/rehber/app/Enums/DeletionSubject.md
 */
enum DeletionSubject: string
{
    case Account = 'account';
    case Invitation = 'invitation';
    case Rsvp = 'rsvp';
    case Media = 'media';
}
