<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hız sınırı kovasının IP anahtarı (Faz 10, 10.60 · K104).
 *
 * IPv4 olduğu gibi kalır. IPv6 ilk 64 bitine (/64) indirilir: bir ev ya da
 * mobil hat genellikle koca bir /64 alır ve içindeki her adres ayrı bir cihaz
 * gibi görünebilir. Adres başına sayılsaydı tek bir cihaz adres değiştirerek
 * kovayı sınırsızca atlatırdı.
 *
 * IPv6 içine gömülü IPv4 (::ffff:1.2.3.4) IPv4 olarak sayılır; yoksa bu
 * biçimdeki bütün adresler aynı /64'e, yani tek kovaya düşerdi.
 * Ayrıntılı açıklama: docs/rehber/app/Support/IpBucket.md
 */
final class IpBucket
{
    public static function of(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'bilinmeyen';
        }

        $packed = @inet_pton($ip);

        // IPv4 ya da geçersiz: olduğu gibi.
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // ::ffff:a.b.c.d -> a.b.c.d
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
