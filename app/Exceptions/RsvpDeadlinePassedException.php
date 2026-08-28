<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * LCV son tarihi gecmis bir davetiyeye yanit gonderilmeye calisildi.
 *
 * 403 doner (ErrorCode::RsvpDeadlinePassed), 404 DEGIL: burada saklanacak bir
 * sey yok. Son tarih zaten misafire acik gonderilen govdenin icinde
 * (`rsvpDeadline`), dolayisiyla "gecti" demek hicbir yeni bilgi ifsa etmez —
 * H7'nin gerekcesi burada olusmuyor.
 *
 * 422 de degil: girdi KUSURSUZ olabilir; reddedilen sey bicim degil ZAMAN.
 * Ayrintili aciklama: docs/rehber/app/Exceptions/RsvpDeadlinePassedException.md
 */
final class RsvpDeadlinePassedException extends RuntimeException implements HasErrorCode
{
    public function __construct()
    {
        parent::__construct('RSVP rejected: the deadline for this invitation has passed.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::RsvpDeadlinePassed;
    }

    /** @return array<string, mixed> */
    public function errorParams(): array
    {
        return [];
    }
}
