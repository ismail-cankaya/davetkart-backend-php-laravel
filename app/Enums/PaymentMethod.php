<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Müşterinin bir planı nasıl ödeyebileceği (Faz 13, K134).
 *
 * Değerler `.env`'deki `PAYMENT_METHODS` listesinin ve frontend sözleşmesinin
 * (src/types.ts → PaymentMethod) kelimeleridir. Yöntemin AÇIK olup olmadığı
 * burada değil, Services/Payment/PaymentMethods'ta sorulur.
 * Ayrıntılı açıklama: docs/rehber/app/Enums/PaymentMethod.md
 */
enum PaymentMethod: string
{
    /** Kart — aktif ödeme sağlayıcısının (Shopier) sayfasında. */
    case Card = 'card';

    /** Havale/EFT — hesap bilgisi gösterilir, ödeme elle kontrol edilir. */
    case BankTransfer = 'bank_transfer';

    /** Hediye kodu — tutarı düşürür; %100 kodla sağlayıcıya hiç gidilmez. */
    case GiftCode = 'gift_code';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
