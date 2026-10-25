<?php

declare(strict_types=1);

namespace App\Services\Payment\Shopier;

use InvalidArgumentException;

/**
 * Kuruş (int) <-> Shopier'in tutar metni ("399.00") — saf fonksiyonlar (K124).
 *
 * 🔴 Float YOK. `number_format(39900 / 100, 2)` bugün doğru sonuç verir, ama
 * bölme bir float üretir ve float bazı kuruş değerlerini tam temsil edemez
 * (0.1 + 0.2 = 0.30000000000000004). İmzanın içine giren bir metinde "neredeyse
 * doğru" yoktur: tek bayt farkı Shopier'de "imza geçersiz" demektir.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/Shopier/ShopierAmount.md
 */
final class ShopierAmount
{
    /** 39900 -> "399.00" · 5 -> "0.05" */
    public static function format(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('An amount cannot be negative.');
        }

        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    /**
     * "399.00" | "399,00" | "399.5" | "399" -> kuruş; başka her şey null.
     *
     * OSB'nin `price` biçimi resmi dokümanda görülmedi (§A-c). Dar bir dilbilgisi
     * kabul edilir: tanınmayan biçim tahmin edilmez, null döner ve çağıran reddeder.
     */
    public static function parse(string $value): ?int
    {
        if (preg_match('/^(\d{1,9})(?:[.,](\d{1,2}))?$/', trim($value), $m) !== 1) {
            return null;
        }

        $fraction = isset($m[2]) ? str_pad($m[2], 2, '0') : '00';

        return (int) $m[1] * 100 + (int) $fraction;
    }
}
