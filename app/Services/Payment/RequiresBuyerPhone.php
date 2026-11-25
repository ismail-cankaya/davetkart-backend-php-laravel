<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * K262 (A-E5): bu sürücü alıcının gerçek cep telefonunu ister (Shopier `buyer_phone`); checkout onu zorunlu kılar.
 * Ayrintili aciklama: docs/rehber/app/Services/Payment/RequiresBuyerPhone.md
 */
interface RequiresBuyerPhone {}
