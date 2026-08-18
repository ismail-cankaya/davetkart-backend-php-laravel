<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Davetiyenin yaşam döngüsündeki yeri.
 *
 * Değerler frontend sözleşmesiyle birebir aynıdır (src/types.ts → InvitationStatus).
 * Durum adı çevrilmez; gösterim kararı frontend'e aittir (K21).
 * Ayrıntılı açıklama: docs/rehber/app/Enums/InvitationStatus.md
 */
enum InvitationStatus: string
{
    /** Hesaba kaydedilmiş, misafire kapalı. */
    case Saved = 'saved';

    /** Yayında; paylaşılan linkten okunabilir. */
    case Published = 'published';

    /** Yeni davetiyenin doğduğu durum. Migration ve Action tek buradan beslenir. */
    public static function default(): self
    {
        return self::Saved;
    }

    /**
     * Veritabanı CHECK kısıtı ve doğrulama kuralları için ham değerler.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
