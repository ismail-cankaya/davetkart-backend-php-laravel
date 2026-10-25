<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Havale/EFT için gösterilecek hesap (Faz 13, K135). Saf veri.
 *
 * Yalnızca PaymentMethods üretir; IBAN sağlama toplamından geçmiş ve
 * boşluksuz-büyük harf hâline getirilmiştir.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/PaymentMethods.md §4
 */
final readonly class BankTransferAccount
{
    public function __construct(
        public string $bankName,
        public string $accountHolder,
        public string $iban,
    ) {}
}
