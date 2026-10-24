<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Hediye kodunun sağladığı fayda türü (Faz 11).
 *
 * free → tek bir planı bedava açar · percent → yüzde indirim · fixed → sabit tutar (kuruş).
 * İndirim hesabı GiftCode::discountFor()'da; burada yalnızca türler var.
 * Ayrıntılı açıklama: docs/rehber/app/Enums/GiftCodeBenefit.md
 */
enum GiftCodeBenefit: string
{
    case Free = 'free';
    case Percent = 'percent';
    case Fixed = 'fixed';

    /**
     * Veritabanı CHECK kısıtı ve komut seçenekleri için ham değerler.
     *
     * Elle yazılmaz: enum değişince kısıt sessizce eskimesin (K39).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
