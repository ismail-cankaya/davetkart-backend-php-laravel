<?php

declare(strict_types=1);

namespace App\Enums;

/** Misafirin davetiyeyi bildirme nedeni (Faz 25, K313). */
enum InvitationReportReason: string
{
    case Phishing = 'phishing';
    case Spam = 'spam';
    case Offensive = 'offensive';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
