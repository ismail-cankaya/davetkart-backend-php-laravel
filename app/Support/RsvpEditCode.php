<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Misafirin LCV'sini sonradan güncelleyebilmesi için verilen gizli kod (Faz 10, 10.59 · K101).
 *
 * Kod yalnızca ilk gönderimin yanıtında bir kez döner; veritabanında yalnızca
 * özeti (sha256) durur.
 * Ayrıntılı açıklama: docs/rehber/app/Support/RsvpEditCode.md
 */
final class RsvpEditCode
{
    public const LENGTH = 40;

    /** Yeni bir kod üretir (kriptografik rastgele, harf ve rakam). */
    public static function generate(): string
    {
        return Str::random(self::LENGTH);
    }

    /** Veritabanına yazılacak özet. */
    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    /** Gelen kod saklanan özetle eşleşiyor mu? Süresi sabit karşılaştırma. */
    public static function matches(?string $storedHash, string $code): bool
    {
        return $storedHash !== null && hash_equals($storedHash, self::hash($code));
    }
}
