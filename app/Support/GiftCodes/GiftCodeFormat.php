<?php

declare(strict_types=1);

namespace App\Support\GiftCodes;

/**
 * Hediye kodunun biçimi: normalleştirme, özet, üretim ve elle girilen kodun kuralı (Faz 11).
 *
 * Kod veritabanına yalnızca özet olarak girer (D3); aynı kodun her yazılışı
 * (küçük harf, boşluk, tire) normalize() ile tek biçime iner, sonra özetlenir.
 * Ayrıntılı açıklama: docs/rehber/app/Support/GiftCodes/GiftCodeFormat.md
 */
final class GiftCodeFormat
{
    /** Üretilen kodların öneki; elle girilen kodlardan ayırt edilsin diye. */
    public const PREFIX = 'DK';

    /** Önekten sonraki rastgele karakter sayısı (3 grup × 4). */
    public const RANDOM_LENGTH = 12;

    /**
     * Crockford base32'den 0 ve 1 de çıkarıldı: O/0 ve I/L/1 karışmaz.
     * 30 sembol × 12 karakter ≈ 58,9 bit.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const CUSTOM_MIN = 4;

    public const CUSTOM_MAX = 32;

    /** Listeleme için saklanan ipucunun uzunluğu (son N karakter). */
    public const HINT_LENGTH = 4;

    /** Büyük harf; boşluk ve tireler atılır. " dk-7kq2 m9xa " → "DK7KQ2M9XA". */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', $code));
    }

    /** Veritabanına yazılan ve aranan özet (64 onaltılık karakter). */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /** Listelemede gösterilen son karakterler; kodu geri vermez. */
    public static function hint(string $code): string
    {
        return substr(self::normalize($code), -self::HINT_LENGTH);
    }

    /** Tek kullanımlık kod üretir: DK-XXXX-XXXX-XXXX (kriptografik rastgele). */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $random = '';

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $random .= self::ALPHABET[random_int(0, $last)];
        }

        return self::PREFIX.'-'.implode('-', str_split($random, 4));
    }

    /** Elle girilen kod geçerli mi? Normalize edildikten sonra 4–32 karakter, A-Z ve 0-9. */
    public static function isValidCustom(string $code): bool
    {
        $normalized = self::normalize($code);
        $length = strlen($normalized);

        return $length >= self::CUSTOM_MIN
            && $length <= self::CUSTOM_MAX
            && preg_match('/^[A-Z0-9]+$/', $normalized) === 1;
    }
}
