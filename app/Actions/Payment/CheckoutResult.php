<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Models\Order;

/**
 * StartCheckoutAction'in ciktisi — siparis ve odeme sayfasi birlikte.
 *
 * Neden Action sadece Order dondurmuyor? `redirectUrl` bir SIPARIS ALANI
 * DEGIL: veritabaninda saklanmaz (E1 — turetilebilir/gecici veri saklanmaz),
 * saglayicidan gelir ve yalnizca bu istegin yaniti icin anlamlidir. Order'a
 * gecici bir ozellik olarak iliştirmek onu bir kolonmus gibi gosterirdi —
 * Media::url()'un accessor DEGIL metot olmasiyla ayni gerekce (Faz 6).
 *
 * Ad, frontend sozlesmesiyle bilerek ayni: types.ts -> CheckoutResult.
 * Ayrintili aciklama: docs/rehber/app/Actions/Payment/StartCheckoutAction.md §6
 */
final readonly class CheckoutResult
{
    public function __construct(
        public Order $order,
        public string $redirectUrl,
    ) {}
}
