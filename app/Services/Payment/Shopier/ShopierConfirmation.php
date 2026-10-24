<?php

declare(strict_types=1);

namespace App\Services\Payment\Shopier;

/**
 * Siparişi kim 'paid' yapar? (Faz 12, K117) — config('payment.providers.shopier.confirmation')
 *
 * Enum `app/Enums/` altında değil: bu bir alan kavramı değil, tek bir sürücünün
 * ayarı. Sürücünün yanında durur (B6: kapsamı kadar görünür).
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/ShopierGateway.md §3
 */
enum ShopierConfirmation: string
{
    /** Yalnızca imzalı OSB bildirimi. Tarayıcı callback'i yalnızca yönlendirir. (Varsayılan) */
    case Osb = 'osb';

    /** İmzası doğrulanmış tarayıcı callback'i de. 🔴 `status` imzasız: bilinçli risk. */
    case Callback = 'callback';
}
