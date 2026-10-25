<?php

declare(strict_types=1);

namespace App\Services\Refund;

use App\Enums\OrderStatus;
use App\Enums\RefundBlocker;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * "Bu hak iade edilebilir mi?" sorusunun TEK cevabı (Faz 14, 14.3 · K139).
 *
 * İki tüketici: hak listesi (düğmeyi göster/gizle) ve RequestRefundAction
 * (talebi aç/reddet). Kural iki yerde yazılsaydı ekran "iptal edebilirsin"
 * der, sunucu reddederdi — ya da tersi, ki daha kötüsü (C3).
 *
 * 🔴 `openRefundRequest` ilişkisi YÜKLENMİŞ olmalı: katı kipte (strict mode)
 * tembel yükleme hata fırlatır. Çağıranlar ya `with()` ya kilitli okumadan
 * sonra `load()` kullanır.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Refund/RefundEligibility.md
 */
final class RefundEligibility
{
    /**
     * İadeyi engelleyen İLK sebep; null = iade edilebilir.
     *
     * Sıra önemli: kullanıcıya en anlamlı sebep söylenir. Açık talebi olan
     * bir siparişe "süre geçti" demek yanıltırdı.
     */
    public function blockerFor(Order $order): ?RefundBlocker
    {
        return match (true) {
            $order->openRefundRequest !== null => RefundBlocker::AlreadyRequested,
            $order->status !== OrderStatus::Paid => RefundBlocker::NotPaid,
            $order->invitation_id !== null || $order->isConsumed() => RefundBlocker::InUse,
            $order->isGift() || $order->amount_minor === 0 => RefundBlocker::Free,
            ! $this->windowIsOpen($order) => RefundBlocker::WindowClosed,
            default => null,
        };
    }

    /** İade penceresinin sonu; ödenmemiş siparişte null. */
    public function windowEndsAt(Order $order): ?CarbonImmutable
    {
        return $order->paid_at?->addDays(Config::integer('davetkart.refunds.window_days'));
    }

    private function windowIsOpen(Order $order): bool
    {
        $endsAt = $this->windowEndsAt($order);

        return $endsAt !== null && $endsAt->isFuture();
    }
}
