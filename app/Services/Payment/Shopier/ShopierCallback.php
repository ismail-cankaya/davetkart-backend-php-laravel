<?php

declare(strict_types=1);

namespace App\Services\Payment\Shopier;

use App\Services\Payment\PaymentNotification;

/**
 * İmzası doğrulanmış bir tarayıcı callback'i (Faz 12, K127).
 *
 * İki ayrı bilgi taşır ve ayrılmaları bu sınıfın varlık sebebidir:
 *   - nereye yönlendirilecek? ($orderId, $succeeded) -> HER modda var
 *   - durum ilerletilsin mi?  ($notification)        -> yalnızca 'callback' modunda
 *
 * `notification === null` "bu callback hiçbir siparişi değiştiremez" demektir.
 * 'osb' modunda üretilemez; yani controller unutsa bile ilerletecek bir şey yok.
 * Ayrıntılı açıklama: docs/rehber/app/Services/Payment/ShopierGateway.md §4
 */
final readonly class ShopierCallback
{
    public function __construct(
        /** Doğrulanmış sipariş kimliği (ULID). Yönlendirmenin `?order=` değeri. */
        public string $orderId,

        /** Shopier'in tarayıcıya söylediği sonuç. 🔴 İmzasız; yalnızca hangi sayfa gösterilecek. */
        public bool $succeeded,

        /** Durumu ilerletecek bildirim; 'osb' modunda null. */
        public ?PaymentNotification $notification,
    ) {}
}
