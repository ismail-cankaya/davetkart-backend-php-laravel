<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Siparişe OTOMATİK uygulanan indirimin kaynağı (Faz 13, K130–K133).
 *
 * Hediye kodu burada YOK: o kullanıcının yazdığı bir şey, ayrı bir tabloda
 * (gift_code_redemptions) izleniyor ve otomatik indirimin üstüne uygulanıyor.
 * Ayrıntılı açıklama: docs/rehber/app/Enums/PromotionKind.md
 */
enum PromotionKind: string
{
    /** Kullanıcının ilk yayını — `davetkart.pricing.first_publish_percent`. */
    case FirstPublish = 'first_publish';

    /** Senin `pricing:campaign:start` ile açtığın kampanya (`price_campaigns`). */
    case Campaign = 'campaign';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
