<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\AccountLoginThrottledException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Hesap başına, IP'den bağımsız başarısız giriş kovası: saatte 30 (SEC-16, K265). Yalnızca başarısızlık sayılır.
 * Ayrıntılı açıklama: docs/rehber/app/Support/AccountLoginThrottle.md
 */
final class AccountLoginThrottle
{
    public const int MAX_FAILURES = 30;

    public const int DECAY_SECONDS = 3600;

    /** @throws AccountLoginThrottledException Kova doluysa; parola hiç denenmez. */
    public function ensureOpen(string $email): void
    {
        $key = self::key('login-fail', $email);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            throw new AccountLoginThrottledException(RateLimiter::availableIn($key));
        }
    }

    /** Başarısız denemeyi sayar; kovayı bu deneme doldurduysa true. */
    public function recordFailure(string $email): bool
    {
        return RateLimiter::hit(self::key('login-fail', $email), self::DECAY_SECONDS) === self::MAX_FAILURES;
    }

    /** Uyarı postasının kendi kovası: saatte en çok bir kez true döner. */
    public function claimWarning(string $email): bool
    {
        $key = self::key('login-warn', $email);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return false;
        }
        RateLimiter::hit($key, self::DECAY_SECONDS);

        return true;
    }

    /** Parola sıfırlanınca kova boşalır: sahibin her zaman bir giriş yolu kalır. */
    public function clear(string $email): void
    {
        RateLimiter::clear(self::key('login-fail', $email));
    }

    /** E-posta kişisel veri: anahtara ham değil, APP_KEY'li HMAC girer (IpHasher deseni). */
    private static function key(string $prefix, string $email): string
    {
        return $prefix.'|'.hash_hmac('sha256', EmailNormalizer::normalize($email), Config::string('app.key'));
    }
}
