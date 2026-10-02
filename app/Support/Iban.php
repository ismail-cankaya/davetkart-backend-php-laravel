<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IBAN doğrulaması: biçim + ISO 13616 mod-97 sağlama toplamı (Faz 10, 10.62 · K105).
 *
 * Yanlış yazılmış bir IBAN, misafirin gönderdiği hediyenin kaybolması demek.
 * Sağlama toplamı tek hane hatalarının ve yan yana iki hanenin yer
 * değiştirmesinin tamamını yakalar. Frontend'deki utils/iban.ts ile aynı
 * algoritma.
 * Ayrıntılı açıklama: docs/rehber/app/Support/Iban.md
 */
final class Iban
{
    /** Türkiye IBAN'ının sabit uzunluğu. */
    private const TR_LENGTH = 26;

    /** Boşlukları atar, harfleri büyütür: `tr12 0006…` → `TR120006…`. */
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    /** Geçerli bir IBAN mı? Boşluk ve küçük harf kabul edilir. */
    public static function isValid(string $value): bool
    {
        $iban = self::normalize($value);

        // Ülke kodu (2 harf) + kontrol hanesi (2 rakam) + 11–30 hane/harf.
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        if (str_starts_with($iban, 'TR') && strlen($iban) !== self::TR_LENGTH) {
            return false;
        }

        // İlk dört karakter sona alınır, harfler sayıya çevrilir (A=10 … Z=35)
        // ve kalan parça parça hesaplanır: sayı 30 haneyi aşıp taşardı.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $char) {
            $digits = ctype_digit($char) ? $char : (string) (ord($char) - 55);

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
