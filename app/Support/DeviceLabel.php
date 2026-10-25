<?php

declare(strict_types=1);

namespace App\Support;

/**
 * User-Agent'tan kaba bir cihaz etiketi: tarayıcı + işletim sistemi (Faz 14, 14.9).
 *
 * 🔴 Bir güvenlik kararı DEĞİL, bir SUNUM yardımcısı. User-Agent istemcinin
 * sözüdür ve taklit edilebilir; yalnızca "bu oturum hangisiydi?" sorusunu
 * kullanıcıya hatırlatır. Tam bir ayrıştırıcı (bağımlılık) bu iş için ağır.
 *
 * Sıra önemli: Edge ve Opera "Chrome", Chrome "Safari" kelimesini de taşır.
 * Ayrıntılı açıklama: docs/rehber/app/Actions/Auth/IssueApiTokenAction.md §4
 */
final class DeviceLabel
{
    /** @var array<string, string> desen -> ad (ilk eşleşen kazanır) */
    private const BROWSERS = [
        '/Edg(e|A|iOS)?\//' => 'Edge',
        '/OPR\/|Opera/' => 'Opera',
        '/SamsungBrowser\//' => 'Samsung Internet',
        '/Firefox\/|FxiOS\//' => 'Firefox',
        '/Chrome\/|CriOS\//' => 'Chrome',
        '/Safari\//' => 'Safari',
    ];

    /** @var array<string, string> */
    private const SYSTEMS = [
        '/iPhone|iPad|iPod/' => 'iOS',
        '/Android/' => 'Android',
        '/Windows/' => 'Windows',
        '/Mac OS X|Macintosh/' => 'macOS',
        '/CrOS/' => 'ChromeOS',
        '/Linux/' => 'Linux',
    ];

    public static function browser(?string $userAgent): ?string
    {
        return self::first(self::BROWSERS, $userAgent);
    }

    public static function system(?string $userAgent): ?string
    {
        return self::first(self::SYSTEMS, $userAgent);
    }

    /** @param  array<string, string>  $patterns */
    private static function first(array $patterns, ?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
